<?php

namespace Plugins\Sirsoft\Koreaexim;

use App\Extension\AbstractPlugin;
use Carbon\Carbon;

/** 수출입은행 환율 제공자. 이커머스 선택 계약을 통해서만 환율을 적용합니다. */
class Plugin extends AbstractPlugin
{
    public function getConfigValues(): array
    {
        return array_map(static fn (array $field) => $field['default'], $this->getSettingsSchema());
    }

    public function getSettingsSchema(): array
    {
        return [
            'auth_key' => ['type' => 'string', 'default' => '', 'sensitive' => true, 'max' => 200, 'label' => ['ko' => '인증키', 'en' => 'API key']],
            'auto_manage' => ['type' => 'boolean', 'default' => false, 'label' => ['ko' => '환율 자동 관리 사용', 'en' => 'Automatic rate management']],
            'update_time' => ['type' => 'string', 'default' => '11:30', 'label' => ['ko' => '갱신 시각 (한국시간)', 'en' => 'Update time (KST)']],
            'run_days' => ['type' => 'array', 'default' => [1, 2, 3, 4, 5], 'label' => ['ko' => '실행 요일', 'en' => 'Run days']],
            'retry_max' => ['type' => 'integer', 'default' => 3, 'min' => 0, 'max' => 12, 'label' => ['ko' => '최대 재시도', 'en' => 'Maximum retries']],
            'rate_type' => ['type' => 'enum', 'default' => 'deal_bas_r', 'options' => ['deal_bas_r', 'ttb', 'tts'], 'label' => ['ko' => '환율 종류', 'en' => 'Rate type']],
            'rate_decimal_places' => ['type' => 'integer', 'default' => 6, 'min' => 0, 'max' => 8, 'label' => ['ko' => '환율 소수 자릿수', 'en' => 'Rate decimal places']],
            'lookback_days' => ['type' => 'integer', 'default' => 7, 'min' => 0, 'max' => 14, 'label' => ['ko' => '직전 영업일 탐색 일수', 'en' => 'Lookback days']],
            'history_retention_days' => ['type' => 'integer', 'default' => 365, 'min' => 30, 'max' => 3650, 'label' => ['ko' => '이력 보존 일수', 'en' => 'History retention days']],
            'notify_on_failure' => ['type' => 'boolean', 'default' => true, 'label' => ['ko' => '실패 알림', 'en' => 'Failure notifications']],
        ];
    }

    public function getHookListeners(): array
    {
        return [Listeners\RegisterExchangeRateProviderListener::class, Listeners\ReapplyRatesOnCurrencyChangeListener::class,
            Listeners\ValidatePluginSettingsListener::class, Listeners\UpdateFailedNotificationDataListener::class];
    }

    public function getHooks(): array
    {
        return [
            ['name' => 'sirsoft-koreaexim.update.failed', 'type' => 'action', 'description' => ['ko' => '환율 갱신 실패', 'en' => 'Exchange rate update failed'], 'parameters' => ['run' => 'Model - 갱신 실행']],
            ['name' => 'sirsoft-koreaexim.notification.extract_data', 'type' => 'filter', 'description' => ['ko' => '실패 알림 변수', 'en' => 'Failure notification variables'], 'parameters' => ['data' => 'array', 'type' => 'string', 'args' => 'array'], 'return' => 'array'],
        ];
    }

    public function getSchedules(): array
    {
        $cron = '30 11 * * *';
        try {
            $time = plugin_setting($this->getIdentifier(), 'update_time', '11:30');
            if (! is_string($time) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $time)) {
                $time = '11:30';
            }
            $site = Carbon::today('Asia/Seoul')->setTimeFromTimeString($time)
                ->setTimezone(config('app.schedule_timezone', config('app.timezone', 'Asia/Seoul')));
            $cron = $site->minute.' '.$site->hour.' * * *';
        } catch (\Throwable) {
            // 설정 손상으로 다른 확장의 스케줄 등록까지 중단하지 않습니다.
        }

