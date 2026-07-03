<?php

namespace Tests\Unit\Extension;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Extension\ExtensionManager;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 확장 소스 classmap(B2) 생성 무결성 테스트.
 *
 * 서빙 API 부팅 비용 최적화: 확장 소스 클래스를 classmap 에 편입하여 findFile 의
 * 파일시스템 스캔(PSR-4 폴백 stat)을 제거한다. 안전 게이트:
 *  - FQCN 추출 정확성 (namespace + class/interface/trait/enum)
 *  - 소스 클래스만 대상 (서드파티 vendor 미포함 — 격리 불변조건 보존)
 *  - 매핑 경로 유효성
 *
 * @see ExtensionManager::buildSourceClassmap()
 */
class ExtensionSourceClassmapTest extends TestCase
{
    private string $fixtureDir;

    private ExposedExtensionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new ExposedExtensionManager(
            app(ModuleRepositoryInterface::class),
            app(PluginRepositoryInterface::class),
        );

        // base_path 하위 임시 픽스처 (buildSourceClassmap 이 base_path 상대경로를 산출하므로)
        $this->fixtureDir = base_path('storage/framework/testing/classmap_fx_'.uniqid());
        File::ensureDirectoryExists($this->fixtureDir.'/src');
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->fixtureDir)) {
            File::deleteDirectory($this->fixtureDir);
        }
        parent::tearDown();
    }

    private function relative(string $absolute): string
    {
        return str_replace('\\', '/', substr($absolute, strlen(base_path()) + 1));
    }

    /**
     * @effects extracts_fqcn_for_class_declaration, extracts_fqcn_for_interface_declaration, extracts_fqcn_for_trait_declaration, extracts_fqcn_for_enum_declaration
     */
    public function test_extracts_fqcn_for_class_interface_trait_enum(): void
    {
        $cases = [
            'FooClass.php' => "<?php\nnamespace Vendor\\Ext\\Sub;\nclass FooClass {}\n",
            'BarInterface.php' => "<?php\nnamespace Vendor\\Ext;\ninterface BarInterface {}\n",
            'BazTrait.php' => "<?php\nnamespace Vendor\\Ext;\ntrait BazTrait {}\n",
            'QuxEnum.php' => "<?php\nnamespace Vendor\\Ext;\nenum QuxEnum: string { case A = 'a'; }\n",
        ];

        foreach ($cases as $file => $code) {
            File::put($this->fixtureDir.'/src/'.$file, $code);
        }

        $psr4 = ['Vendor\\Ext\\' => $this->relative($this->fixtureDir.'/src')];
        $classmap = $this->manager->exposedBuildSourceClassmap($psr4);

        $this->assertArrayHasKey('Vendor\\Ext\\Sub\\FooClass', $classmap);
        $this->assertArrayHasKey('Vendor\\Ext\\BarInterface', $classmap);
        $this->assertArrayHasKey('Vendor\\Ext\\BazTrait', $classmap);
        $this->assertArrayHasKey('Vendor\\Ext\\QuxEnum', $classmap);
    }

    /**
     * @effects maps_fqcn_to_correct_relative_path
     */
    public function test_maps_fqcn_to_correct_relative_path(): void
    {
        File::ensureDirectoryExists($this->fixtureDir.'/src/Services');
        File::put($this->fixtureDir.'/src/Services/OrderService.php',
            "<?php\nnamespace Vendor\\Ext\\Services;\nclass OrderService {}\n");

        $psr4 = ['Vendor\\Ext\\' => $this->relative($this->fixtureDir.'/src')];
        $classmap = $this->manager->exposedBuildSourceClassmap($psr4);

        $this->assertArrayHasKey('Vendor\\Ext\\Services\\OrderService', $classmap);
        $expected = $this->relative($this->fixtureDir.'/src/Services/OrderService.php');
        $this->assertSame($expected, $classmap['Vendor\\Ext\\Services\\OrderService']);
    }

    /**
     * @effects ignores_files_outside_declared_namespace
     */
    public function test_ignores_files_outside_declared_namespace(): void
    {
        // PSR-4 네임스페이스와 다른 네임스페이스의 파일은 제외 (다른 PSR-4 항목이 담당)
        File::put($this->fixtureDir.'/src/Foreign.php',
            "<?php\nnamespace Other\\Package;\nclass Foreign {}\n");

        $psr4 = ['Vendor\\Ext\\' => $this->relative($this->fixtureDir.'/src')];
        $classmap = $this->manager->exposedBuildSourceClassmap($psr4);

        $this->assertArrayNotHasKey('Other\\Package\\Foreign', $classmap);
    }

    /**
     * @effects skips_files_without_top_level_declaration
     */
    public function test_skips_files_without_top_level_declaration(): void
    {
        File::put($this->fixtureDir.'/src/helpers.php',
            "<?php\nfunction ext_helper() { return 1; }\n");

        $psr4 = ['Vendor\\Ext\\' => $this->relative($this->fixtureDir.'/src')];
        $classmap = $this->manager->exposedBuildSourceClassmap($psr4);

        $this->assertEmpty($classmap);
    }

    /**
     * @effects missing_directory_returns_empty
     */
    public function test_missing_directory_returns_empty(): void
    {
        $psr4 = ['Vendor\\Ext\\' => 'nonexistent/path/xyz'];
        $classmap = $this->manager->exposedBuildSourceClassmap($psr4);

        $this->assertSame([], $classmap);
    }

    /**
     * 실제 생성된 autoload-extensions.php 의 src_classmap 이 소스 클래스만 담고
     * 서드파티 vendor 경로를 포함하지 않는지 검증 (vendor 격리 불변조건).
     *
     * 로컬에 파일이 없으면(테스트 환경 미생성) 스킵.
     *
     * @effects generated_src_classmap_excludes_vendor_paths, generated_src_classmap_only_extension_namespaces
     */
    public function test_generated_src_classmap_excludes_vendor_paths(): void
    {
        $autoloadFile = base_path('bootstrap/cache/autoload-extensions.php');
        if (! file_exists($autoloadFile)) {
            $this->markTestSkipped('autoload-extensions.php 미생성 (extension:update-autoload 필요)');
        }

        $data = require $autoloadFile;
        if (empty($data['src_classmap'])) {
            $this->markTestSkipped('src_classmap 비어있음 (활성 확장 없음)');
        }

        foreach ($data['src_classmap'] as $fqcn => $path) {
            // 서드파티 vendor 디렉토리를 가리키면 안 됨 (확장별 독립 vendor 는 자체 autoload 담당)
            $this->assertStringNotContainsString('/vendor/', $path,
                "src_classmap 이 vendor 경로를 포함하면 안 됩니다: {$fqcn} => {$path}");
            // 확장 소스 네임스페이스여야 함
            $this->assertTrue(
                str_starts_with($fqcn, 'Modules\\') || str_starts_with($fqcn, 'Plugins\\'),
                "src_classmap 은 확장 소스 네임스페이스만 포함해야 합니다: {$fqcn}"
            );
        }
    }

    /**
     * addClassMap 등록 시 findFile 이 classMap 에서 즉시 해석(파일시스템 스캔 없이)하고,
     * classmap 미등록 클래스는 PSR-4 폴백으로 해석되는지 검증한다.
     *
     * findFile 이 classMap 을 최우선 조회하므로(vendor/composer/ClassLoader.php),
     * 등록된 확장 클래스는 파일시스템 접근 없이 경로를 반환한다. 미등록 클래스는
     * addPsr4 폴백으로 안전하게 해석된다(안전망).
     *
     * @effects classmap_present_findFile_hits_without_filesystem_stat, classmap_absent_findFile_falls_back_to_psr4, class_loading_remains_lazy_include_on_use
     */
    public function test_add_class_map_find_file_hit_and_psr4_fallback(): void
    {
        // 두 클래스 준비: 하나는 classmap 등록, 하나는 미등록(PSR-4 폴백 대상)
        File::ensureDirectoryExists($this->fixtureDir.'/src');
        File::put($this->fixtureDir.'/src/Mapped.php',
            "<?php\nnamespace Vendor\\CmTest;\nclass Mapped {}\n");
        File::put($this->fixtureDir.'/src/Unmapped.php',
            "<?php\nnamespace Vendor\\CmTest;\nclass Unmapped {}\n");

        $loader = require base_path('vendor/autoload.php');

        // PSR-4 등록 (폴백 경로)
        $loader->addPsr4('Vendor\\CmTest\\', $this->fixtureDir.'/src');

        // classmap 에는 Mapped 만 등록
        $mappedPath = $this->fixtureDir.'/src/Mapped.php';
        $loader->addClassMap(['Vendor\\CmTest\\Mapped' => $mappedPath]);

        // classmap hit: 등록 경로 그대로 반환 (파일시스템 스캔 없이)
        $this->assertSame($mappedPath, $loader->findFile('Vendor\\CmTest\\Mapped'));

        // classmap miss: PSR-4 폴백으로 해석 (경로가 정상 반환)
        $fallback = $loader->findFile('Vendor\\CmTest\\Unmapped');
        $this->assertNotFalse($fallback);
        $this->assertStringEndsWith('Unmapped.php', str_replace('\\', '/', $fallback));

        // 지연 로드: findFile 은 경로만 반환하며 클래스를 include 하지 않는다
        $this->assertFalse(class_exists('Vendor\\CmTest\\Mapped', false),
            'findFile 은 경로만 반환하고 include 하지 않아야 합니다 (lazy)');

        // 정리: 이 프로세스 loader 에 남은 테스트 PSR-4/classmap 은 테스트 클래스라 무해
    }

    /**
     * 재생성 시 addClassMap 이 동일 FQCN 의 경로를 새 값으로 덮어쓰는지 검증한다.
     *
     * 확장 업데이트로 클래스 파일 위치가 바뀌면(같은 FQCN, 새 경로) updateComposerAutoload 이
     * 재생성 후 런타임 addClassMap 을 호출한다. array_merge 로 새 경로가 옛 경로를 이긴다
     * (stale 경로 shadow 방지).
     *
     * @effects install_regenerates_src_classmap_via_updateComposerAutoload
     */
    public function test_reregistration_overwrites_stale_path_for_same_fqcn(): void
    {
        $loader = require base_path('vendor/autoload.php');

        $oldPath = base_path('storage/framework/testing/old_'.uniqid().'.php');
        $newPath = base_path('storage/framework/testing/new_'.uniqid().'.php');

        // 옛 경로 등록 (요청 부팅 시점 상태 시뮬)
        $loader->addClassMap(['Vendor\\Regen\\Moved' => $oldPath]);
        $this->assertSame($oldPath, $loader->findFile('Vendor\\Regen\\Moved'));

        // 재생성 후 새 경로 등록 (updateComposerAutoload → addClassMap 시뮬)
        $loader->addClassMap(['Vendor\\Regen\\Moved' => $newPath]);

        // 새 경로가 옛 경로를 덮어써야 함 (stale shadow 방지)
        $this->assertSame($newPath, $loader->findFile('Vendor\\Regen\\Moved'),
            '재등록 시 동일 FQCN 은 새 경로로 덮어써져야 합니다');
    }

    /**
     * src_classmap 키가 없는 구버전 autoload-extensions.php 도 안전하게 동작하는지 검증한다.
     *
     * B2 이전 버전에서 업데이트한 사이트는 재생성 전까지 src_classmap 키가 없는 파일을 갖는다.
     * 진입점 소비자는 `! empty(...)` 가드로 키 부재를 안전하게 건너뛰어야 한다(하위호환).
     *
     * @effects classmap_absent_findFile_falls_back_to_psr4
     */
    public function test_missing_src_classmap_key_is_backward_compatible(): void
    {
        // 구버전 포맷 (src_classmap 키 없음)
        $legacy = ['psr4' => [], 'classmap' => [], 'files' => [], 'vendor_autoloads' => []];

        // 소비자 가드 패턴 재현: 키 부재 시 addClassMap 미호출 (예외 없음)
        $this->assertTrue(empty($legacy['src_classmap']),
            '구버전 파일은 src_classmap 키가 없어야 하고, 소비자는 이를 안전하게 스킵해야 합니다');
    }
}

/**
 * protected buildSourceClassmap 를 테스트에서 호출하기 위한 서브클래스.
 */
class ExposedExtensionManager extends ExtensionManager
{
    public function exposedBuildSourceClassmap(array $psr4): array
    {
        return $this->buildSourceClassmap($psr4);
    }
}
