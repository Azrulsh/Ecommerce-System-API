<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PriceCountController;

Route::get('/', function () {
    return view('welcome');
});

//last question
Route::get('/discount', [PriceCountController::class, 'calculate']);
