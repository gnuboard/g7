<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\BladeServiceProvider;
use App\Providers\BroadcastServiceProvider;
use App\Providers\CoreServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\InstallerRuntimeServiceProvider;
use App\Providers\LanguagePackServiceProvider;
use App\Providers\ModuleRouteServiceProvider;
use App\Providers\ModuleServiceProvider;
use App\Providers\PluginRouteServiceProvider;
use App\Providers\PluginServiceProvider;
use App\Providers\ScoutServiceProvider;
use App\Providers\SettingsServiceProvider;
use App\Providers\TranslationServiceProvider;
use App\Seo\SeoServiceProvider;

return [
    InstallerRuntimeServiceProvider::class,  // 설치 진행 중 runtime.php 로 동적 설정 주입 (.env 무수정)
    SettingsServiceProvider::class,  // DB 연결 전 JSON 설정 로드
    AppServiceProvider::class,
    AuthServiceProvider::class,
    BroadcastServiceProvider::class,  // routes/channels.php 채널 인가 정의 로드 (Broadcast::routes() 자동 등록은 배제)
    BladeServiceProvider::class,
    CoreServiceProvider::class,
    ModuleServiceProvider::class,
    PluginServiceProvider::class,
    EventServiceProvider::class,
    ModuleRouteServiceProvider::class,
    PluginRouteServiceProvider::class,
    TranslationServiceProvider::class,
    LanguagePackServiceProvider::class,
    ScoutServiceProvider::class,
    SeoServiceProvider::class,
];
