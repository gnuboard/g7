<?php

namespace Database\Seeders\Concerns;

use App\Extension\HookManager;

/**
 * 코어 시더 보일러플레이트 추상화 — config/core.php SSoT 직독 + lang pack 필터 발화.
 *
 * `NotificationDefinitionSeeder`, `IdentityMessageDefinitionSeeder`, `IdentityPolicySeeder` 등
 * config 의 i18n SSoT 블록을 DB 로 동기화하는 시더가 공통으로 사용하는 패턴을 단일 진입점으로 흡수.
 *
 * 사용 예:
 *   $definitions = $this->loadConfigSeed('core.notification_definitions', 'seed.notifications.translations');
 *
 * 효과:
 *   - 신규 SSoT 도메인 시더 작성 시 lang pack 필터 호출 누락 자동 방지
 *   - audit 룰 `seeder-translation-filter` 와 정합 (트레이트 사용 = 자동 검증 통과)
 *
 * @since 7.0.0-beta.4 (이슈 #263 후속 — Seeder 보일러플레이트 추상화)
 */
trait LoadsConfigSeedWithLangPackFilter
{
    /**
     * config 키에서 SSoT 데이터를 로드하고 lang pack 다국어 필터를 적용합니다.
     *
     * @param  string  $configKey  config 경로 (예: 'core.notification_definitions')
     * @param  string  $filterKey  HookManager 필터 키 (예: 'seed.notifications.translations')
     * @return array<int|string, mixed> 다국어 키가 보강된 SSoT 데이터
     */
    protected function loadConfigSeed(string $configKey, string $filterKey): array
    {
        $data = config($configKey, []);

        return HookManager::applyFilters($filterKey, $data);
    }
}
