<?php

use App\Models\Friendship;
use App\Models\Game;
use App\Models\Mechanic;
use App\Models\User;
use App\Models\UserBlock;
use App\Models\UserGame;
use App\Services\GameTaxonomySyncer;
use App\Services\Bootstrap\BootstrapVersion;
use Illuminate\Support\Facades\DB;

function giveGame(User $user, array $attributes = []): UserGame
{
    return UserGame::factory()->create([
        'user_id' => $user->id,
        'game_id' => Game::factory()->create($attributes)->id,
    ]);
}

it('rejects unauthenticated access', function () {
    $this->getJson('/api/bootstrap')->assertUnauthorized();
});

it('returns every section the app asks for when it opens, with an ETag', function () {
    $user = actingAsUser();
    giveGame($user, ['name' => 'Catan']);
    $friend = User::factory()->create();
    Friendship::factory()->accepted()->create(['requester_id' => $user->id, 'recipient_id' => $friend->id]);

    $response = $this->getJson('/api/bootstrap')->assertOk();

    $response->assertJsonStructure(['user', 'games', 'mechanics', 'categories', 'friends', 'requests' => ['incoming', 'outgoing'], 'blocks']);
    $response->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('games.0.game.name', 'Catan')
        ->assertJsonCount(1, 'friends');
    expect($response->headers->get('ETag'))->toMatch('/^"[0-9a-f]{32}"$/');
});

it('serves each section exactly as its own endpoint does', function () {
    $user = actingAsUser();
    $game = giveGame($user, ['name' => 'Catan']);
    $game->game->mechanics()->attach(Mechanic::factory()->create(['name' => 'Trading']));
    $friend = User::factory()->create(['name' => 'Ana']);
    Friendship::factory()->accepted()->create(['requester_id' => $user->id, 'recipient_id' => $friend->id]);
    Friendship::factory()->create(['requester_id' => User::factory()->create()->id, 'recipient_id' => $user->id]);
    UserBlock::factory()->create(['blocker_id' => $user->id, 'blocked_id' => User::factory()->create()->id]);

    $bootstrap = $this->getJson('/api/bootstrap')->json();

    expect($bootstrap['user'])->toBe($this->getJson('/api/user')->json())
        ->and($bootstrap['games'])->toBe($this->getJson('/api/games')->json('data'))
        ->and($bootstrap['mechanics'])->toBe($this->getJson('/api/mechanics')->json('data'))
        ->and($bootstrap['categories'])->toBe($this->getJson('/api/categories')->json('data'))
        ->and($bootstrap['friends'])->toBe($this->getJson('/api/friends')->json('data'))
        ->and($bootstrap['requests'])->toBe($this->getJson('/api/friends/requests')->json('data'))
        ->and($bootstrap['blocks'])->toBe($this->getJson('/api/friends/blocks')->json('data'));
});

it('answers 304 with no body when nothing changed', function () {
    $user = actingAsUser();
    giveGame($user);

    $etag = $this->getJson('/api/bootstrap')->headers->get('ETag');

    $this->getJson('/api/bootstrap', ['If-None-Match' => $etag])
        ->assertStatus(304)
        ->assertHeader('ETag', $etag);
});

it('accepts the weak validator prefix and a list of candidates', function () {
    $user = actingAsUser();
    $etag = BootstrapVersion::etagFor($user);

    expect(BootstrapVersion::matches('W/'.$etag, $etag))->toBeTrue()
        ->and(BootstrapVersion::matches('"otro", '.$etag, $etag))->toBeTrue()
        ->and(BootstrapVersion::matches('"otro"', $etag))->toBeFalse()
        ->and(BootstrapVersion::matches(null, $etag))->toBeFalse()
        ->and(BootstrapVersion::matches('', $etag))->toBeFalse();
});

/** Cada cambio que la app tiene que ver: la descripcion dice que lo provoca. */
function etagChanges(): array
{
    return [
        'a game is added' => function (User $user) {
            giveGame($user);
        },
        'a game is removed' => function (User $user, UserGame $game) {
            $game->delete();
        },
        'its status changes' => function (User $user, UserGame $game) {
            $game->update(['status' => $game->status === 'owned' ? 'wishlist' : 'owned']);
        },
        'the shared game is edited' => function (User $user, UserGame $game) {
            $game->game->update(['name' => 'Catan 2']);
        },
        'only its mechanics change (the game is touched)' => function (User $user, UserGame $game) {
            app(GameTaxonomySyncer::class)->sync($game->game, ['Trading'], []);
        },
        'mechanics are added in bulk without touching the game' => function (User $user, UserGame $game) {
            $mechanic = Mechanic::factory()->create();
            DB::table('game_mechanic')->insert(['game_id' => $game->game_id, 'mechanic_id' => $mechanic->id]);
        },
        'the user edits their profile' => function (User $user) {
            $user->update(['name' => 'Otro nombre']);
        },
        'a friend request is accepted' => function (User $user, UserGame $game, User $friend, Friendship $friendship) {
            $friendship->update(['status' => 'accepted']);
        },
        'a friend request is cancelled' => function (User $user, UserGame $game, User $friend, Friendship $friendship) {
            $friendship->delete();
        },
        'a friend renames themselves' => function (User $user, UserGame $game, User $friend) {
            $friend->update(['name' => 'Ana María']);
        },
        'someone is blocked' => function (User $user) {
            UserBlock::factory()->create(['blocker_id' => $user->id, 'blocked_id' => User::factory()->create()->id]);
        },
        'the mechanics catalog grows' => function () {
            Mechanic::factory()->create();
        },
    ];
}

foreach (etagChanges() as $description => $change) {
    it("changes the ETag when $description", function () use ($change) {
        $user = actingAsUser();
        $game = giveGame($user, ['name' => 'Catan']);
        $friend = User::factory()->create(['name' => 'Ana']);
        $friendship = Friendship::factory()->create(['requester_id' => $user->id, 'recipient_id' => $friend->id]);

        $before = BootstrapVersion::etagFor($user);

        $this->travel(2)->minutes();
        $change($user, $game, $friend, $friendship);

        expect(BootstrapVersion::etagFor($user->fresh()))->not->toBe($before);
    });
}

it('does not change the ETag because of other people\'s unrelated activity', function () {
    $user = actingAsUser();
    giveGame($user);
    $before = BootstrapVersion::etagFor($user);

    $this->travel(2)->minutes();
    $stranger = User::factory()->create();
    giveGame($stranger, ['name' => 'Otro juego']);
    $stranger->update(['name' => 'Cambiado']);

    expect(BootstrapVersion::etagFor($user->fresh()))->toBe($before);
});

it('never returns another account\'s data', function () {
    $mine = actingAsUser();
    giveGame($mine, ['name' => 'Mine']);
    giveGame(User::factory()->create(), ['name' => 'Theirs']);

    $names = collect($this->getJson('/api/bootstrap')->json('games'))->pluck('game.name');

    expect($names->all())->toBe(['Mine']);
});
