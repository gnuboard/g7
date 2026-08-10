<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\ShippingFeeTaxPolicy;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 현금영수증 환경설정 저장 (W-5 / #454 S3)
 *
 * 관리자 환경설정 UI 가 저장하는 3키가 실제로 DB 에 반영되는지 검증한다.
 * FormRequest 에 rules 를 명시하지 않으면 validated() 가 키를 떨궈 저장이 조용히 무효화된다 —
 * 이 테스트가 그 회귀를 고정한다.
 *
 * @scenario actor=admin, change_mode=manual
 *
 * @effects settings_cash_receipt_provider_persisted,
 *   settings_shipping_fee_tax_policy_persisted,
 *   settings_cash_receipt_self_issue_persisted,
 *   settings_invalid_shipping_fee_tax_policy_rejected_422
 */
class EcommerceSettingsCashReceiptTest extends ModuleTestCase
{
    private string $apiBase = '/api/modules/sirsoft-ecommerce/admin/settings';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.settings.read',
            'sirsoft-ecommerce.settings.update',
        ]);
    }

    private function settings(): EcommerceSettingsService
    {
        $settings = app(EcommerceSettingsService::class);
        $settings->clearCache();

        return $settings;
    }

    public function test_현금영수증_프로바이더가_저장된다(): void
    {
        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'order_settings',
            'order_settings' => ['cash_receipt_provider' => 'tosspayments'],
        ])->assertOk();

        $this->assertSame('tosspayments', $this->settings()->getSetting('order_settings.cash_receipt_provider'));
        $this->assertSame('tosspayments', $this->settings()->getCashReceiptProvider());
    }

    public function test_프로바이더를_빈_문자열로_저장하면_미사용이_된다(): void
    {
        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'order_settings',
            'order_settings' => ['cash_receipt_provider' => ''],
        ])->assertOk();

        // 빈 문자열은 "미사용" 이며 접근자는 null 로 정규화한다.
        $this->assertNull($this->settings()->getCashReceiptProvider());
    }

    public function test_배송비_과세_정책이_저장된다(): void
    {
        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'order_settings',
            'order_settings' => ['shipping_fee_tax_policy' => ShippingFeeTaxPolicy::TAXABLE->value],
        ])->assertOk();

        $this->assertSame(
            ShippingFeeTaxPolicy::TAXABLE,
            $this->settings()->getShippingFeeTaxPolicy()
        );
    }

    public function test_배송비_과세_정책_3종이_모두_저장된다(): void
    {
        foreach (ShippingFeeTaxPolicy::cases() as $policy) {
            $this->actingAs($this->adminUser)->putJson($this->apiBase, [
                '_tab' => 'order_settings',
                'order_settings' => ['shipping_fee_tax_policy' => $policy->value],
            ])->assertOk();

            $this->assertSame($policy, $this->settings()->getShippingFeeTaxPolicy(), $policy->value);
        }
    }

    public function test_알_수_없는_배송비_과세_정책은_거부된다(): void
    {
        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'order_settings',
            'order_settings' => ['shipping_fee_tax_policy' => 'half_taxable'],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['order_settings.shipping_fee_tax_policy']);
    }

    public function test_자진발급_토글이_저장된다(): void
    {
        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'order_settings',
            'order_settings' => ['cash_receipt_self_issue' => true],
        ])->assertOk();

        $this->assertTrue($this->settings()->isCashReceiptSelfIssueEnabled());

        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'order_settings',
            'order_settings' => ['cash_receipt_self_issue' => false],
        ])->assertOk();

        $this->assertFalse($this->settings()->isCashReceiptSelfIssueEnabled());
    }

    public function test_설정_조회_응답에_발급_프로바이더_후보_목록이_포함된다(): void
    {
        // 등록된 프로바이더가 없어도 키 자체는 존재해야 한다 (관리자 UI 가 length 로 분기한다).
        $this->actingAs($this->adminUser)->getJson($this->apiBase)
            ->assertOk()
            ->assertJsonStructure(['data' => ['available_cash_receipt_providers']]);
    }
}
