<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 기본 통화가 바뀌면 마일리지 통화 규칙 첫 행이 새 기본 통화를 따른다.
 *
 * 마일리지 탭 저장은 첫 행이 기본 통화여야 통과한다(`first_must_be_default`). 그런데 기본 통화를
 * 바꾸는 언어/통화 탭 저장은 마일리지 규칙을 건드리지 않았고, 화면은 첫 행의 통화 코드와 삭제를
 * 잠가 두어서, 기본 통화를 바꾼 상점은 마일리지 탭을 다시는 저장할 수 없었다(공유 개발 서버 실측:
 * 기본 통화 JPY + 규칙 [KRW] → 저장마다 422).
 *
 * 저장 경로(EcommerceSettingsService::saveSettings)가 기본 통화 행을 첫 행에 세운다 — 있던 행은
 * 앞으로 옮기고 없으면 기본값 행을 새로 만든다. 다른 통화 행은 순서대로 보존하고 중복을 만들지 않는다.
 */
class EcommerceSettingsMileageBaseCurrencyAlignTest extends ModuleTestCase
{
    private string $apiBase = '/api/modules/sirsoft-ecommerce/admin/settings';

    private User $adminUser;

    private string $storagePath;

    /**
     * 격리된 설정 저장소와 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.settings.read',
            'sirsoft-ecommerce.settings.update',
        ]);
    }

    /**
     * 테스트가 남긴 설정 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
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
     * 언어/통화 탭 저장 payload 의 통화 목록을 만듭니다.
     *
     * @param  string  $defaultCode  기본 통화 코드
     * @return array<int, array<string, mixed>> 통화 항목 배열
     */
    private function currencies(string $defaultCode): array
    {
        $meta = [
            'KRW' => ['rate' => 1300, 'dp' => 0, 'unit' => '1', 'base_unit' => 1000],
            'USD' => ['rate' => 0.85, 'dp' => 2, 'unit' => '0.01', 'base_unit' => 1],
            'JPY' => ['rate' => 115, 'dp' => 0, 'unit' => '1', 'base_unit' => 100],
        ];

        return array_map(fn (string $code) => [
            'code' => $code,
            'name' => ['ko' => $code, 'en' => $code],
            'exchange_rate' => $code === $defaultCode ? null : $meta[$code]['rate'],
            'base_unit' => $meta[$code]['base_unit'],
            'rounding_unit' => $meta[$code]['unit'],
            'rounding_method' => 'floor',
            'decimal_places' => $meta[$code]['dp'],
            'is_default' => $code === $defaultCode,
        ], array_keys($meta));
    }

    /**
     * 통화 규칙 한 줄을 만듭니다.
     *
     * @param  string  $code  통화 코드
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return array<string, mixed> 통화 규칙
     */
    private function rule(string $code, array $overrides = []): array
    {
        return array_merge([
            'currency_code' => $code,
            'point_value' => 1,
            'min_use_amount' => 1000,
            'use_unit' => 10,
            'max_use_type' => 'fixed',
            'max_use_percent' => 30,
            'max_use_value' => 50000,
            'earn_rounding_unit' => '1',
            'earn_rounding_method' => 'floor',
        ], $overrides);
    }

