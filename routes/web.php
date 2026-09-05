<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Application API — la seule page servie est la console d'administration.
// Documentation Scramble disponible sur /docs/api

/*
 * Console d'administration (build statique déposé dans public/admin).
 *
 * Apache sert d'abord les fichiers existants ; seules les URL de navigation
 * arrivent ici, et doivent rendre index.html pour que le routeur React
 * reprenne la main. Le .htaccess du dossier fait déjà ce repli, mais cette
 * route garantit le même comportement sur un serveur sans mod_rewrite.
 */
Route::get('/admin/{path?}', function () {
    $index = public_path('admin/index.html');

    abort_unless(
        is_file($index),
        404,
        "La console d'administration n'est pas déployée sur ce serveur."
    );

    return response()->file($index, ['Cache-Control' => 'no-cache']);
})->where('path', '.*')->name('admin.console');
