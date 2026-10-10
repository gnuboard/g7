<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Privacy;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 이커머스 개인정보 보유기간 설정·미리보기 API 테스트 (이슈 #636 S5)
 *
 * 「개인정보 보유기간」 탭 저장이 카테고리 화이트리스트·검증 규칙·기본값 세 지점을 모두 지나는지,
 * 전자상거래법 하한(5년)을 서버가 강제하는지, 미리보기 API 가 「꺼짐 = null」 계약을 지키는지를
 * 고정합니다(§8.5 T2 의 「4년 → 422」).
 */
class OrderPrivacyRetentionSettingsTest extends ModuleTestCase
{
    private const API = '/api/modules/sirsoft-ecommerce/admin/settings';

    /** 설정 권한을 가진 관리자 */
    private User $admin;

    /**
     * 설정 권한 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.settings.read',
            'sirsoft-ecommerce.settings.update',
        ]);
    }

    /**
     * 기본값은 꺼짐·5년·익명화이며 조회 응답에 한계값(하한 5)이 실린다.
     *
     * @return void 반환값 없음
     *
     * @scenario order_state=confirmed, toggle=off, mode=anonymize
     *
     * @effects order_retention_is_off_by_default
     */
    #[Test]
    public function defaults_are_off_five_years_and_anonymize(): void
    {
        $this->actingAs($this->admin)->getJson(self::API)
            ->assertOk()
            ->assertJsonPath('data.privacy_retention.enabled', false)
            ->assertJsonPath('data.privacy_retention.retention_years', 5)
            ->assertJsonPath('data.privacy_retention.mode', 'anonymize')
            ->assertJsonPath('data._meta.limits.privacy_retention_years_min', 5);
    }

    /**
     * 「개인정보 보유기간」 탭 저장이 값을 저장하고, 4년(법정 하한 미만)·알 수 없는 방식은 422 다.
     *
     * @return void 반환값 없음
     *
     * @scenario order_state=confirmed, toggle=on, mode=delete
     *
     * @effects order_retention_settings_are_saved, order_retention_settings_enforce_legal_minimum
     */
    #[Test]
    public function privacy_tab_saves_and_enforces_the_legal_minimum(): void
    {
        $this->actingAs($this->admin)->putJson(self::API, [
            '_tab' => 'privacy_retention',
            'privacy_retention' => ['enabled' => true, 'retention_years' => '7', 'mode' => 'delete'],
        ])->assertOk();

        $settings = app(EcommerceSettingsService::class);
        $settings->clearCache();
        $this->assertTrue((bool) $settings->getSetting('privacy_retention.enabled'));
        $this->assertSame(7, (int) $settings->getSetting('privacy_retention.retention_years'));
        $this->assertSame('delete', $settings->getSetting('privacy_retention.mode'));

        $this->actingAs($this->admin)->putJson(self::API, [
            '_tab' => 'privacy_retention',
            'privacy_retention' => ['enabled' => true, 'retention_years' => 4, 'mode' => 'purge'],
        ])->assertStatus(422)->assertJsonValidationErrors([
            'privacy_retention.retention_years',
            'privacy_retention.mode',
        ]);
    }

    /**
     * 미리보기 API: 꺼져 있어도 건수를 세고(켜기 전에 규모를 본다) 꺼짐은 enabled 로 구분한다.
     * 명단 권한은 주문 읽기까지 있어야 참이다. 권한 없는 사용자는 403.
     *
     * @return void 반환값 없음
     *
     * @scenario order_state=confirmed, toggle=on, mode=anonymize
     *
     * @effects order_retention_preview_api_counts_while_off
     */
    #[Test]
    public function preview_api_distinguishes_disabled_from_zero(): void
    {
        $this->actingAs($this->admin)->getJson(self::API.'/privacy-retention-preview')
            ->assertOk()
            ->assertJsonPath('data.orders', 0)
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.mode', 'anonymize')
            ->assertJsonPath('data.retention_years', 5)
            ->assertJsonPath('data.can_view_targets', false);

        $this->actingAs($this->admin)->putJson(self::API, [
            '_tab' => 'privacy_retention',
            'privacy_retention' => ['enabled' => true, 'retention_years' => 5, 'mode' => 'anonymize'],
        ])->assertOk();

        $viewer = $this->createAdminUser(['sirsoft-ecommerce.settings.read', 'sirsoft-ecommerce.orders.read']);
        $this->actingAs($viewer)->getJson(self::API.'/privacy-retention-preview')
            ->assertOk()
            ->assertJsonPath('data.orders', 0)
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.can_view_targets', true);

        $this->actingAs($this->createUser())->getJson(self::API.'/privacy-retention-preview')->assertStatus(403);
    }
}
