<?php

use Illuminate\Support\Facades\Route;

// Module 41: the API host answers its root with a minimal service note instead of the framework's welcome page
// (which needed built front-end assets and named the framework). The applications live on their own web origins.
Route::get('/', fn () => response()->json(['service' => 'FoodOnTheGo API', 'api' => '/api/v1']));
