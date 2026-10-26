<?php

namespace Plugins\Sirsoft\Koreaexim;

use App\Extension\AbstractPlugin;

/** 수출입은행 환율 제공자. 이커머스 선택 계약을 통해서만 환율을 적용합니다. */
class Plugin extends AbstractPlugin
{
    /**
     * 설정 기본값을 반환합니다.
     *
     * @return array<string, mixed> 설정 키 => 설정 스키마의 기본값
     */
    public function getConfigValues(): array
    {
        return array_map(static fn (array $field) => $field['default'], $this->getSettingsSchema());
    }

    /**
     * 플러그인 설정 스키마를 반환합니다.
     *
     * @return array<string, array<string, mixed>> 설정 키 => 형식·기본값·한계값·다국어 라벨
     */
    public function getSettingsSchema(): array
    {
        return [
            'auth_key' => ['type' => 'string', 'default' => '', 'sensitive' => true, 'max' => 200, 'label' => ['ko' => '인증키', 'en' => 'API key']],
            'auto_manage' => ['type' => 'boolean', 'default' => false, 'label' => ['ko' => '환율 자동 관리 사용', 'en' => 'Automatic rate management']],
            'run_days' => ['type' => 'array', 'default' => [1, 2, 3, 4, 5], 'label' => ['ko' => '실행 요일', 'en' => 'Run days']],
            'retry_max' => ['type' => 'integer', 'default' => 3, 'min' => 0, 'max' => 12, 'label' => ['ko' => '최대 재시도', 'en' => 'Maximum retries']],
            'rate_type' => ['type' => 'enum', 'default' => 'deal_bas_r', 'options' => ['deal_bas_r', 'ttb', 'tts'], 'label' => ['ko' => '환율 종류', 'en' => 'Rate type']],
            'rate_decimal_places' => ['type' => 'integer', 'default' => 6, 'min' => 0, 'max' => 8, 'label' => ['ko' => '환율 소수 자릿수', 'en' => 'Rate decimal places']],
            'lookback_days' => ['type' => 'integer', 'default' => 7, 'min' => 0, 'max' => 14, 'label' => ['ko' => '직전 영업일 탐색 일수', 'en' => 'Lookback days']],
            'history_retention_days' => ['type' => 'integer', 'default' => 365, 'min' => 30, 'max' => 3650, 'label' => ['ko' => '이력 보관 기간 (일)', 'en' => 'History retention period (days)']],
            'notify_on_failure' => ['type' => 'boolean', 'default' => true, 'label' => ['ko' => '실패 알림', 'en' => 'Failure notifications']],
        ];
    }

    /**
     * 훅 리스너 클래스 목록을 반환합니다.
     *
     * @return array<int, class-string> 환율 제공자 등록·통화 변경 재적용·설정 검증·실패 알림 변수 리스너
     */
    public function getHookListeners(): array
    {
        return [Listeners\RegisterExchangeRateProviderListener::class, Listeners\ReapplyRatesOnCurrencyChangeListener::class,
            Listeners\ValidatePluginSettingsListener::class, Listeners\UpdateFailedNotificationDataListener::class];
    }

    /**
     * 이 플러그인이 발행하는 훅 목록을 반환합니다.
     *
     * @return array<int, array<string, mixed>> 훅 이름·유형·설명·인자 정의
     */
    public function getHooks(): array
    {
        return [
            ['name' => 'sirsoft-koreaexim.update.failed', 'type' => 'action', 'description' => ['ko' => '환율 갱신 실패', 'en' => 'Exchange rate update failed'], 'parameters' => ['run' => 'Model - 갱신 실행']],
            ['name' => 'sirsoft-koreaexim.notification.extract_data', 'type' => 'filter', 'description' => ['ko' => '실패 알림 변수', 'en' => 'Failure notification variables'], 'parameters' => ['data' => 'array', 'type' => 'string', 'args' => 'array'], 'return' => 'array'],
        ];
    }

