<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;

/**
 * [건너뛰고 계속] 의 의존성 파급 회귀 테스트 (9차 #M3).
 *
 * 의존성 사전 검증(dependency_precheck)은 「선택된 확장의 의존 대상이 함께 선택되었는가」를
 * **설치 시작 전 한 번** 판정한다. 그 뒤 운영자가 실패한 확장을 [건너뛰고 계속] 으로 지나가면
 * 그 판정은 더 이상 사실이 아니다 — 종전 구현은 같은 target 의 `_install`→`_activate` 짝만
 * 묶었고, 그 확장에 **의존하는** 다른 확장은 그대로 설치·활성화된 채 「완료」됐다. 오류도
 * 경고도 남지 않는다.
 *
 * 고정하는 계약:
 *  - 아직 실행되지 않은 의존 확장은 함께 지나간다 (설치 순서상 뒤에 오는 경우)
 *  - 이미 설치가 끝난 의존 확장은 되돌릴 수 없으므로 사실을 상태·진행 기록에 남긴다
 *
 * 커버 축: skip_dependency pending_dependent·already_installed_dependent·no_dependent
 *
 * 효과 요약(마커 아님 — 평문): 건너뛴 확장에 의존하는 미실행 확장은 함께 지나가고, 이미 설치된 의존 확장은 경고로 남는다.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerSkipDependencyCascadeTest extends TestCase
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
                    .'격리 실행: php vendor/bin/phpunit --filter=InstallerSkipDependencyCascadeTest';

                return;
            }
            self::$base = (string) BASE_PATH;
        } else {
            self::$base = sys_get_temp_dir().'/g7-installer-skipdep-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$base);
        }

        foreach (['storage/logs', 'storage/installer', 'storage/app', 'config/settings'] as $dir) {
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
            unlink(self::$base.'/storage/installer-state.json');
        }

        setProgressEmitter(new \BufferEmitter);
    }

    /**
     * 모듈 A ← 플러그인 B 의존 관계를 매니페스트로 만든다.
     *
     * @param  array<int, string>  $moduleIds  매니페스트를 만들 모듈 id 목록
     * @param  array<string, array<int, string>>  $pluginDeps  플러그인 id → 의존 모듈 id 목록
     * @return void 반환값 없음
     */
    private function writeFixtures(array $moduleIds, array $pluginDeps): void
    {
        foreach ($moduleIds as $id) {
            $dir = self::$base.'/modules/_bundled/'.$id;
            @mkdir($dir, 0755, true);
            file_put_contents($dir.'/module.json', json_encode(['identifier' => $id, 'name' => $id]));
        }

        foreach ($pluginDeps as $id => $deps) {
            $dir = self::$base.'/plugins/_bundled/'.$id;
            @mkdir($dir, 0755, true);
            file_put_contents($dir.'/plugin.json', json_encode([
                'identifier' => $id,
                'name' => $id,
                'dependencies' => ['modules' => array_fill_keys($deps, '*')],
            ]));
        }
    }

    /**
     * 모듈 vendor-a 와 플러그인 vendor-b 를 고른 stepwise 실패 상태를 상태 파일에 저장한다.
     *
     * @param  array<int, string>  $completed  완료로 기록할 태스크 키 목록
     * @param  array<string, mixed>  $overrides  기본 상태 위에 덮어쓸 필드
     * @return void 반환값 없음
     */
    private function seedState(array $completed, array $overrides = []): void
    {
        $state = DEFAULT_INSTALLATION_STATE;
        $state['installation_status'] = 'failed';
        $state['installation_mode'] = 'stepwise';
        $state['config'] = ['vendor_mode' => 'bundled', 'php_binary' => 'php'];
        $state['selected_extensions'] = [
            'admin_templates' => [],
            'user_templates' => [],
            'modules' => ['vendor-a'],
            'plugins' => ['vendor-b'],
            'language_packs' => [],
        ];
        $state['completed_tasks'] = $completed;
        $state = array_replace($state, $overrides);

        $this->assertTrue(saveInstallationState($state));
    }

    /**
     * 아직 실행되지 않은 의존 확장은 함께 지나간다.
     *
     * 태스크 순서는 모듈 → 플러그인이므로, 모듈을 건너뛰면 그 모듈에 의존하는 플러그인은
     * 아직 실행 전이다. 그대로 두면 의존이 없는 채 설치·활성화된다.
     *
     * @scenario installation_mode=stepwise, skip_request=skippable, task=module, task=plugin
     *
     * @effects skip_cascades_to_pending_dependents
     *
     * @return void 반환값 없음
     */
    public function test_skip_cascades_to_pending_dependents(): void
    {
        $this->writeFixtures(['vendor-a'], ['vendor-b' => ['vendor-a']]);

        $this->seedState([], [
            'failed_task' => 'module_install:vendor-a',
            'failed_task_skippable' => true,
        ]);

        $result = markInstallationTaskSkipped(getInstallationState());
        $this->assertTrue($result['success']);

        $skipped = getInstallationState()['skipped_tasks'];
        $this->assertContains('module_install:vendor-a', $skipped);
        $this->assertContains('module_activate:vendor-a', $skipped, '짝 태스크는 종전대로 함께 지나간다');
        $this->assertContains('plugin_install:vendor-b', $skipped, '건너뛴 모듈에 의존하는 플러그인이 그대로 설치되면 의존이 깨진 채 「완료」된다');
        $this->assertContains('plugin_activate:vendor-b', $skipped);
    }

    /**
     * 이미 설치된 의존 확장은 되돌릴 수 없으므로 사실을 남긴다.
     *
     * @scenario installation_mode=stepwise, skip_request=skippable, task=module, task=plugin
     *
     * @effects skip_records_warning_for_installed_dependents
     *
     * @return void 반환값 없음
     */
    public function test_skip_records_warning_for_already_installed_dependents(): void
    {
        $this->writeFixtures(['vendor-a'], ['vendor-b' => ['vendor-a']]);

        $this->seedState(['plugin_install:vendor-b', 'plugin_activate:vendor-b'], [
            'failed_task' => 'module_install:vendor-a',
            'failed_task_skippable' => true,
        ]);

        markInstallationTaskSkipped(getInstallationState());

        $warnings = getInstallationState()['dependency_skip_warnings'] ?? [];
        $this->assertSame(
            [['dependent' => 'vendor-b', 'skipped' => 'vendor-a']],
            $warnings,
            '이미 설치가 끝난 의존 확장은 조용히 두면 의존이 깨진 사실이 어디에도 남지 않는다'
        );
    }

    /**
     * 의존 관계가 없으면 종전 동작 그대로 — 짝 태스크만 지나간다.
     *
     * @scenario installation_mode=stepwise, skip_request=skippable, task=module
     *
     * @effects stepwise_skip_marks_pair_and_advances
     *
     * @return void 반환값 없음
     */
    public function test_skip_without_dependents_is_unchanged(): void
    {
        $this->writeFixtures(['vendor-a'], ['vendor-b' => []]);

        $this->seedState([], [
            'failed_task' => 'module_install:vendor-a',
            'failed_task_skippable' => true,
        ]);

        markInstallationTaskSkipped(getInstallationState());

        $skipped = getInstallationState()['skipped_tasks'];
        $this->assertSame(['module_install:vendor-a', 'module_activate:vendor-a'], $skipped);
    }
}
