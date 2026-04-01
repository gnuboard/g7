<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use ReflectionClass;
use Tests\TestCase;

/**
 * 이커머스 모듈 주문설정(order_settings) 카테고리 테스트
 *
 * - 기본값 로딩
 * - getSetting() 조회
 * - 결제수단 병합 (builtin + 플러그인 + 사용자 저장)
 * - 고아 결제수단 감지
 * - _cached_* 메타데이터 스냅샷
 * - payment → order_settings 마이그레이션
 * - bank_accounts CRUD
 * - getFrontendSettings() bank_name 해석
 */
class EcommerceSettingsOrderSettingsTest extends TestCase
{
    private EcommerceSettingsService $service;

    private string $storagePath;

    /**
     * 테스트에서 등록한 필터 콜백 (tearDown에서 제거용)
     */
    private array $registeredFilterCallbacks = [];

    /**
     * 원본 HookManager filters 백업 (다른 테스트의 static state 오염 방지)
     */
    private array $originalFilters = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = storage_path('app/modules/sirsoft-ecommerce/settings');

        // 다른 테스트에서 누적된 HookManager static filters 백업 후 초기화
        $ref = new ReflectionClass(HookManager::class);
        $prop = $ref->getProperty('filters');
        $this->originalFilters = $prop->getValue();
        $prop->setValue(null, []);