    /**
     * 정기 갱신·재시도·이력 정리 스케줄을 선언합니다.
     *
     * 정기 갱신 시각은 한국시간 11:30 고정 선언이다 — 운영자는 관리자 > 스케줄 관리의 이 행에서 바꾸고
     * (플러그인 설정의 갱신 시각 칸은 1.0.2 에서 없어졌다), 바꾼 값은 갱신 뒤에도 보존된다.
     * 상태 화면의 다음 실행 시각과 재시도 시작 시각도 이 행을 읽는다(UpdateScheduleResolver).
     * 재시도·이력 정리는 사이트 시간대 기준이다.
     *
     * @return array<int, array<string, mixed>> 스케줄 선언 목록 (형식: AbstractPlugin::getSchedules() 참고)
     */
    public function getSchedules(): array
    {
        $autoManage = [['source' => 'plugin_setting', 'key' => 'auto_manage', 'operator' => 'truthy', 'default' => true]];

        return [
            [
                'name' => Services\UpdateScheduleResolver::SCHEDULE_NAME,
                'type' => 'artisan',
                'label' => ['ko' => '환율 정기 갱신', 'en' => 'Update exchange rates'],
                'description' => ['ko' => '수출입은행 고시 환율을 받아 적용합니다(자동 관리를 켠 경우만). 실행 요일은 환율 설정을 따르며 그 밖의 요일은 이 시각에 실행돼도 갱신을 건너뛰므로, 여기의 다음 실행과 환율 설정의 다음 갱신 시각이 다를 수 있습니다.', 'en' => 'Fetches and applies Korea Eximbank rates (only when automatic management is on). Run days follow the exchange rate settings; on other days the run at this time skips the update, so the next run here can differ from the next update shown in the exchange rate settings.'],
                'command' => 'sirsoft-koreaexim:update --scheduled',
                'expression' => Services\UpdateScheduleResolver::DEFAULT_EXPRESSION,
                'timezone' => Services\UpdateScheduleResolver::TIMEZONE,
                'enabled_condition' => $autoManage,
            ],
            [
                'name' => 'retry-rates',
                'type' => 'artisan',
                'label' => ['ko' => '환율 갱신 재시도', 'en' => 'Retry exchange rate update'],
                'description' => ['ko' => '정기 갱신이 통신 오류·빈 응답으로 실패한 날 매시 다시 시도합니다(자동 관리를 켠 경우만).', 'en' => 'Retries hourly on days the scheduled update failed with a network error or empty response (only when automatic management is on).'],
                'command' => 'sirsoft-koreaexim:update --scheduled --retry',
                'frequency' => 'hourly',
                'expression' => '0 * * * *',
                'enabled_condition' => $autoManage,
            ],
            [
                'name' => 'prune-history',
                'type' => 'artisan',
                'label' => ['ko' => '환율 이력 정리', 'en' => 'Prune exchange rate history'],
                'description' => ['ko' => '보존 기간이 지난 환율 갱신 이력을 지웁니다.', 'en' => 'Deletes exchange rate update history past the retention period.'],
                'command' => 'sirsoft-koreaexim:prune-history',
                'expression' => '57 4 * * *',
            ],
        ];
    }

    /**
     * 권한 정의를 반환합니다.
     *
     * @return array<string, mixed> 권한 그룹 이름·설명과 환율 조회·관리 권한
     */
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

    /**
     * 역할 정의를 반환합니다.
     *
     * @return array<int, array<string, mixed>> 환율 운영자 역할
     */
    public function getRoles(): array
    {
        return [['identifier' => 'sirsoft-koreaexim.operator', 'name' => ['ko' => '환율 운영자', 'en' => 'Exchange rate operator'], 'description' => ['ko' => '환율 설정과 실행 이력을 관리합니다.', 'en' => 'Manage exchange rate settings and run history.']]];
    }

    /**
     * 미들웨어 등록 정의를 반환합니다.
     *
     * @return array<int, array<string, mixed>> 설정 저장 API 에 붙는 환율 설정 관리 미들웨어
     */
    public function getMiddleware(): array
    {
        return [['class' => Http\Middleware\ManageExchangeRateSettings::class, 'groups' => ['api'],
            'timing' => 'after_core', 'targets' => ['/api/admin/plugins/sirsoft-koreaexim/settings']]];
    }

    /**
     * 이 플러그인이 만드는 테이블 목록을 반환합니다.
     *
     * @return array<int, string> 접두사 없는 테이블 이름
     */
    public function getDynamicTables(): array
    {
        return ['koreaexim_rate_run_items', 'koreaexim_rate_runs'];
    }

    /**
     * 알림 정의를 반환합니다.
     *
     * @return array<int, array<string, mixed>> 환율 갱신 실패 알림(메일·사이트 알림 템플릿 포함)
     */
    public function getNotificationDefinitions(): array
    {
        return [[
            'type' => 'koreaexim_update_failed', 'hook_prefix' => 'sirsoft-koreaexim',
            'name' => ['ko' => '환율 갱신 실패', 'en' => 'Exchange rate update failed'],
            'description' => ['ko' => '환율 갱신 실패를 관리자에게 알립니다.', 'en' => 'Notifies administrators when exchange rate updates fail.'],
            'channels' => ['mail', 'database'], 'hooks' => ['sirsoft-koreaexim.update.failed'],
            // 받는 사람은 관리자 역할뿐이다 — 운영자 수신 알림
            'audience' => 'staff',
            'variables' => [
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'name', 'description' => '수신자(관리자) 이름'],
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
