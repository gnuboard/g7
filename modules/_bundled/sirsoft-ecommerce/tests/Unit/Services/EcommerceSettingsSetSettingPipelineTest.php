<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Support\CurrencySettingsCache;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 단건 설정 저장(setSetting)·은행 저장(saveBanks)의 정규화 파이프라인 경유 테스트 (공개 #114)
 *
 * 두 경로는 `Arr::set` 후 카테고리 파일을 통째로 덮어써서 벌크 저장(saveSettings)의
 * 정규화 단계(분리 입력 필드 병합 / 기본 통화 동기화 / defaults 스키마 정규화 /
 * 결제수단 메타데이터 스냅샷 / 삭제 통화 기록 / 통화 캐시 무효화)를 전부 건너뛰었다.
 * 저장 파일이 서로 어긋난 상태로 남고(예: default_currency 는 USD 인데 통화 목록의
 * is_default 는 KRW), 읽기 경로의 재동기화가 그 어긋남을 가려 왔다.
 *
 * 위임 payload 는 반드시 **저장본**(loadCategorySettings) 기준이어야 한다. 읽기 결과
 * (getAllSettings)를 넘기면 조회 시점 보강분(통화 symbol/flag, 결제수단 병합 메타)이
 * 영속화되고, 삭제 통화 기록(tombstone, 공개 #91)이 재계산으로 지워져 관리자가 삭제한
 * 통화가 부활한다.
 */
class EcommerceSettingsSetSettingPipelineTest extends ModuleTestCase
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

    // ──────────────────────────────────────────────
    // 헬퍼
    // ──────────────────────────────────────────────

    /**
     * 카테고리 저장 파일을 직접 기록합니다. (저장 이전 상태 구성용)
     *
     * @param  string  $category  카테고리명
     * @param  array  $data  파일에 기록할 데이터
     */
    private function seedFile(string $category, array $data): void
    {
        File::ensureDirectoryExists($this->storagePath);
        File::put(
            $this->storagePath.'/'.$category.'.json',
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        $this->service->clearCache();
    }

    /**
     * 카테고리 저장 파일을 그대로 읽습니다.
     *
     * @param  string  $category  카테고리명
     * @return array 저장 파일의 디코드 결과 (파일 부재 시 빈 배열)
     */
    private function savedFile(string $category): array
    {
        $path = $this->storagePath.'/'.$category.'.json';

        if (! File::exists($path)) {
            return [];
        }

        return json_decode(File::get($path), true) ?? [];
    }

    /**
     * 통화 항목 배열을 만듭니다.
     *
     * @param  array<int, string>  $codes  통화 코드 목록
     * @param  string  $defaultCode  기본 통화 코드
     * @return array 통화 항목 배열
     */
    private function currencies(array $codes, string $defaultCode = 'KRW'): array
    {
        return array_map(fn (string $code) => [
            'code' => $code,
            'name' => ['ko' => $code, 'en' => $code],
            'exchange_rate' => $code === $defaultCode ? null : 1.5,
            'base_unit' => 1,
            'rounding_unit' => '0.01',
            'rounding_method' => 'round',
            'decimal_places' => 2,
            'is_default' => $code === $defaultCode,
        ], $codes);
    }

    // ──────────────────────────────────────────────
    // ① 저장본 기준 위임
    // ──────────────────────────────────────────────

    /**
     * 단건 저장은 조회 시점 보강분을 영속화하지 않고 타 카테고리 파일도 건드리지 않는다.
     *
     * @scenario pipeline_stage=stored_file_basis
     *
     * @effects read_time_enrichment_not_persisted, other_category_file_untouched
     */
    public function test_single_key_save_persists_stored_basis_only(): void
    {
        $this->seedFile('language_currency', [
            'default_currency' => 'KRW',
            'currencies' => $this->currencies(['KRW', 'USD']),
        ]);

        // 조회 보강(symbol/flag 주입)이 일어난 뒤에 저장해도 그 보강분이 파일에 남으면 안 된다
        $this->service->getAllSettings();
        $this->service->setSetting('language_currency.default_currency', 'USD');

        $saved = $this->savedFile('language_currency');

        $this->assertCount(2, $saved['currencies'] ?? [], '조회 병합으로 보충된 통화가 저장본에 영속화되었습니다.');
        foreach ($saved['currencies'] as $currency) {
            $this->assertArrayNotHasKey('flag', $currency, '표시 전용 메타(flag)가 저장본에 영속화되었습니다.');
        }

        $this->assertSame([], $this->savedFile('basic_info'), '단건 저장이 다른 카테고리 파일을 생성/변경했습니다.');
        $this->assertSame([], $this->savedFile('order_settings'), '단건 저장이 다른 카테고리 파일을 생성/변경했습니다.');
    }

    /**
     * 단건 저장이 관리자의 통화 삭제 기록(tombstone)을 지우지 않는다.
     *
     * @scenario pipeline_stage=stored_file_basis
     *
     * @effects removed_currency_tombstone_preserved
     */
    public function test_single_key_save_preserves_removed_currency_tombstone(): void
    {
        $this->seedFile('language_currency', [
            'default_currency' => 'KRW',
            'currencies' => $this->currencies(['KRW', 'USD']),
            'removed_default_currencies' => ['JPY', 'CNY', 'EUR'],
        ]);

        $this->service->setSetting('language_currency.default_currency', 'USD');

        $saved = $this->savedFile('language_currency');
        $this->assertSame(
            ['JPY', 'CNY', 'EUR'],
            $saved['removed_default_currencies'] ?? [],
            '단건 저장이 삭제 통화 기록을 훼손했습니다.'
        );

        $this->service->clearCache();
        $codes = array_map(fn ($c) => $c['code'] ?? null, $this->service->getSettings('language_currency')['currencies'] ?? []);
        $this->assertNotContains('JPY', $codes, '관리자가 삭제한 통화가 단건 저장으로 부활했습니다.');
    }

    // ──────────────────────────────────────────────
    // ② 정규화 파이프라인 경유 (실패-먼저)
    // ──────────────────────────────────────────────

    /**
     * 기본 통화를 단건으로 바꾸면 통화 목록의 is_default 도 함께 동기화된다. (실패-먼저)
     *
     * @scenario pipeline_stage=currency_default_sync
     *
     * @effects saved_file_default_currency_matches_is_default
     */
    public function test_single_key_save_syncs_currency_is_default(): void
    {
        $this->seedFile('language_currency', [
            'default_currency' => 'KRW',
            'currencies' => $this->currencies(['KRW', 'USD']),
        ]);

        $this->service->setSetting('language_currency.default_currency', 'USD');

        $saved = $this->savedFile('language_currency');
        $byCode = collect($saved['currencies'] ?? [])->keyBy('code');

        $this->assertSame('USD', $saved['default_currency'] ?? null);
        $this->assertTrue(
            (bool) ($byCode['USD']['is_default'] ?? false),
            '저장 파일의 default_currency 와 is_default 가 어긋났습니다 (정규화 파이프라인 미경유).'
        );
        $this->assertFalse(
            (bool) ($byCode['KRW']['is_default'] ?? false),
            '이전 기본 통화의 is_default 가 해제되지 않았습니다.'
        );
    }

    /**
     * 카테고리 통째 단건 저장도 분리 입력 필드 병합을 거친다. (실패-먼저)
     *
     * @scenario pipeline_stage=split_fields
     *
     * @effects split_input_fields_merged_on_single_key_save
     */
    public function test_single_key_save_merges_split_fields(): void
    {
        $this->service->setSetting('basic_info', [
            'phone_1' => '02',
            'phone_2' => '1234',
            'phone_3' => '5678',
        ]);

        $saved = $this->savedFile('basic_info');

        $this->assertSame('02-1234-5678', $saved['phone'] ?? null, '분리 입력 필드가 병합되지 않았습니다.');
        $this->assertArrayNotHasKey('phone_1', $saved, '분리 입력 필드 원본이 저장본에 남았습니다.');
    }

    /**
     * 결제수단 단건 저장도 _cached_* 스냅샷을 남기고 런타임 전용 플래그를 제거한다. (실패-먼저)
     *
     * @scenario pipeline_stage=payment_method_snapshot
     *
     * @effects payment_method_metadata_snapshotted, runtime_only_flag_not_persisted
     */
    public function test_single_key_save_snapshots_payment_method_metadata(): void
    {
        $this->service->setSetting('order_settings.payment_methods', [
            ['id' => 'card', 'is_active' => true, 'sort_order' => 1],
            ['id' => 'zombie_pay', 'is_active' => true, 'sort_order' => 2, '_orphaned' => true],
        ]);

        $saved = $this->savedFile('order_settings');
        $byId = collect($saved['payment_methods'] ?? [])->keyBy('id');

        $this->assertArrayHasKey(
            '_cached_name',
            $byId['card'] ?? [],
            '결제수단 메타데이터 스냅샷이 남지 않았습니다.'
        );
        $this->assertArrayNotHasKey(
            '_orphaned',
            $byId['zombie_pay'] ?? ['_orphaned' => true],
            '런타임 전용 플래그(_orphaned)가 저장본에 박제되었습니다.'
        );
    }

    /**
     * 단건 저장 후 요청 단위 통화 캐시가 비워져 같은 요청에서 신값이 읽힌다. (실패-먼저)
     *
     * @scenario pipeline_stage=currency_cache_flush
     *
     * @effects currency_request_cache_cleared_on_single_key_save
     */
    public function test_single_key_save_clears_currency_request_cache(): void
    {
        // 캐시 선점 (저장 전 통화 구성)
        $before = array_map(fn ($c) => $c['code'] ?? null, CurrencySettingsCache::currencies());
        $this->assertNotEmpty($before);

        $this->service->setSetting('language_currency.currencies', $this->currencies(['KRW']));

        $after = array_map(fn ($c) => $c['code'] ?? null, CurrencySettingsCache::currencies());

        $this->assertSame(
            ['KRW'],
            array_values($after),
            '단건 저장 후에도 요청 단위 통화 캐시가 저장 전 구성을 유지했습니다.'
        );
    }

    // ──────────────────────────────────────────────
    // ③ saveBanks
    // ──────────────────────────────────────────────

    /**
     * 은행 목록 저장도 정규화를 거치고 order_settings 의 다른 키를 보존한다. (실패-먼저)
     *
     * @scenario pipeline_stage=normalize_category_data
     *
     * @effects bank_name_normalized_to_multilingual, sibling_keys_preserved_on_bank_save
     */
    public function test_save_banks_normalizes_and_preserves_siblings(): void
    {
        $this->seedFile('order_settings', [
            'cash_receipt_provider' => 'keep_me',
            'banks' => [['code' => '004', 'name' => ['ko' => '국민은행', 'en' => 'Kookmin Bank']]],
        ]);

        $this->service->saveBanks([
            ['code' => '999', 'name' => '테스트은행'],
        ]);

        $saved = $this->savedFile('order_settings');

        $this->assertSame('keep_me', $saved['cash_receipt_provider'] ?? null, '은행 저장이 형제 키를 지웠습니다.');
        $this->assertIsArray(
            $saved['banks'][0]['name'] ?? null,
            '은행명이 다국어 배열로 정규화되지 않았습니다 (정규화 파이프라인 미경유).'
        );
        $this->assertSame('테스트은행', $saved['banks'][0]['name']['ko'] ?? null);
    }
}
