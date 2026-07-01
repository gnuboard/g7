<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Listeners\AssignDefaultCurrencyOnRegisterListener;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\SignupCurrencyResolver;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 가입 시 통화 부여 리스너/리졸버 테스트 (A4 — D-SIGNUP)
 */
class AssignDefaultCurrencyOnRegisterListenerTest extends ModuleTestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storagePath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }
    }

    /**
     * locales 매핑이 설정된 통화 목록을 저장합니다.
     */
    private function saveCurrencies(array $currencies, string $default = 'KRW'): void
    {
        File::ensureDirectoryExists($this->storagePath);
        File::put(
            $this->storagePath.'/language_currency.json',
            json_encode(['default_currency' => $default, 'currencies' => $currencies], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        app()->forgetInstance(EcommerceSettingsService::class);
    }

    private function resolver(): SignupCurrencyResolver
    {
        return app(SignupCurrencyResolver::class);
    }

    // ── 리졸버 매칭 ──

    public function test_single_locale_match_assigns_that_currency(): void
    {
        $this->saveCurrencies([
            ['code' => 'KRW', 'name' => ['ko' => 'KRW'], 'is_default' => true, 'locales' => ['ko']],
            ['code' => 'JPY', 'name' => ['ko' => 'JPY'], 'is_default' => false, 'exchange_rate' => 115, 'locales' => ['ja']],
        ]);

        $this->assertSame('JPY', $this->resolver()->resolve('ja'));
    }

    public function test_no_match_falls_back_to_default_currency(): void
    {
        $this->saveCurrencies([
            ['code' => 'KRW', 'name' => ['ko' => 'KRW'], 'is_default' => true, 'locales' => ['ko']],
            ['code' => 'JPY', 'name' => ['ko' => 'JPY'], 'is_default' => false, 'exchange_rate' => 115, 'locales' => ['ja']],
        ], default: 'KRW');

        // 매칭 없는 locale(어느 통화 locales 에도 없음) → is_default(KRW) 폴백
        // (defaults 보충으로 USD/JPY 등이 자동 합쳐지므로, 어느 통화에도 없는 'xx' 로 검증)
        $this->assertSame('KRW', $this->resolver()->resolve('xx'));
    }

    public function test_multi_match_prefers_is_default(): void
    {
        $this->saveCurrencies([
            ['code' => 'KRW', 'name' => ['ko' => 'KRW'], 'is_default' => true, 'locales' => ['en']],
            ['code' => 'USD', 'name' => ['ko' => 'USD'], 'is_default' => false, 'exchange_rate' => 0.85, 'locales' => ['en']],
        ], default: 'KRW');

        // en 이 KRW(default)와 USD 양쪽 매칭 → is_default(KRW) 우선
        $this->assertSame('KRW', $this->resolver()->resolve('en'));
    }

    public function test_locale_with_region_suffix_normalized(): void
    {
        $this->saveCurrencies([
            ['code' => 'KRW', 'name' => ['ko' => 'KRW'], 'is_default' => true, 'locales' => ['ko']],
            ['code' => 'JPY', 'name' => ['ko' => 'JPY'], 'is_default' => false, 'exchange_rate' => 115, 'locales' => ['ja']],
        ]);

        // ja-JP → ja 정규화 후 JPY 매칭
        $this->assertSame('JPY', $this->resolver()->resolve('ja-JP'));
    }

    // ── 리스너 부여 ──

    public function test_listener_assigns_currency_on_register(): void
    {
        $this->saveCurrencies([
            ['code' => 'KRW', 'name' => ['ko' => 'KRW'], 'is_default' => true, 'locales' => ['ko']],
            ['code' => 'JPY', 'name' => ['ko' => 'JPY'], 'is_default' => false, 'exchange_rate' => 115, 'locales' => ['ja']],
        ]);
        $user = User::factory()->create(['language' => 'ja']);

        app(AssignDefaultCurrencyOnRegisterListener::class)->handleRegister($user, []);

        $this->assertSame('JPY', app(EcommerceUserProfileRepositoryInterface::class)->getPreferredCurrency($user->id));
    }

    public function test_listener_admin_create_also_assigns(): void
    {
        $this->saveCurrencies([
            ['code' => 'KRW', 'name' => ['ko' => 'KRW'], 'is_default' => true, 'locales' => ['ko']],
        ], default: 'KRW');
        // 어느 통화 locales 에도 없는 locale(defaults 보충 포함) → default(KRW) 폴백
        $user = User::factory()->create(['language' => 'xx']);

        app(AssignDefaultCurrencyOnRegisterListener::class)->handleAdminCreate($user, []);

        // 매칭 없음 → default(KRW)
        $this->assertSame('KRW', app(EcommerceUserProfileRepositoryInterface::class)->getPreferredCurrency($user->id));
    }

    public function test_listener_does_not_overwrite_existing_currency(): void
    {
        $this->saveCurrencies([
            ['code' => 'KRW', 'name' => ['ko' => 'KRW'], 'is_default' => true, 'locales' => ['ko']],
            ['code' => 'JPY', 'name' => ['ko' => 'JPY'], 'is_default' => false, 'exchange_rate' => 115, 'locales' => ['ja']],
        ]);
        $user = User::factory()->create(['language' => 'ja']);
        // 관리자가 이미 USD 명시 지정
        app(EcommerceUserProfileRepositoryInterface::class)->setPreferredCurrency($user->id, 'USD');

        app(AssignDefaultCurrencyOnRegisterListener::class)->handleRegister($user, []);

        // 기존 값 보존 (덮어쓰지 않음)
        $this->assertSame('USD', app(EcommerceUserProfileRepositoryInterface::class)->getPreferredCurrency($user->id));
    }

    public function test_subscribes_to_both_register_and_admin_create_hooks(): void
    {
        $hooks = AssignDefaultCurrencyOnRegisterListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.auth.after_register', $hooks);
        $this->assertArrayHasKey('core.user.after_create', $hooks);
    }
}
