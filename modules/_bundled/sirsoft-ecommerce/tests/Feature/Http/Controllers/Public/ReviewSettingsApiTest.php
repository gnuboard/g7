<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 공개 리뷰 설정 API 테스트 (A6)
 *
 * GET /api/modules/sirsoft-ecommerce/settings/review 가 리뷰 정책(최대 개수/용량/작성기한)을
 * 프론트로 전달하는지 검증.
 */
class ReviewSettingsApiTest extends ModuleTestCase
{
    private const ENDPOINT = '/api/modules/sirsoft-ecommerce/settings/review';

    public function test_returns_review_settings_payload(): void
    {
        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'review_settings' => [
                    'max_images',
                    'max_image_size_mb',
                    'write_deadline_days',
                ],
            ],
        ]);
    }

    public function test_review_settings_defaults_present(): void
    {
        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk();
        $reviewSettings = $response->json('data.review_settings');

        // 기본값 (defaults.json) 노출 확인
        $this->assertSame(5, $reviewSettings['max_images']);
        $this->assertSame(10, $reviewSettings['max_image_size_mb']);
        $this->assertSame(90, $reviewSettings['write_deadline_days']);
    }

    public function test_accessible_without_authentication(): void
    {
        // 공개 API — 비회원도 접근 가능
        $this->getJson(self::ENDPOINT)->assertOk();
    }
}
