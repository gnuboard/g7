<?php

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Jobs\GenerateSitemapJob;
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
// withoutOverlapping(5): 5분 후 mutex 자동 만료 — 백그라운드 프로세스가 비정상 종료되어도
// 무한 락에 빠지지 않도록 보호 (기본값은 무한 락)
Schedule::command('dashboard:broadcast-resources')
    ->everyThirtySeconds()
    ->withoutOverlapping(5)
    ->runInBackground();

/*
| 만료 데이터 정리 예약 (주기는 항목별, 다중 서버 1대만)
|
| 시각 해석 기준은 사이트 설정 시간대(`general.timezone`)다 —
| SettingsServiceProvider 가 `app.schedule_timezone` 을 세팅하고,
| Laravel 이 그 값을 Schedule 인스턴스 전체에 일괄 적용한다.
| 서머타임이 있는 시간대에서는 전환일에 특정 시각이 건너뛰거나 두 번 발생할 수 있으나,
| 아래 정리 배치는 모두 멱등이라 이중 실행이 데이터를 해치지 않고
| 건너뛴 날은 다음 날 배치가 흡수한다.
|
| 아래 begin/end 표식 사이가 정리 예약의 전부다. 등록 전수 회귀 테스트가 이 구간을
| 읽어 대상 목록을 도출하므로, 정리 예약을 추가할 때는 반드시 이 안에 둔다.
*/
// gc-schedules:begin
// 만료된 레이아웃 미리보기 정리 (30분마다)
Schedule::command('layout-previews:cleanup')->everyThirtyMinutes()->onOneServer();

Schedule::command('sanctum:prune-expired', ['--hours' => 24])->dailyAt('04:00')->onOneServer();
Schedule::command('auth:clear-resets')->dailyAt('04:05')->onOneServer();
Schedule::command('queue:prune-failed')->dailyAt('04:10')->onOneServer();
Schedule::command('queue:prune-batches')->dailyAt('04:15')->onOneServer();
Schedule::command('notification:cleanup')->dailyAt('04:20')->onOneServer();
Schedule::command('ext-bundles:cleanup')->dailyAt('04:25')->onOneServer();
// 오래된 부트스트랩 리소스 정적 게시 디렉토리 정리 (현재 + 직전 버전 보존)
Schedule::command('ext-static:cleanup')->dailyAt('04:28')->onOneServer();
Schedule::command('seo:prune-stats')->dailyAt('04:30')->onOneServer();
Schedule::command('schedules:prune-history')->dailyAt('04:35')->onOneServer();
Schedule::command('identity:prune-logs')->dailyAt('04:40')->onOneServer();
Schedule::command('activity-log:prune')->dailyAt('04:45')->onOneServer();
Schedule::command('notification-log:prune')->dailyAt('04:50')->onOneServer();
// 사용자 파일을 파기하므로 기본 꺼짐 — 커맨드가 `--scheduled` 에서 설정을 false 폴백으로 재확인한다.
Schedule::command('attachments:prune-orphans --scheduled')->dailyAt('04:55')->onOneServer();
// 중단된 업데이트·설치가 남긴 임시 산출물 + 오래된 백업본(최신 1개 보존) 정리
Schedule::command('storage:prune-leftovers')->dailyAt('05:00')->onOneServer();

// 개인정보를 파기하므로 기본 꺼짐 — 각 도메인 핸들러가 `--scheduled` 에서 자기 설정을
// false 폴백으로 재확인한다.
Schedule::command('privacy:process-retention --scheduled')->dailyAt('05:05')->onOneServer();
// 회원 상태를 바꾸고 설정에 따라 탈퇴까지 하므로 기본 꺼짐 — 커맨드가 `--scheduled` 에서
// `privacy.dormant_enabled` 를 false 폴백으로 재확인한다. 보유기간 처리 뒤에 두는 이유는
// 같은 날 탈퇴된 회원의 잔여 정보 2차 파기가 보존기간부터 새로 시작되어야 하기 때문이다.
Schedule::command('privacy:process-dormant --scheduled')->dailyAt('05:10')->onOneServer();
// 비밀번호 만료 사전 안내·만료일 처리(#736) — 설정에 따라 로그인을 해제하므로 기본 꺼짐. 커맨드가
// `security.password_expiry_enabled` 를 재확인하고, 1회 실행당 안내 상한을 넘는 인원은 다음 날로 넘긴다.
Schedule::command('auth:process-password-expiry --scheduled')->dailyAt('05:15')->onOneServer();

// 만료 시각이 지난 본인인증 challenge 상태 전환 (비파괴 — 물리 파기는 identity:prune-logs)
Schedule::command('identity:expire-challenges')->hourly()->onOneServer();

// 보존 기간이 지난 방문자 통계 원장 정리 (집계 표는 남으므로 과거 숫자는 그대로 보인다)
Schedule::command('analytics:prune')->dailyAt('05:10')->onOneServer();
// gc-schedules:end

