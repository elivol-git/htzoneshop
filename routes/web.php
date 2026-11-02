<?php

use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'search')->name('home');

Route::get('/api/search', SearchController::class)
    ->middleware('throttle:60,1')
    ->name('search');

Route::get('/health', fn () => response()->json(['ok' => true]));
