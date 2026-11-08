<?php

use App\Http\Controllers\PriceCountController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// last question
Route::get('/discount', [PriceCountController::class, 'calculate']);
