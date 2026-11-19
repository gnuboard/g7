<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;

/**
 * [건너뛰고 계속] 이 짝 활성화까지 지나가도 되는지의 판정 회귀 테스트.
 *
 * ## 무엇이 걸려 있는가
 *
 * `*_install` 이 실패로 기록되면 운영자에게 남는 행동은 둘뿐이다.
 *
 *   - **재시도** — 이미 설치된 확장에서는 원리상 성공할 수 없다. 인스톨러는 `--force` 를
 *     넘기지 않고(`--force` 는 `migrate`·`db:seed` 두 곳뿐), `module:install` 은 기존 설치를
 *     발견하면 「이미 설치되었습니다」로 실패를 돌려준다.
 *   - **[건너뛰고 계속]** — 종전 구현은 짝이 되는 `*_activate` 를 **무조건** 함께 지나갔다.
 *
 * 그 짝 처리의 근거는 「설치되지 않은 확장을 활성화할 수 없다」인데, 부수효과가 끝난 뒤
 * 완료 마커를 남기기 전에 요청이 죽으면(앞서 실패로 승격시킨 그 경로) 전제가 깨진다.
 * 그러면 운영자가 고른 확장이 **설치되어 있는데 켜지지 않은 채** 설치가 「완료」로 끝난다 —
 * 라우트도 메뉴도 레이아웃도 없고, 오류·경고·로그가 하나도 남지 않는다.
 *
 * ## 왜 스텁으로 판정기를 주입하는가
 *
 * 실제 판정기(`installerExtensionIsInstalled`)는 `rollback-functions.php` 소유이고 DB 를
 * 조회한다. 여기서 고정하려는 계약은 **DB 조회 방법**이 아니라 「판정 결과가 짝 처리를
 * 가르는가」이므로, 세 갈래(설치됨·미설치·판정 불가)를 스텁으로 주입해 전부 단언한다.
 * 소비부(`installerApplyTaskSkip`)가 `function_exists` 로 판정기를 찾는 구조라 그대로 된다.
 *
 * 커버 축: skip_paired_activate installed·not_installed·undeterminable / outcome pair_skipped·pair_kept
 *
 * 효과 요약(마커 아님 — 평문): skip_keeps_paired_activate_when_extension_is_installed, skip_pairs_activate_when_extension_is_absent, skip_pairs_activate_when_verdict_is_unavailable, skip_cascade_keeps_activate_for_installed_dependents
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerSkipPairedActivateTest extends TestCase
{
    use CleansTemporaryBasePath;

    private static string $base = '';

    private static string $skipReason = '';

    private static bool $booted = false;

    /**
     * 프로세스마다 한 번 임시 기준 경로와 저장, 설정 디렉터리를 준비하고, 설치 판정기 스텁을 상태 헬퍼보다 먼저 선언한 뒤 설치기 설정, 상태, 런타임, 진행 이벤트 소스를 읽어 들인다.
     * 기준 경로 상수가 시스템 임시 경로 밖으로 이미 정의돼 있으면 실제 상태 파일을 지키려고 건너뛰기 사유만 남긴다.
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
                    .'격리 실행: php vendor/bin/phpunit --filter=InstallerSkipPairedActivateTest';

                return;
            }
            self::$base = (string) BASE_PATH;
        } else {
            self::$base = sys_get_temp_dir().'/g7-installer-skippair-'.bin2hex(random_bytes(4));
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

        // 판정기 스텁 — installer-state.php 보다 **먼저** 선언한다. 실제 판정기는
        // rollback-functions.php 소유이며 이 테스트는 그 파일을 로드하지 않는다.
        // 소비부가 전역 `function_exists` 로 찾으므로 스텁은 전역 네임스페이스여야 한다.
        require_once __DIR__.'/stubs/extension_installed_verdict_stub.php';

        require_once $root.'/public/install/includes/installer-state.php';
        require_once $root.'/public/install/includes/installer-runtime.php';
        require_once $root.'/public/install/includes/progress-emitter.php';

        // task-runner.php 는 로드하지 않는다 — 그것이 rollback-functions.php 를 끌어와
        // **진짜 판정기**를 선언해 버려 위 스텁과 충돌한다(그 사실 자체가 「실 경로에서는
        // 판정기가 반드시 로드된다」는 증거다). 이 검사가 고정하는 것은 짝 처리이고,
        // 의존 파급(task-runner 소유)은 InstallerSkipDependencyCascadeTest 가 맡는다.
    }

    /**
     * 설치기 소스를 올리고 건너뛰기 사유가 있으면 건너뛰며, 남은 상태 파일을 지우고 진행 이벤트를 버퍼로 받는다.
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
     * `module_install:vendor-a` 가 실패한 상태를 만든다.
     *
     * 의존 확장은 두지 않는다 — 이 테스트가 가르는 것은 **같은 확장의 짝** 처리이며,
     * 의존 파급은 InstallerSkipDependencyCascadeTest 가 이미 고정한다.
     *
     * @return void 반환값 없음
     */
    private function seedFailedModuleInstall(): void
    {
        $dir = self::$base.'/modules/_bundled/vendor-a';
        @mkdir($dir, 0755, true);
        file_put_contents($dir.'/module.json', json_encode(['identifier' => 'vendor-a', 'name' => 'vendor-a']));

        $state = DEFAULT_INSTALLATION_STATE;
        $state['installation_status'] = 'failed';
        $state['installation_mode'] = 'stepwise';
        $state['config'] = ['vendor_mode' => 'bundled', 'php_binary' => 'php'];
        $state['selected_extensions'] = [
            'admin_templates' => [],
            'user_templates' => [],
            'modules' => ['vendor-a'],
            'plugins' => [],
            'language_packs' => [],
        ];
        $state['completed_tasks'] = [];
        $state['failed_task'] = 'module_install:vendor-a';
        $state['failed_task_target'] = 'vendor-a';

        $this->assertTrue(saveInstallationState($state));
    }

    /**
     * 현재 상태로 건너뛰기를 적용해 수락됐는지 확인하고, 이번에 지나간 태스크 키 목록을 돌려준다.
     *
     * @return array<int, string> 이번 건너뛰기로 지나간 태스크 키
     */
    private function skip(): array
    {
        $result = installerApplyTaskSkip(getInstallationState());

        $this->assertTrue($result['success'] ?? false, '건너뛰기가 수락되지 않았다: '.json_encode($result));

        return $result['skipped'] ?? [];
    }

    /**
     * 확장이 **실제로 설치되어 있으면** 짝 활성화는 지나가지 않는다.
     *
     * 이것이 이 검사가 막는 상태다 — 지나가면 그 확장은 설치된 채 영영 켜지지 않는다.
     *
     * @scenario skip_request=skippable, task=module, task_outcome=failed_extension
     *
     * @effects skip_keeps_paired_activate_when_extension_is_installed
     *
     * @return void 반환값 없음
     */
    public function test_paired_activate_is_kept_when_the_extension_is_actually_installed(): void
    {
        $GLOBALS['g7_test_extension_installed_verdict'] = true;
        $this->seedFailedModuleInstall();

        $skipped = $this->skip();

        $this->assertContains('module_install:vendor-a', $skipped, '실패한 설치 태스크 자신은 지나가야 한다');
        $this->assertNotContains(
            'module_activate:vendor-a',
            $skipped,
            '이미 설치된 확장의 활성화까지 지나갔다 — 그 확장은 설치된 채 켜지지 않고 설치가 「완료」로 끝난다'
        );
    }

    /**
     * 확장이 정말 설치되지 않았으면 짝 활성화도 함께 지나간다 (종전 계약 유지).
     *
     * @scenario skip_request=skippable, task=module, task_outcome=failed_extension
     *
     * @effects skip_pairs_activate_when_extension_is_absent
     *
     * @return void 반환값 없음
     */
    public function test_paired_activate_is_skipped_when_the_extension_is_absent(): void
    {
        $GLOBALS['g7_test_extension_installed_verdict'] = false;
        $this->seedFailedModuleInstall();

        $skipped = $this->skip();

        $this->assertContains('module_install:vendor-a', $skipped);
        $this->assertContains(
            'module_activate:vendor-a',
            $skipped,
            '설치되지 않은 확장의 활성화는 성공할 수 없다 — 함께 지나가야 한다'
        );
    }

    /**
     * 파급 목록도 같은 규칙을 받는다 (재귀).
     *
     * `pending` 판정의 근거 역시 **완료 마커의 부재**다 — 직접 짝만 고치면 같은 결함이
     * 한 단계 밖(의존 확장)에 그대로 남는다. 건너뛴 확장은 미설치, 의존 확장은 설치됨으로
     * 갈라 두 규칙이 한 지점을 공유하는지 본다.
     *
     * @scenario skip_request=skippable, task=module, task_outcome=failed_extension
     *
     * @effects skip_cascade_keeps_activate_for_installed_dependents
     *
     * @return void 반환값 없음
     */
    public function test_dependent_activate_is_kept_when_that_dependent_is_actually_installed(): void
    {
        $GLOBALS['g7_test_extension_installed_verdict'] = null;
        $GLOBALS['g7_test_extension_installed_by_target'] = [
            'vendor-a' => false,  // 건너뛴 확장 자신은 설치되지 않았다
            'vendor-b' => true,   // 의존 확장은 실제로 설치가 끝났다
        ];
        $GLOBALS['g7_test_skip_dependents'] = [
            'pending' => ['plugin_install:vendor-b', 'plugin_activate:vendor-b'],
            'already' => [],
        ];

        $this->seedFailedModuleInstall();

        $skipped = $this->skip();

        $this->assertContains('module_activate:vendor-a', $skipped, '미설치 확장의 짝은 함께 지나가야 한다');
        $this->assertContains('plugin_install:vendor-b', $skipped, '의존 확장의 설치 태스크는 파급 대상이다');
        $this->assertNotContains(
            'plugin_activate:vendor-b',
            $skipped,
            '이미 설치된 의존 확장의 활성화까지 파급으로 지나갔다 — 직접 짝만 고치고 파급을 두면 같은 결함이 한 단계 밖에 남는다'
        );
    }

    /**
     * 판정할 수 없으면 **종전 동작**을 유지한다.
     *
     * DB 설정이 없거나 닿지 못하는 상태에서 동작을 바꾸면, 확인하지 못한 것을 근거로
     * 계약을 바꾸는 것이 된다. fail-safe 방향은 종전(함께 지나감)이다.
     *
     * @scenario skip_request=skippable, task=module, task_outcome=failed_extension
     *
     * @effects skip_pairs_activate_when_verdict_is_unavailable
     *
     * @return void 반환값 없음
     */
    public function test_paired_activate_is_skipped_when_the_verdict_is_unavailable(): void
    {
        $GLOBALS['g7_test_extension_installed_verdict'] = null;
        $this->seedFailedModuleInstall();

        $skipped = $this->skip();

        $this->assertContains('module_install:vendor-a', $skipped);
        $this->assertContains(
            'module_activate:vendor-a',
            $skipped,
            '판정 불가에서 동작이 바뀌었다 — 확인하지 못한 것을 근거로 계약을 바꾸면 안 된다'
        );
    }
}
