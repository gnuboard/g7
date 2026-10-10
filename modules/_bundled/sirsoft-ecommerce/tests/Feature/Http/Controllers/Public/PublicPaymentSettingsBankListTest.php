<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 공개 결제 설정의 은행 목록이 회원 환불 신청서와 같은 출처를 쓰는지 검증한다 (실측 2026-10-03)
 *
 * 운영자가 은행 목록을 비운 상점에서 회원 환불 신청서는 모듈 기본 목록(16곳)을 보여 주는데,
 * 공개 결제 설정은 빈 원본을 내보내 관리자 대리 접수·주문 취소·환불 실행 창이 「등록된 은행이
 * 없다」며 환불 계좌를 고를 수 없었고, 주문서의 입금 계좌에는 은행명 대신 코드("004")가 섰다.
 */
class PublicPaymentSettingsBankListTest extends ModuleTestCase
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
     * 운영자 은행 목록이 비었으면 기본 목록을 내고, 입금 계좌의 은행명도 그 목록으로 푼다.
     *
     * @scenario configured_banks=empty
     *
     * @effects public_payment_settings_falls_back_to_default_bank_list, bank_account_name_resolves_from_effective_bank_list
     *
     * @return void 없음
     */
    public function test_empty_operator_bank_list_falls_back_to_defaults(): void
    {
        File::ensureDirectoryExists($this->storagePath);
        File::put($this->storagePath.'/order_settings.json', json_encode([
            'banks' => [],
            'bank_accounts' => [
                ['bank_code' => '004', 'account_number' => '00000000000', 'account_holder' => '시험', 'is_active' => true, 'is_default' => true],
            ],
        ], JSON_UNESCAPED_UNICODE));
        app(EcommerceSettingsService::class)->clearCache();

        $response = $this->getJson(self::PAYMENT_ENDPOINT)->assertOk();

        $banks = $response->json('data.order_settings.banks');
        $this->assertNotEmpty($banks, '운영자 목록이 비어도 회원 신청서와 같은 기본 목록이 나가야 한다.');
        $this->assertContains('004', array_column($banks, 'code'));

        $bankName = $response->json('data.order_settings.bank_accounts.0.bank_name');
        $this->assertIsArray($bankName, '은행 코드 대신 은행명(다국어)이 실려야 한다.');
        $this->assertSame('국민은행', $bankName['ko'] ?? null);
    }

    /**
     * 운영자가 등록한 목록이 있으면 그 목록을 그대로 낸다.
     *
     * @scenario configured_banks=custom
     *
     * @effects public_payment_settings_keeps_operator_bank_list
     *
     * @return void 없음
     */
    public function test_operator_bank_list_is_kept_as_is(): void
    {
        File::ensureDirectoryExists($this->storagePath);
        File::put($this->storagePath.'/order_settings.json', json_encode([
            'banks' => [
                ['code' => '999', 'name' => ['ko' => '시험은행', 'en' => 'Test Bank']],
            ],
            'bank_accounts' => [],
        ], JSON_UNESCAPED_UNICODE));
        app(EcommerceSettingsService::class)->clearCache();

        $banks = $this->getJson(self::PAYMENT_ENDPOINT)->assertOk()->json('data.order_settings.banks');

        $this->assertSame(['999'], array_column($banks, 'code'));
    }
}
