<?php

/*
 * The public site, loaded with no middleware (bootstrap/app.php): no session, no cookie, no CSRF — the pages are the
 * same for everyone, and they work before the install has an APP_KEY or a database.
 *
 * - The website (the theme's pages; App\Http\Controllers\Site\WebsiteController).
 * - The console's own pages (config('kit.console.paths')), answered with the built console (ConsolePageController).
 *
 * The kit API is routes/api.php.
 */

use App\Http\Controllers\Site\ConsolePageController;
use App\Http\Controllers\Site\WebsiteController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WebsiteController::class, 'home']);
Route::get('features', [WebsiteController::class, 'features']);
Route::get('pricing', [WebsiteController::class, 'pricing']);
Route::get('contact', [WebsiteController::class, 'contact']);
Route::get('legal/{page}', [WebsiteController::class, 'legal'])->whereIn('page', config('kit.website.legal_pages'));

Route::get('{area}/{rest?}', ConsolePageController::class)->whereIn('area', config('kit.console.paths'))->where('rest', '.*');
