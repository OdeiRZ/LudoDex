<?php

namespace App\Http\Controllers\Games;

use App\Http\Controllers\Controller;
use App\Models\Mechanic;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class MechanicController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return JsonResource::collection(
            Mechanic::orderBy('name')->get(['id', 'name'])
        );
    }
}
