<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 「기본정보」 탭 조회·저장 왕복 테스트
 *
 * 설정은 사업자등록번호·전화·팩스·이메일을 합친 값으로 저장하고, 관리자 폼은 나눔 칸
 * (business_number_1 …, phone_1 …, email_id·email_domain)으로 입력받는다. 관리자 조회가 합친 값을
 * 나눔 칸으로 펼치지 않으면 폼이 빈 칸을 보이고, 다른 칸만 고쳐 저장해도 그 빈 칸이 합쳐져 네 값이 지워진다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/business-info-admin-round-trip.yaml
 */
class BusinessInfoAdminRoundTripTest extends ModuleTestCase
{
    private string $apiBase = '/api/modules/sirsoft-ecommerce/admin/settings';

    private User $adminUser;

    /**
     * 관리자와 합친 값으로 저장된 사업자 연락처를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.settings.read',
            'sirsoft-ecommerce.settings.update',
        ]);

        $settings = app(EcommerceSettingsService::class);
        $settings->setSetting('basic_info.shop_name', '시험 상점');
        $settings->setSetting('basic_info.business_number', '123-45-67890');
        $settings->setSetting('basic_info.phone', '02-123-4567');
        $settings->setSetting('basic_info.fax', '02-123-4568');
        $settings->setSetting('basic_info.email', 'help@example.com');
        $settings->clearCache();
    }

    /**
     * 관리자 조회가 합친 값을 폼의 나눔 칸으로 펼쳐 준다.
     *
     * @scenario surface=admin_index, stored=merged_only
     *
     * @effects admin_settings_expand_split_fields
     *
     * @return void 반환값 없음
     */
    public function test_admin_settings_expand_merged_contacts_into_split_fields(): void
    {
        $basic = $this->actingAs($this->adminUser)->getJson($this->apiBase)->assertOk()->json('data.basic_info');

        $this->assertSame(['123', '45', '67890'], [$basic['business_number_1'] ?? null, $basic['business_number_2'] ?? null, $basic['business_number_3'] ?? null]);
        $this->assertSame(['02', '123', '4567'], [$basic['phone_1'] ?? null, $basic['phone_2'] ?? null, $basic['phone_3'] ?? null]);
        $this->assertSame(['02', '123', '4568'], [$basic['fax_1'] ?? null, $basic['fax_2'] ?? null, $basic['fax_3'] ?? null]);
        $this->assertSame(['help', 'example.com'], [$basic['email_id'] ?? null, $basic['email_domain'] ?? null]);
        // 합친 값도 그대로 남는다(화면의 다른 곳·저장 응답이 읽는다)
        $this->assertSame('02-123-4567', $basic['phone'] ?? null);
    }

    /**
     * 폼처럼 조회 결과를 그대로 보내며 다른 칸만 고쳐 저장해도 연락처가 지워지지 않는다.
     *
     * @scenario surface=admin_save_round_trip, stored=merged_only
     *
     * @effects admin_basic_info_save_keeps_contacts, admin_save_response_keeps_split_fields
     *
     * @return void 반환값 없음
     */
    public function test_saving_basic_info_tab_keeps_merged_contacts(): void
    {
        $basic = $this->actingAs($this->adminUser)->getJson($this->apiBase)->assertOk()->json('data.basic_info');
        $basic['hosting_provider'] = '시험 호스팅';
        $basic['show_business_footer'] = false;

        $saved = $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'basic_info',
            'basic_info' => $basic,
        ])->assertOk()->json('data.basic_info');

        $settings = app(EcommerceSettingsService::class);
        $settings->clearCache();
        $this->assertSame('123-45-67890', $settings->getSetting('basic_info.business_number'));
        $this->assertSame('02-123-4567', $settings->getSetting('basic_info.phone'));
        $this->assertSame('02-123-4568', $settings->getSetting('basic_info.fax'));
        $this->assertSame('help@example.com', $settings->getSetting('basic_info.email'));
        $this->assertSame('시험 호스팅', $settings->getSetting('basic_info.hosting_provider'));
        $this->assertFalse($settings->getSetting('basic_info.show_business_footer'));

        // 저장 응답으로 폼을 다시 채워도 나눔 칸이 비지 않는다 — 한 번 더 저장해도 지워지지 않게
        $this->assertSame('02', $saved['phone_1'] ?? null);
        $this->assertSame('help', $saved['email_id'] ?? null);
    }
}
