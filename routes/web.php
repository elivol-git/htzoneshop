<?php

use App\Http\Controllers\SearchController;
use App\Http\Controllers\SuggestController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'search')->name('home');

Route::get('/api/search', SearchController::class)
    ->middleware('throttle:60,1')
    ->name('search');

Route::get('/api/suggest', SuggestController::class)
    ->middleware('throttle:180,1')
    ->name('suggest');

Route::get('/health', fn () => response()->json(['ok' => true]));
