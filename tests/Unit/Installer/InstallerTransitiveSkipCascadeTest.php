<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;

/**
 * [건너뛰고 계속] 의 의존성 파급이 사슬 끝까지 가는지에 대한 회귀 테스트 (12차 검증 #A10).
 *
 * 배경:
 *   `installerDependentsOfSkippedTarget()` 이 **직접 의존 1단계**만 봤고 호출부도 한 번만
 *   불렀다. A → B → C 사슬(C 를 A 가 간접 의존)에서 C 를 건너뛰면 B 는 함께 지나가지만
 *   A 는 그대로 설치·활성화되어, 의존이 깨진 확장이 「완료」된 채 남는다. 현행 번들 확장이
 *   전부 깊이 1 이라 잠복해 있었을 뿐, 사용자 확장에서는 바로 드러난다.
 *
 * 고정하는 계약:
 *  - 파급은 고정점까지 간다 — 건너뛴 확장에 의존하는 확장이 지나가면 그 확장에 의존하는
 *    확장도 함께 지나간다.
 *  - 이미 설치가 끝난 의존 확장은 되돌릴 수 없으므로 경고만 남기고, 그 확장에 의존하는
 *    확장까지 끌어내리지는 않는다(그 확장은 여전히 설치되어 있다).
 *  - 순환 의존이 들어와도 유한 종료한다.
 *
 * 커버 축: skip_dependency transitive_dependent·cycle
 *
 * 효과 요약(마커 아님 — 평문): 건너뛰기 파급이 간접 의존 확장까지 닿고, 순환 의존에서도 유한 종료한다.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerTransitiveSkipCascadeTest extends TestCase
{
    use CleansTemporaryBasePath;

    private static string $base = '';

    private static string $skipReason = '';

    private static bool $booted = false;

    /**
     * 프로세스마다 한 번 임시 BASE_PATH 와 저장소 디렉토리를 만들고 인스톨러 런타임을 적재한다.
     *
     * BASE_PATH 가 이미 시스템 임시 디렉토리 밖으로 정의돼 있으면 적재하지 않고 건너뛸 사유만 남긴다.
     *
     * @return void 반환값 없음
     */
    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();

        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                self::$skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — 실 상태 파일 파괴 방지를 위해 skip. '
                    .'격리 실행: php vendor/bin/phpunit --filter=InstallerTransitiveSkipCascadeTest';

                return;
            }
            self::$base = (string) BASE_PATH;
        } else {
            self::$base = sys_get_temp_dir().'/g7-installer-transitiveskip-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$base);
        }

        foreach (['storage/logs', 'storage/installer', 'storage/app'] as $dir) {
            if (! is_dir(self::$base.'/'.$dir)) {
                @mkdir(self::$base.'/'.$dir, 0755, true);
            }
        }

        if (! isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = '/install/api/state-management.php';
        }

        $root = dirname(__DIR__, 3);
        require_once $root.'/public/install/includes/config.php';
        require_once $root.'/public/install/includes/functions.php';
        require_once $root.'/public/install/includes/installer-state.php';
        require_once $root.'/public/install/includes/installer-runtime.php';
        require_once $root.'/public/install/includes/progress-emitter.php';
        require_once $root.'/public/install/includes/task-runner.php';
    }

    /**
     * 런타임을 적재하고 이전 테스트의 상태 파일을 지운 뒤 진행 기록을 버퍼 송신기로 받는다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::boot();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }

        if (is_file(self::$base.'/storage/installer-state.json')) {
            @unlink(self::$base.'/storage/installer-state.json');
        }

        setProgressEmitter(new \BufferEmitter);
    }

    /**
     * 모듈 1개 + 플러그인 2개로 A(plugin-c) → B(plugin-b) → C(module-a) 사슬을 만든다.
     *
     * @param  array<string, array{modules?: array<int, string>}>  $moduleDeps  모듈 id → 의존 대상 목록
     * @param  array<string, array{modules?: array<int, string>, plugins?: array<int, string>}>  $pluginDeps  플러그인 id → 의존 대상 목록
     * @return void 반환값 없음
     */
    private function writeFixtures(array $moduleDeps, array $pluginDeps): void
    {
        foreach ($moduleDeps as $id => $deps) {
            $dir = self::$base.'/modules/_bundled/'.$id;
            @mkdir($dir, 0755, true);
            file_put_contents($dir.'/module.json', json_encode([
                'identifier' => $id,
                'name' => $id,
                'dependencies' => ['modules' => array_fill_keys($deps['modules'] ?? [], '*')],
            ]));
        }

        foreach ($pluginDeps as $id => $deps) {
            $dir = self::$base.'/plugins/_bundled/'.$id;
            @mkdir($dir, 0755, true);
            file_put_contents($dir.'/plugin.json', json_encode([
                'identifier' => $id,
                'name' => $id,
                'dependencies' => [
                    'modules' => array_fill_keys($deps['modules'] ?? [], '*'),
                    'plugins' => array_fill_keys($deps['plugins'] ?? [], '*'),
                ],
            ]));
        }
    }

    /**
     * 고른 모듈과 플러그인, 완료 태스크를 담은 stepwise 실패 상태를 상태 파일에 저장한다.
     *
     * @param  array<int, string>  $modules  선택된 모듈 id 목록
     * @param  array<int, string>  $plugins  선택된 플러그인 id 목록
     * @param  array<int, string>  $completed  완료로 기록할 태스크 키 목록
     * @return void 반환값 없음
     */
    private function seedState(array $modules, array $plugins, array $completed): void
    {
        $state = DEFAULT_INSTALLATION_STATE;
        $state['installation_status'] = 'failed';
        $state['installation_mode'] = 'stepwise';
        $state['config'] = ['vendor_mode' => 'bundled', 'php_binary' => 'php'];
        $state['selected_extensions'] = [
            'admin_templates' => [],
            'user_templates' => [],
            'modules' => $modules,
            'plugins' => $plugins,
            'language_packs' => [],
        ];
        $state['completed_tasks'] = $completed;

        $this->assertTrue(saveInstallationState($state));
    }

    /**
     * 모듈을 건너뛰면 그 모듈에 직접 의존하는 플러그인과 그 플러그인에 의존하는 플러그인이 모두 대기 의존 목록에 오르는지 확인한다.
     *
     * @scenario installation_mode=stepwise, skip_request=skippable, task=module, task=plugin
     *
     * @effects skip_cascade_reaches_indirect_dependents
     *
     * @return void 반환값 없음
     */
    public function test_cascade_reaches_the_indirect_dependent(): void
    {
        $this->writeFixtures(
            ['vendor-a' => []],
            [
                'vendor-b' => ['modules' => ['vendor-a']],
                'vendor-c' => ['plugins' => ['vendor-b']],
            ],
        );
        $this->seedState(['vendor-a'], ['vendor-b', 'vendor-c'], []);

        $dependents = installerDependentsOfSkippedTarget('vendor-a', []);

        $this->assertContains('plugin_install:vendor-b', $dependents['pending']);
        $this->assertContains(
            'plugin_install:vendor-c',
            $dependents['pending'],
            'vendor-c 는 vendor-b 를 통해 vendor-a 에 의존한다 — 1단계만 보면 의존이 깨진 채 설치된다.'
        );
    }

    /**
     * 건너뛰고 계속을 실행하면 사슬 끝 플러그인의 설치와 활성화까지 건너뛴 목록에 실리는지 확인한다.
     *
     * @scenario installation_mode=stepwise, skip_request=skippable, task=module, task=plugin
     *
     * @effects skip_cascade_reaches_indirect_dependents
     *
     * @return void 반환값 없음
     */
    public function test_skip_and_continue_marks_the_whole_chain(): void
    {
        $this->writeFixtures(
            ['vendor-a' => []],
            [
                'vendor-b' => ['modules' => ['vendor-a']],
                'vendor-c' => ['plugins' => ['vendor-b']],
            ],
        );
        $this->seedState(['vendor-a'], ['vendor-b', 'vendor-c'], []);

        $state = getInstallationState();
        $state['failed_task'] = 'module_install:vendor-a';
        $this->assertTrue(saveInstallationState($state));

        $result = markInstallationTaskSkipped(getInstallationState());

        $this->assertTrue($result['success']);
        $this->assertContains('plugin_install:vendor-c', $result['skipped']);
        $this->assertContains('plugin_activate:vendor-c', $result['skipped']);
    }

    /**
     * 이미 설치된 중간 의존 확장은 경고 대상에만 오르고 그 뒤 사슬은 대기 의존 목록에 끌려오지 않는지 확인한다.
     *
     * @scenario installation_mode=stepwise, skip_request=skippable, task=module, task=plugin
     *
     * @effects skip_records_warning_for_installed_dependents
     *
     * @return void 반환값 없음
     */
    public function test_installed_dependent_is_warned_but_does_not_drag_its_own_dependents(): void
    {
        $this->writeFixtures(
            ['vendor-a' => []],
            [
                'vendor-b' => ['modules' => ['vendor-a']],
                'vendor-c' => ['plugins' => ['vendor-b']],
            ],
        );
        // vendor-b 는 이미 설치·활성화가 끝났다 — 되돌릴 수 없으므로 경고만 남는다.
        $completed = ['plugin_install:vendor-b', 'plugin_activate:vendor-b'];
        $this->seedState(['vendor-a'], ['vendor-b', 'vendor-c'], $completed);

        $dependents = installerDependentsOfSkippedTarget('vendor-a', $completed);

        $this->assertContains('vendor-b', $dependents['already']);
        $this->assertNotContains(
            'plugin_install:vendor-c',
            $dependents['pending'],
            'vendor-b 는 지나간 것이 아니라 설치되어 있다 — vendor-c 의 의존은 충족된 상태다.'
        );
    }

    /**
     * 두 플러그인이 서로 의존하는 순환이 있어도 의존 판정이 끝나고 두 플러그인을 모두 돌려주는지 확인한다.
     *
     * @scenario installation_mode=stepwise, skip_request=skippable, task=module, task=plugin
     *
     * @effects skip_cascade_terminates_on_dependency_cycle
     *
     * @return void 반환값 없음
     */
    public function test_dependency_cycle_terminates(): void
    {
        $this->writeFixtures(
            ['vendor-a' => []],
            [
                'vendor-b' => ['modules' => ['vendor-a'], 'plugins' => ['vendor-c']],
                'vendor-c' => ['plugins' => ['vendor-b']],
            ],
        );
        $this->seedState(['vendor-a'], ['vendor-b', 'vendor-c'], []);

        $dependents = installerDependentsOfSkippedTarget('vendor-a', []);

        $this->assertContains('plugin_install:vendor-b', $dependents['pending']);
        $this->assertContains('plugin_install:vendor-c', $dependents['pending']);
    }
}
