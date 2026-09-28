<?php

use App\Http\Controllers\Auth\SocialAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
| Connexion OAuth à la démo. Groupe `web` : Socialite garde le paramètre
| `state` en session. Le départ vers le fournisseur compte comme une création
| de compte pour le limiteur `demo-signup` ; le retour, lui, est borné par le
| `state` émis au départ.
*/
Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
    ->whereIn('provider', ['github', 'google'])
    ->middleware('throttle:demo-signup')
    ->name('demo.oauth.redirect');

Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
    ->whereIn('provider', ['github', 'google'])
    ->name('demo.oauth.callback');