/*
| 방문자 통계 집계·점검 예약
|
| 집계는 정리가 아니라 「원장을 읽어 숫자를 만드는」 일이라 gc 블록 밖에 둔다.
|
| 매시 :05 — 오늘치를 다시 계산한다(확정하지 않는다). 오늘 숫자는 계속 변한다.
| 매일 00:15 — 어제치를 확정한다. 하루가 끝난 뒤 한 번만 한다.
| withoutOverlapping 의 분은 mutex 자동 만료 시간이다 — 비정상 종료가 무한 락이 되지 않게 한다.
*/
Schedule::command('analytics:archive --period=hour --scheduled')
    ->hourlyAt(5)
    ->onOneServer()
    ->withoutOverlapping(55);

Schedule::command('analytics:archive --period=day --scheduled')
    ->dailyAt('00:15')
    ->onOneServer()
    ->withoutOverlapping(120);

// 원장이 경고 한계를 넘었는지 점검 (정리 배치보다 먼저 돌아 「지우기 전」 크기를 본다)
Schedule::command('analytics:check-volume')->dailyAt('05:05')->onOneServer();

// 정기 리포트 — 설정이 꺼져 있으면 커맨드가 아무것도 보내지 않는다
Schedule::command('analytics:report --period=weekly')->weeklyOn(1, '08:00')->onOneServer();
Schedule::command('analytics:report --period=monthly')->monthlyOn(1, '08:00')->onOneServer();

// 클라우드 IP 대역 목록 갱신 — 그 차단을 켠 사이트에서만 등록한다
if (file_exists(base_path('.env'))) {
    if ((bool) g7_core_settings('analytics.block_cloud_ip_ranges', false)) {
        Schedule::command('analytics:update-cloud-ranges')
            ->weekly()
            ->onOneServer()
            ->withoutOverlapping(60);
    }

    // 현재 접속자 브로드캐스트 — 브로드캐스트가 설정된 사이트에서만 등록한다.
    // 설정이 없으면 실시간 화면이 폴링으로 돌므로 등록하지 않아도 기능이 빠지지 않는다.
    if ((string) config('broadcasting.connections.reverb.key', '') !== '') {
        Schedule::command('analytics:broadcast-realtime')
            ->everyThirtySeconds()
            ->withoutOverlapping(5)
            ->runInBackground();
    }
}

// 언어팩 업데이트 확인 (주 1회, GitHub 기반 언어팩 latest_version 갱신)
Schedule::command('language-pack:check-updates')
    ->weekly()
    ->runInBackground()
    ->withoutOverlapping(60);

// Sitemap 생성 스케줄
if (file_exists(base_path('.env'))) {
    $sitemapEnabled = (bool) g7_core_settings('seo.sitemap_enabled', true);
    if ($sitemapEnabled) {
        $sitemapFrequency = g7_core_settings('seo.sitemap_schedule', 'daily');
        $sitemapTime = g7_core_settings('seo.sitemap_schedule_time', '02:00');

        // 대용량 생성은 스케줄 주기를 넘길 수 있으므로 이전 실행이 끝나기 전에는 겹쳐 돌지 않게 한다.
        $sitemapScheduled = Schedule::job(new GenerateSitemapJob)
            ->onOneServer()
            ->withoutOverlapping(30);

        match ($sitemapFrequency) {
            'hourly' => $sitemapScheduled->hourly(),
            'weekly' => $sitemapScheduled->weekly()->at($sitemapTime),
            default => $sitemapScheduled->daily()->at($sitemapTime),
        };
    }
}

// GeoIP DB 정기 갱신 스케줄
// 마스터 토글 + 자동 업데이트 토글 + 라이선스 키가 모두 충족되어야 등록됨
if (file_exists(base_path('.env'))) {
    $geoipMasterEnabled = (bool) g7_core_settings('geoip.feature_enabled', false);
    $geoipAutoUpdate = (bool) g7_core_settings('geoip.auto_update_enabled', true);
    $geoipLicenseKey = (string) g7_core_settings('geoip.license_key', '');

    if ($geoipMasterEnabled && $geoipAutoUpdate && $geoipLicenseKey !== '') {
        $geoipFrequency = (string) config('geoip.schedule.frequency', 'weekly');
        $geoipTime = (string) config('geoip.schedule.time', '03:00');

        $geoipScheduled = Schedule::command('geoip:update')
            ->onOneServer()
            ->withoutOverlapping(60);

        // MaxMind는 주 2회(화/금) 갱신 — 수요일 다운로드로 충분
        match ($geoipFrequency) {
            'daily' => $geoipScheduled->dailyAt($geoipTime),
            default => $geoipScheduled->weeklyOn(3, $geoipTime),
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
        $moduleManager = app(ModuleManager::class);
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
                    if (str_starts_with($settingKey, $identifier.'.')) {
                        $settingKey = substr($settingKey, strlen($identifier) + 1);
                    }

                    $cmd->when(fn () => (bool) module_setting($identifier, $settingKey, true));
                }
            }
        }

        // 플러그인 스케줄 등록
        $pluginManager = app(PluginManager::class);
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
                    if (str_starts_with($settingKey, $identifier.'.')) {
                        $settingKey = substr($settingKey, strlen($identifier) + 1);
                    }

                    $cmd->when(fn () => (bool) plugin_setting($identifier, $settingKey, true));
                }
            }
        }
    } catch (Exception $e) {
        Log::debug('확장 스케줄 등록 스킵', ['error' => $e->getMessage()]);
    }
}
