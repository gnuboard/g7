<?php

namespace Tests\Feature\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackSourceType;
use App\Enums\LanguagePackStatus;
use App\Listeners\LanguagePack\MergeFrontendLanguage;
use App\Models\LanguagePack;
use App\Services\LanguagePack\LanguagePackRegistry;
use App\Services\LanguagePack\LanguagePackTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 이슈 #263 후속 — 활성 모듈 언어팩이 표시되지 않던 회귀를 차단하는 테스트.
 *
 * 두 결함을 검증:
 *   1. 프론트엔드: MergeFrontendLanguage 가 module/plugin 팩의 frontend 데이터를
 *      target_identifier 키로 wrap 하지 않아, $t:sirsoft-ecommerce.admin.foo 표현식이
 *      활성 ja 팩의 키를 찾지 못하고 ko/en 으로 폴백되던 문제.
 *   2. 백엔드: LanguagePackServiceProvider::registerActivePack 가 loadTranslationsFrom()
 *      으로 module 자체 src/lang 의 namespace hint 를 덮어쓰면서, ko 호출이 raw key
 *      로 떨어지던 문제. fallback path 메커니즘으로 전환되어야 한다.
 */
class NamespaceMergeRegressionTest extends TestCase
{
    use RefreshDatabase;

    private string $packDir;

    protected function setUp(): void
    {
        parent::setUp();

        // 테스트용 ja 모듈 언어팩 디렉토리 (lang-packs/g7-test-namespace-ja/)
        $this->packDir = base_path('lang-packs/g7-test-namespace-ja');
        File::ensureDirectoryExists($this->packDir.'/backend/ja');
        File::ensureDirectoryExists($this->packDir.'/frontend/partial');

        File::put(
            $this->packDir.'/backend/ja/messages.php',
            "<?php\nreturn ['greeting' => 'こんにちは'];\n"
        );
        File::put(
            $this->packDir.'/frontend/partial/admin.json',
            json_encode(['settings' => ['title' => 'タイトル(JA)']], JSON_UNESCAPED_UNICODE)
        );

        LanguagePack::create([
            'identifier' => 'g7-test-namespace-ja',
            'vendor' => 'g7',
            'name' => 'Test Namespace JA',
            'version' => '1.0.0',
            'scope' => LanguagePackScope::Module->value,
            'target_identifier' => 'sirsoft-ecommerce',
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'is_protected' => false,
            'manifest' => [
                'identifier' => 'g7-test-namespace-ja',
                'scope' => 'module',
                'target_identifier' => 'sirsoft-ecommerce',
                'locale' => 'ja',
                'requires' => ['depends_on_core_locale' => false],
            ],
            'status' => LanguagePackStatus::Active->value,
            'source_type' => LanguagePackSourceType::Bundled->value,
            'installed_at' => now(),
            'activated_at' => now(),
        ]);

        // Registry 캐시 무효화 (setUp 이전에 부트된 싱글톤 회피)
        app()->forgetInstance(LanguagePackRegistry::class);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->packDir)) {
            File::deleteDirectory($this->packDir);
        }

        parent::tearDown();
    }

    /**
     * #1 회귀: MergeFrontendLanguage 가 module 팩 데이터를 target_identifier 로 wrap 한다.
     *
     * wrap 누락 시 활성 ja 팩의 admin 키가 root 에 평탄 병합되어 sirsoft-ecommerce.admin
     * 경로 하위가 ko 데이터만 보유하게 됨. wrap 적용 시 sirsoft-ecommerce 키 하위에
     * 정확히 병합되어야 한다.
     */
    public function test_merge_frontend_language_wraps_module_pack_with_target_identifier(): void
    {
        $merger = app(MergeFrontendLanguage::class);

        $merged = $merger([], 'sirsoft-admin_basic', 'ja');

        $this->assertArrayHasKey('sirsoft-ecommerce', $merged, 'module 팩은 target_identifier 키 하위로 wrap 되어야 함');
        $this->assertSame('タイトル(JA)', $merged['sirsoft-ecommerce']['admin']['settings']['title'] ?? null);
    }

    /**
     * #2 회귀: 모듈 자체 ko/en namespace 등록을 덮어쓰지 않는다.
     *
     * loadTranslationsFrom() 직접 호출 시 FileLoader hint 가 덮어써져 ko 가 raw key 로
     * 떨어지지만, addNamespaceFallbackPath 사용 시 ko/en 정상 + ja 보완 모두 동작해야 한다.
     */
    public function test_active_module_pack_does_not_overwrite_namespace_hint_for_existing_locales(): void
    {
        $translator = app('translator');
        $this->assertInstanceOf(LanguagePackTranslator::class, $translator);

        // 모듈 자체 src/lang 등록을 시뮬레이션 (TranslationServiceProvider 가 부팅 시 수행하는 작업)
        $translator->addNamespace('sirsoft-ecommerce', base_path('modules/sirsoft-ecommerce/src/lang'));

        // 언어팩 fallback 등록 (LanguagePackServiceProvider::registerActivePack 가 수행)
        $translator->addNamespaceFallbackPath(
            namespace: 'sirsoft-ecommerce',
            locale: 'ja',
            path: $this->packDir.'/backend/ja',
        );

        // ko: 모듈 자체 src/lang/ko/messages.php 의 키가 보존되어야 함 (덮어쓰지 않음)
        app()->setLocale('ko');
        $this->assertNotSame(
            'sirsoft-ecommerce::messages.cart.empty',
            trans('sirsoft-ecommerce::messages.cart.empty'),
            'ko 호출이 raw key 로 떨어지면 namespace hint 가 덮어써졌다는 증거'
        );

        // ja: 언어팩 fallback 에서 키가 보완되어야 함
        app()->setLocale('ja');
        $this->assertSame('こんにちは', trans('sirsoft-ecommerce::messages.greeting'));
    }
}
