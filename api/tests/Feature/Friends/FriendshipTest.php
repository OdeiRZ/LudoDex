<?php

use App\Models\Friendship;
use App\Models\User;
use App\Notifications\FriendRequestReceivedNotification;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;

it('finds a discoverable user by email', function () {
    actingAsUser();
    $target = User::factory()->create(['email' => 'target@example.com', 'discoverable' => true]);

    $this->getJson('/api/friends/search?email=target@example.com')
        ->assertOk()
        ->assertJsonPath('data.id', $target->id)
        ->assertJsonMissingPath('data.email');
});

it('finds a discoverable user by bgg_username', function () {
    actingAsUser();
    $target = User::factory()->create(['bgg_username' => 'targetbgg', 'discoverable' => true]);

    $this->getJson('/api/friends/search?bgg_username=targetbgg')
        ->assertOk()
        ->assertJsonPath('data.id', $target->id);
});

it('responds the same way for an email that does not exist and one that exists but is not discoverable, to avoid leaking which emails are registered', function () {
    actingAsUser();
    User::factory()->create(['email' => 'private@example.com', 'discoverable' => false]);

    $forNonExistent = $this->getJson('/api/friends/search?email=nobody@example.com')->assertOk();
    $forPrivate = $this->getJson('/api/friends/search?email=private@example.com')->assertOk();

    expect($forNonExistent->json())->toBe($forPrivate->json());
    expect($forNonExistent->json('data'))->toBeNull();
});

