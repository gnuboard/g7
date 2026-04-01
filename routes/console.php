<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Commands
|--------------------------------------------------------------------------
|
| 아래에서 애플리케이션의 스케줄된 작업을 정의합니다.
|
*/

// 30초마다 시스템 리소스 브로드캐스트 (대시보드 실시간 업데이트)
Schedule::command('dashboard:broadcast-resources')
    ->everyThirtySeconds()
    ->withoutOverlapping()
    ->runInBackground();

// 만료된 레이아웃 미리보기 정리 (30분마다)
Schedule::command('layout-previews:cleanup')->everyThirtyMinutes();

// Sitemap 생성 스케줄
if (file_exists(base_path('.env'))) {
    $sitemapEnabled = (bool) g7_core_settings('seo.sitemap_enabled', true);
    if ($sitemapEnabled) {
        $sitemapFrequency = g7_core_settings('seo.sitemap_schedule', 'daily');
        $sitemapTime = g7_core_settings('seo.sitemap_schedule_time', '02:00');

        $sitemapScheduled = Schedule::job(new \App\Jobs\GenerateSitemapJob)
            ->onOneServer();

        match ($sitemapFrequency) {
            'hourly' => $sitemapScheduled->hourly(),
            'weekly' => $sitemapScheduled->weekly()->at($sitemapTime),
            default => $sitemapScheduled->daily()->at($sitemapTime),
        };
    }
}

/*
|--------------------------------------------------------------------------
| Extension Scheduled Commands
|--------------------------------------------------------------------------
|
| 활성 모듈/플러그인의 getSchedules()에 정의된 스케줄 작업을 등록합니다.
| 확장 업데이트 후 큐 워커 재시작과 함께 새 스케줄이 즉시 반영됩니다.
|
*/

if (file_exists(base_path('.env'))) {
    try {
        // 모듈 스케줄 등록
        $moduleManager = app(\App\Extension\ModuleManager::class);
        foreach ($moduleManager->getActiveModules() as $module) {
            foreach ($module->getSchedules() as $config) {
                if (empty($config['command']) || empty($config['schedule'])) {
                    continue;
                }

                $cmd = Schedule::command($config['command']);

                // cron expression (공백 포함) vs Laravel 메서드명 분기
                str_contains($config['schedule'], ' ')
                    ? $cmd->cron($config['schedule'])
                    : $cmd->{$config['schedule']}();

                if (isset($config['description'])) {
                    $cmd->description($config['description']);
                }

                // enabled_config: "identifier.setting_key" → module_setting()으로 조회
                if (isset($config['enabled_config'])) {
                    $identifier = $module->getIdentifier();
                    $settingKey = $config['enabled_config'];

                    // "identifier.key" 형식이면 identifier 부분 제거하여 설정 키만 추출
                    if (str_starts_with($settingKey, $identifier . '.')) {
                        $settingKey = substr($settingKey, strlen($identifier) + 1);
                    }

                    $cmd->when(fn () => (bool) module_setting($identifier, $settingKey, true));
                }
            }
        }

        // 플러그인 스케줄 등록
        $pluginManager = app(\App\Extension\PluginManager::class);
        foreach ($pluginManager->getActivePlugins() as $plugin) {
            foreach ($plugin->getSchedules() as $config) {
                if (empty($config['command']) || empty($config['schedule'])) {
                    continue;
                }

                $cmd = Schedule::command($config['command']);

                str_contains($config['schedule'], ' ')
                    ? $cmd->cron($config['schedule'])
                    : $cmd->{$config['schedule']}();

                if (isset($config['description'])) {
                    $cmd->description($config['description']);
                }

                // enabled_config: "identifier.setting_key" → plugin_setting()으로 조회
                if (isset($config['enabled_config'])) {
                    $identifier = $plugin->getIdentifier();
                    $settingKey = $config['enabled_config'];

                    // "identifier.key" 형식이면 identifier 부분 제거하여 설정 키만 추출
                    if (str_starts_with($settingKey, $identifier . '.')) {
                        $settingKey = substr($settingKey, strlen($identifier) + 1);
                    }

                    $cmd->when(fn () => (bool) plugin_setting($identifier, $settingKey, true));
                }
            }
        }
    } catch (\Exception $e) {
        Log::debug('확장 스케줄 등록 스킵', ['error' => $e->getMessage()]);
    }
}
