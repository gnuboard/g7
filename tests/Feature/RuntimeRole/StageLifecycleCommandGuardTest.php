<?php

namespace Tests\Feature\RuntimeRole;

use App\Console\RuntimeRoleCommandGuard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * 검수 서버(stage)의 확장 수명주기 CLI — 공유 DB 의 확장 상태를 바꾸는 명령은 안내 후 종료 1.
 *
 * 검수 서버는 운영 서버와 같은 DB 를 쓴다. 검수 서버에서 `template:activate` 를 실행하면 같은 종류의 운영 활성 템플릿이 꺼지고,
 * `module:uninstall --delete-data` 는 운영 테이블을 지운다. 명령 가드는 큐 명령 4개만 막고 있었다.
 * 설치·업데이트·레이아웃 갱신·번들 파일 복원·업그레이드 스텝은 새 확장을 검수 서버에서 시험하는 흐름이라 허용한다.
 *
 * @scenario-manifest tests/scenarios/stage-lifecycle-command-guard.yaml
 */
class StageLifecycleCommandGuardTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 검수 서버에서 막는 수명주기 명령 (모듈·플러그인·템플릿·언어팩의 활성화·비활성화·제거).
     *
     * @var array<int, string>
     */
    private const BLOCKED = [
        'module:activate', 'module:deactivate', 'module:uninstall',
        'plugin:activate', 'plugin:deactivate', 'plugin:uninstall',
        'template:activate', 'template:deactivate', 'template:uninstall',
        'language-pack:activate', 'language-pack:deactivate', 'language-pack:uninstall',
    ];

    /**
     * 검수 서버에서도 허용하는 명령 (설치·업데이트·레이아웃 갱신·번들 파일 복원·업그레이드 스텝).
     *
     * @var array<int, string>
     */
    private const ALLOWED = [
        'module:install', 'module:update', 'module:refresh-layout',
        'plugin:install', 'plugin:update', 'plugin:refresh-layout',
        'template:install', 'template:update', 'template:refresh-layout',
        'language-pack:install', 'language-pack:update',
        'extension:reset-files-from-bundled', 'core:execute-upgrade-steps',
    ];

    /**
     * 시험용 종료 가로채기를 풉니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        RuntimeRoleCommandGuard::$exitUsing = null;

        parent::tearDown();
    }

    /**
     * 역할을 정합니다.
     *
     * @param  string  $role  실행 역할 (live|stage)
     * @return void 반환값 없음
     */
    private function role(string $role): void
    {
        config(['core.runtime_role' => $role, 'core.scheduler.enabled' => true]);
    }

    /**
     * 막는 명령과 허용 명령의 이름이 실제 등록된 Artisan 명령이다 — 이름이 바뀌면 가드가 조용히 빠진다.
     *
     * @scenario case=command_names
     *
     * @effects lifecycle_guard_names_match_registered_commands
     *
     * @return void 반환값 없음
     */
    public function test_guarded_command_names_are_registered(): void
    {
        $registered = array_keys(Artisan::all());

        foreach ([...self::BLOCKED, ...self::ALLOWED] as $command) {
            $this->assertContains($command, $registered, "{$command} 가 등록된 명령이 아니다");
        }
    }

    /**
     * stage 에서 확장 상태를 바꾸는 수명주기 명령은 수명주기 전용 안내와 함께 종료 1 이다.
     *
     * @scenario case=stage_blocked
     *
     * @effects stage_blocks_lifecycle_state_commands
     *
     * @return void 반환값 없음
     */
    public function test_stage_blocks_lifecycle_state_commands(): void
    {
        $this->role('stage');

        $this->assertTrue(Lang::has('common.console.runtime_role.lifecycle_stage'), '수명주기 전용 안내 문구가 있어야 한다');

        foreach (self::BLOCKED as $command) {
            $verdict = RuntimeRoleCommandGuard::verdict($command);

            $this->assertNotNull($verdict, "stage 의 {$command} 를 막지 않았다");
            $this->assertSame(1, $verdict['exit'], "{$command} 는 종료 1 이어야 한다");
            $this->assertSame(
                (string) __('common.console.runtime_role.lifecycle_stage', ['command' => $command]),
                $verdict['message'],
                "{$command} 는 큐 명령 안내가 아니라 수명주기 안내를 내야 한다"
            );
        }
    }

    /**
     * 명령 시작 리스너가 stage 의 수명주기 명령을 안내 출력 후 종료 1 로 끝낸다.
     *
     * @scenario case=stage_blocked
     *
     * @effects stage_blocks_lifecycle_state_commands
     *
     * @return void 반환값 없음
     */
    public function test_listener_prints_notice_and_exits_one_on_stage(): void
    {
        $this->role('stage');

        $exit = null;
        RuntimeRoleCommandGuard::$exitUsing = function (int $code) use (&$exit): void {
            $exit = $code;
        };
        $output = new BufferedOutput;

        (new RuntimeRoleCommandGuard)->handle(new CommandStarting('module:uninstall', new ArrayInput([]), $output));

        $this->assertSame(1, $exit, 'stage 의 module:uninstall 은 종료 1 로 끝나야 한다');
        $this->assertStringContainsString(
            (string) __('common.console.runtime_role.lifecycle_stage', ['command' => 'module:uninstall']),
            $output->fetch()
        );
    }

    /**
     * stage 에서도 설치·업데이트·레이아웃 갱신·번들 파일 복원·업그레이드 스텝은 막지 않는다.
     *
     * @scenario case=stage_allowed
     *
     * @effects stage_allows_install_update_and_maintenance_commands
     *
     * @return void 반환값 없음
     */
    public function test_stage_allows_install_update_and_maintenance_commands(): void
    {
        $this->role('stage');

        foreach (self::ALLOWED as $command) {
            $this->assertNull(RuntimeRoleCommandGuard::verdict($command), "stage 에서도 {$command} 는 허용해야 한다");
        }
    }

    /**
     * live 는 수명주기 명령을 모두 그대로 실행한다 (회귀 축).
     *
     * @scenario case=live_regression
     *
     * @effects live_runs_lifecycle_commands
     *
     * @return void 반환값 없음
     */
    public function test_live_runs_lifecycle_commands(): void
    {
        $this->role('live');

        foreach ([...self::BLOCKED, ...self::ALLOWED] as $command) {
            $this->assertNull(RuntimeRoleCommandGuard::verdict($command), "live 의 {$command} 를 막으면 안 된다");
        }
    }
}
