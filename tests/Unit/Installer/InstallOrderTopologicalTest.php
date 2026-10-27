<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 인스톨러 — 같은 유형 확장의 설치 순서 위상 정렬 단위 테스트.
 *
 * 설치 작업은 `selected_extensions.modules`·`plugins` 배열 순서대로 install → activate 를 잇는다.
 * Step 4 의 자동 선택은 의존 대상을 배열 끝에 붙이므로, 모듈 → 모듈 의존이면 의존 대상이 나중에
 * 설치돼 실패할 수 있었다. `save-extensions.php` 가 저장할 때 dependencies 로 정렬하는 것을 잠근다.
 */
class InstallOrderTopologicalTest extends TestCase
{
    /** @var array<int, string> 테스트가 만든 임시 디렉터리 */
    private array $tempDirs = [];

    /**
     * 정렬 함수 파일을 적재합니다(앱 부팅 없이 함수만 정의된다).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 3).'/public/install/includes/install-order.php';
    }

    /**
     * 임시 디렉터리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeRecursive($dir);
        }
        $this->tempDirs = [];

        parent::tearDown();
    }

    /**
     * 의존 대상이 뒤에 붙어 있으면 앞으로 옮깁니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=save_extensions, owner_type=module
     *
     * @effects install_order_dependency_first
     */
    #[Test]
    public function dependency_selected_later_is_moved_before_its_dependent(): void
    {
        $sorted = installerSortSameTypeByDependencies(
            ['vendor-shop', 'vendor-board'],
            ['vendor-shop' => ['vendor-board'], 'vendor-board' => []]
        );

        $this->assertSame(['vendor-board', 'vendor-shop'], $sorted);
    }

    /**
     * 의존 관계가 없는 항목끼리는 사용자가 고른 순서를 지킵니다(안정 정렬).
     *
     * @return void 반환값 없음
     *
     * @scenario surface=save_extensions, owner_type=module
     *
     * @effects install_order_stable_for_unrelated
     */
    #[Test]
    public function unrelated_items_keep_their_selected_order(): void
    {
        $sorted = installerSortSameTypeByDependencies(
            ['vendor-c', 'vendor-a', 'vendor-shop', 'vendor-b', 'vendor-board'],
            ['vendor-shop' => ['vendor-board']]
        );

        $this->assertSame(['vendor-c', 'vendor-a', 'vendor-board', 'vendor-shop', 'vendor-b'], $sorted);
    }

    /**
     * 추이 의존(A → B → C)도 C, B, A 순으로 풉니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=save_extensions, owner_type=module
     *
     * @effects install_order_dependency_first
     */
    #[Test]
    public function transitive_chain_is_resolved(): void
    {
        $sorted = installerSortSameTypeByDependencies(
            ['vendor-a', 'vendor-b', 'vendor-c'],
            ['vendor-a' => ['vendor-b'], 'vendor-b' => ['vendor-c']]
        );

        $this->assertSame(['vendor-c', 'vendor-b', 'vendor-a'], $sorted);
    }

    /**
     * 선택되지 않은 의존 대상·자기 자신 의존은 정렬에 끼어들지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=save_extensions, owner_type=module
     *
     * @effects install_order_stable_for_unrelated
     */
    #[Test]
    public function unselected_and_self_dependencies_are_ignored(): void
    {
        $sorted = installerSortSameTypeByDependencies(
            ['vendor-a', 'vendor-b'],
            ['vendor-a' => ['vendor-missing', 'vendor-a'], 'vendor-b' => []]
        );

        $this->assertSame(['vendor-a', 'vendor-b'], $sorted);
    }

    /**
     * 순환이면 원래 순서를 그대로 돌려주고 순환 경로를 콜백으로 알립니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=save_extensions, owner_type=plugin
     *
     * @effects install_order_cycle_keeps_original_and_logs
     */
    #[Test]
    public function cycle_keeps_original_order_and_reports_the_path(): void
    {
        $reported = null;
        $original = ['vendor-x', 'vendor-a', 'vendor-b'];

        $sorted = installerSortSameTypeByDependencies(
            $original,
            ['vendor-a' => ['vendor-b'], 'vendor-b' => ['vendor-a']],
            function (array $cycle) use (&$reported): void {
                $reported = $cycle;
            }
        );

        $this->assertSame($original, $sorted);
        $this->assertSame(['vendor-a', 'vendor-b', 'vendor-a'], $reported);
    }

    /**
     * 빈 배열은 빈 배열입니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=save_extensions, owner_type=plugin
     *
     * @effects install_order_stable_for_unrelated
     */
    #[Test]
    public function empty_selection_stays_empty(): void
    {
        $this->assertSame([], installerSortSameTypeByDependencies([], ['vendor-a' => ['vendor-b']]));
    }

