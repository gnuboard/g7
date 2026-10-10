<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 저장할 설정 구역이 없는 요청의 거절 회귀 테스트 (#703 브라우저 실측)
 *
 * 회귀 배경: 쇼핑몰 환경설정 화면의 저장 본문 식이 평가에 실패하자(구조분해 매개변수) 식 원문 문자열이 본문으로
 * 나갔다. 서버는 저장할 구역이 하나도 없는데도 「설정이 저장되었습니다」 200 으로 응답해, 운영자는 어느 탭을
 * 저장해도 저장된 줄 알았지만 아무것도 바뀌지 않았다. 구역이 없는 요청은 422 로 거절한다.
 */
class EcommerceSettingsEmptyPayloadTest extends ModuleTestCase
{
    private string $storagePath;

    /**
     * 설정 격리 경로를 비웁니다.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }
        app(EcommerceSettingsService::class)->clearCache();
    }

    /**
     * 설정 격리 경로를 정리합니다.
     *
     * @return void 없음
     */
    protected function tearDown(): void
    {
        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }

        parent::tearDown();
    }

    /**
     * 구역이 없는 본문(식 원문 문자열·빈 객체·_tab 만)은 422 로 거절하고, 구역이 있으면 저장한다.
     *
     * @scenario payload=no_section
     *
     * @effects settings_save_rejects_payload_without_section
     *
     * @return void 없음
     */
    public function test_payload_without_any_section_is_rejected(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.settings.read', 'sirsoft-ecommerce.settings.update']);
        $endpoint = '/api/modules/sirsoft-ecommerce/admin/settings';

        // 평가에 실패한 식 원문이 그대로 본문이 된 경우
        $this->actingAs($admin)
            ->call('PUT', $endpoint, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode('{{(function() { return {}; })()}}'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['_tab']);

        $this->actingAs($admin)->putJson($endpoint, [])->assertStatus(422)->assertJsonValidationErrors(['_tab']);
        $this->actingAs($admin)->putJson($endpoint, ['_tab' => 'basic_info'])->assertStatus(422)->assertJsonValidationErrors(['_tab']);

        $this->actingAs($admin)->putJson($endpoint, [
            '_tab' => 'basic_info',
            'basic_info' => ['shop_name' => 'E2E 상점', 'route_path' => 'shop'],
        ])->assertOk();
    }
}
