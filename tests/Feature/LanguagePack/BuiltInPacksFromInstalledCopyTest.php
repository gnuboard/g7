<?php

namespace Tests\Feature\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackSourceType;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Models\LanguagePack;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use App\Services\LanguagePackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 언어팩 관리의 내장 번역 목록이 설치된 확장의 설치본에서 만들어지는지 검증합니다.
 *
 * 내장 번역(ko·en 가상 보호 행)은 런타임이 실제로 읽는 파일과 같아야 한다. 그래서 확장 테이블에
 * 설치 기록(active·inactive)이 있는 확장의 설치본(`modules/{id}` 등)만 훑는다 — 설치 소스가 번들이
 * 아니어도 나오고, 설치하지 않은 번들 확장은 나오지 않으며, 설치본에 번역이 없으면 동봉 원본으로
 * 채우지 않는다.
 *
 * 소유 시나리오: `installed-copy-reads`
 */
class BuiltInPacksFromInstalledCopyTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> 정리할 픽스처 디렉토리 (base_path 기준) */
    private array $fixtureDirs = [];

    /**
     * 테스트가 만든 픽스처 디렉토리를 지우고 확장 상태 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->fixtureDirs as $dir) {
            File::deleteDirectory(base_path($dir));
        }
        $this->fixtureDirs = [];

        $this->flushExtensionStatusCache();

        parent::tearDown();
    }

    /**
     * 번들이 아닌 경로로 설치된 모듈·플러그인·템플릿의 설치본 번역이 내장 번역으로 나옵니다.
     *
     * 설치본에만 있고 동봉 원본에는 없는 확장(마켓·업로드 설치에 해당)이 빠지지 않아야 하며,
     * 가상 행의 경로·버전은 설치본을 가리켜야 한다.
     *
     * @scenario surface=built_in_lang_pack, install_state=installed_non_bundled
     *
     * @effects built_in_packs_come_from_installed_copies
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function installed_non_bundled_extensions_appear_as_built_in_packs(): void
    {
        $this->makeDir('modules/test-icp-mod/src/lang/ko', ['module.json' => ['vendor' => 'test', 'version' => '2.3.4']], 'modules/test-icp-mod');
        $this->makeDir('plugins/test-icp-plug/lang/ko', ['plugin.json' => ['vendor' => 'test', 'version' => '1.1.0']], 'plugins/test-icp-plug');
        $this->makeDir('templates/test-icp-tpl/lang', ['template.json' => ['vendor' => 'test', 'version' => '0.9.0']], 'templates/test-icp-tpl');
        File::put(base_path('templates/test-icp-tpl/lang/ko.json'), '{}');

        Module::factory()->create(['identifier' => 'test-icp-mod', 'status' => 'active', 'version' => '2.3.4']);
        Plugin::factory()->create(['identifier' => 'test-icp-plug', 'status' => 'inactive', 'version' => '1.1.0']);
        Template::factory()->create(['identifier' => 'test-icp-tpl', 'status' => 'active', 'type' => 'user', 'version' => '0.9.0']);
        $this->flushExtensionStatusCache();

        $packs = app(LanguagePackService::class)->getVirtualBuiltInPacks();

        $module = $this->findPack($packs, LanguagePackScope::Module, 'test-icp-mod', 'ko');
        $this->assertNotNull($module, '설치된 비번들 모듈의 내장 번역이 나와야 합니다.');
        $this->assertSame('modules/test-icp-mod/src/lang/ko', $module->source_url);
        $this->assertSame('2.3.4', $module->version);
        $this->assertSame(LanguagePackSourceType::BuiltIn->value, $module->source_type);
        $this->assertTrue($module->is_protected);

        $plugin = $this->findPack($packs, LanguagePackScope::Plugin, 'test-icp-plug', 'ko');
        $this->assertNotNull($plugin, '비활성이어도 설치된 플러그인의 내장 번역은 나와야 합니다.');
        $this->assertSame('plugins/test-icp-plug/lang/ko', $plugin->source_url);

        $template = $this->findPack($packs, LanguagePackScope::Template, 'test-icp-tpl', 'ko');
        $this->assertNotNull($template, '설치된 비번들 템플릿의 내장 번역이 나와야 합니다.');
        $this->assertSame('templates/test-icp-tpl/lang', $template->source_url);

        // 설치본에 en 번역이 없으면 en 행은 만들지 않는다.
        $this->assertNull($this->findPack($packs, LanguagePackScope::Module, 'test-icp-mod', 'en'));
    }

    /**
     * 설치하지 않은 번들 확장은 동봉 원본에 번역이 있어도 내장 번역으로 나오지 않습니다.
     *
     * @scenario surface=built_in_lang_pack, install_state=not_installed_bundled
     *
     * @effects not_installed_bundled_extension_has_no_built_in_pack
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function not_installed_bundled_extensions_do_not_appear(): void
    {
        $this->makeDir('modules/_bundled/test-icp-bmod/src/lang/ko', ['module.json' => ['vendor' => 'test', 'version' => '1.0.0']], 'modules/_bundled/test-icp-bmod');
        $this->makeDir('plugins/_bundled/test-icp-bplug/lang/ko', ['plugin.json' => ['vendor' => 'test', 'version' => '1.0.0']], 'plugins/_bundled/test-icp-bplug');
        $this->makeDir('templates/_bundled/test-icp-btpl/lang', ['template.json' => ['vendor' => 'test', 'version' => '1.0.0']], 'templates/_bundled/test-icp-btpl');
        File::put(base_path('templates/_bundled/test-icp-btpl/lang/ko.json'), '{}');
        $this->flushExtensionStatusCache();

        $service = app(LanguagePackService::class);
        $packs = $service->getVirtualBuiltInPacks();

        $this->assertNull($this->findPack($packs, LanguagePackScope::Module, 'test-icp-bmod', 'ko'));
        $this->assertNull($this->findPack($packs, LanguagePackScope::Plugin, 'test-icp-bplug', 'ko'));
        $this->assertNull($this->findPack($packs, LanguagePackScope::Template, 'test-icp-btpl', 'ko'));

        // 확장별 언어팩 화면(getPacksForExtension)도 같은 판정을 쓴다.
        $this->assertTrue(
            $service->getPacksForExtension(LanguagePackScope::Module, 'test-icp-bmod')
                ->where('source_type', LanguagePackSourceType::BuiltIn->value)
                ->isEmpty()
        );
    }

    /**
     * 설치된 확장이라도 설치본에 번역이 없으면 동봉 원본으로 채우지 않습니다.
     *
     * @scenario surface=built_in_lang_pack, install_state=installed_file_only_in_bundled
     *
     * @effects built_in_packs_come_from_installed_copies
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function installed_extension_without_installed_copy_lang_is_not_filled_from_bundled(): void
    {
        $this->makeDir('modules/test-icp-gap', ['module.json' => ['vendor' => 'test', 'version' => '1.0.0']], 'modules/test-icp-gap');
        $this->makeDir('modules/_bundled/test-icp-gap/src/lang/ko', ['module.json' => ['vendor' => 'test', 'version' => '1.0.0']], 'modules/_bundled/test-icp-gap');

        Module::factory()->create(['identifier' => 'test-icp-gap', 'status' => 'active']);
        $this->flushExtensionStatusCache();

        $packs = app(LanguagePackService::class)->getVirtualBuiltInPacks();

        $this->assertNull(
            $this->findPack($packs, LanguagePackScope::Module, 'test-icp-gap', 'ko'),
            '설치본에 번역이 없으면 동봉 원본의 번역을 내장 번역으로 보여 주면 안 됩니다.'
        );
    }

    /**
     * 확장 디렉토리와 매니페스트 픽스처를 만듭니다.
     *
     * @param  string  $dir  만들 디렉토리 (base_path 기준, 하위 lang 경로 포함 가능)
     * @param  array<string, array<string, mixed>>  $manifests  확장 루트에 쓸 매니페스트 파일명 → 내용
     * @param  string  $root  정리 대상이자 매니페스트를 둘 확장 루트 (base_path 기준)
     * @return void 반환값 없음
     */
    private function makeDir(string $dir, array $manifests, string $root): void
    {
        File::ensureDirectoryExists(base_path($dir));
        foreach ($manifests as $file => $content) {
            File::put(base_path($root.'/'.$file), (string) json_encode($content));
        }
        $this->fixtureDirs[] = $root;
    }

    /**
     * 가상 행 컬렉션에서 슬롯 하나를 찾습니다.
     *
     * @param  Collection<int, LanguagePack>  $packs  가상 보호 행 컬렉션
     * @param  LanguagePackScope  $scope  스코프
     * @param  string  $target  대상 확장 식별자
     * @param  string  $locale  로케일
     * @return LanguagePack|null 찾은 행, 없으면 null
     */
    private function findPack(Collection $packs, LanguagePackScope $scope, string $target, string $locale): ?LanguagePack
    {
        return $packs->first(
            fn (LanguagePack $p) => $p->scope === $scope->value
                && $p->target_identifier === $target
                && $p->locale === $locale
        );
    }

    /**
     * 설치된 확장 식별자 캐시를 비웁니다 — 픽스처 DB 행이 판정에 바로 반영되게 합니다.
     *
     * @return void 반환값 없음
     */
    private function flushExtensionStatusCache(): void
    {
        ModuleManager::invalidateModuleStatusCache();
        PluginManager::invalidatePluginStatusCache();
        TemplateManager::invalidateTemplateStatusCache();
    }
}
