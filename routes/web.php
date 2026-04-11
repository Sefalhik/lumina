<?php

use Illuminate\Support\Facades\Route;

// Public routes
Route::get('/', fn () => view('home'))->name('home');
Route::get('/cv', fn () => view('cv'))->name('cv');
Route::get('/projects', fn () => view('projects'))->name('projects');
Route::get('/blog', fn () => view('blog.index'))->name('blog');
Route::get('/blog/{slug}', fn (string $slug) => view('blog.show', ['slug' => $slug]))->name('blog.show');
