<?php

namespace Tests\Unit\Services\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Models\LanguagePack;
use App\Services\LanguagePack\LanguagePackBundledRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * LanguagePackBundledRegistrar 단위 테스트.
 *
 * 확장(모듈/플러그인/템플릿) 설치/제거 시 가상 등록 동작 + 슬롯 다중 벤더 시나리오를 검증합니다.
 */
class LanguagePackBundledRegistrarTest extends TestCase
{
    use RefreshDatabase;

    private LanguagePackBundledRegistrar $registrar;

    private string $extensionRoot;

    private string $relativePath = 'storage/app/test-extension/lang';

    protected function setUp(): void
    {
        parent::setUp();
        $this->registrar = $this->app->make(LanguagePackBundledRegistrar::class);

        $this->extensionRoot = base_path($this->relativePath);
        File::ensureDirectoryExists($this->extensionRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('storage/app/test-extension'));
        parent::tearDown();
    }

    /**
     * 확장 lang 디렉토리에 locale 디렉토리를 생성합니다.
     *
     * @param  array<int, string>  $locales  생성할 locale 목록
     * @return void
     */
    private function setupExtensionLangDirs(array $locales): void
    {
        foreach ($locales as $locale) {
            File::ensureDirectoryExists($this->extensionRoot.'/'.$locale);
            File::put(
                $this->extensionRoot.'/'.$locale.'/messages.php',
                "<?php\nreturn ['hello' => 'Hello'];\n"
            );
        }
    }

    public function test_sync_registers_bundled_records_for_each_locale(): void
    {
        $this->setupExtensionLangDirs(['ko', 'en', 'ja']);

        $this->registrar->syncFromExtension(
            'module',
            'test-module',
            'test',
            '1.0.0',
            $this->relativePath,
        );

        $this->assertCount(3, LanguagePack::query()->where('target_identifier', 'test-module')->get());
    }

    public function test_sync_promotes_first_to_active_and_others_installed(): void
    {
        $this->setupExtensionLangDirs(['ko', 'en']);

        $this->registrar->syncFromExtension('module', 'test-module', 'test', '1.0.0', $this->relativePath);

        $packs = LanguagePack::query()->where('target_identifier', 'test-module')->get();
        $activeCount = $packs->where('status', LanguagePackStatus::Active->value)->count();

        // 각 locale 슬롯이 비어있었으므로 모두 active 로 자동 승격
        $this->assertSame(2, $activeCount);
    }

    public function test_sync_keeps_existing_active_when_new_record_added(): void
    {
        // 외부 벤더의 ja 언어팩이 이미 active
        LanguagePack::query()->create([
            'identifier' => 'acme-module-test-module-ja',
            'vendor' => 'acme',
            'scope' => LanguagePackScope::Module->value,
            'target_identifier' => 'test-module',
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Active->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => 'zip',
        ]);

        $this->setupExtensionLangDirs(['ja']);
        $this->registrar->syncFromExtension('module', 'test-module', 'test', '1.0.0', $this->relativePath);

        // 새 가상 레코드는 installed 상태로 진입 (기존 active 유지)
        $bundled = LanguagePack::query()
            ->where('target_identifier', 'test-module')
            ->where('source_type', 'bundled_with_extension')
            ->first();

        $this->assertSame(LanguagePackStatus::Installed->value, $bundled->status);
    }

    public function test_cleanup_deletes_bundled_records(): void
    {
        $this->setupExtensionLangDirs(['ja']);
        $this->registrar->syncFromExtension('module', 'test-module', 'test', '1.0.0', $this->relativePath);

        $this->registrar->cleanupForExtension('module', 'test-module');

        $this->assertCount(0, LanguagePack::query()
            ->where('target_identifier', 'test-module')
            ->where('source_type', 'bundled_with_extension')
            ->get());
    }

    public function test_cleanup_marks_external_packs_as_error(): void
    {
        $external = LanguagePack::query()->create([
            'identifier' => 'foobar-module-test-module-ja',
            'vendor' => 'foobar',
            'scope' => LanguagePackScope::Module->value,
            'target_identifier' => 'test-module',
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '2.0.0',
            'status' => LanguagePackStatus::Active->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => 'zip',
        ]);

        $this->registrar->cleanupForExtension('module', 'test-module');

        $this->assertSame(LanguagePackStatus::Error->value, $external->fresh()->status);
    }

    public function test_sync_skips_invalid_locale_directories(): void
    {
        File::ensureDirectoryExists($this->extensionRoot.'/UPPERCASE_INVALID');
        File::ensureDirectoryExists($this->extensionRoot.'/ko');

        $this->registrar->syncFromExtension('module', 'test-module', 'test', '1.0.0', $this->relativePath);

        $packs = LanguagePack::query()->where('target_identifier', 'test-module')->get();
        $this->assertSame(1, $packs->count());
        $this->assertSame('ko', $packs->first()->locale);
    }

    public function test_sync_handles_partial_directory_pattern(): void
    {
        // {lang}/partial/{locale}/*.json 패턴 (G7 템플릿 fragment 구조)
        File::ensureDirectoryExists($this->extensionRoot.'/partial/ja');
        File::put($this->extensionRoot.'/partial/ja/admin.json', '{"hello":"こんにちは"}');

        $this->registrar->syncFromExtension('template', 'test-template', 'test', '1.0.0', $this->relativePath);

        $packs = LanguagePack::query()->where('target_identifier', 'test-template')->get();
        $this->assertCount(1, $packs);
        $this->assertSame('ja', $packs->first()->locale);
    }
}
