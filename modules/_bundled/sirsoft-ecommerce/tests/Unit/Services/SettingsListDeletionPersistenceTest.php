<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 목록형 설정의 삭제 영속성 실측 (공개 #91 전수 조사)
 *
 * defaults.json 이 항목을 제공하고 관리자 화면이 그 항목의 삭제를 제공하는 목록 설정 전부에 대해,
 * 삭제 저장 후 재조회에서 항목이 되살아나지 않는지 확인한다. 통화(currencies)만 code 기준
 * 보충 병합을 거치고 나머지는 통째 교체이므로 삭제가 그대로 유지되어야 하는데, 이 구분은
 * 코드를 읽어야만 드러나므로 실측으로 고정한다.
 */
class SettingsListDeletionPersistenceTest extends ModuleTestCase
{
    private EcommerceSettingsService $service;

    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');

        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }

        $this->service = new EcommerceSettingsService;
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }

        parent::tearDown();
    }

    /**
     * 카테고리의 목록 값을 저장하고 재조회 결과를 돌려줍니다.
     *
     * @param  string  $category  설정 카테고리
     * @param  string  $key  목록 키
     * @param  array  $list  저장할 목록
     * @return array 재조회된 목록
     */
    private function saveAndReload(string $category, string $key, array $list): array
    {
        $current = $this->service->getSettings($category);
        $current[$key] = $list;

        $this->service->saveSettings([$category => $current]);
        $this->service->clearCache();

        return $this->service->getSettings($category)[$key] ?? [];
    }

    /**
     * 은행 목록에서 항목을 삭제하면 유지된다. (통째 교체)
     *
     * @scenario saved_currency_set=default_removed_by_admin, deletion_tombstone=has_removed_codes
     *
     * @effects non_supplemented_list_settings_keep_deletion
     */
    public function test_deleted_bank_stays_deleted(): void
    {
        $banks = $this->service->getSettings('order_settings')['banks'] ?? [];
        $this->assertGreaterThan(1, count($banks), '기본 은행 목록이 비어 있어 삭제를 측정할 수 없습니다.');

        $kept = array_values(array_slice($banks, 0, 2));
        $reloaded = $this->saveAndReload('order_settings', 'banks', $kept);

        $this->assertCount(2, $reloaded, '삭제한 은행이 defaults 에서 되살아났습니다.');
    }

    /**
     * 입금 계좌 목록에서 항목을 삭제하면 유지된다. (통째 교체)
     *
     * @scenario saved_currency_set=default_removed_by_admin, deletion_tombstone=has_removed_codes
     *
     * @effects non_supplemented_list_settings_keep_deletion
     */
    public function test_deleted_bank_account_stays_deleted(): void
    {
        $accounts = $this->service->getSettings('order_settings')['bank_accounts'] ?? [];
        $this->assertNotEmpty($accounts, '기본 입금 계좌가 없어 삭제를 측정할 수 없습니다.');

        $reloaded = $this->saveAndReload('order_settings', 'bank_accounts', []);

        $this->assertSame([], $reloaded, '삭제한 입금 계좌가 defaults 에서 되살아났습니다.');
    }

    /**
     * 배송 가능 국가에서 항목을 삭제하면 유지된다. (통째 교체 + 라벨 보강만)
     *
     * @scenario saved_currency_set=default_removed_by_admin, deletion_tombstone=has_removed_codes
     *
     * @effects non_supplemented_list_settings_keep_deletion
     */
    public function test_deleted_shipping_country_stays_deleted(): void
    {
        $countries = $this->service->getSettings('shipping')['available_countries'] ?? [];
        $this->assertGreaterThan(1, count($countries), '기본 배송 국가 목록이 비어 있어 삭제를 측정할 수 없습니다.');

        $kept = array_values(array_slice($countries, 0, 1));
        $reloaded = $this->saveAndReload('shipping', 'available_countries', $kept);

        $this->assertCount(1, $reloaded, '삭제한 배송 국가가 defaults 에서 되살아났습니다.');
    }

    /**
     * 마일리지 통화 규칙에서 항목을 삭제하면 유지된다. (통째 교체)
     *
     * @scenario saved_currency_set=default_removed_by_admin, deletion_tombstone=has_removed_codes
     *
     * @effects non_supplemented_list_settings_keep_deletion
     */
    public function test_deleted_mileage_currency_rule_stays_deleted(): void
    {
        $rules = $this->service->getSettings('mileage')['currency_rules'] ?? [];
        $this->assertNotEmpty($rules, '기본 마일리지 통화 규칙이 없어 삭제를 측정할 수 없습니다.');

        $reloaded = $this->saveAndReload('mileage', 'currency_rules', []);

        $this->assertSame([], $reloaded, '삭제한 마일리지 통화 규칙이 defaults 에서 되살아났습니다.');
    }

    /**
     * 결제수단은 카탈로그 병합 모델이라 저장본에서 빠져도 카탈로그에서 다시 채워진다.
     *
     * 삭제 UX 가 아니라 is_active 토글 모델이므로 #91 과 다른 구조다 — 그 사실을 고정한다.
     *
     * @scenario saved_currency_set=default_removed_by_admin, deletion_tombstone=has_removed_codes
     *
     * @effects catalog_merged_list_settings_refill_by_design
     */
    public function test_payment_methods_are_catalog_merged_not_deletable(): void
    {
        $methods = $this->service->getSettings('order_settings')['payment_methods'] ?? [];
        $this->assertNotEmpty($methods, '기본 결제수단 카탈로그가 비어 있습니다.');

        $reloaded = $this->saveAndReload('order_settings', 'payment_methods', []);

        $this->assertNotEmpty($reloaded, '결제수단은 카탈로그에서 다시 채워져야 합니다(토글 모델).');
    }
}
