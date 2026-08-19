<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 기본 제공 통화의 의도적 삭제 영속화 테스트 (공개 #91)
 *
 * U11-A 는 array_merge 통째 교체로 defaults 통화가 소실되던 문제를 code 기준 보충으로 막았으나,
 * "부작용 소실" 과 "관리자 의도 삭제" 를 구분할 장치가 없어 의도 삭제까지 되돌렸다.
 * 저장 시점에 삭제 의도를 서버가 도출해 `removed_default_currencies` 로 기록하고,
 * 조회 병합이 그 기록을 존중하는지 검증한다.
 */
class EcommerceSettingsServiceCurrencyTombstoneTest extends ModuleTestCase
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
     * 통화 코드 목록으로 저장 payload 의 currencies 배열을 만듭니다.
     *
     * @param  array<int, string>  $codes  통화 코드 목록
     * @param  string  $defaultCode  기본 통화 코드
     * @return array 통화 항목 배열
     */
    private function currencies(array $codes, string $defaultCode = 'KRW'): array
    {
        $meta = [
            'KRW' => ['rate' => null, 'unit' => '1', 'method' => 'floor', 'dp' => 0, 'base_unit' => 1000],
            'USD' => ['rate' => 0.85, 'unit' => '0.01', 'method' => 'round', 'dp' => 2, 'base_unit' => 1],
            'JPY' => ['rate' => 115, 'unit' => '1', 'method' => 'floor', 'dp' => 0, 'base_unit' => 100],
            'CNY' => ['rate' => 5.8, 'unit' => '0.01', 'method' => 'round', 'dp' => 2, 'base_unit' => 1],
            'EUR' => ['rate' => 0.78, 'unit' => '0.01', 'method' => 'round', 'dp' => 2, 'base_unit' => 1],
            'GBP' => ['rate' => 0.6, 'unit' => '0.01', 'method' => 'round', 'dp' => 2, 'base_unit' => 1],
        ];

        return array_map(function (string $code) use ($meta, $defaultCode) {
            $m = $meta[$code] ?? ['rate' => 1.0, 'unit' => '0.01', 'method' => 'round', 'dp' => 2, 'base_unit' => 1];

            return [
                'code' => $code,
                'name' => ['ko' => $code, 'en' => $code],
                'exchange_rate' => $code === $defaultCode ? null : $m['rate'],
                'base_unit' => $m['base_unit'],
                'rounding_unit' => $m['unit'],
                'rounding_method' => $m['method'],
                'decimal_places' => $m['dp'],
                'is_default' => $code === $defaultCode,
            ];
        }, $codes);
    }

    /**
     * 관리자 저장 경로(saveSettings)로 language_currency 를 저장합니다.
     *
     * @param  array  $languageCurrency  language_currency 카테고리 payload
     */
    private function save(array $languageCurrency): void
    {
        $this->service->saveSettings(['language_currency' => $languageCurrency]);
        $this->service->clearCache();
    }

    /**
     * 저장된 language_currency.json 을 그대로 읽습니다.
     *
     * @return array 저장 파일의 디코드 결과
     */
    private function savedFile(): array
    {
        $path = $this->storagePath.'/language_currency.json';

        if (! File::exists($path)) {
            return [];
        }

        return json_decode(File::get($path), true) ?? [];
    }

    /**
     * 현재 조회 결과의 통화 코드 목록을 반환합니다.
     *
     * @return array<int, string> 통화 코드 목록
     */
    private function resolvedCodes(): array
    {
        $lc = $this->service->getSettings('language_currency');

        return array_values(array_map(fn ($c) => $c['code'] ?? null, $lc['currencies'] ?? []));
    }

    // ──────────────────────────────────────────────
    // 삭제 영속화 (핵심)
    // ──────────────────────────────────────────────

    /**
     * 관리자가 삭제한 기본 제공 통화가 저장 후에도 되살아나지 않는다.
     *
     * @scenario saved_currency_set=default_removed_by_admin, deletion_tombstone=has_removed_codes
     *
     * @effects intentionally_removed_default_currency_stays_deleted
     */
    public function test_deleted_default_currency_stays_deleted_after_save(): void
    {
        $this->save([
            'default_currency' => 'KRW',
            'currencies' => $this->currencies(['KRW', 'USD']),
        ]);

        $codes = $this->resolvedCodes();

        $this->assertContains('KRW', $codes);
        $this->assertContains('USD', $codes);
        $this->assertNotContains('JPY', $codes, '관리자가 삭제한 JPY 가 defaults 에서 되살아났습니다.');
        $this->assertNotContains('CNY', $codes, '관리자가 삭제한 CNY 가 defaults 에서 되살아났습니다.');
        $this->assertNotContains('EUR', $codes, '관리자가 삭제한 EUR 가 defaults 에서 되살아났습니다.');
    }

    /**
     * 삭제 의도가 저장 파일에 tombstone 으로 기록된다.
     *
     * @scenario saved_currency_set=default_removed_by_admin, deletion_tombstone=has_removed_codes
     *
     * @effects tombstone_recomputed_server_side_on_save
     */
    public function test_tombstone_written_to_saved_file(): void
    {
        $this->save([
            'default_currency' => 'KRW',
            'currencies' => $this->currencies(['KRW', 'USD']),
        ]);

        $saved = $this->savedFile();

        $this->assertArrayHasKey('removed_default_currencies', $saved, '삭제 기록이 저장되지 않았습니다.');
        $this->assertSame(['JPY', 'CNY', 'EUR'], $saved['removed_default_currencies']);
        // 비연속 키가 JSON 객체로 직렬화되지 않도록 array_values 재정렬 (코딩 규약)
        $this->assertSame([0, 1, 2], array_keys($saved['removed_default_currencies']));
    }

    /**
     * 기본 통화 코드는 제출에서 빠져도 tombstone 되지 않는다.
     *
     * @scenario saved_currency_set=default_removed_by_admin, deletion_tombstone=has_removed_codes
     *
     * @effects base_currency_never_tombstoned
     */
    public function test_default_currency_code_never_tombstoned(): void
    {
        // API 직접 제출로 기본 통화(KRW)가 빠진 목록을 보낸 상황
        $this->save([
            'default_currency' => 'KRW',
            'currencies' => $this->currencies(['USD'], 'USD'),
        ]);

        $saved = $this->savedFile();
        $this->assertNotContains('KRW', $saved['removed_default_currencies'] ?? [], '기본 통화가 tombstone 되었습니다.');

        $codes = $this->resolvedCodes();
        $this->assertContains('KRW', $codes, '기본 통화가 조회 결과에서 사라졌습니다.');

        $lc = $this->service->getSettings('language_currency');
        $krw = collect($lc['currencies'])->firstWhere('code', 'KRW');
        $this->assertTrue($krw['is_default'], '기본 통화의 is_default 가 유지되어야 합니다.');
    }

    /**
     * 삭제한 통화를 다시 추가하면 tombstone 이 해제되고 복원된다.
     *
     * @scenario saved_currency_set=default_removed_by_admin, deletion_tombstone=readded
     *
     * @effects intentionally_removed_default_currency_stays_deleted, tombstone_recomputed_server_side_on_save
     */
    public function test_readding_currency_clears_tombstone_and_resurrects(): void
    {
        $this->save([
            'default_currency' => 'KRW',
            'currencies' => $this->currencies(['KRW']),
        ]);
        $this->assertSame(['USD', 'JPY', 'CNY', 'EUR'], $this->savedFile()['removed_default_currencies'] ?? []);

        // USD 재등록
        $this->save([
            'default_currency' => 'KRW',
            'currencies' => $this->currencies(['KRW', 'USD']),
        ]);

        $codes = $this->resolvedCodes();
        $this->assertContains('USD', $codes, '재추가한 통화가 복원되지 않았습니다.');
        $this->assertSame(['JPY', 'CNY', 'EUR'], $this->savedFile()['removed_default_currencies'] ?? []);
    }

    /**
     * 관리자가 추가한 통화(defaults 밖)의 삭제는 종전대로 유지된다.
     *
     * @scenario saved_currency_set=default_removed_by_admin, deletion_tombstone=has_removed_codes
     *
     * @effects intentionally_removed_default_currency_stays_deleted
     */
    public function test_admin_added_currency_still_deletable(): void
    {
        $this->save([
            'default_currency' => 'KRW',
            'currencies' => $this->currencies(['KRW', 'GBP']),
        ]);
        $this->assertContains('GBP', $this->resolvedCodes());

        $this->save([
            'default_currency' => 'KRW',
            'currencies' => $this->currencies(['KRW']),
        ]);

        $this->assertNotContains('GBP', $this->resolvedCodes(), '관리자 추가 통화의 삭제가 유지되지 않았습니다.');
        // defaults 밖 통화는 tombstone 대상이 아니다 (보충 병합이 되살리지 않으므로 기록 불필요)
        $this->assertNotContains('GBP', $this->savedFile()['removed_default_currencies'] ?? []);
    }

    /**
     * 다른 탭 저장은 language_currency 의 삭제 상태를 되돌리지 않는다.
     *
     * @scenario saved_currency_set=default_removed_by_admin, deletion_tombstone=has_removed_codes
     *
     * @effects other_category_save_preserves_tombstone
     */
    public function test_other_category_save_preserves_tombstone(): void
    {
        $this->save([
            'default_currency' => 'KRW',
            'currencies' => $this->currencies(['KRW']),
        ]);
        $before = $this->savedFile();

        // 기본정보 탭만 저장
        $this->service->saveSettings(['basic_info' => ['shop_name' => ['ko' => '테스트샵', 'en' => 'Test Shop']]]);
        $this->service->clearCache();

        $this->assertSame($before, $this->savedFile(), '타 카테고리 저장이 language_currency 파일을 변경했습니다.');
        $this->assertSame(['KRW'], $this->resolvedCodes());
    }

    /**
     * currencies 키 없이 language_currency 를 저장해도 tombstone 이 이월된다.
     *
     * saveCategorySettings 는 파일 통째 교체라 이월하지 않으면 삭제가 전부 부활한다.
     *
     * @scenario saved_currency_set=default_removed_by_admin, deletion_tombstone=has_removed_codes
     *
     * @effects other_category_save_preserves_tombstone
     */
    public function test_language_currency_save_without_currencies_preserves_tombstone(): void
    {
        $this->save([
            'default_currency' => 'KRW',
            'currencies' => $this->currencies(['KRW']),
        ]);

        // default_currency 만 담긴 저장 (currencies 키 부재)
        $this->save(['default_currency' => 'KRW']);

        $this->assertSame(['USD', 'JPY', 'CNY', 'EUR'], $this->savedFile()['removed_default_currencies'] ?? []);
        $this->assertSame(['KRW'], $this->resolvedCodes(), 'currencies 미제출 저장이 삭제 통화를 부활시켰습니다.');
    }

    /**
     * tombstone 키가 없는 구 저장본은 U11-A 보충 동작을 그대로 유지한다.
     *
     * @scenario saved_currency_set=jpy_missing, deletion_tombstone=none_legacy
     *
     * @effects legacy_file_without_tombstone_keeps_supplement
     */
    public function test_legacy_saved_file_without_tombstone_supplements_defaults(): void
    {
        File::ensureDirectoryExists($this->storagePath);
        File::put(
            $this->storagePath.'/language_currency.json',
            json_encode([
                'default_currency' => 'KRW',
                'currencies' => $this->currencies(['KRW', 'USD']),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        $this->service->clearCache();
        $codes = $this->resolvedCodes();

        $this->assertContains('JPY', $codes, '구 저장본의 defaults 보충(U11-A)이 깨졌습니다.');
        $this->assertContains('CNY', $codes);
        $this->assertContains('EUR', $codes);
    }

    /**
     * 클라이언트가 보낸 tombstone 값은 무시되고 서버가 재계산한다.
     *
     * @scenario saved_currency_set=default_removed_by_admin, deletion_tombstone=has_removed_codes
     *
     * @effects tombstone_recomputed_server_side_on_save
     */
    public function test_client_supplied_tombstone_is_recomputed(): void
    {
        $this->save([
            'default_currency' => 'KRW',
            'currencies' => $this->currencies(['KRW', 'USD', 'JPY', 'CNY', 'EUR']),
            'removed_default_currencies' => ['KRW', 'USD'],
        ]);

        $this->assertSame([], $this->savedFile()['removed_default_currencies'] ?? null, '클라이언트 값이 그대로 저장되었습니다.');

        $codes = $this->resolvedCodes();
        $this->assertContains('KRW', $codes);
        $this->assertContains('USD', $codes);
    }

    /**
     * tombstone 은 프론트엔드 공개 설정에 노출되지 않는다.
     *
     * @scenario saved_currency_set=default_removed_by_admin, deletion_tombstone=has_removed_codes
     *
     * @effects tombstone_recomputed_server_side_on_save
     */
    public function test_tombstone_not_exposed_in_frontend_settings(): void
    {
        $this->save([
            'default_currency' => 'KRW',
            'currencies' => $this->currencies(['KRW']),
        ]);

        $frontend = $this->service->getFrontendSettings();

        $this->assertArrayHasKey('language_currency', $frontend);
        $this->assertArrayNotHasKey(
            'removed_default_currencies',
            $frontend['language_currency'],
            '내부 삭제 기록이 공개 설정에 노출되었습니다.'
        );
    }
}
