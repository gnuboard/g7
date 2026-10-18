<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 사용 중인 입금 계좌가 없을 때의 무통장입금 노출·활성화 회귀 테스트 (#703)
 *
 * 회귀 배경: 설치 기본값은 무통장입금(dbank)을 켜 두고 입금 계좌는 비어 있는 비활성 1건만 둔다.
 * 공개 결제 설정이 그대로 dbank 를 내보내 주문서가 무통장입금을 기본 선택하는데, 고를 계좌가 없어
 * 결제하기가 「입금 은행을 선택해주세요」 422 로만 끝났다. 관리자 저장도 계좌 목록을 비운 채
 * dbank 를 켜는 것을 막지 않았다.
 */
class DbankWithoutActiveAccountTest extends ModuleTestCase
{
    private const PAYMENT_ENDPOINT = '/api/modules/sirsoft-ecommerce/settings/payment';

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
     * 주문설정 파일을 기록합니다.
     *
     * @param  array<int, array<string, mixed>>  $bankAccounts  입금 계좌 목록
     * @return void 없음
     */
    private function saveOrderSettings(array $bankAccounts): void
    {
        File::ensureDirectoryExists($this->storagePath);
        File::put($this->storagePath.'/order_settings.json', json_encode([
            'payment_methods' => [
                ['id' => 'card', 'sort_order' => 1, 'is_active' => false, 'min_order_amount' => 0, 'stock_deduction_timing' => 'payment_complete'],
                ['id' => 'dbank', 'sort_order' => 3, 'is_active' => true, 'min_order_amount' => 0, 'stock_deduction_timing' => 'order_placed'],
            ],
            'bank_accounts' => $bankAccounts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        app(EcommerceSettingsService::class)->clearCache();
    }

    /**
     * 사용 중인 계좌가 없으면 공개 결제 설정이 무통장입금을 내보내지 않는다 (설치 기본값 상태).
     *
     * @scenario surface=public_payment_settings, active_account=none
     *
     * @effects dbank_hidden_without_active_account
     *
     * @return void 없음
     */
    public function test_public_settings_hide_dbank_without_active_account(): void
    {
        $this->saveOrderSettings([
            ['bank_code' => '004', 'account_number' => '', 'account_holder' => '', 'is_active' => false, 'is_default' => false],
        ]);

        $ids = array_column($this->getJson(self::PAYMENT_ENDPOINT)->assertOk()->json('data.order_settings.payment_methods'), 'id');

        $this->assertNotContains('dbank', $ids);
    }

    /**
     * 사용 중인 계좌가 있으면 무통장입금은 그대로 노출된다.
     *
     * @scenario surface=public_payment_settings, active_account=present
     *
     * @effects dbank_offered_with_active_account
     *
     * @return void 없음
     */
    public function test_public_settings_keep_dbank_with_active_account(): void
    {
        $this->saveOrderSettings([
            ['bank_code' => '004', 'account_number' => '123-456', 'account_holder' => '테스트상점', 'is_active' => true, 'is_default' => true],
        ]);

        $ids = array_column($this->getJson(self::PAYMENT_ENDPOINT)->assertOk()->json('data.order_settings.payment_methods'), 'id');

        $this->assertContains('dbank', $ids);
    }

    /**
     * 계좌를 모두 지운 채 무통장입금을 켜서 저장하면 검증 오류로 막힌다.
     *
     * @scenario surface=admin_settings_save, active_account=none
     *
     * @effects dbank_activation_requires_active_account
     *
     * @return void 없음
     */
    public function test_admin_cannot_enable_dbank_without_active_account(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.settings.read', 'sirsoft-ecommerce.settings.update']);

        $response = $this->actingAs($admin)->putJson('/api/modules/sirsoft-ecommerce/admin/settings', [
            '_tab' => 'order_settings',
            'order_settings' => [
                'payment_methods' => [
                    ['id' => 'dbank', 'sort_order' => 3, 'is_active' => true, 'min_order_amount' => 0, 'stock_deduction_timing' => 'order_placed'],
                ],
                'bank_accounts' => [],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['order_settings.bank_accounts']);
    }

    /**
     * 사용 중인 계좌가 있으면 무통장입금을 켠 저장은 통과한다.
     *
     * @scenario surface=admin_settings_save, active_account=present
     *
     * @effects dbank_activation_allowed_with_active_account
     *
     * @return void 없음
     */
    public function test_admin_can_enable_dbank_with_active_account(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.settings.read', 'sirsoft-ecommerce.settings.update']);

        $response = $this->actingAs($admin)->putJson('/api/modules/sirsoft-ecommerce/admin/settings', [
            '_tab' => 'order_settings',
            'order_settings' => [
                'payment_methods' => [
                    ['id' => 'dbank', 'sort_order' => 3, 'is_active' => true, 'min_order_amount' => 0, 'stock_deduction_timing' => 'order_placed'],
                ],
                'bank_accounts' => [
                    ['bank_code' => '004', 'account_number' => '123-456', 'account_holder' => '테스트상점', 'is_active' => true, 'is_default' => true],
                ],
            ],
        ]);

        $response->assertJsonMissingValidationErrors(['order_settings.bank_accounts']);
    }
}
