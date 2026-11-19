<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Sso\Http\Controllers\Scim\ScimDiscoveryController;
use Plugins\Sirsoft\Sso\Http\Controllers\Scim\ScimGroupController;
use Plugins\Sirsoft\Sso\Http\Controllers\Scim\ScimUserController;

/*
| SSO 연동 — SCIM 2.0 라우트 (api)
|
| 프리픽스·이름 접두·그룹 미들웨어는 `src/routes/api.php` 를 거쳐 PluginRouteServiceProvider 가 붙인다 (/api/plugins/sirsoft-sso).
| IdP 별 Bearer 토큰 인증(`ScimBearerAuth`)은 `plugin.php` 의 `getMiddleware()` 가 `api.plugins.sirsoft-sso.scim.*` 에 붙인다.
*/

$idp = '[a-z][a-z0-9_]{1,49}';

Route::prefix('/scim/v2/{idp}')
    ->where(['idp' => $idp])
    ->middleware('throttle:300,1,sirsoft-sso.scim')
    ->name('scim.')
    ->group(function () {
        Route::get('/ServiceProviderConfig', [ScimDiscoveryController::class, 'serviceProviderConfig'])->name('service-provider-config');
        Route::get('/ResourceTypes', [ScimDiscoveryController::class, 'resourceTypes'])->name('resource-types.index');
        Route::get('/ResourceTypes/{id}', [ScimDiscoveryController::class, 'resourceType'])->where('id', 'User|Group')->name('resource-types.show');
        Route::get('/Schemas', [ScimDiscoveryController::class, 'schemas'])->name('schemas.index');
        Route::get('/Schemas/{id}', [ScimDiscoveryController::class, 'schema'])->where('id', '[A-Za-z0-9:._-]{1,120}')->name('schemas.show');

        Route::get('/Users', [ScimUserController::class, 'index'])->name('users.index');
        Route::post('/Users', [ScimUserController::class, 'store'])->name('users.store');
        Route::get('/Users/{id}', [ScimUserController::class, 'show'])->where('id', '[0-9]{1,19}')->name('users.show');
        Route::put('/Users/{id}', [ScimUserController::class, 'update'])->where('id', '[0-9]{1,19}')->name('users.update');
        Route::patch('/Users/{id}', [ScimUserController::class, 'patch'])->where('id', '[0-9]{1,19}')->name('users.patch');
        Route::delete('/Users/{id}', [ScimUserController::class, 'destroy'])->where('id', '[0-9]{1,19}')->name('users.destroy');

        Route::get('/Groups', [ScimGroupController::class, 'index'])->name('groups.index');
        Route::post('/Groups', [ScimGroupController::class, 'store'])->name('groups.store');
        Route::get('/Groups/{id}', [ScimGroupController::class, 'show'])->where('id', '[0-9]{1,19}')->name('groups.show');
        Route::put('/Groups/{id}', [ScimGroupController::class, 'update'])->where('id', '[0-9]{1,19}')->name('groups.update');
        Route::patch('/Groups/{id}', [ScimGroupController::class, 'patch'])->where('id', '[0-9]{1,19}')->name('groups.patch');
        Route::delete('/Groups/{id}', [ScimGroupController::class, 'destroy'])->where('id', '[0-9]{1,19}')->name('groups.destroy');
    });
