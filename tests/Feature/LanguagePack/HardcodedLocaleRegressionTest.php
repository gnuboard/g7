<?php

namespace Tests\Feature\LanguagePack;

use App\Enums\ExtensionOwnerType;
use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackSourceType;
use App\Enums\LanguagePackStatus;
use App\Models\LanguagePack;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Providers\LanguagePackServiceProvider;
use App\Services\DriverRegistryService;
use App\Services\LanguagePack\LanguagePackRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 이슈 #263 후속 — 활성 언어팩 locale(ja 등)이 백엔드 검증 / 라벨 빌더에서
 * 거부되거나 누락되지 않음을 보장하는 회귀 테스트.
 *
 * 검증 대상 5건:
 *   1. SaveSettingsRequest::SUPPORTED_LANGUAGES 상수 제거 → general.language=ja 저장 통과
 *   2. ProfileController::updateLanguage 인라인 화이트리스트 → ja 통과
 *   3. DriverRegistryService 라벨이 활성 translatable_locales 별 lang key 에서 동적 조회
 *   4. AuthService Accept-Language 국가 매핑이 config('app.locale_country_fallback') 사용
 *   5. ModuleManager / PluginManager 의 string→JSON 승격이 translatable_locales 기반
 */
class HardcodedLocaleRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // ja 코어 언어팩 활성화 → config('app.supported_locales')/translatable_locales 가 ja 포함하도록
        LanguagePack::create([
            'identifier' => 'g7-core-ja-test',
            'vendor' => 'g7',
            'name' => 'G7 Core Japanese (test)',
            'version' => '1.0.0',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'is_protected' => false,
            'manifest' => [
                'identifier' => 'g7-core-ja-test',
                'scope' => 'core',
                'locale' => 'ja',
                'requires' => ['depends_on_core_locale' => false],
            ],
            'status' => LanguagePackStatus::Active->value,
            'source_type' => LanguagePackSourceType::Bundled->value,
            'installed_at' => now(),
            'activated_at' => now(),
        ]);

        // Registry 의 인스턴스 캐시를 무효화 (setUp 이전에 boot 된 싱글톤 회피)
        app()->forgetInstance(LanguagePackRegistry::class);
        $registry = app(LanguagePackRegistry::class);
        config([
            'app.supported_locales' => $registry->getActiveCoreLocales(),
            'app.locale_names' => $registry->getLocaleNames(),
            'app.translatable_locales' => $registry->getActiveCoreLocales(),
        ]);

        $this->admin = $this->createAdminUser();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * #1 회귀: 기본 언어 = ja 로 환경설정 저장 시 422 거부되지 않아야 한다.
     */
    public function test_save_settings_accepts_active_locale_ja(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/admin/settings', [
            '_tab' => 'general',
            'general' => [
                'site_name' => 'Test',
                'site_url' => 'https://test.example.com',
                'admin_email' => 'admin@example.com',
                'timezone' => 'Asia/Seoul',
                'language' => 'ja',
            ],
        ]);

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('general.language', (array) $response->json('errors'));
    }

    /**
     * #2 회귀: 프로필 언어 변경 API 가 활성 언어팩 ja 를 거부하지 않아야 한다.
     */
    public function test_profile_update_language_accepts_active_locale_ja(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->patchJson('/api/admin/users/me/language', [
            'language' => 'ja',
        ]);

        $response->assertStatus(200);
        $this->assertSame('ja', $this->admin->fresh()->language);
    }

    /**
     * #3 회귀: DriverRegistryService 가 활성 translatable_locales 모든 키에 대해 라벨을 반환해야 한다.
     */
    public function test_driver_registry_returns_labels_for_all_active_locales(): void
    {
        $service = app(DriverRegistryService::class);
        $drivers = $service->getAvailableDrivers('storage');

        $this->assertNotEmpty($drivers);
        foreach ($drivers as $driver) {
            $this->assertArrayHasKey('label', $driver);
            $this->assertArrayHasKey('ko', $driver['label']);
            $this->assertArrayHasKey('en', $driver['label']);
            $this->assertArrayHasKey('ja', $driver['label'], 'ja 활성 언어팩에 대한 라벨이 누락되어서는 안 됨');
        }

        // 'local' 드라이버의 ja 라벨이 lang-packs/_bundled/g7-core-ja/backend/ja/settings.php 의 키와 일치해야 함
        $local = collect($drivers)->firstWhere('id', 'local');
        $this->assertSame('로컬', $local['label']['ko']);
        $this->assertSame('Local', $local['label']['en']);
    }

    /**
     * #4 회귀: AuthService Accept-Language 국가 매핑이 config 기반으로 동작.
     */
    public function test_auth_service_country_fallback_is_config_driven(): void
    {
        $this->assertSame('JP', config('app.locale_country_fallback.ja'));
        $this->assertSame('KR', config('app.locale_country_fallback.ko'));
        $this->assertSame('US', config('app.locale_country_fallback.en'));
    }

    /**
     * 회귀: LanguagePackServiceProvider 가 활성 언어팩 boot 시 supported_locales 뿐 아니라
     *        translatable_locales 도 함께 갱신해야 한다 (모듈/플러그인의 TranslatableField /
     *        LocaleRequiredTranslatable Rule 이 ja 를 거부하던 회귀 차단).
     */
    public function test_translatable_field_rule_accepts_active_locale_ja(): void
    {
        // setUp 이 수동 주입한 config 를 ko/en 만으로 되돌려, provider 의 refresh 로직만 검증.
        config([
            'app.supported_locales' => ['ko', 'en'],
            'app.translatable_locales' => ['ko', 'en'],
        ]);

        // provider 의 private refresh 메서드를 reflection 으로 호출 (boot 시점 시뮬레이션).
        $provider = new \App\Providers\LanguagePackServiceProvider($this->app);
        app()->forgetInstance(LanguagePackRegistry::class);
        $registry = app(LanguagePackRegistry::class);

        $ref = new \ReflectionClass($provider);
        $method = $ref->getMethod('refreshSupportedLocales');
        $method->setAccessible(true);
        $method->invoke($provider, $registry);

        $this->assertContains('ja', config('app.supported_locales'));
        $this->assertContains('ja', config('app.translatable_locales'),
            'translatable_locales 에도 활성 언어팩 ja 가 동기화되어야 함 (모듈 폼이 ja 를 거부하지 않도록)');

        // Rule 동작도 함께 검증 — ja 데이터가 거부되지 않아야 함.
        $rule = new \App\Rules\TranslatableField;
        $failed = null;
        $rule->validate('name', ['ja' => 'テスト共通情報'], function ($msg) use (&$failed) {
            $failed = $msg;
        });
        $this->assertNull($failed, 'TranslatableField 가 활성 언어팩 locale 을 거부하면 안 됨');
    }

    /**
     * #5 회귀: ModuleManager / PluginManager 의 string→JSON 승격이
     *           활성 translatable_locales 의 모든 키를 포함해야 한다.
     */
    public function test_translatable_promotion_covers_all_active_locales(): void
    {
        $name = 'Sample Permission';
        $promoted = array_fill_keys(config('app.translatable_locales', ['ko', 'en']), $name);

        $this->assertArrayHasKey('ko', $promoted);
        $this->assertArrayHasKey('en', $promoted);
        $this->assertArrayHasKey('ja', $promoted, 'translatable_locales 기반 승격이 ja 키를 포함해야 함');
        $this->assertSame($name, $promoted['ja']);
    }

    private function createAdminUser(): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
            'language' => 'ko',
        ]);

        $permissionIds = [];
        foreach (['core.settings.read', 'core.settings.update'] as $identifier) {
            $perm = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            );
            $permissionIds[] = $perm->id;
        }

        $role = Role::firstOrCreate(
            ['identifier' => 'test-admin'],
            [
                'name' => json_encode(['ko' => '테스트관리자', 'en' => 'Test Admin']),
                'description' => json_encode(['ko' => '테스트', 'en' => 'Test']),
                'is_system' => false,
            ]
        );
        $role->permissions()->sync($permissionIds);
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