    /**
     * dependencies 의 세 모양(객체형·유형별 목록형·옛 배열형)에서 같은 유형 식별자를 뽑습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=save_extensions, owner_type=plugin
     *
     * @effects install_order_reads_bundled_manifests
     */
    #[Test]
    public function same_type_dependency_ids_are_extracted_from_every_shape(): void
    {
        $this->assertSame(
            ['vendor-board'],
            installerSameTypeDependencyIds(['modules' => ['vendor-board' => '>=1.0'], 'plugins' => ['vendor-pg' => '*']], 'modules')
        );
        $this->assertSame(
            ['vendor-board', 'vendor-page'],
            installerSameTypeDependencyIds(['modules' => ['vendor-board', 'vendor-page']], 'modules')
        );
        $this->assertSame(['vendor-pg'], installerSameTypeDependencyIds(['modules' => ['vendor-board'], 'plugins' => ['vendor-pg']], 'plugins'));
        $this->assertSame(['vendor-a', 'vendor-b'], installerSameTypeDependencyIds(['vendor-a', 'vendor-b'], 'modules'));
        $this->assertSame([], installerSameTypeDependencyIds(['plugins' => ['vendor-pg']], 'modules'));
        $this->assertSame([], installerSameTypeDependencyIds('vendor-a', 'modules'));
    }

    /**
     * `_bundled` 매니페스트에서 식별자별 같은 유형 의존 맵을 만들고, 그 맵으로 정렬이 이어집니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=save_extensions, owner_type=module
     *
     * @effects install_order_reads_bundled_manifests
     */
    #[Test]
    public function bundled_manifests_feed_the_sort(): void
    {
        $base = sys_get_temp_dir().'/g7-install-order-'.bin2hex(random_bytes(4));
        $this->tempDirs[] = $base;

        $this->writeManifest($base, 'modules', 'vendor-shop', 'module.json', [
            'identifier' => 'vendor-shop',
            'dependencies' => ['modules' => ['vendor-board'], 'plugins' => ['vendor-pg' => '>=1.0']],
        ]);
        $this->writeManifest($base, 'modules', 'vendor-board', 'module.json', [
            'identifier' => 'vendor-board',
        ]);
        $this->writeManifest($base, 'plugins', 'vendor-pg', 'plugin.json', [
            'identifier' => 'vendor-pg',
            'dependencies' => ['modules' => ['vendor-shop' => '>=1.0']],
        ]);

        $moduleMap = installerLoadBundledDependencyMap($base, 'modules');
        $pluginMap = installerLoadBundledDependencyMap($base, 'plugins');

        $this->assertSame(['vendor-board'], $moduleMap['vendor-shop']);
        $this->assertSame([], $moduleMap['vendor-board']);
        $this->assertSame([], $pluginMap['vendor-pg'], '다른 유형(모듈) 의존은 같은 유형 맵에 들어가지 않습니다.');
        $this->assertSame([], installerLoadBundledDependencyMap($base, 'templates'));

        $this->assertSame(
            ['vendor-board', 'vendor-shop'],
            installerSortSameTypeByDependencies(['vendor-shop', 'vendor-board'], $moduleMap)
        );
    }

    /**
     * 저장 API 가 모듈·플러그인 배열을 정렬한 뒤 state 에 저장하고, 순환은 로그로 남깁니다.
     *
     * save-extensions.php 는 세션·소유권 가드를 거치는 실행 스크립트라 소스 배선을 대조한다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=save_extensions, owner_type=plugin
     *
     * @effects save_extensions_sorts_before_storing, install_order_cycle_keeps_original_and_logs
     */
    #[Test]
    public function save_api_sorts_modules_and_plugins_before_storing(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/api/save-extensions.php');

        $this->assertStringContainsString("require_once dirname(__DIR__).'/includes/install-order.php';", $source);
        $this->assertStringContainsString("\$modules = \$sortInstallOrder(\$modules, 'modules');", $source);
        $this->assertStringContainsString("\$plugins = \$sortInstallOrder(\$plugins, 'plugins');", $source);
        $this->assertStringContainsString("lang('log_install_order_cycle'", $source);
        $this->assertLessThan(
            strpos($source, "\$state['selected_extensions'] = ["),
            strpos($source, "\$modules = \$sortInstallOrder(\$modules, 'modules');"),
            '정렬은 state 저장보다 먼저 일어나야 합니다.'
        );

        foreach (['ko', 'en'] as $locale) {
            $translations = require dirname(__DIR__, 3).'/public/install/lang/'.$locale.'.php';
            $this->assertArrayHasKey('log_install_order_cycle', $translations, $locale);
        }
    }

    /**
     * 매니페스트 픽스처를 씁니다.
     *
     * @param  string  $base  임시 루트
     * @param  string  $type  유형 디렉터리 (modules|plugins)
     * @param  string  $dir  확장 디렉터리 이름
     * @param  string  $file  매니페스트 파일 이름
     * @param  array<string, mixed>  $data  매니페스트 내용
     * @return void 반환값 없음
     */
    private function writeManifest(string $base, string $type, string $dir, string $file, array $data): void
    {
        $root = $base.'/'.$type.'/_bundled/'.$dir;
        if (! is_dir($root)) {
            mkdir($root, 0o755, true);
        }
        file_put_contents($root.'/'.$file, json_encode($data));
    }

    /**
     * 디렉터리를 재귀적으로 지웁니다.
     *
     * @param  string  $path  대상 경로
     * @return void 반환값 없음
     */
    private function removeRecursive(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeRecursive($path.DIRECTORY_SEPARATOR.$entry);
                }
            }
            @rmdir($path);

            return;
        }

        @unlink($path);
    }
}
