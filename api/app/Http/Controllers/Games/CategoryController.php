<?php

namespace App\Http\Controllers\Games;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return JsonResource::collection(
            Category::orderBy('name')->get(['id', 'name'])
        );
    }
}
