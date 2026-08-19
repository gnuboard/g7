<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\ShippingFeeTaxPolicy;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\RegistersTestCashReceiptProvider;
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
    use RegistersTestCashReceiptProvider;

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
        // 저장값 해석은 레지스트리 대조를 거치므로 제공 확장을 함께 등록한다 (A3)
        $this->registerCashReceiptProvider('tosspayments');

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

    /**
     * 저장값이 남아 있어도 그 프로바이더를 제공하는 확장이 없으면 미설정으로 본다. (A3, 실패-먼저)
     *
     * 플러그인을 제거해도 `order_settings.cash_receipt_provider` 문자열은 그대로 남는다.
     * 그 값을 그대로 신뢰하면 체크아웃의 현금영수증 신청 폼과 마이페이지 발급 버튼이 계속
     * 렌더되고, 신청하면 구독자 없는 훅을 호출해 발급 실패로만 기록된다.
     *
     * @scenario provider_state=dead
     *
     * @effects dead_cash_receipt_provider_treated_as_unset
     */
    public function test_등록되지_않은_프로바이더는_미설정으로_취급된다(): void
    {
        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'order_settings',
            'order_settings' => ['cash_receipt_provider' => 'ghost_provider'],
        ])->assertOk();

        // 저장값 자체는 남는다 (관리자가 확인하고 고칠 수 있어야 하므로)
        $this->assertSame('ghost_provider', $this->settings()->getSetting('order_settings.cash_receipt_provider'));

        // 해석 결과는 미설정
        $this->assertNull(
            $this->settings()->getCashReceiptProvider(),
            '제공 확장이 없는 프로바이더가 유효한 것으로 해석되었습니다.'
        );
    }

    /**
     * 공개 결제 설정에서도 죽은 프로바이더가 미설정으로 정규화된다. (실패-먼저)
     *
     * 체크아웃 신청 폼은 카테고리 raw 값을 그대로 읽으므로, 공개 응답에서 정규화하지 않으면
     * 폼이 계속 렌더된다.
     *
     * @scenario provider_state=dead
     *
     * @effects dead_cash_receipt_provider_normalized_in_public
     */
    public function test_공개_결제설정에서_죽은_프로바이더가_정규화된다(): void
    {
        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'order_settings',
            'order_settings' => ['cash_receipt_provider' => 'ghost_provider'],
        ])->assertOk();

        $public = $this->settings()->getPublicPaymentSettings();

        $this->assertArrayHasKey('cash_receipt_provider', $public);
        $this->assertNull(
            $public['cash_receipt_provider'],
            '죽은 현금영수증 프로바이더가 공개 응답에 그대로 노출되었습니다.'
        );
    }

    /**
     * 등록된 프로바이더는 그대로 해석된다. (비회귀 pin)
     *
     * @scenario provider_state=live
     *
     * @effects live_cash_receipt_provider_resolved
     */
    public function test_등록된_프로바이더는_그대로_해석된다(): void
    {
        $this->registerCashReceiptProvider('tosspayments');

        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'order_settings',
            'order_settings' => ['cash_receipt_provider' => 'tosspayments'],
        ])->assertOk();

        $this->assertSame('tosspayments', $this->settings()->getCashReceiptProvider());
        $this->assertSame('tosspayments', $this->settings()->getPublicPaymentSettings()['cash_receipt_provider'] ?? null);
    }
}