it('never returns yourself in a search, even if you are discoverable', function () {
    $me = actingAsUser();
    $me->update(['discoverable' => true, 'email' => 'me@example.com']);

    $this->getJson('/api/friends/search?email=me@example.com')
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('creates a pending friend request', function () {
    $me = actingAsUser();
    $target = User::factory()->create(['discoverable' => true]);

    $this->postJson('/api/friends/requests', ['user_id' => $target->id])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('friendships', [
        'requester_id' => $me->id,
        'recipient_id' => $target->id,
        'status' => 'pending',
    ]);
});

it('still creates the friend request even if the notification email fails to send', function () {
    // Reproduces a real failure found testing this live: a mail transport
    // error (Resend rejecting a @example.com recipient locally) turned an
    // already-successful request into a 500, with the row still saved but
    // no way for the user to tell it had actually gone through. Binding a
    // ChannelManager that always throws (rather than mailing for real,
    // which the test mailer - MAIL_MAILER=array in phpunit.xml - never
    // fails at) reproduces that same failure path directly.
    $me = actingAsUser();
    $target = User::factory()->create(['discoverable' => true]);

    app()->bind(ChannelManager::class, fn () => new class
    {
        public function send($notifiables, $notification): void
        {
            throw new RuntimeException('mail transport down');
        }
    });

    $this->postJson('/api/friends/requests', ['user_id' => $target->id])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('friendships', [
        'requester_id' => $me->id,
        'recipient_id' => $target->id,
        'status' => 'pending',
    ]);
});

it('emails the recipient when a new pending request is created', function () {
    Notification::fake();
    $me = actingAsUser();
    $target = User::factory()->create(['discoverable' => true]);

    $this->postJson('/api/friends/requests', ['user_id' => $target->id])->assertCreated();

    Notification::assertSentTo($target, FriendRequestReceivedNotification::class);
});

it('points the friend request email at the frontend friends page', function () {
    Notification::fake();
    $me = actingAsUser();
    $target = User::factory()->create(['discoverable' => true]);

    $this->postJson('/api/friends/requests', ['user_id' => $target->id]);

    Notification::assertSentTo(
        $target,
        FriendRequestReceivedNotification::class,
        function (FriendRequestReceivedNotification $notification) use ($target) {
            $mail = $notification->toMail($target);

            expect($mail->actionUrl)->toBe('http://localhost:5173/friends');

            return true;
        }
    );
});

it('does not email anyone when a request auto-accepts a mutual pending one', function () {
    Notification::fake();
    $me = actingAsUser();
    $other = User::factory()->create(['discoverable' => true]);
    Friendship::factory()->create(['requester_id' => $other->id, 'recipient_id' => $me->id]);

    $this->postJson('/api/friends/requests', ['user_id' => $other->id])->assertCreated();

    Notification::assertNothingSent();
});

it('rejects sending yourself a friend request', function () {
    $me = actingAsUser();

    $this->postJson('/api/friends/requests', ['user_id' => $me->id])
        ->assertUnprocessable();
});

it('rejects a request to a non-discoverable user, even naming their user_id directly', function () {
    actingAsUser();
    $target = User::factory()->create(['discoverable' => false]);

    $this->postJson('/api/friends/requests', ['user_id' => $target->id])
        ->assertUnprocessable();

    $this->assertDatabaseMissing('friendships', ['recipient_id' => $target->id]);
});

it('rejects a request to a user_id that does not exist at all, with the same message as a non-discoverable one', function () {
    actingAsUser();

    $forMissing = $this->postJson('/api/friends/requests', ['user_id' => 999999])->assertUnprocessable();

    $target = User::factory()->create(['discoverable' => false]);
    $forPrivate = $this->postJson('/api/friends/requests', ['user_id' => $target->id])->assertUnprocessable();

    expect($forMissing->json('errors.user_id.0'))->toBe($forPrivate->json('errors.user_id.0'));
});

it('rejects a duplicate pending request', function () {
    $me = actingAsUser();
    $target = User::factory()->create(['discoverable' => true]);
    Friendship::factory()->create(['requester_id' => $me->id, 'recipient_id' => $target->id]);

    $this->postJson('/api/friends/requests', ['user_id' => $target->id])
        ->assertUnprocessable();
});

it('rejects a request when already friends', function () {
    $me = actingAsUser();
    $target = User::factory()->create(['discoverable' => true]);
    Friendship::factory()->accepted()->create(['requester_id' => $me->id, 'recipient_id' => $target->id]);

    $this->postJson('/api/friends/requests', ['user_id' => $target->id])
        ->assertUnprocessable();
});

it('auto-accepts instead of duplicating when the target already sent a pending request the other way', function () {
    $me = actingAsUser();
    $other = User::factory()->create(['discoverable' => true]);
    $reverse = Friendship::factory()->create(['requester_id' => $other->id, 'recipient_id' => $me->id]);

    $this->postJson('/api/friends/requests', ['user_id' => $other->id])
        ->assertCreated()
        ->assertJsonPath('data.status', 'accepted');

    expect($reverse->fresh()->status)->toBe('accepted');
    expect(Friendship::count())->toBe(1);
});

it('lets the recipient accept a pending request', function () {
    $recipient = actingAsUser();
    $requester = User::factory()->create();
    $friendship = Friendship::factory()->create(['requester_id' => $requester->id, 'recipient_id' => $recipient->id]);

    $this->postJson("/api/friends/requests/{$friendship->id}/accept")
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted');
});

it('rejects accepting a request from anyone other than the recipient', function () {
    actingAsUser();
    $requester = User::factory()->create();
    $recipient = User::factory()->create();
    $friendship = Friendship::factory()->create(['requester_id' => $requester->id, 'recipient_id' => $recipient->id]);

    $this->postJson("/api/friends/requests/{$friendship->id}/accept")->assertForbidden();
});

it('lets the recipient decline a pending request', function () {
    $recipient = actingAsUser();
    $requester = User::factory()->create();
    $friendship = Friendship::factory()->create(['requester_id' => $requester->id, 'recipient_id' => $recipient->id]);

    $this->deleteJson("/api/friends/requests/{$friendship->id}")->assertNoContent();

    $this->assertDatabaseMissing('friendships', ['id' => $friendship->id]);
});

it('lets the requester cancel a pending request', function () {
    $requester = actingAsUser();
    $recipient = User::factory()->create();
    $friendship = Friendship::factory()->create(['requester_id' => $requester->id, 'recipient_id' => $recipient->id]);

    $this->deleteJson("/api/friends/requests/{$friendship->id}")->assertNoContent();
});

it('lets either side unfriend an accepted relationship', function () {
    $me = actingAsUser();
    $friend = User::factory()->create();
    $friendship = Friendship::factory()->accepted()->create(['requester_id' => $friend->id, 'recipient_id' => $me->id]);

    $this->deleteJson("/api/friends/requests/{$friendship->id}")->assertNoContent();

    $this->assertDatabaseMissing('friendships', ['id' => $friendship->id]);
});

it('rejects deleting a friendship a third party is not part of', function () {
    actingAsUser();
    $a = User::factory()->create();
    $b = User::factory()->create();
    $friendship = Friendship::factory()->create(['requester_id' => $a->id, 'recipient_id' => $b->id]);

    $this->deleteJson("/api/friends/requests/{$friendship->id}")->assertForbidden();

    $this->assertDatabaseHas('friendships', ['id' => $friendship->id]);
});

it('lists only accepted friends, merging both directions, never pending ones', function () {
    $me = actingAsUser();
    $friendAsRequester = User::factory()->create(['name' => 'Friend One']);
    $friendAsRecipient = User::factory()->create(['name' => 'Friend Two']);
    $stillPending = User::factory()->create(['name' => 'Pending']);
    $asRequesterFriendship = Friendship::factory()->accepted()->create(['requester_id' => $me->id, 'recipient_id' => $friendAsRequester->id]);
    Friendship::factory()->accepted()->create(['requester_id' => $friendAsRecipient->id, 'recipient_id' => $me->id]);
    Friendship::factory()->create(['requester_id' => $me->id, 'recipient_id' => $stillPending->id]);

    $response = $this->getJson('/api/friends')->assertOk();

    $entries = collect($response->json('data'));
    expect($entries->pluck('user.name'))
        ->toContain('Friend One')
        ->toContain('Friend Two')
        ->not->toContain('Pending');
    // Each entry carries its own friendship row id, not just the friend's
    // user id - "quitar amigo" needs it for DELETE /friends/requests/{id}.
    expect($entries->firstWhere('user.name', 'Friend One')['id'])->toBe($asRequesterFriendship->id);
});

it('splits pending requests into incoming and outgoing', function () {
    $me = actingAsUser();
    $incomingFrom = User::factory()->create(['name' => 'Sent To Me']);
    $outgoingTo = User::factory()->create(['name' => 'I Sent This']);
    Friendship::factory()->create(['requester_id' => $incomingFrom->id, 'recipient_id' => $me->id]);
    Friendship::factory()->create(['requester_id' => $me->id, 'recipient_id' => $outgoingTo->id]);

    $response = $this->getJson('/api/friends/requests')->assertOk();

    expect($response->json('data.incoming.0.user.name'))->toBe('Sent To Me');
    expect($response->json('data.outgoing.0.user.name'))->toBe('I Sent This');
});

it('rejects every friends endpoint from a guest', function () {
    $this->getJson('/api/friends')->assertUnauthorized();
    $this->getJson('/api/friends/search?email=a@example.com')->assertUnauthorized();
    $this->getJson('/api/friends/requests')->assertUnauthorized();
    $this->postJson('/api/friends/requests', ['user_id' => 1])->assertUnauthorized();
    $this->postJson('/api/friends/requests/1/accept')->assertUnauthorized();
    $this->deleteJson('/api/friends/requests/1')->assertUnauthorized();
});