        // 설정 디렉토리 초기화
        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }

        $this->service = new EcommerceSettingsService;
    }

    protected function tearDown(): void
    {
        // 테스트용 설정 파일 정리
        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }

        // HookManager filters를 원본으로 복원
        $ref = new ReflectionClass(HookManager::class);
        $prop = $ref->getProperty('filters');
        $prop->setValue(null, $this->originalFilters);

        $this->registeredFilterCallbacks = [];

        parent::tearDown();
    }

    /**
     * 결제수단 필터를 등록합니다.
     */
    private function addPaymentMethodFilter(callable $callback): void
    {
        HookManager::addFilter(
            'sirsoft-ecommerce.settings.filter_available_payment_methods',
            $callback
        );
    }

    // ──────────────────────────────────────────────
    // 기본값 로딩
    // ──────────────────────────────────────────────

    public function test_defaults_include_order_settings_category(): void
    {
        $settings = $this->service->getAllSettings();

        $this->assertArrayHasKey('order_settings', $settings);
    }

    public function test_order_settings_default_values(): void
    {
        $settings = $this->service->getSettings('order_settings');

        $this->assertTrue($settings['auto_cancel_expired']);
        $this->assertEquals(3, $settings['auto_cancel_days']);
        $this->assertEquals(3, $settings['vbank_due_days']);
        $this->assertEquals(7, $settings['dbank_due_days']);
        $this->assertEquals(30, $settings['cart_expiry_days']);
        $this->assertTrue($settings['stock_restore_on_cancel']);
    }

    public function test_default_banks_loaded(): void
    {
        $settings = $this->service->getSettings('order_settings');

        $this->assertArrayHasKey('banks', $settings);
        $this->assertCount(17, $settings['banks']);
        $this->assertEquals('004', $settings['banks'][0]['code']);
        $this->assertEquals('국민은행', $settings['banks'][0]['name']['ko']);
    }

    public function test_default_bank_accounts_loaded(): void
    {
        $settings = $this->service->getSettings('order_settings');

        $this->assertArrayHasKey('bank_accounts', $settings);
        $this->assertCount(1, $settings['bank_accounts']);
        $this->assertEquals('004', $settings['bank_accounts'][0]['bank_code']);
        $this->assertFalse($settings['bank_accounts'][0]['is_default']);
    }

    // ──────────────────────────────────────────────
    // getSetting() 조회
    // ──────────────────────────────────────────────

    public function test_get_setting_with_dot_notation(): void
    {
        $this->assertTrue(
            $this->service->getSetting('order_settings.auto_cancel_expired', false)
        );

        $this->assertEquals(
            7,
            $this->service->getSetting('order_settings.dbank_due_days')
        );
    }

    public function test_get_setting_returns_default_for_missing_key(): void
    {
        $this->assertEquals(
            'fallback',
            $this->service->getSetting('order_settings.nonexistent_key', 'fallback')
        );
    }

    // ──────────────────────────────────────────────
    // 결제수단 병합 (builtin)
    // ──────────────────────────────────────────────

    public function test_default_builtin_payment_methods(): void
    {
        $settings = $this->service->getSettings('order_settings');
        $methods = $settings['payment_methods'];

        $this->assertCount(8, $methods);

        $ids = array_column($methods, 'id');
        $this->assertEquals(['card', 'vbank', 'dbank', 'bank', 'phone', 'point', 'deposit', 'free'], $ids);
    }

    public function test_builtin_payment_methods_have_cached_metadata(): void
    {
        $settings = $this->service->getSettings('order_settings');
        $dbank = collect($settings['payment_methods'])->firstWhere('id', 'dbank');

        $this->assertEquals(['ko' => '무통장입금', 'en' => 'Bank Transfer'], $dbank['_cached_name']);
        $this->assertEquals('building-columns', $dbank['_cached_icon']);
        $this->assertEquals('builtin', $dbank['_cached_source']);
    }

    // ──────────────────────────────────────────────
    // 결제수단 병합 (플러그인 필터)
    // ──────────────────────────────────────────────

    public function test_plugin_payment_method_added_via_filter(): void
    {
        // 플러그인이 결제수단을 추가하는 시나리오
        $this->addPaymentMethodFilter(function (array $methods) {
            $methods[] = [
                'id' => 'tosspayments',
                'name' => ['ko' => '토스페이먼츠', 'en' => 'Toss Payments'],
                'description' => ['ko' => '토스 간편결제', 'en' => 'Toss Easy Pay'],
                'icon' => 'credit-card',
                'source' => 'plugin:sirsoft-tosspayments',
                'defaults' => [
                    'is_active' => false,
                    'min_order_amount' => 0,
                    'stock_deduction_timing' => 'payment_complete',
                ],
            ];

            return $methods;
        });

        // 캐시 초기화 후 재조회
        $this->service->clearCache();
        $settings = $this->service->getSettings('order_settings');
        $methods = $settings['payment_methods'];

        $this->assertCount(9, $methods); // 8 builtin + 1 plugin

        $toss = collect($methods)->firstWhere('id', 'tosspayments');
        $this->assertNotNull($toss);
        $this->assertEquals(['ko' => '토스페이먼츠', 'en' => 'Toss Payments'], $toss['_cached_name']);
        $this->assertEquals('plugin:sirsoft-tosspayments', $toss['_cached_source']);
        $this->assertFalse($toss['is_active']); // 기본값: 비활성
    }

    // ──────────────────────────────────────────────
    // 결제수단 병합 (사용자 저장 설정 오버라이드)
    // ──────────────────────────────────────────────

    public function test_user_settings_override_payment_method_defaults(): void
    {
        // 사용자가 결제수단 순서를 변경하고 dbank를 비활성화한 설정 저장
        $savedSettings = [
            'payment_methods' => [
                ['id' => 'point', 'sort_order' => 1, 'is_active' => true, 'min_order_amount' => 0, 'stock_deduction_timing' => 'payment_complete'],
                ['id' => 'dbank', 'sort_order' => 2, 'is_active' => false, 'min_order_amount' => 10000, 'stock_deduction_timing' => 'order_placed'],
                ['id' => 'deposit', 'sort_order' => 3, 'is_active' => true, 'min_order_amount' => 5000, 'stock_deduction_timing' => 'payment_complete'],
                ['id' => 'card', 'sort_order' => 4, 'is_active' => false, 'min_order_amount' => 0, 'stock_deduction_timing' => 'payment_complete'],
                ['id' => 'vbank', 'sort_order' => 5, 'is_active' => false, 'min_order_amount' => 0, 'stock_deduction_timing' => 'payment_complete'],
                ['id' => 'bank', 'sort_order' => 6, 'is_active' => false, 'min_order_amount' => 0, 'stock_deduction_timing' => 'payment_complete'],
                ['id' => 'phone', 'sort_order' => 7, 'is_active' => false, 'min_order_amount' => 0, 'stock_deduction_timing' => 'payment_complete'],
                ['id' => 'free', 'sort_order' => 8, 'is_active' => false, 'min_order_amount' => 0, 'stock_deduction_timing' => 'order_placed'],
            ],
        ];

        $this->saveOrderSettings($savedSettings);
        $this->service->clearCache();

        $settings = $this->service->getSettings('order_settings');
        $methods = $settings['payment_methods'];

        // sort_order 순 정렬 확인 (point=1, dbank=2, deposit=3, ...)
        $this->assertCount(8, $methods);
        $this->assertEquals('point', $methods[0]['id']);
        $this->assertEquals('dbank', $methods[1]['id']);
        $this->assertEquals('deposit', $methods[2]['id']);

        // 사용자 오버라이드 확인
        $dbank = collect($methods)->firstWhere('id', 'dbank');
        $this->assertFalse($dbank['is_active']);
        $this->assertEquals(10000, $dbank['min_order_amount']);
    }

    // ──────────────────────────────────────────────
    // 고아 결제수단 감지
    // ──────────────────────────────────────────────

    public function test_orphaned_payment_method_detected(): void
    {
        // 이전에 tosspayments 플러그인이 있었지만 지금은 비활성화된 시나리오
        $savedSettings = [
            'payment_methods' => [
                ['id' => 'card', 'sort_order' => 1, 'is_active' => false, 'min_order_amount' => 0, 'stock_deduction_timing' => 'payment_complete'],
                ['id' => 'vbank', 'sort_order' => 2, 'is_active' => false, 'min_order_amount' => 0, 'stock_deduction_timing' => 'payment_complete'],
                ['id' => 'dbank', 'sort_order' => 3, 'is_active' => true, 'min_order_amount' => 0, 'stock_deduction_timing' => 'order_placed'],
                ['id' => 'bank', 'sort_order' => 4, 'is_active' => false, 'min_order_amount' => 0, 'stock_deduction_timing' => 'payment_complete'],
                ['id' => 'phone', 'sort_order' => 5, 'is_active' => false, 'min_order_amount' => 0, 'stock_deduction_timing' => 'payment_complete'],
                ['id' => 'point', 'sort_order' => 6, 'is_active' => true, 'min_order_amount' => 0, 'stock_deduction_timing' => 'payment_complete'],
                ['id' => 'deposit', 'sort_order' => 7, 'is_active' => true, 'min_order_amount' => 0, 'stock_deduction_timing' => 'payment_complete'],
                ['id' => 'free', 'sort_order' => 8, 'is_active' => false, 'min_order_amount' => 0, 'stock_deduction_timing' => 'order_placed'],
                [
                    'id' => 'tosspayments',
                    'sort_order' => 9,
                    'is_active' => true,
                    'min_order_amount' => 0,
                    'stock_deduction_timing' => 'payment_complete',
                    '_cached_name' => ['ko' => '토스페이먼츠', 'en' => 'Toss Payments'],
                    '_cached_icon' => 'credit-card',
                    '_cached_source' => 'plugin:sirsoft-tosspayments',
                ],
            ],
        ];

        $this->saveOrderSettings($savedSettings);
        $this->service->clearCache();

        // 플러그인 필터를 등록하지 않음 → tosspayments는 available에 없음
        $settings = $this->service->getSettings('order_settings');
        $methods = $settings['payment_methods'];

        $this->assertCount(9, $methods); // 8 builtin + 1 orphan

        $toss = collect($methods)->firstWhere('id', 'tosspayments');
        $this->assertNotNull($toss);
        $this->assertTrue($toss['_orphaned'] ?? false);

        // 고아 항목은 목록 끝에 배치
        $lastMethod = end($methods);
        $this->assertEquals('tosspayments', $lastMethod['id']);
    }

    // ──────────────────────────────────────────────
    // _cached_* 메타데이터 스냅샷
    // ──────────────────────────────────────────────

    public function test_cached_metadata_snapshot_on_save(): void
    {
        // 플러그인 결제수단 추가
        $this->addPaymentMethodFilter(function (array $methods) {
            $methods[] = [
                'id' => 'kakaopay',
                'name' => ['ko' => '카카오페이', 'en' => 'Kakao Pay'],
                'description' => ['ko' => '카카오페이 결제', 'en' => 'Kakao Pay Payment'],
                'icon' => 'mobile',
                'source' => 'plugin:sirsoft-kakaopay',
                'defaults' => ['is_active' => true, 'min_order_amount' => 0, 'stock_deduction_timing' => 'payment_complete'],
            ];

            return $methods;
        });

        // 저장 시 _cached_* 스냅샷 확인
        $this->service->clearCache();
        $this->service->saveSettings([
            'order_settings' => [
                'payment_methods' => [
                    ['id' => 'dbank', 'sort_order' => 1, 'is_active' => true, 'min_order_amount' => 0, 'stock_deduction_timing' => 'order_placed'],
                    ['id' => 'point', 'sort_order' => 2, 'is_active' => true, 'min_order_amount' => 0, 'stock_deduction_timing' => 'payment_complete'],
                    ['id' => 'deposit', 'sort_order' => 3, 'is_active' => true, 'min_order_amount' => 0, 'stock_deduction_timing' => 'payment_complete'],
                    ['id' => 'kakaopay', 'sort_order' => 4, 'is_active' => true, 'min_order_amount' => 0, 'stock_deduction_timing' => 'payment_complete'],
                ],
                'auto_cancel_expired' => true,
                'auto_cancel_days' => 3,
                'vbank_due_days' => 3,
                'dbank_due_days' => 7,
                'cart_expiry_days' => 30,
                'stock_restore_on_cancel' => true,
            ],
        ]);

        // 저장된 파일에서 직접 읽어 _cached_* 확인
        $saved = json_decode(File::get($this->storagePath.'/order_settings.json'), true);
        $kakaopay = collect($saved['payment_methods'])->firstWhere('id', 'kakaopay');

        $this->assertEquals(['ko' => '카카오페이', 'en' => 'Kakao Pay'], $kakaopay['_cached_name']);
        $this->assertEquals('mobile', $kakaopay['_cached_icon']);
        $this->assertEquals('plugin:sirsoft-kakaopay', $kakaopay['_cached_source']);

        // _orphaned 플래그는 저장되지 않아야 함
        $this->assertArrayNotHasKey('_orphaned', $kakaopay);
    }

    // ──────────────────────────────────────────────
    // payment → order_settings 마이그레이션
    // ──────────────────────────────────────────────

    public function test_migrate_payment_to_order_settings(): void
    {
        // 기존 payment.json 생성 (레거시 형식)
        $paymentData = [
            'banks' => [
                ['code' => '004', 'name' => ['ko' => '국민은행', 'en' => 'Kookmin Bank']],
            ],
            'bank_accounts' => [
                ['bank_code' => '004', 'account_number' => '123-456', 'account_holder' => '홍길동', 'is_active' => true, 'is_default' => true],
            ],
            'auto_cancel_expired' => false,
            'auto_cancel_days' => 5,
            'vbank_due_days' => 5,
            'dbank_due_days' => 10,
        ];

        File::ensureDirectoryExists($this->storagePath);
        File::put($this->storagePath.'/payment.json', json_encode($paymentData));

        $this->service->clearCache();
        $settings = $this->service->getSettings('order_settings');

        // 마이그레이션된 값 확인
        $this->assertFalse($settings['auto_cancel_expired']);
        $this->assertEquals(5, $settings['auto_cancel_days']);
        $this->assertEquals(5, $settings['vbank_due_days']);
        $this->assertEquals(10, $settings['dbank_due_days']);

        // bank_accounts 이전 확인
        $this->assertCount(1, $settings['bank_accounts']);
        $this->assertEquals('홍길동', $settings['bank_accounts'][0]['account_holder']);

        // order_settings.json이 생성되었는지 확인
        $this->assertTrue(File::exists($this->storagePath.'/order_settings.json'));

        // payment.json은 보존되어야 함
        $this->assertTrue(File::exists($this->storagePath.'/payment.json'));
    }

    public function test_migrate_dbank_single_fields_to_bank_accounts(): void
    {
        // 기존 단일 필드 형식 (dbank_bank_code 등)
        $paymentData = [
            'dbank_bank_code' => '088',
            'dbank_account_number' => '110-123-456',
            'dbank_account_holder' => '김철수',
            'auto_cancel_expired' => true,
        ];

        File::ensureDirectoryExists($this->storagePath);
        File::put($this->storagePath.'/payment.json', json_encode($paymentData));

        $this->service->clearCache();
        $settings = $this->service->getSettings('order_settings');

        // dbank_* → bank_accounts[0] 변환 확인
        $this->assertCount(1, $settings['bank_accounts']);
        $this->assertEquals('088', $settings['bank_accounts'][0]['bank_code']);
        $this->assertEquals('110-123-456', $settings['bank_accounts'][0]['account_number']);
        $this->assertEquals('김철수', $settings['bank_accounts'][0]['account_holder']);
        $this->assertTrue($settings['bank_accounts'][0]['is_default']);
    }

    public function test_migration_skipped_when_order_settings_exists(): void
    {
        // order_settings.json이 이미 있으면 마이그레이션 스킵
        $orderSettingsData = ['auto_cancel_expired' => false, 'auto_cancel_days' => 7];
        $paymentData = ['auto_cancel_expired' => true, 'auto_cancel_days' => 3];

        File::ensureDirectoryExists($this->storagePath);
        File::put($this->storagePath.'/order_settings.json', json_encode($orderSettingsData));
        File::put($this->storagePath.'/payment.json', json_encode($paymentData));

        $this->service->clearCache();
        $settings = $this->service->getSettings('order_settings');

        // order_settings.json의 값이 사용됨 (payment.json 값 아님)
        $this->assertFalse($settings['auto_cancel_expired']);
        $this->assertEquals(7, $settings['auto_cancel_days']);
    }

    // ──────────────────────────────────────────────
    // order_settings 카테고리 저장/로드
    // ──────────────────────────────────────────────

    public function test_save_and_load_order_settings(): void
    {
        $this->service->saveSettings([
            'order_settings' => [
                'auto_cancel_expired' => false,
                'auto_cancel_days' => 5,
                'vbank_due_days' => 5,
                'dbank_due_days' => 14,
                'cart_expiry_days' => 60,
                'stock_restore_on_cancel' => false,
            ],
        ]);

        $this->service->clearCache();
        $settings = $this->service->getSettings('order_settings');

        $this->assertFalse($settings['auto_cancel_expired']);
        $this->assertEquals(5, $settings['auto_cancel_days']);
        $this->assertEquals(5, $settings['vbank_due_days']);
        $this->assertEquals(14, $settings['dbank_due_days']);
        $this->assertEquals(60, $settings['cart_expiry_days']);
        $this->assertFalse($settings['stock_restore_on_cancel']);
    }

    // ──────────────────────────────────────────────
    // 은행 CRUD
    // ──────────────────────────────────────────────

    public function test_save_bank_accounts_crud(): void
    {
        // 2개 계좌 저장
        $this->service->saveSettings([
            'order_settings' => [
                'bank_accounts' => [
                    ['bank_code' => '004', 'account_number' => '123-456', 'account_holder' => '홍길동', 'is_active' => true, 'is_default' => true],
                    ['bank_code' => '088', 'account_number' => '789-012', 'account_holder' => '김영희', 'is_active' => true, 'is_default' => false],
                ],
            ],
        ]);

        $this->service->clearCache();
        $accounts = $this->service->getSettings('order_settings')['bank_accounts'];

        $this->assertCount(2, $accounts);
        $this->assertEquals('홍길동', $accounts[0]['account_holder']);
        $this->assertTrue($accounts[0]['is_default']);
        $this->assertFalse($accounts[1]['is_default']);
    }

    // ──────────────────────────────────────────────
    // getAllSettings() — bank_name 해석 및 필드 검증
    // (order_settings는 expose: false → getFrontendSettings()에 미포함,
    //  Admin API는 getAllSettings() 사용)
    // ──────────────────────────────────────────────

    public function test_all_settings_includes_bank_accounts(): void
    {
        // bank_accounts에 은행 코드 설정
        $this->service->saveSettings([
            'order_settings' => [
                'bank_accounts' => [
                    ['bank_code' => '004', 'account_number' => '123-456', 'account_holder' => '홍길동', 'is_active' => true, 'is_default' => true],
                    ['bank_code' => '088', 'account_number' => '789-012', 'account_holder' => '김영희', 'is_active' => true, 'is_default' => false],
                ],
            ],
        ]);

        $this->service->clearCache();
        $allSettings = $this->service->getAllSettings();

        $this->assertArrayHasKey('order_settings', $allSettings);
        $accounts = $allSettings['order_settings']['bank_accounts'];

        // bank_accounts가 저장된 데이터를 포함하는지 확인
        $this->assertCount(2, $accounts);
        $this->assertEquals('004', $accounts[0]['bank_code']);
        $this->assertEquals('088', $accounts[1]['bank_code']);
    }

    public function test_public_payment_settings_resolves_bank_name(): void
    {
        // bank_accounts에 은행 코드 설정
        $this->service->saveSettings([
            'order_settings' => [
                'bank_accounts' => [
                    ['bank_code' => '004', 'account_number' => '123-456', 'account_holder' => '홍길동', 'is_active' => true, 'is_default' => true],
                    ['bank_code' => '088', 'account_number' => '789-012', 'account_holder' => '김영희', 'is_active' => true, 'is_default' => false],
                ],
            ],
        ]);

        $this->service->clearCache();
        $paymentSettings = $this->service->getPublicPaymentSettings();

        $accounts = $paymentSettings['bank_accounts'];

        // bank_name이 은행코드로부터 해석되었는지 확인 (getPublicPaymentSettings에서 해석)
        $this->assertEquals(['ko' => '국민은행', 'en' => 'Kookmin Bank'], $accounts[0]['bank_name']);
        $this->assertEquals(['ko' => '신한은행', 'en' => 'Shinhan Bank'], $accounts[1]['bank_name']);
    }

    public function test_all_settings_includes_order_settings_fields(): void
    {
        $allSettings = $this->service->getAllSettings();

        $this->assertArrayHasKey('order_settings', $allSettings);

        // order_settings 필드가 포함되어야 함
        $this->assertArrayHasKey('auto_cancel_expired', $allSettings['order_settings']);
        $this->assertArrayHasKey('stock_restore_on_cancel', $allSettings['order_settings']);
        $this->assertArrayHasKey('vbank_due_days', $allSettings['order_settings']);
        $this->assertArrayHasKey('dbank_due_days', $allSettings['order_settings']);
        $this->assertArrayHasKey('payment_methods', $allSettings['order_settings']);
    }

    public function test_all_settings_boolean_fields_are_boolean_type(): void
    {
        $allSettings = $this->service->getAllSettings();

        $this->assertIsBool(
            $allSettings['order_settings']['auto_cancel_expired'],
            'auto_cancel_expired는 boolean 타입이어야 합니다'
        );
        $this->assertIsBool(
            $allSettings['order_settings']['stock_restore_on_cancel'],
            'stock_restore_on_cancel은 boolean 타입이어야 합니다'
        );
    }

    public function test_frontend_settings_excludes_order_settings(): void
    {
        $frontendSettings = $this->service->getFrontendSettings();

        // order_settings는 expose: false → getFrontendSettings()에 미포함
        $this->assertArrayNotHasKey('order_settings', $frontendSettings);
    }

    // ──────────────────────────────────────────────
    // saveBanks() — 은행 목록만 별도 저장
    // ──────────────────────────────────────────────

    public function test_save_banks_only_preserves_other_order_settings(): void
    {
        // 먼저 전체 order_settings 저장
        $this->service->saveSettings([
            'order_settings' => [
                'auto_cancel_expired' => false,
                'auto_cancel_days' => 10,
                'dbank_due_days' => 14,
                'bank_accounts' => [
                    ['bank_code' => '004', 'account_number' => '123-456', 'account_holder' => '홍길동', 'is_active' => true, 'is_default' => true],
                ],
            ],
        ]);

        $this->service->clearCache();

        // saveBanks로 은행만 교체
        $newBanks = [
            ['code' => '999', 'name' => ['ko' => '테스트은행', 'en' => 'Test Bank']],
        ];
        $this->service->saveBanks($newBanks);
        $this->service->clearCache();

        $settings = $this->service->getSettings('order_settings');

        // 은행이 교체되었는지 확인
        $this->assertCount(1, $settings['banks']);
        $this->assertEquals('999', $settings['banks'][0]['code']);
        $this->assertEquals('테스트은행', $settings['banks'][0]['name']['ko']);

        // 다른 설정은 보존되었는지 확인
        $this->assertFalse($settings['auto_cancel_expired']);
        $this->assertEquals(10, $settings['auto_cancel_days']);
        $this->assertEquals(14, $settings['dbank_due_days']);
        $this->assertCount(1, $settings['bank_accounts']);
        $this->assertEquals('홍길동', $settings['bank_accounts'][0]['account_holder']);
    }

    public function test_save_banks_with_empty_array(): void
    {
        // 기본 설정 로드 (은행 17개)
        $settings = $this->service->getSettings('order_settings');
        $this->assertCount(17, $settings['banks']);

        // 빈 배열로 저장
        $this->service->saveBanks([]);
        $this->service->clearCache();

        $settings = $this->service->getSettings('order_settings');
        $this->assertCount(0, $settings['banks']);
    }

    public function test_save_banks_returns_true_on_success(): void
    {
        $result = $this->service->saveBanks([
            ['code' => '004', 'name' => ['ko' => '국민은행', 'en' => 'Kookmin Bank']],
        ]);

        $this->assertTrue($result);
    }

    // ──────────────────────────────────────────────
    // 고아 항목 삭제
    // ──────────────────────────────────────────────

    public function test_orphaned_item_removed_on_save(): void
    {
        // 고아 항목 포함된 상태에서 해당 항목 제외하고 저장
        $savedSettings = [
            'payment_methods' => [
                ['id' => 'dbank', 'sort_order' => 1, 'is_active' => true, 'min_order_amount' => 0, 'stock_deduction_timing' => 'order_placed'],
                ['id' => 'point', 'sort_order' => 2, 'is_active' => true, 'min_order_amount' => 0, 'stock_deduction_timing' => 'payment_complete'],
                ['id' => 'deposit', 'sort_order' => 3, 'is_active' => true, 'min_order_amount' => 0, 'stock_deduction_timing' => 'payment_complete'],
                // tosspayments 항목을 제외 (관리자가 삭제)
            ],
        ];

        $this->service->saveSettings(['order_settings' => $savedSettings]);
        $this->service->clearCache();

        $settings = $this->service->getSettings('order_settings');
        $ids = array_column($settings['payment_methods'], 'id');

        $this->assertNotContains('tosspayments', $ids);
    }

    // ──────────────────────────────────────────────
    // getStockDeductionTiming()
    // ──────────────────────────────────────────────

    public function test_get_stock_deduction_timing_returns_configured_value(): void
    {
        // dbank는 기본값이 order_placed
        $this->saveOrderSettings([
            'payment_methods' => [
                ['id' => 'dbank', 'sort_order' => 1, 'is_active' => true, 'min_order_amount' => 0, 'stock_deduction_timing' => 'order_placed'],
                ['id' => 'card', 'sort_order' => 2, 'is_active' => true, 'min_order_amount' => 0, 'stock_deduction_timing' => 'payment_complete'],
                ['id' => 'free', 'sort_order' => 3, 'is_active' => true, 'min_order_amount' => 0, 'stock_deduction_timing' => 'order_placed'],
            ],
        ]);

        $this->service->clearCache();

        $this->assertEquals('order_placed', $this->service->getStockDeductionTiming('dbank'));
        $this->assertEquals('payment_complete', $this->service->getStockDeductionTiming('card'));
        $this->assertEquals('order_placed', $this->service->getStockDeductionTiming('free'));
    }

    public function test_get_stock_deduction_timing_returns_default_for_unknown_method(): void
    {
        $this->service->clearCache();

        // 존재하지 않는 결제수단 → 기본값 payment_complete
        $this->assertEquals('payment_complete', $this->service->getStockDeductionTiming('nonexistent_method'));
    }

    public function test_get_stock_deduction_timing_uses_defaults_when_no_saved_settings(): void
    {
        $this->service->clearCache();

        // 저장된 설정 없이 defaults.json에서 로드
        // dbank 기본값: order_placed, card 기본값: payment_complete
        $this->assertEquals('order_placed', $this->service->getStockDeductionTiming('dbank'));
        $this->assertEquals('payment_complete', $this->service->getStockDeductionTiming('card'));
    }

    // ──────────────────────────────────────────────
    // 헬퍼
    // ──────────────────────────────────────────────

    /**
     * order_settings.json을 직접 생성하여 저장된 설정을 시뮬레이션합니다.
     */
    private function saveOrderSettings(array $settings): void
    {
        File::ensureDirectoryExists($this->storagePath);
        File::put(
            $this->storagePath.'/order_settings.json',
            json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}
