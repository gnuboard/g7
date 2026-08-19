<?php

namespace Tests\Feature\Settings;

use App\Services\SettingsService;
use Tests\TestCase;

/**
 * 본인인증 provider 저장값의 공개 SSR 노출 정리 (A6b)
 *
 * `identity` 카테고리는 `expose: true` 이고 `default_provider` 에 필드 단위 차단이 없어,
 * 저장값이 전 공개 페이지의 SSR 부트스트랩과 봇 SSR 에 그대로 실렸다. 소비처가 없는데도
 * 제거된 플러그인의 provider ID 가 계속 노출되는 상태다.
 */
class IdentityFrontendExposureTest extends TestCase
{
    /**
     * default_provider 는 프론트엔드 설정에 노출되지 않는다. (실패-먼저)
     */
    public function test_default_provider_is_not_exposed_to_frontend(): void
    {
        $frontend = app(SettingsService::class)->getFrontendSettings();

        $this->assertArrayNotHasKey(
            'default_provider',
            $frontend['identity'] ?? [],
            '본인인증 provider 저장값이 공개 프론트엔드 설정에 노출되었습니다.'
        );
    }

    /**
     * identity 카테고리의 나머지 필드도 그대로 미노출을 유지한다. (비회귀 pin)
     */
    public function test_other_identity_fields_stay_hidden(): void
    {
        $identity = app(SettingsService::class)->getFrontendSettings()['identity'] ?? [];

        foreach (['purpose_providers', 'challenge_ttl_minutes', 'max_attempts'] as $field) {
            $this->assertArrayNotHasKey($field, $identity);
        }
    }
}