        return [
            ['command' => 'sirsoft-koreaexim:update --scheduled', 'schedule' => $cron, 'description' => '환율 정기 갱신', 'enabled_config' => 'auto_manage'],
            ['command' => 'sirsoft-koreaexim:update --scheduled --retry', 'schedule' => 'hourly', 'description' => '환율 재시도', 'enabled_config' => 'auto_manage'],
            ['command' => 'sirsoft-koreaexim:prune-history', 'schedule' => '57 4 * * *', 'description' => '환율 이력 정리'],
        ];
    }

    public function getPermissions(): array
    {
        return ['name' => ['ko' => '수출입은행 환율', 'en' => 'Exchange rates'], 'description' => ['ko' => '수출입은행 환율 운영 권한', 'en' => 'Exchange rate operation permissions'], 'categories' => [[
            'identifier' => 'exchange_rate', 'name' => ['ko' => '환율', 'en' => 'Exchange rates'],
            'description' => ['ko' => '환율 설정과 실행 이력', 'en' => 'Rate settings and run history'],
            'permissions' => [
                ['action' => 'view', 'name' => ['ko' => '환율 조회', 'en' => 'View rates'], 'description' => ['ko' => '환율 상태와 이력을 조회합니다.', 'en' => 'View rate status and history.'], 'type' => 'admin', 'roles' => ['admin', 'sirsoft-koreaexim.operator']],
                ['action' => 'manage', 'name' => ['ko' => '환율 관리', 'en' => 'Manage rates'], 'description' => ['ko' => '환율 설정을 변경하고 갱신합니다.', 'en' => 'Change settings and update rates.'], 'type' => 'admin', 'roles' => ['admin', 'sirsoft-koreaexim.operator']],
            ],
        ]]];
    }

    public function getRoles(): array
    {
        return [['identifier' => 'sirsoft-koreaexim.operator', 'name' => ['ko' => '환율 운영자', 'en' => 'Exchange rate operator'], 'description' => ['ko' => '환율 설정과 실행 이력을 관리합니다.', 'en' => 'Manage exchange rate settings and run history.']]];
    }

    public function getMiddleware(): array
    {
        return [['class' => Http\Middleware\ManageExchangeRateSettings::class, 'groups' => ['api'],
            'timing' => 'after_core', 'targets' => ['/api/admin/plugins/sirsoft-koreaexim/settings']]];
    }

    public function getDynamicTables(): array
    {
        return ['koreaexim_rate_run_items', 'koreaexim_rate_runs'];
    }

    public function getNotificationDefinitions(): array
    {
        return [[
            'type' => 'koreaexim_update_failed', 'hook_prefix' => 'sirsoft-koreaexim',
            'name' => ['ko' => '환율 갱신 실패', 'en' => 'Exchange rate update failed'],
            'description' => ['ko' => '환율 갱신 실패를 관리자에게 알립니다.', 'en' => 'Notifies administrators when exchange rate updates fail.'],
            'channels' => ['mail', 'database'], 'hooks' => ['sirsoft-koreaexim.update.failed'],
            'variables' => [
                ['key' => 'status_label', 'description' => '실패 유형'],
                ['key' => 'message', 'description' => '인증키를 제외한 상세 실패 사유'],
                ['key' => 'search_date', 'description' => '한국시간 기준 조회일'],
                ['key' => 'settings_url', 'description' => '환율 플러그인 설정 화면 주소'],
            ],
            'templates' => array_map(fn ($channel) => [
                'channel' => $channel, 'recipients' => [['type' => 'role', 'value' => 'admin']],
                'subject' => $channel === 'mail'
                    ? ['ko' => '[{app_name}] 환율 갱신 실패 — {status_label}', 'en' => '[{app_name}] Exchange rate update failed — {status_label}']
                    : ['ko' => '환율 갱신 실패 — {status_label}', 'en' => 'Exchange rate update failed — {status_label}'],
                'body' => $channel === 'mail'
                    ? ['ko' => "{name}님,\n\n{search_date} 환율 갱신이 실패했습니다.\n사유: {message}\n\n설정 확인: {settings_url}", 'en' => "Hello {name},\n\n{search_date} exchange rate update failed.\nReason: {message}\n\nSettings: {settings_url}"]
                    : ['ko' => '{search_date} 갱신 실패: {message}', 'en' => '{search_date} update failed: {message}'],
                ...($channel === 'database' ? ['click_url' => '{settings_url}'] : []),
            ], ['mail', 'database']),
        ]];
    }
}