    /**
     * 언어/통화 탭을 저장합니다 (화면 저장 버튼과 같은 payload 모양).
     *
     * @param  string  $defaultCode  기본 통화 코드
     * @return TestResponse 응답
     */
    private function saveLanguageCurrencyTab(string $defaultCode)
    {
        return $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'language_currency',
            'language_currency' => [
                'default_currency' => $defaultCode,
                'currencies' => $this->currencies($defaultCode),
            ],
        ]);
    }

    /**
     * 마일리지 탭을 저장합니다.
     *
     * @param  array<int, array<string, mixed>>  $rules  통화 규칙
     * @return TestResponse 응답
     */
    private function saveMileageTab(array $rules)
    {
        return $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'mileage',
            'mileage' => ['enabled' => false, 'currency_rules' => $rules],
        ]);
    }

    /**
     * 저장된 마일리지 통화 규칙의 통화 코드 순서를 읽습니다.
     *
     * @return array<int, string> 통화 코드 목록
     */
    private function storedRuleCodes(): array
    {
        return array_column($this->storedRules(), 'currency_code');
    }

    /**
     * 저장된 마일리지 통화 규칙을 읽습니다.
     *
     * @return array<int, array<string, mixed>> 통화 규칙
     */
    private function storedRules(): array
    {
        $settings = app(EcommerceSettingsService::class);
        $settings->clearCache();

        return array_values((array) $settings->getSetting('mileage.currency_rules', []));
    }

    /**
     * 기본 통화 JPY 저장 상태를 파일로 직접 만듭니다 (기본값: 수정 전 버전이 남긴 어긋난 규칙 [KRW]).
     *
     * @param  array<int, array<string, mixed>>|null  $rules  저장할 통화 규칙 (null 이면 [KRW])
     * @return void 반환값 없음
     */
    private function seedMisalignedStoredState(?array $rules = null): void
    {
        File::ensureDirectoryExists($this->storagePath);
        File::put($this->storagePath.'/language_currency.json', json_encode([
            'default_currency' => 'JPY',
            'currencies' => $this->currencies('JPY'),
        ], JSON_UNESCAPED_UNICODE));
        File::put($this->storagePath.'/mileage.json', json_encode([
            'enabled' => false,
            'currency_rules' => $rules ?? [$this->rule('KRW')],
        ], JSON_UNESCAPED_UNICODE));
        app(EcommerceSettingsService::class)->clearCache();
    }

    // ──────────────────────────────────────────────
    // 기본 통화 변경
    // ──────────────────────────────────────────────

    /**
     * 규칙이 없는 통화로 기본 통화를 바꾸면 그 통화의 기본값 행이 첫 행에 생기고 옛 행은 그 뒤에 남는다.
     *
     * @scenario trigger=default_currency_changed, base_rule=absent
     *
     * @effects base_currency_rule_becomes_first_row, other_rules_preserved_without_duplicates
     *
     * @return void 반환값 없음
     */
    public function test_changing_default_currency_prepends_new_base_rule(): void
    {
        $this->saveLanguageCurrencyTab('KRW')->assertOk();
        $this->saveMileageTab([$this->rule('KRW'), $this->rule('USD', ['min_use_amount' => 5])])->assertOk();

        $this->saveLanguageCurrencyTab('JPY')->assertOk();

        $this->assertSame(['JPY', 'KRW', 'USD'], $this->storedRuleCodes());

        $rules = $this->storedRules();
        // 새 행은 화면의 「통화 추가」와 같은 기본값이다 — 다른 통화의 금액 단위를 옮겨 오지 않는다
        $this->assertSame(0, (int) $rules[0]['min_use_amount']);
        $this->assertSame(1, (int) $rules[0]['use_unit']);
        $this->assertSame('percent', $rules[0]['max_use_type']);
        $this->assertSame('1', (string) $rules[0]['earn_rounding_unit']);
        $this->assertSame('floor', $rules[0]['earn_rounding_method']);
        // 옛 행 값은 그대로다
        $this->assertSame(1000, (int) $rules[1]['min_use_amount']);
        $this->assertSame(5, (int) $rules[2]['min_use_amount']);
    }

    /**
     * 이미 규칙이 있는 통화로 기본 통화를 바꾸면 그 행을 첫 행으로 옮길 뿐 새로 만들지 않는다.
     *
     * @scenario trigger=default_currency_changed, base_rule=present_not_first
     *
     * @effects base_currency_rule_becomes_first_row, other_rules_preserved_without_duplicates
     *
     * @return void 반환값 없음
     */
    public function test_changing_default_currency_moves_existing_rule_first(): void
    {
        $this->saveLanguageCurrencyTab('KRW')->assertOk();
        $this->saveMileageTab([$this->rule('KRW'), $this->rule('USD', ['min_use_amount' => 5])])->assertOk();

        $this->saveLanguageCurrencyTab('USD')->assertOk();

        $this->assertSame(['USD', 'KRW'], $this->storedRuleCodes());
        $this->assertSame(5, (int) $this->storedRules()[0]['min_use_amount'], '옮긴 행의 값이 보존되지 않았다');
    }

    /**
     * 기본 통화를 바꾼 뒤에도 마일리지 탭을 저장할 수 있다 (종전: 첫 행 불일치로 항상 422).
     *
     * @scenario trigger=default_currency_changed, base_rule=absent
     *
     * @effects mileage_tab_saves_after_default_currency_change
     *
     * @return void 반환값 없음
     */
    public function test_mileage_tab_saves_after_default_currency_change(): void
    {
        $this->saveLanguageCurrencyTab('KRW')->assertOk();
        $this->saveMileageTab([$this->rule('KRW')])->assertOk();
        $this->saveLanguageCurrencyTab('JPY')->assertOk();

        // 화면은 저장 뒤 다시 읽은 규칙을 그대로 보낸다
        $rules = $this->storedRules();
        $rules[0]['earn_rounding_unit'] = '10';

        $this->saveMileageTab($rules)->assertOk();
        $this->assertSame('10', (string) $this->storedRules()[0]['earn_rounding_unit']);
    }

    // ──────────────────────────────────────────────
    // 이미 어긋난 저장 상태의 복구
    // ──────────────────────────────────────────────

    /**
     * 수정 전에 어긋난 채 저장된 상태도 언어/통화 탭을 한 번 저장하면 맞춰진다.
     *
     * @scenario trigger=language_currency_resaved, base_rule=absent
     *
     * @effects misaligned_stored_state_recovers_on_save, other_rules_preserved_without_duplicates
     *
     * @return void 반환값 없음
     */
    public function test_resaving_language_currency_recovers_misaligned_state(): void
    {
        $this->seedMisalignedStoredState();
        $this->assertSame(['KRW'], $this->storedRuleCodes());

        $this->saveLanguageCurrencyTab('JPY')->assertOk();

        $this->assertSame(['JPY', 'KRW'], $this->storedRuleCodes());
    }

    /**
     * 어긋난 상태에서 화면이 기본 통화 행을 첫 행에 세워 보낸 마일리지 저장은 통과한다.
     *
     * 어긋난 채로 그대로 보낸 저장은 지금도 422 다 — 서버가 몰래 고쳐 저장하지 않고, 화면이
     * 어긋남을 알리고 복구 단추로 운영자가 맞추게 한다.
     *
     * @scenario trigger=mileage_tab_saved, base_rule=absent
     *
     * @effects misaligned_stored_state_recovers_on_save
     *
     * @return void 반환값 없음
     */
    public function test_mileage_save_recovers_when_base_rule_put_first(): void
    {
        $this->seedMisalignedStoredState();

        $this->saveMileageTab([$this->rule('KRW')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mileage.currency_rules.0.currency_code');

        $this->saveMileageTab([$this->rule('JPY', ['min_use_amount' => 0]), $this->rule('KRW')])->assertOk();
        $this->assertSame(['JPY', 'KRW'], $this->storedRuleCodes());
    }

    /**
     * 서비스 직접 저장(검증을 거치지 않는 경로)도 기본 통화 행을 첫 행에 둔다.
     *
     * @scenario trigger=mileage_tab_saved, base_rule=present_not_first
     *
     * @effects base_currency_rule_becomes_first_row, other_rules_preserved_without_duplicates
     *
     * @return void 반환값 없음
     */
    public function test_direct_service_save_keeps_base_rule_first(): void
    {
        $this->seedMisalignedStoredState();

        app(EcommerceSettingsService::class)->saveSettings([
            'mileage' => ['enabled' => false, 'currency_rules' => [$this->rule('KRW'), $this->rule('JPY', ['use_unit' => 7])]],
        ]);

        $this->assertSame(['JPY', 'KRW'], $this->storedRuleCodes());
        $this->assertSame(7, (int) $this->storedRules()[0]['use_unit']);
    }

    /**
     * 규칙이 비어 있으면 맞출 것이 없다 — 빈 목록에 행을 만들어 마일리지 사용을 몰래 켜지 않는다.
     *
     * @scenario trigger=default_currency_changed, base_rule=no_rules
     *
     * @effects empty_rules_stay_empty
     *
     * @return void 반환값 없음
     */
    public function test_empty_rules_stay_empty(): void
    {
        $this->saveLanguageCurrencyTab('KRW')->assertOk();
        $this->saveMileageTab([])->assertOk();
        File::put($this->storagePath.'/mileage.json', json_encode(['enabled' => false, 'currency_rules' => []]));

        $this->saveLanguageCurrencyTab('JPY')->assertOk();

        $this->assertSame([], $this->storedRuleCodes());
    }

    /**
     * 언어/통화 탭을 다시 저장할 때 기본 통화 행이 뒤에 있으면 앞으로 옮긴다(값 보존, 새 행 없음).
     *
     * @scenario trigger=language_currency_resaved, base_rule=present_not_first
     *
     * @effects misaligned_stored_state_recovers_on_save, other_rules_preserved_without_duplicates
     *
     * @return void 반환값 없음
     */
    public function test_resaving_language_currency_moves_trailing_base_rule(): void
    {
        $this->seedMisalignedStoredState([$this->rule('KRW'), $this->rule('JPY', ['use_unit' => 7])]);

        $this->saveLanguageCurrencyTab('JPY')->assertOk();

        $this->assertSame(['JPY', 'KRW'], $this->storedRuleCodes());
        $this->assertSame(7, (int) $this->storedRules()[0]['use_unit']);
    }

    /**
     * 이미 맞는 규칙은 언어/통화 탭을 다시 저장해도 그대로다.
     *
     * @scenario trigger=language_currency_resaved, base_rule=first
     *
     * @effects base_currency_rule_becomes_first_row, other_rules_preserved_without_duplicates
     *
     * @return void 반환값 없음
     */
    public function test_resaving_language_currency_keeps_aligned_rules(): void
    {
        $this->seedMisalignedStoredState([$this->rule('JPY', ['use_unit' => 3]), $this->rule('KRW')]);

        $this->saveLanguageCurrencyTab('JPY')->assertOk();

        $this->assertSame(['JPY', 'KRW'], $this->storedRuleCodes());
        $this->assertSame(3, (int) $this->storedRules()[0]['use_unit']);
    }

    /**
     * 빈 규칙은 언어/통화 탭을 다시 저장해도 비어 있다.
     *
     * @scenario trigger=language_currency_resaved, base_rule=no_rules
     *
     * @effects empty_rules_stay_empty
     *
     * @return void 반환값 없음
     */
    public function test_resaving_language_currency_keeps_empty_rules(): void
    {
        $this->seedMisalignedStoredState([]);

        $this->saveLanguageCurrencyTab('JPY')->assertOk();

        $this->assertSame([], $this->storedRuleCodes());
    }

    /**
     * 첫 행이 기본 통화인 마일리지 저장은 순서를 바꾸지 않는다.
     *
     * @scenario trigger=mileage_tab_saved, base_rule=first
     *
     * @effects base_currency_rule_becomes_first_row, other_rules_preserved_without_duplicates
     *
     * @return void 반환값 없음
     */
    public function test_mileage_save_with_base_first_keeps_order(): void
    {
        $this->seedMisalignedStoredState();

        $this->saveMileageTab([$this->rule('JPY'), $this->rule('USD'), $this->rule('KRW')])->assertOk();

        $this->assertSame(['JPY', 'USD', 'KRW'], $this->storedRuleCodes());
    }

    /**
     * 빈 규칙의 마일리지 저장은 행을 만들지 않는다.
     *
     * @scenario trigger=mileage_tab_saved, base_rule=no_rules
     *
     * @effects empty_rules_stay_empty
     *
     * @return void 반환값 없음
     */
    public function test_mileage_save_with_empty_rules_stays_empty(): void
    {
        $this->seedMisalignedStoredState();

        $this->saveMileageTab([])->assertOk();

        $this->assertSame([], $this->storedRuleCodes());
    }
}
