<?php

use App\Http\Controllers\Public\GeoController;
use Illuminate\Support\Facades\Route;

Route::get('/geo', GeoController::class)->name('api.geo');
