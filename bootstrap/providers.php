<?php

return [
    App\Providers\SettingsServiceProvider::class,  // DB 연결 전 JSON 설정 로드
    App\Providers\AppServiceProvider::class,
    App\Providers\AuthServiceProvider::class,
    App\Providers\BladeServiceProvider::class,
    App\Providers\CoreServiceProvider::class,
    App\Providers\ModuleServiceProvider::class,
    App\Providers\PluginServiceProvider::class,
    App\Providers\EventServiceProvider::class,
    App\Providers\ModuleRouteServiceProvider::class,
    App\Providers\PluginRouteServiceProvider::class,
    App\Providers\TranslationServiceProvider::class,
    App\Providers\ScoutServiceProvider::class,
    App\Seo\SeoServiceProvider::class,
];
