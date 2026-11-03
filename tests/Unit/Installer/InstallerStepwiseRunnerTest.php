<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 단계별(stepwise) 설치 실행기 회귀 테스트 — task-runner 의 태스크 단위 실행 계약.
 *
 * 실제 Artisan 스폰/부팅은 실행기 seam(`$GLOBALS['g7_installer_artisan_executor_override']`)
 * 으로 대체해 태스크 흐름만 검증한다. 고정하는 계약:
 *
 *  - 태스크 목록은 진행 모드와 무관한 한 목록이고 순서가 고정된다
 *  - runNextInstallationTask 는 서버의 completed_tasks 기준 첫 미완료 태스크 하나만 실행한다
 *  - 요청을 반복하면 목록 전체가 한 번씩 실행되고 completed 로 끝난다 (P2 "태스크 전수 완주")
 *  - R2: 관리자 언어·계정 ENV 가 db_seed 요청 안에서 커맨드 직전에 주입된다 (요청 경계를 넘어도 값이 틀리지 않는다)
 *  - R6: DB 태스크 실패 시 롤백이 러너를 경유하고 completed 마커(db_migrate·db_seed)를 제거한다 —
 *        그래서 재시도는 같은 태스크를 **다시 실행**한다 (P2 "실패 후 재시도")
 *  - W3: 선택 확장 실패는 skippable, 건너뛰면 짝 태스크까지 지나가고 다음 태스크가 이어진다
 *  - A-4: inprocess 확정 상태에서는 --vendor-mode 가 bundled 로 전파된다 (R10)
 *
 * BASE_PATH 는 temp 로 고정한다 (R7 — 실 .env/상태 파일 파괴 금지, skip 가드). 인스톨러 테스트
 * 여럿이 실 저장소 루트를 BASE_PATH 로 define 하므로, 같은 프로세스에서 그쪽이 먼저 돌면 이 클래스는
 * 통째로 skip 되어 커버리지가 조용히 사라진다 — 메서드마다 새 프로세스에서 돌려 상수 충돌을 피한다.
 * 부팅은 setUp 에서 한다 (프로세스 격리에서는 setUpBeforeClass 가 자식 프로세스에서 실행되지 않는다).
 *
 * 커버 축: task_outcome done·failed_db·failed_extension·best_effort_failed / resume_point first_pending·retry_after_failure·already_completed·all_completed / skip_request skippable·required·no_failure / php_binary_source detected_autodetect·configured / task composer·env·db_migrate·db_seed·template·module·plugin·language_pack·settings·cache·complete_flag·config_cache
 *
 * 효과 요약(마커 아님 — 평문): stepwise_runs_first_pending_task_only, stepwise_seed_injects_admin_env_in_same_request, stepwise_db_failure_rolls_back_through_runner, stepwise_retry_re_executes_rolled_back_task, stepwise_full_list_completes_by_repeating_requests, stepwise_skip_marks_pair_and_advances, stepwise_inprocess_propagates_bundled_vendor_mode, stepwise_best_effort_failure_advances, stepwise_lock_loss_surfaces_as_busy, installer_php_binary_precedence_explicit_resolved_default, exception_error_event_carries_failure_path_display_fields, installer_settings_json_carries_locale_and_execution_mode, installer_completion_status_survives_best_effort_retry, installer_task_marker_persistence_is_verified
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerStepwiseRunnerTest extends TestCase
{
    use CleansTemporaryBasePath;
    use ScansPhpSourceFunctions;

    private static string $base = '';

    private static string $skipReason = '';

    private static bool $booted = false;

    /** @var array<int, array{command: string, parameters: array<string|int, mixed>, env: array<string, string|false>}> */
    private array $calls = [];

    /**
     * 인스톨러 런타임을 temp BASE_PATH 로 부팅한다 (프로세스당 1회).
     *
     * @return void 반환값 없음
     */
    private static function bootInstallerRuntime(): void
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
                    .'격리 실행: php vendor/bin/phpunit --filter=InstallerStepwiseRunnerTest';

                return;
            }
            self::$base = (string) BASE_PATH;
        } else {
            self::$base = sys_get_temp_dir().'/g7-installer-stepwise-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$base);
        }

        foreach (['storage/logs', 'storage/installer', 'storage/app', 'storage/temp', 'config/settings'] as $dir) {
            if (! is_dir(self::$base.'/'.$dir)) {
                @mkdir(self::$base.'/'.$dir, 0755, true);
            }
        }

        if (! isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = '/install/api/run-task.php';
        }

        $root = dirname(__DIR__, 3);

        // create_settings_json 태스크가 읽는 카탈로그 기본값 — 완주 루프가 실 파일 구조를 그대로 탄다
        if (! is_file(self::$base.'/config/settings/defaults.json')) {
            @copy($root.'/config/settings/defaults.json', self::$base.'/config/settings/defaults.json');
        }

        require_once $root.'/public/install/includes/config.php';
        require_once $root.'/public/install/includes/functions.php';
        require_once $root.'/public/install/includes/installer-state.php';
        require_once $root.'/public/install/includes/installer-runtime.php';
        require_once $root.'/public/install/includes/progress-emitter.php';
        require_once $root.'/public/install/includes/task-runner.php';
    }

    /**
     * 있을 때만 지운다 — 없는 파일의 unlink 경고가 테스트 결과를 WARN 으로 물들이지 않게.
     *
     * @param  string  ...$paths  지울 파일 경로들(없는 파일은 건너뛴다)
     * @return void 반환값 없음
     */
    private static function removeIfExists(string ...$paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * 설치기 런타임을 준비하고 상태, 진행 기록, 완료 표식, 런타임 파일을 지운 뒤 진행 기록 버퍼와 늘 성공하는 실행기를 끼운다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::bootInstallerRuntime();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }

        $this->calls = [];
        self::removeIfExists(
            self::$base.'/storage/installer-state.json',
            self::$base.'/storage/logs/installation.log',
            self::$base.'/storage/app/g7_installed',
            INSTALLER_RUNTIME_PATH,
        );

        setProgressEmitter(new \BufferEmitter);

        // 기본 실행기: 모든 커맨드 성공 — 호출 시점의 ENV 를 함께 기록한다 (R2 검증용)
        $this->installExecutor(fn (string $command, array $parameters): array => ['exit_code' => 0, 'output' => ["ran {$command}"]]);
    }

    /**
     * 끼운 Artisan 실행기를 걷어내고 시딩이 남길 수 있는 관리자 환경 변수 넷을 비운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        unset($GLOBALS['g7_installer_artisan_executor_override']);
        putenv('INSTALLER_ADMIN_PASSWORD');
        putenv('INSTALLER_ADMIN_LANGUAGE');
        putenv('INSTALLER_ADMIN_NAME');
        putenv('INSTALLER_ADMIN_EMAIL');

        parent::tearDown();
    }

    /**
     * 실행기 seam 을 설치하고 호출 기록을 남긴다.
     *
     * @param  callable  $decider  fn (string $command, array $parameters): array{exit_code:int, output:array}  명령과 인자를 받아 종료 코드와 출력을 정하는 판정 함수
     * @return void 반환값 없음
     */
    private function installExecutor(callable $decider): void
    {
        $GLOBALS['g7_installer_artisan_executor_override'] = function (string $command, array $parameters) use ($decider): array {
            $this->calls[] = [
                'command' => $command,
                'parameters' => $parameters,
                'env' => [
                    'INSTALLER_ADMIN_LANGUAGE' => getenv('INSTALLER_ADMIN_LANGUAGE'),
                    'INSTALLER_ADMIN_PASSWORD' => getenv('INSTALLER_ADMIN_PASSWORD'),
                ],
            ];

            return $decider($command, $parameters);
        };
    }

    /**
     * 설치 상태를 기록한다 (비밀은 runtime.php 로 — R3).
     *
     * @param  array<int, string>  $completed  완료 마커
     * @param  array<string, mixed>  $overrides  state 덮어쓰기
     * @return void 반환값 없음
     */
    private function seedState(array $completed, array $overrides = []): void
    {
        $state = DEFAULT_INSTALLATION_STATE;
        $state['installation_status'] = 'running';
        $state['installation_mode'] = 'stepwise';
        $state['execution_mode_resolved'] = 'subprocess';
        $state['g7_locale'] = 'en';
        $state['config'] = [
            'db_write_host' => 'localhost',
            'db_write_port' => '3306',
            'db_write_database' => 'g7_test',
            'db_write_username' => 'g7',
            'db_prefix' => 'g7_',
            'admin_name' => 'Tester',
            'admin_email' => 'admin@example.com',
            // g7_locale('en') 과 일부러 다르게 둔다 — 같으면 admin_language 경로와 g7_locale
            // 폴백 경로가 구분되지 않아 R2 회귀(값만 조용히 틀리는 유형)를 잡지 못한다.
            'admin_language' => 'ja',
            'vendor_mode' => 'composer',
            'execution_mode' => 'auto',
            'php_binary' => 'php',
        ];
        // 확장 **종류를 전부** 시드한다 (11차 #D5). 빈 배열로 두면 그 종류의 태스크 함수가
        // 아예 생성되지 않아, 「전수 완주」를 자처하는 이 테스트가 plugin·user_template·
        // language_pack 경로를 한 번도 실행하지 않는다 — 그 경로의 vendorModeOptionFromState()
        // 전파를 지워도 전 스위트가 초록이었다(inprocess 설치에서 플러그인만 composer 를 타려
        // 하는 R9/R10 형태의 사고). 10차 #M1 이 getManualCommands 픽스처에 한 조치와 같다.
        $state['selected_extensions'] = [
            'admin_templates' => ['sirsoft-admin_basic'],
            'user_templates' => ['sirsoft-basic'],
            'modules' => ['sirsoft-board'],
            'plugins' => ['sirsoft-gdpr'],
            'language_packs' => ['g7-core-ja'],
        ];
        $state['completed_tasks'] = $completed;

        $state = array_replace($state, $overrides);
        $this->assertTrue(saveInstallationState($state));

        writeInstallerRuntime([
            'db' => ['write' => ['host' => 'localhost', 'port' => '3306', 'database' => 'g7_test', 'username' => 'g7', 'password' => 'pw']],
            'app' => ['key' => generateAppKeyInline()],
            'admin' => ['password' => 'admin-secret'],
            'installer' => ['stepwise_token' => 'tok'],
        ]);
    }

    /**
     * 환경·DB 정리 단계까지 끝난 상태 (다음 = db_migrate)
     *
     * Composer 확인부터 DB 정리까지 끝낸 완료 마커 목록을 돌려준다.
     *
     * @return array<int, string> 다음 미완료 태스크가 db_migrate 가 되는 완료 마커 목록
     */
    private function completedThroughCleanup(): array
    {
        return ['composer_check', 'composer_install', 'env_update', 'key_generate', 'dependency_precheck', 'db_cleanup'];
    }

    /**
     * 태스크 목록의 순서와 키가 고정 목록과 같고, 필수, 선택 확장, best_effort 표시가 태스크마다 맞게 붙는지 확인한다.
     *
     * @scenario task=module, skip_request=skippable
     *
     * @effects installer_task_list_order_and_flags_are_fixed
     *
     * @return void 반환값 없음
     */
    public function test_task_list_order_keys_and_skippable_flags(): void
    {
        $this->seedState([]);

        $tasks = buildInstallationTaskList();
        $keys = array_column($tasks, 'key');

        // 11차 #D5 — 픽스처가 확장 **전 종류**를 시드하도록 넓히면서 목록도 함께 늘었다.
        // 좁은 픽스처에서는 plugin·user_template·language_pack 태스크가 아예 만들어지지
        // 않아 그 경로가 통째로 미도달이었다.
        $this->assertSame([
            'composer_check', 'composer_install', 'env_update', 'key_generate',
            'dependency_precheck', 'db_cleanup', 'db_migrate', 'db_seed',
            'template_install:sirsoft-admin_basic', 'template_activate:sirsoft-admin_basic',
            'module_install:sirsoft-board', 'module_activate:sirsoft-board',
            'plugin_install:sirsoft-gdpr', 'plugin_activate:sirsoft-gdpr',
            'user_template_install:sirsoft-basic', 'user_template_activate:sirsoft-basic',
            'language_pack_install:g7-core-ja',
            'create_settings_json', 'cache_clear', 'complete_flag', 'config_cache',
            // 라우트 캐시 — complete_flag 뒤라 실패해도 설치는 완료로 남는다(best_effort).
            'route_cache',
            // 부트스트랩 리소스 정적 게시 — 없으면 첫 방문자가 자가 치유(1회 API 폴백)를 겪는다.
            // SSE 인라인 목록에만 있던 것을 목록 SSoT(buildInstallationTaskList)로 옮겼다.
            'static_publish',
        ], $keys);

        $byKey = array_column($tasks, null, 'key');
        $this->assertFalse($byKey['db_migrate']['skippable'], 'DB 단계는 필수 — 건너뛰기 불가');
        $this->assertFalse($byKey['template_install:sirsoft-admin_basic']['skippable'], '관리자 템플릿은 필수');
        $this->assertTrue($byKey['module_install:sirsoft-board']['skippable'], '선택 확장은 건너뛰기 가능 (W3)');
        $this->assertTrue($byKey['plugin_install:sirsoft-gdpr']['skippable'], '플러그인도 선택 확장이다');
        $this->assertTrue($byKey['user_template_install:sirsoft-basic']['skippable'], '사용자 템플릿도 선택 확장이다');
        $this->assertTrue($byKey['config_cache']['best_effort']);
        $this->assertTrue($byKey['route_cache']['best_effort'], '라우트 캐시 실패가 완료된 설치를 실패로 뒤집으면 안 된다');
    }

    /**
     * DB 정리까지 끝난 상태에서 요청 하나가 첫 미완료 태스크인 db_migrate 하나만 실행하고 그 마커만 남기는지 확인한다.
     *
     * @scenario installation_mode=stepwise, resume_point=first_pending, task=db_migrate
     *
     * @effects stepwise_runs_first_pending_task_only
     *
     * @return void 반환값 없음
     */
    public function test_run_next_executes_only_first_pending_task(): void
    {
        $this->seedState($this->completedThroughCleanup());

        $run = runNextInstallationTask();

        $this->assertSame('task_done', $run['outcome']);
        $this->assertSame('db_migrate', $run['task']);
        $this->assertCount(1, $this->calls, '요청 하나는 태스크 하나만 실행한다');
        $this->assertSame('migrate', $this->calls[0]['command']);
        $this->assertSame(['--force' => true], $this->calls[0]['parameters']);

        $state = getInstallationState();
        $this->assertContains('db_migrate', $state['completed_tasks']);
        $this->assertNotContains('db_seed', $state['completed_tasks']);
        $this->assertNull($state['current_task']);
        $this->assertSame('running', $state['installation_status']);
    }

    /**
     * R2 — 관리자 언어·비밀번호 ENV 가 db_seed 커맨드 직전(같은 요청)에 주입되고, 끝나면 비워진다.
     *
     * @scenario installation_mode=stepwise, task=db_seed
     *
     * @effects stepwise_seed_injects_admin_env_in_same_request
     *
     * @return void 반환값 없음
     */
    public function test_seed_task_injects_admin_env_in_same_request_and_purges_after(): void
    {
        $this->seedState(array_merge($this->completedThroughCleanup(), ['db_migrate']));

        $run = runNextInstallationTask();

        $this->assertSame('task_done', $run['outcome']);
        $this->assertSame('db_seed', $run['task']);
        $this->assertSame('db:seed', $this->calls[0]['command']);
        $this->assertSame('ja', $this->calls[0]['env']['INSTALLER_ADMIN_LANGUAGE'], '관리자 언어가 커맨드 실행 시점에 ENV 로 도달해야 한다 (R2 — g7_locale 이 아니라 admin_language 가 출처다)');
        $this->assertSame('admin-secret', $this->calls[0]['env']['INSTALLER_ADMIN_PASSWORD']);

        $this->assertFalse(getenv('INSTALLER_ADMIN_PASSWORD'), '시딩 후 평문 ENV 는 해제된다');
        $this->assertArrayNotHasKey('admin', readInstallerRuntime() ?? [], '시딩 후 runtime 의 admin 섹션은 제거된다');
        $this->assertSame('tok', readInstallerRuntime()['installer']['stepwise_token'] ?? null, '토큰 섹션은 purge 에서 살아남는다');
    }

    /**
     * R2 — admin_language 가 없으면 사이트 언어(g7_locale)로 떨어진다.
     *
     * 정상 경로와 폴백 경로가 서로 다른 값을 내는지까지 고정한다 — 두 출처가 같은 값이면
     * 폴백이 깨져도 테스트가 초록으로 남는다.
     *
     * @scenario installation_mode=stepwise, task=db_seed, locale=en
     *
     * @effects stepwise_seed_injects_admin_env_in_same_request
     *
     * @return void 반환값 없음
     */
    public function test_seed_task_falls_back_to_site_locale_when_admin_language_missing(): void
    {
        $this->seedState(array_merge($this->completedThroughCleanup(), ['db_migrate']));

        // seedState 의 overrides 는 최상위 array_replace 라 config 를 통째로 갈아끼운다 —
        // 여기서는 한 키만 빼야 하므로 저장된 상태에서 지우고 다시 쓴다.
        $state = getInstallationState();
        unset($state['config']['admin_language']);
        $this->assertTrue(saveInstallationState($state));

        $run = runNextInstallationTask();

        $this->assertSame('task_done', $run['outcome']);
        $this->assertSame('db:seed', $this->calls[0]['command']);
        $this->assertSame(
            'en',
            $this->calls[0]['env']['INSTALLER_ADMIN_LANGUAGE'],
            'admin_language 부재 시 g7_locale 로 떨어진다 (R2 폴백)'
        );
    }

    /**
     * R6 — DB 태스크 실패 시 completed 마커가 정리된다.
     *
     * 19차 R1 로 기대값이 바뀌었다. 종전에는 `['migrate', 'migrate:rollback']` 과
     * **`['--force' => true]`**(= `--step` 없는 롤백)를 단언했는데, 그것은 13차 #D9 가 금지한
     * 바로 그 형태다 — 배치 지정이 없으면 마지막 배치(= 운영자의 것)가 대상이 된다.
     * `installerMigrateRollbackStepCount()` docblock · `installerRecordMigrationBatchBaseline()`
     * 주석 · `rollback_migrate_range_unknown` 문구 셋이 모두 「범위를 모르면 되돌리지 않는다」를
     * 말하고 있었고, 이 단언만 반대 방향을 계약으로 잠그고 있었다.
     *
     * DB 가 없는 단위 환경에서는 `installerReadMigrationBatches()` 가 접속 불가(null)를 돌려주므로
     * 범위가 확정되지 않는다 → 롤백은 **수행되지 않는다**. 범위가 확정된 경우의 `--step` 전달은
     * `InstallerRollbackDataIntegrityTest` 가 소유한다.
     *
     * @scenario installation_mode=stepwise, task=db_migrate, task_outcome=failed_db
     *
     * @effects stepwise_db_failure_rolls_back_through_runner
     *
     * @return void 반환값 없음
     */
    public function test_db_failure_rolls_back_through_runner_and_clears_markers(): void
    {
        $this->seedState($this->completedThroughCleanup());
        $this->installExecutor(fn (string $command): array => $command === 'migrate'
            ? ['exit_code' => 1, 'output' => ['SQLSTATE[HY000] boom']]
            : ['exit_code' => 0, 'output' => ['rolled back']]);

        $run = runNextInstallationTask();

        $this->assertSame('failed', $run['outcome']);
        $this->assertSame(['migrate'], array_column($this->calls, 'command'));

        foreach ($this->calls as $call) {
            $this->assertNotSame(
                'migrate:rollback',
                $call['command'],
                '범위를 확정하지 못한 채 롤백이 나가면 운영자의 마지막 배치가 사라진다 (#D9 · 19차 R1)',
            );
        }

        $state = getInstallationState();
        $this->assertSame('failed', $state['installation_status']);
        $this->assertSame('db_migrate', $state['failed_task']);
        $this->assertFalse((bool) $state['failed_task_skippable']);
        $this->assertNotContains('db_migrate', $state['completed_tasks']);
        $this->assertNotContains('db_seed', $state['completed_tasks']);

        $events = array_column(getProgressEmitter()->drain(), 'event');
        $this->assertContains('error', $events);
    }

    /**
     * P2 "실패 후 재시도" — 롤백으로 마커가 지워진 태스크는 재개 요청에서 건너뛰지 않고 다시 실행된다 (R6).
     *
     * 재시도는 install-process.php 가 status 를 running 으로 되돌리되 completed_tasks 는 유지한 채
     * 다음 run-task 요청이 오는 흐름이다 — 그 상태 전이를 그대로 재현한다.
     *
     * @scenario installation_mode=stepwise, resume_point=retry_after_failure, task_outcome=failed_db
     *
     * @effects stepwise_retry_re_executes_rolled_back_task
     *
     * @return void 반환값 없음
     */
    public function test_retry_after_db_failure_re_executes_the_failed_task(): void
    {
        $this->seedState($this->completedThroughCleanup());
        $migrateAttempts = 0;
        $this->installExecutor(function (string $command) use (&$migrateAttempts): array {
            if ($command === 'migrate' && ++$migrateAttempts === 1) {
                return ['exit_code' => 1, 'output' => ['SQLSTATE[HY000] boom']];
            }

            return ['exit_code' => 0, 'output' => ['ok']];
        });

        $this->assertSame('failed', runNextInstallationTask()['outcome']);

        $state = getInstallationState();
        $this->assertSame('failed', $state['installation_status']);
        $state['installation_status'] = 'running';
        $state['failed_task'] = null;
        $state['current_task'] = null;
        $this->assertTrue(saveInstallationState($state));

        $run = runNextInstallationTask();

        $this->assertSame('task_done', $run['outcome']);
        $this->assertSame('db_migrate', $run['task'], '롤백된 DB 태스크가 재시도에서 재실행되어야 한다 — 건너뛰면 DB 가 비어 있는 채 "완료" 된다');

        // 19차 R1 — 범위 미확정이면 롤백이 나가지 않는다(위 형제 검사의 근거 참조).
        // 이 검사가 지키는 계약은 「재시도가 그 태스크를 다시 실행한다」이고, 그것은
        // 마커가 지워졌는지가 아니라 **애초에 세워지지 않았는지**로 성립한다.
        $this->assertSame(['migrate', 'migrate'], array_column($this->calls, 'command'));
        $this->assertContains('db_migrate', getInstallationState()['completed_tasks']);
        $this->assertSame('running', getInstallationState()['installation_status']);
    }

    /**
     * SSE·폴링 오케스트레이터(runInstallationTasks)도 한 요청 안에서 전수 완주한다.
     *
     * 이 함수는 저장소 어느 테스트도 부르지 않아, 그 안에 든 두 수정(best-effort 를 pending
     * 필터에서 제외해 stepwise 와 규칙을 맞춘 것 · lock 손실 rethrow)을 지워도 전 스위트가
     * 초록이었다 — 「두 경로의 규칙을 맞췄다」는 수정 자체가 게이트로 잠기지 않은 상태다.
     *
     * @scenario installation_mode=sse, resume_point=all_completed, task_outcome=done
     *
     * @effects sse_orchestrator_completes_the_whole_list_in_one_request
     *
     * @return void 반환값 없음
     */
    public function test_sse_orchestrator_completes_the_whole_list_in_one_request(): void
    {
        $this->seedState($this->completedThroughCleanup());

        runInstallationTasks();

        $state = getInstallationState();
        $this->assertSame('completed', $state['installation_status'] ?? null);
        $this->assertSame(
            array_column(buildInstallationTaskList(), 'key'),
            $state['completed_tasks'],
            '한 요청 안에서 목록의 모든 키가 순서대로 완료 마커에 들어간다'
        );

        $events = array_column(getProgressEmitter()->drain(), 'event');
        $this->assertContains('connected', $events, 'SSE 경로는 연결 이벤트로 시작한다');
        $this->assertContains('completed', $events);
    }

    /**
     * best-effort 실패 태스크는 두 오케스트레이터 모두에서 「지나간 것」으로 셈한다.
     *
     * completed 에만 의존하면 SSE 경로가 그 태스크를 다시 뽑아 stepwise 와 규칙이 갈린다.
     * 그 병합 루프(runInstallationTasks 안의 foreach)가 이 테스트의 대상이다.
     *
     * @scenario installation_mode=sse, task=config_cache, task_outcome=best_effort_failed
     *
     * @effects stepwise_best_effort_failure_advances
     *
     * @return void 반환값 없음
     */
    public function test_sse_orchestrator_treats_best_effort_failures_as_passed(): void
    {
        $this->seedState($this->completedThroughCleanup());
        $state = getInstallationState();
        $state['best_effort_failed_tasks'] = ['config_cache'];
        saveInstallationState($state);

        runInstallationTasks();

        // 병합은 이번 실행의 pending 필터에만 쓰이고 상태에는 기록하지 않는다 —
        // 고정할 계약은 「그 태스크를 다시 실행하지 않는다」이며, 그 결과가 커맨드 호출 목록이다.
        // config_cache 태스크가 부르는 커맨드는 `config:cache` 다 (cache_clear 가 optimize:clear).
        $this->assertNotContains(
            'config:cache',
            array_column($this->calls, 'command'),
            '이미 best-effort 로 지나간 태스크를 SSE 경로가 다시 시도하면 stepwise 와 규칙이 갈린다'
        );
        $this->assertSame('completed', getInstallationState()['installation_status'] ?? null);
    }

    /**
     * P2 "태스크 전수 완주" — run-task 요청을 반복하면 DB 단계부터 마무리까지 모든 태스크가 한 번씩
     * 실행되고 마지막 요청이 completed 로 끝난다. 요청 수 = 남은 태스크 수.
     *
     * @scenario installation_mode=stepwise, resume_point=all_completed, task_outcome=done
     *
     * @effects stepwise_full_list_completes_by_repeating_requests
     *
     * @return void 반환값 없음
     */
    public function test_full_task_list_completes_by_repeating_run_next(): void
    {
        $this->seedState($this->completedThroughCleanup());
        $remaining = count(buildInstallationTaskList()) - count($this->completedThroughCleanup());

        $outcomes = [];
        for ($i = 0; $i <= $remaining; $i++) {
            $run = runNextInstallationTask();
            $outcomes[] = $run['outcome'];
            if ($run['outcome'] === 'completed') {
                break;
            }
            $this->assertSame('task_done', $run['outcome'], "태스크 {$run['task']} 가 실패 없이 끝나야 한다");
            $this->assertSame($remaining - count($outcomes), $run['remaining'], '응답의 remaining 이 남은 태스크 수와 같아야 한다');
        }

        $this->assertSame('completed', end($outcomes));
        $this->assertCount($remaining, array_filter($outcomes, fn (string $o) => $o === 'task_done'), '요청 하나가 태스크 하나 — 요청 수는 남은 태스크 수와 같다');

        $state = getInstallationState();
        $this->assertSame(array_column(buildInstallationTaskList(), 'key'), $state['completed_tasks'], '목록의 모든 키가 순서대로 완료 마커에 있다');
        $this->assertContains('completed', array_column(getProgressEmitter()->drain(), 'event'));
        $this->assertFileExists(self::$base.'/storage/app/g7_installed', 'complete_flag 가 설치 완료 락을 만든다');

        $commands = array_column($this->calls, 'command');
        $this->assertSame(['migrate', 'db:seed'], array_slice($commands, 0, 2));
        $this->assertContains('template:install', $commands);
        $this->assertContains('module:install', $commands);
    }

    /**
     * 설치본 설정 파일이 실제로 무엇을 담고 나가는지 고정한다 (10차 #G3).
     *
     * 완주 루프는 `create_settings_json` 을 **돌리기만** 하고 결과 파일을 한 번도 읽지 않았다.
     * 그 사각에서 두 축이 무검사로 남아 있었다:
     *
     *  - **사이트 기본 언어** — 8차가 「진행 모드에 따라 언어가 갈린다」를 고치며 `$state['g7_locale']`
     *    로 옮긴 값. `getCurrentLanguage()` 로 되돌려도(=세션 의존 회귀) 전 스위트가 초록이었다.
     *  - **`system.execution_mode`** — D33 의 종단이다. Step 3 에서 고른 값이 설치본 설정에 남아야
     *    운영 중 실행 모드가 그 선택을 따른다. 저장 시점(Step 3)만 테스트가 덮고 있었다.
     *
     * @scenario task=settings, locale=en, execution_mode_choice=inprocess
     *
     * @effects installer_settings_json_carries_locale_and_execution_mode
     *
     * @return void 반환값 없음
     */
    public function test_settings_json_carries_site_locale_and_execution_mode_choice(): void
    {
        $this->seedState($this->completedThroughCleanup(), ['g7_locale' => 'en']);

        // Step 3 선택을 명시로 바꾼다 — seedState 기본값은 `auto` 라 「선택이 도달했는가」와
        // 「기본값이 그대로인가」가 구분되지 않는다.
        $state = getInstallationState();
        $state['config']['execution_mode'] = 'inprocess';
        $this->assertTrue(saveInstallationState($state));

        $this->assertTrue(createSettingsJsonSSE()['success']);

        $general = json_decode((string) file_get_contents(self::$base.'/storage/app/settings/general.json'), true);
        $system = json_decode((string) file_get_contents(self::$base.'/storage/app/settings/system.json'), true);

        $this->assertSame(
            'en',
            $general['language'] ?? null,
            '사이트 기본 언어는 상태 파일의 로케일에서 온다 — 세션에서 읽으면 SSE·단계별 실행에서 언제나 ko 로 떨어진다'
        );
        $this->assertSame(
            'inprocess',
            $system['execution_mode'] ?? null,
            'Step 3 실행 방식 선택이 설치본 설정에 도달하지 않으면 D33 이 화면에서만 존재하는 기능이 된다'
        );
    }

    /**
     * W3 — 선택 확장 실패는 건너뛸 수 있고, 건너뛰면 짝(activate)까지 지나가 다음 태스크가 이어진다.
     *
     * @scenario task=module, task_outcome=failed_extension, skip_request=skippable
     *
     * @effects stepwise_skip_marks_pair_and_advances
     *
     * @return void 반환값 없음
     */
    public function test_extension_failure_is_skippable_and_skip_advances_past_pair(): void
    {
        $this->seedState(array_merge($this->completedThroughCleanup(), [
            'db_migrate', 'db_seed', 'template_install:sirsoft-admin_basic', 'template_activate:sirsoft-admin_basic',
        ]));
        $this->installExecutor(fn (string $command): array => $command === 'module:install'
            ? ['exit_code' => 1, 'output' => ['dependency missing']]
            : ['exit_code' => 0, 'output' => []]);

        $run = runNextInstallationTask();

        $this->assertSame('failed', $run['outcome']);
        $this->assertSame('module_install', $run['task']);
        $this->assertSame('sirsoft-board', $run['target']);

        $state = getInstallationState();
        $this->assertSame('module_install:sirsoft-board', $state['failed_task']);
        $this->assertTrue((bool) $state['failed_task_skippable']);

        $skip = markInstallationTaskSkipped($state);
        $this->assertTrue($skip['success'], $skip['message_key']);
        $this->assertSame(['module_install:sirsoft-board', 'module_activate:sirsoft-board'], $skip['skipped']);

        $state = getInstallationState();
        $this->assertContains('module_install:sirsoft-board', $state['completed_tasks']);
        $this->assertContains('module_activate:sirsoft-board', $state['completed_tasks']);
        $this->assertSame($skip['skipped'], $state['skipped_tasks']);
        $this->assertNull($state['failed_task']);

        $pending = array_values(array_filter(
            buildInstallationTaskList(),
            fn (array $t) => ! in_array($t['key'], $state['completed_tasks'], true),
        ));
        // 건너뛴 짝 바로 다음은 그 다음 선택 확장이다 (11차 #D5 로 픽스처가 넓어졌다)
        $this->assertSame('plugin_install:sirsoft-gdpr', $pending[0]['key'], '건너뛴 짝 다음 태스크로 이어진다');
    }

    /**
     * 실패한 필수 DB 태스크에 대한 건너뛰기 요청이 거부 키와 함께 막히고 완료 마커가 생기지 않는지 확인한다.
     *
     * @scenario task=db_migrate, task_outcome=failed_db, skip_request=required
     *
     * @effects required_task_skip_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_required_task_cannot_be_skipped(): void
    {
        $this->seedState($this->completedThroughCleanup(), [
            'installation_status' => 'failed',
            'failed_task' => 'db_migrate',
        ]);

        $skip = markInstallationTaskSkipped(getInstallationState());

        $this->assertFalse($skip['success']);
        $this->assertSame('skip_task_not_allowed', $skip['message_key']);
        $this->assertNotContains('db_migrate', getInstallationState()['completed_tasks']);
    }

    /**
     * R10/A-4 — inprocess 확정 상태에서는 운영자가 composer 를 골랐어도 bundled 로 전파된다.
     *
     * @scenario execution_mode_resolved=inprocess, vendor_mode_choice=bundled, mode_touchpoint=vendor_mode_read, task=plugin
     *
     * @effects stepwise_inprocess_propagates_bundled_vendor_mode
     *
     * @return void 반환값 없음
     */
    public function test_inprocess_state_propagates_bundled_vendor_mode_to_extension_commands(): void
    {
        $this->seedState(array_merge($this->completedThroughCleanup(), [
            'db_migrate', 'db_seed', 'template_install:sirsoft-admin_basic', 'template_activate:sirsoft-admin_basic',
        ]), ['execution_mode_resolved' => 'inprocess']);

        $this->assertSame('bundled', vendorModeFromState());

        $run = runNextInstallationTask();

        $this->assertSame('task_done', $run['outcome']);
        $this->assertSame('module:install', $this->calls[0]['command']);
        $this->assertSame(['sirsoft-board', '--vendor-mode' => 'bundled'], $this->calls[0]['parameters']);

        // 확장 **종류마다** 전파를 확인한다 (11차 #D5). 모듈 하나만 단언하면 형제 경로
        // (플러그인·사용자 템플릿)의 `+ vendorModeOptionFromState()` 를 지워도 초록이고,
        // 그 상태의 결과는 inprocess 로 확정된 설치에서 그 확장만 composer 를 타려 하는
        // 것이다 — R9/R10 이 막으려던 형태다.
        $expectations = [
            'plugin:install' => 'sirsoft-gdpr',
            'user-template:install' => 'sirsoft-basic',
        ];

        foreach ($expectations as $command => $identifier) {
            $this->calls = [];
            $state = getInstallationState();
            $state['completed_tasks'][] = $state['current_task'] ?? '';
            saveInstallationState($state);

            do {
                $this->calls = [];
                $next = runNextInstallationTask();
            } while ($next['outcome'] === 'task_done' && ($this->calls[0]['command'] ?? '') !== $command && $next['remaining'] > 0);

            if (($this->calls[0]['command'] ?? '') !== $command) {
                continue; // 그 종류가 이 목록에 없으면 건너뛴다 (픽스처 축소 대비)
            }

            $this->assertSame(
                [$identifier, '--vendor-mode' => 'bundled'],
                $this->calls[0]['parameters'],
                $command.' 도 확정된 vendor 방식을 전파해야 한다'
            );
        }
    }

    /**
     * subprocess 로 확정된 설치에서는 운영자가 고른 composer 방식이 확장 설치 명령에 그대로 실리는지 확인한다.
     *
     * @scenario execution_mode_resolved=subprocess, vendor_mode_choice=composer, task=module
     *
     * @effects stepwise_inprocess_propagates_bundled_vendor_mode
     *
     * @return void 반환값 없음
     */
    public function test_subprocess_state_keeps_operator_vendor_mode(): void
    {
        $this->seedState(array_merge($this->completedThroughCleanup(), [
            'db_migrate', 'db_seed', 'template_install:sirsoft-admin_basic', 'template_activate:sirsoft-admin_basic',
        ]));

        runNextInstallationTask();

        $this->assertSame(['sirsoft-board', '--vendor-mode' => 'composer'], $this->calls[0]['parameters']);
    }

    /**
     * 새로고침 재개 — 완료 마커가 있는 태스크는 실행하지 않고 건너뛴 이벤트만 재생한다.
     *
     * @scenario installation_mode=stepwise, resume_point=already_completed, task=db_migrate
     *
     * @effects stepwise_runs_first_pending_task_only
     *
     * @return void 반환값 없음
     */
    public function test_already_completed_tasks_are_not_re_executed(): void
    {
        $this->seedState(array_merge($this->completedThroughCleanup(), ['db_migrate']));

        $tasks = buildInstallationTaskList();
        $migrate = array_values(array_filter($tasks, fn ($t) => $t['key'] === 'db_migrate'))[0];

        $run = runSingleInstallationTask($migrate, getInstallationState()['completed_tasks']);

        $this->assertSame('already_completed', $run['outcome']);
        $this->assertCount(0, $this->calls, '완료된 태스크를 다시 실행하면 안 된다');
    }

    /**
     * 모든 태스크에 완료 마커가 있으면 아무 명령도 실행하지 않고 남은 수 0 과 완료 이벤트로 끝나는지 확인한다.
     *
     * @scenario installation_mode=stepwise, resume_point=all_completed
     *
     * @effects stepwise_full_list_completes_by_repeating_requests
     *
     * @return void 반환값 없음
     */
    public function test_no_pending_tasks_finalizes_with_completed_event(): void
    {
        $all = array_column(buildInstallationTaskListForSeed($this), 'key');
        $this->seedState($all);

        $run = runNextInstallationTask();

        $this->assertSame('completed', $run['outcome']);
        $this->assertSame(0, $run['remaining']);
        $this->assertContains('completed', array_column(getProgressEmitter()->drain(), 'event'));
        $this->assertCount(0, $this->calls);
    }

    /**
     * 완주는 `installation_status` 를 completed 로 남긴다 (11차 #A6).
     *
     * 그 값을 쓰는 지점은 complete_flag 태스크 본문 하나뿐인데 best-effort 인 config_cache 가
     * **그 뒤**에 온다. config_cache 만 실패한 뒤 재시도하면 complete_flag 는 이미 마커가 있어
     * 재실행되지 않으므로, 설치가 완주해도 상태는 재시작이 써 둔 running 으로 남는다.
     * 브라우저가 finalize 호출 전에 닫히면 그 뒤의 조회·요약이 계속 「진행 중」을 보고한다.
     *
     * @scenario resume_point=all_completed, task=complete_flag, task_outcome=best_effort_failed
     *
     * @effects installer_completion_status_survives_best_effort_retry
     *
     * @return void 반환값 없음
     */
    public function test_completion_marks_installation_status_even_when_complete_flag_is_not_re_executed(): void
    {
        $all = array_column(buildInstallationTaskListForSeed($this), 'key');
        $this->seedState($all, ['installation_status' => 'running']);

        $run = runNextInstallationTask();

        $this->assertSame('completed', $run['outcome']);
        $this->assertSame('completed', getInstallationState()['installation_status'], '완주했는데 상태가 running 으로 남으면 화면이 「진행 중」에 고착된다');
        $this->assertNotNull(getInstallationState()['installation_completed_at'] ?? null);
    }

    /**
     * 완료 마커가 저장되지 않으면 태스크를 성공으로 넘기지 않는다 (11차 #A5).
     *
     * markTaskCompleted() 는 저장 성공 여부를 돌려주는데 호출부 19곳이 전부 그 값을 버렸다.
     * 마커만 없는 상태가 되면 단계별 실행은 completed_tasks 에 없는 첫 작업을 다시 뽑으므로
     * **같은 태스크를 무한 반복**한다 — 오류도 없고 진행률만 제자리이며, 클라이언트의 정지
     * 감지는 task_done 마다 재무장되어 영원히 발화하지 않는다.
     *
     * @scenario installation_mode=stepwise, task_outcome=failed_db
     *
     * @effects installer_task_marker_persistence_is_verified
     *
     * @return void 반환값 없음
     */
    public function test_task_without_persisted_marker_is_reported_as_failure_not_success(): void
    {
        $this->seedState($this->completedThroughCleanup());

        // 태스크는 성공하지만 마커가 남지 않는 상태를 만든다 — 상태 파일을 읽기 전용으로
        // 만들 수 없는 환경이 있으므로, 마커를 지우는 것으로 같은 관측을 만든다.
        $task = [
            'id' => 'db_migrate',
            'key' => 'db_migrate',
            'target' => null,
            'function' => __NAMESPACE__.'\markerLosingTaskForSeed',
            'args' => [],
            'best_effort' => false,
        ];

        $result = runSingleInstallationTask($task, $this->completedThroughCleanup());

        $this->assertSame('failed', $result['outcome'], '마커가 남지 않았는데 done 으로 넘기면 같은 태스크가 무한 반복된다');
        $this->assertSame('error_task_marker_not_persisted', $result['result']['message_key'] ?? null);
    }

    /**
     * best-effort 태스크(config_cache 등)가 실패해도 단계별 실행은 멈추지 않는다.
     *
     * 실패를 completed 로 기록하지 않으면 다음 요청이 같은 태스크를 다시 뽑아 무한 루프가 된다 —
     * config_cache 는 목록 최후미라 completed 가 영영 나오지 않아 finalize 가 수행되지 않는다
     * (감사 2차 #1). 실패는 best_effort_failed_tasks 에 따로 남겨 화면이 "경고" 로 보여 주고
     * 재시도 때 다시 시도할 수 있게 한다.
     *
     * @scenario installation_mode=stepwise, task=config_cache, task_outcome=best_effort_failed
     *
     * @effects stepwise_best_effort_failure_advances
     *
     * @return void 반환값 없음
     */
    public function test_best_effort_failure_advances_instead_of_stalling(): void
    {
        $all = array_column(buildInstallationTaskListForSeed($this), 'key');
        $this->seedState(array_values(array_diff($all, ['config_cache'])));
        $this->installExecutor(fn (string $command): array => $command === 'config:cache'
            ? ['exit_code' => 1, 'output' => ['Unable to write cache']]
            : ['exit_code' => 0, 'output' => []]);

        $first = runNextInstallationTask();

        $this->assertSame('task_done', $first['outcome']);
        $this->assertSame('config_cache', $first['task']);
        $this->assertSame(['config_cache'], getInstallationState()['best_effort_failed_tasks'] ?? []);
        $this->assertNotContains('config_cache', getInstallationState()['completed_tasks']);

        $events = getProgressEmitter()->drain();
        $complete = array_values(array_filter($events, fn ($e) => $e['event'] === 'task_complete' && ($e['data']['task'] ?? null) === 'config_cache'));
        $this->assertCount(1, $complete, '실패한 best-effort 태스크도 화면이 지나간 것으로 표시하도록 task_complete 를 보낸다');
        $this->assertTrue((bool) ($complete[0]['data']['best_effort_failed'] ?? false));

        $second = runNextInstallationTask();

        $this->assertSame('completed', $second['outcome'], '실패한 best-effort 태스크는 다음 요청에서 다시 뽑히지 않고 설치가 끝난다');
        $this->assertSame(['config:cache'], array_column($this->calls, 'command'), 'config:cache 는 한 번만 실행된다 (무한 루프 없음)');
    }

    /**
     * 태스크 도중 워커 lock 을 잃으면 빈 응답(exit)이 아니라 예외로 올라온다 — run-task.php 가
     * busy 로 응답해 브라우저가 재시도하게 한다. 일반 실패(failed)로 뭉개지면 안 된다 (감사 2차 #8).
     *
     * @scenario installation_mode=stepwise, resume_point=first_pending
     *
     * @effects stepwise_lock_loss_surfaces_as_busy, lock_loss_inside_task_body_is_not_a_task_failure
     *
     * @return void 반환값 없음
     */
    public function test_lock_loss_mid_task_surfaces_as_exception_not_failure(): void
    {
        $this->seedState($this->completedThroughCleanup(), ['active_worker_id' => 'other-worker', 'last_heartbeat' => time()]);
        setProgressEmitter(new \BufferEmitter('mine'));

        $this->expectException(\InstallerWorkerLockLostException::class);

        runNextInstallationTask();
    }

    /**
     * D35 — 인스톨러 subprocess 경로의 PHP 바이너리 우선순위: 운영자 명시 > 설치 시작 시 자동 탐색된 경로 > 'php'.
     *
     * @scenario php_binary_source=configured, runner_strategy=subprocess
     *
     * @effects installer_php_binary_precedence_explicit_resolved_default
     *
     * @return void 반환값 없음
     */
    public function test_php_binary_precedence_explicit_then_resolved_then_default(): void
    {
        $this->seedState($this->completedThroughCleanup());
        $this->assertSame('php', getPhpBinary());

        $state = getInstallationState();
        $state['php_binary_resolved'] = '/opt/php83/bin/php';
        $this->assertTrue(saveInstallationState($state));
        $this->assertSame('/opt/php83/bin/php', getPhpBinary(), '자동 탐색된 경로가 실행에 쓰여야 한다 — 판정과 실행이 어긋나면 전 태스크가 실패한다');

        $state = getInstallationState();
        $state['config']['php_binary'] = '/usr/local/bin/php8.3';
        $this->assertTrue(saveInstallationState($state));
        $this->assertSame('/usr/local/bin/php8.3', getPhpBinary(), '운영자가 명시한 경로가 자동 탐색보다 우선한다');
    }

    /**
     * 실패한 단계가 없으면 건너뛰기 요청은 거부된다.
     *
     * [건너뛰고 계속] 은 실패 화면에서만 나타나지만 엔드포인트는 상태와 무관하게 호출될 수 있다 —
     * 실패가 없는데 받아 주면 아직 돌지도 않은 태스크가 completed·skipped 로 박혀 그 단계가
     * 영원히 실행되지 않는다(오류 없이 설치물만 빈다).
     *
     * @scenario skip_request=no_failure, task_outcome=done
     *
     * @effects skip_request_without_failure_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_skip_request_without_failure_is_rejected(): void
    {
        $this->seedState($this->completedThroughCleanup());

        $state = getInstallationState();
        $this->assertSame('running', $state['installation_status']);

        $result = markInstallationTaskSkipped($state);

        $this->assertFalse($result['success']);
        $this->assertSame('skip_task_not_failed', $result['message_key']);
        $this->assertSame([], $result['skipped']);

        $after = getInstallationState();
        $this->assertSame(
            $state['completed_tasks'],
            $after['completed_tasks'],
            '거부된 건너뛰기는 완료 마커를 건드리지 않는다'
        );
    }

    /**
     * 형태 규칙에 걸린 PHP 경로는 폴백하되 **사유를 남긴다**.
     *
     * 조용히 'php' 로 떨어지면 판정(subprocess 가능)과 실행(전 태스크 실패)이 어긋난 이유를
     * 운영자가 알 방법이 없다. 정책 자체는 불변이다 — 공백이 든 경로는 인자 주입 차단을 위해
     * 계속 거부한다. 기록은 경로당 1회(이 함수는 커맨드마다 호출된다).
     *
     * @scenario php_binary_source=configured, runner_strategy=subprocess
     *
     * @effects rejected_php_binary_path_falls_back_with_logged_reason
     *
     * @return void 반환값 없음
     */
    public function test_rejected_php_binary_path_falls_back_and_logs_reason_once(): void
    {
        $this->seedState($this->completedThroughCleanup());

        // 실제 문구로 판정한다 — 키만 확인하면 `:path` 치환 누락(운영자 화면에 자리표시자 노출)을 놓친다
        $GLOBALS['translations'] = require dirname(__DIR__, 3).'/public/install/lang/ko.php';

        $state = getInstallationState();
        $state['config']['php_binary'] = 'C:\\Program Files\\PHP\\php.exe';
        $this->assertTrue(saveInstallationState($state));

        $this->assertSame('php', getPhpBinary(), '형태 규칙 위반 경로는 실행 바이너리가 되지 않는다');

        $logPath = self::$base.'/storage/logs/installation.log';
        $after = is_file($logPath) ? (string) file_get_contents($logPath) : '';
        $this->assertStringContainsString(
            'C:\\Program Files\\PHP\\php.exe',
            $after,
            '거부된 경로가 진행 기록에 남아야 한다'
        );

        $occurrences = substr_count($after, 'C:\\Program Files\\PHP\\php.exe');
        getPhpBinary();
        getPhpBinary();
        $repeated = (string) file_get_contents($logPath);

        $this->assertSame(
            $occurrences,
            substr_count($repeated, 'C:\\Program Files\\PHP\\php.exe'),
            '같은 경로는 한 번만 기록한다 (커맨드마다 호출되므로)'
        );
    }

    /**
     * 예외로 죽은 태스크의 error 이벤트도 실패 경로(handleInstallationTaskFailure)와 같은
     * 표시 정보를 실어야 한다 — 싣지 않으면 SSE·stepwise 는 [건너뛰고 계속] 과 수동 복구
     * 안내가 없고, 새로고침하면 그제야 나타난다 (§16.11.3 #13 이 state 만 고친 잔여 축).
     *
     * @scenario task=module, task_outcome=failed_extension, skip_request=skippable
     *
     * @effects exception_error_event_carries_failure_path_display_fields
     *
     * @return void 반환값 없음
     */
    public function test_exception_error_event_carries_the_same_display_fields_as_the_failure_path(): void
    {
        $this->seedState($this->completedThroughCleanup(), ['current_task' => 'module_install:sirsoft-board']);
        getProgressEmitter()->drain();

        recordInstallationRunException(new \RuntimeException('boom'));

        $events = getProgressEmitter()->drain();
        $errors = array_values(array_filter($events, fn ($e) => $e['event'] === 'error'));
        $this->assertCount(1, $errors);
        $data = $errors[0]['data'];

        $this->assertSame('module_install', $data['task'] ?? null, '합성 키는 종류/대상으로 분리해 보낸다');
        $this->assertSame('sirsoft-board', $data['target'] ?? null);
        $this->assertTrue($data['skippable'] ?? false, '선택 확장은 예외로 죽어도 건너뛸 수 있다');
        $this->assertNotEmpty($data['manual_commands'] ?? [], '수동 복구 명령이 화면에 도달해야 한다');
    }

    /**
     * 자리표시자를 가진 message_key 는 치환값(message_params)을 함께 날라야 한다.
     *
     * 화면은 `message_key` 가 있으면 그것을 우선 번역한다(translateWithKey). 그런데
     * 서버가 키만 보내면 번역 결과에 `:errors` 같은 자리표시자가 그대로 남는다 —
     * 무엇이 왜 실패했는지가 통째로 사라지고 개발자 토큰만 화면에 남는다.
     * 새로고침 복원 경로에는 치환된 문장이 아예 없으므로(state 는 키만 저장) 폴백으로
     * 메울 수도 없다. 따라서 치환값이 이벤트와 state 양쪽에 실려야 한다.
     *
     * @scenario task_outcome=failed_extension, task=module
     *
     * @effects failure_carries_message_params_for_placeholder_keys
     *
     * @return void 반환값 없음
     */
    public function test_failure_carries_message_params_for_placeholder_keys(): void
    {
        $this->seedState($this->completedThroughCleanup());
        getProgressEmitter()->drain();

        handleInstallationTaskFailure(
            ['id' => 'composer_install', 'target' => null, 'key' => 'composer_install', 'skippable' => false],
            [
                'success' => false,
                'message' => 'vendor-bundle.zip 무결성 검증에 실패했습니다: vendor/foo/bar.php',
                'message_key' => 'error_vendor_bundle_integrity_failed',
                'message_params' => ['errors' => 'vendor/foo/bar.php'],
            ]
        );

        $errors = array_values(array_filter(getProgressEmitter()->drain(), fn ($e) => $e['event'] === 'error'));
        $this->assertCount(1, $errors);

        $this->assertSame(
            ['errors' => 'vendor/foo/bar.php'],
            $errors[0]['data']['message_params'] ?? null,
            'error 이벤트가 치환값을 실어야 화면이 자리표시자를 메운다'
        );

        $this->assertSame(
            ['errors' => 'vendor/foo/bar.php'],
            getInstallationState()['error_message_params'] ?? null,
            '새로고침 복원 경로에는 치환된 문장이 없으므로 state 도 치환값을 보관해야 한다'
        );
    }

    /**
     * 자리표시자를 가진 키를 내보내는 태스크는 치환값을 같은 반환에 실어야 한다.
     *
     * 반환에 `message_params` 가 없으면 전달 배선이 있어도 화면에는 여전히 `:errors` 가
     * 남는다 — 생산 지점과 전달 경로는 별개 축이다. 대상 키를 손으로 열거하지 않고
     * 언어 파일에서 **자리표시자를 가진 키**를 도출하므로, 나중에 그런 키를 새로
     * 내보내는 지점이 생기면 이 테스트가 스스로 잡는다.
     *
     * @scenario locale=ko, task_outcome=failed_extension
     *
     * @effects failure_carries_message_params_for_placeholder_keys
     *
     * @return void 반환값 없음
     */
    public function test_placeholder_message_keys_are_produced_with_their_params(): void
    {
        $root = dirname(__DIR__, 3);
        $translations = require $root.'/public/install/lang/ko.php';

        $placeholderKeys = [];
        foreach ($translations as $key => $value) {
            if (is_string($value) && preg_match('/:[a-z_]+/', $value)) {
                $placeholderKeys[$key] = true;
            }
        }
        $this->assertNotEmpty($placeholderKeys, '자리표시자를 가진 키가 하나도 없다면 이 검사가 무의미하다');

        // 모집단은 인스톨러 PHP 전수다 — 손으로 적은 목록은 「자동 검출」이 아니다.
        // 실제로 종전 3파일 목록은 execution-mode.php(error_stepwise_not_running 의 `:status`)를
        // 놓치고 있었고, 그 지점의 message_params 를 지워도 이 테스트가 초록이었다.
        // 재귀 스캔 + `views/` 포함 (12차 L-2). 종전에는 `api`·`includes` 를 한 겹 glob 으로만
        // 훑어, 화면(`views/**`)의 message_key 생산 지점과 하위 디렉토리가 통째로 모집단 밖이었다.
        $sources = [];
        $installDir = $root.'/public/install';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($installDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($installDir) + 1));
            if (str_starts_with($relative, 'lang/') || str_starts_with($relative, 'assets/')) {
                continue;   // 언어 파일은 키의 **정의**이고, assets 에는 PHP 가 없다
            }
            $sources[] = $relative;
        }
        sort($sources);
        $this->assertGreaterThanOrEqual(20, count($sources), '인스톨러 PHP 모집단이 이보다 작을 수 없다');

        $checked = 0;

        foreach ($sources as $relative) {
            // 주석을 지운 뒤 대조한다 — 원문에는 주석 처리된 배선도 글자로 남아, 원문 대조는 「그 배선이 살아 있다」를 증명하지 못한다 (12차 메타 검사 ①).
            $source = $this->stripComments((string) file_get_contents($root.'/public/install/'.$relative));

            if (! preg_match_all("/'message_key'\s*=>\s*'([a-z0-9_]+)'/", $source, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $keyOffsets = array_map(static fn (array $m) => $m[1], $matches[1]);

            foreach ($matches[1] as $index => $match) {
                [$key, $offset] = $match;
                if (! isset($placeholderKeys[$key])) {
                    continue;
                }

                $checked++;

                // 창의 앞뒤를 **이웃 message_key** 로 끊는다 — 한 반환 배열에는 message_key 가
                // 하나뿐이므로 그것이 "같은 반환" 의 경계 근사다. 고정 바이트 창은 사이에 낀
                // 한국어 주석(문자당 3바이트)만으로 경계를 넘겨 오탐을 냈다.
                //
                // 앞쪽도 반드시 끊는다 (9차 #G11): 앞을 고정 800바이트로만 두면 **이전 반환의**
                // message_params 가 창에 들어와 이 키의 단언을 대신 만족시킨다 — 지점 하나만
                // 늘어도 검사가 조용히 무효가 된다.
                $previous = $index > 0 ? $keyOffsets[$index - 1] : 0;
                $start = max($previous, $offset - 800);
                $next = $keyOffsets[$index + 1] ?? strlen($source);
                $end = min($next, $offset + 1500);

                // 「키가 창 안에 있다」가 아니라 「그 값이 비어 있지 않다」를 본다 (12차 M-2).
                // `'message_params' => []` 는 종전 판정을 통과하면서 화면에는 자리표시자를
                // 그대로 내보낸다 — 7차 #1·8차 #16 이 닫은 결함군의 재현 통로다.
                $this->assertMatchesRegularExpression(
                    "/'message_params'\s*=>\s*(?!\[\s*\])\S/",
                    substr($source, $start, max(1, $end - $start)),
                    "{$relative} 의 {$key} 는 자리표시자를 가지므로 같은 반환에 **비어 있지 않은** message_params 가 있어야 한다"
                );
            }
        }

        $this->assertGreaterThan(0, $checked, '자리표시자 키를 message_key 로 내보내는 지점이 하나는 있어야 한다');
    }

    /**
     * db_seed 의 조기 실패(관리자 정보 부재)도 실패 경로가 읽는 키로 사유를 돌려야 한다.
     *
     * 반환이 `'error'` 키를 쓰면 handleInstallationTaskFailure 가 그것을 읽지 않아
     * (읽는 키는 message·message_key·detail) 화면에는 사유 없는 일반 「설치 실패」만
     * 남는다 — runtime.php 가 손상돼 관리자 비밀번호가 사라진 상황에서 운영자가
     * 원인을 알 수 있는 유일한 통로가 막힌다. 문구도 언어 파일을 거쳐야 en 로케일에
     * 한국어가 새지 않는다.
     *
     * @scenario task=db_seed, task_outcome=failed_db, locale=en
     *
     * @effects seed_early_failure_reports_through_failure_path_keys
     *
     * @return void 반환값 없음
     */
    public function test_seed_early_failure_reports_through_the_keys_the_failure_path_reads(): void
    {
        $state = DEFAULT_INSTALLATION_STATE;
        $state['installation_status'] = 'running';
        $state['config'] = ['admin_name' => 'Tester'];   // admin_email 없음 → 조기 실패
        saveInstallationState($state);

        // 실제 문구로 판정한다 — 키만 확인하면 언어 파일 누락(운영자 화면에 키 노출)을 놓친다
        $GLOBALS['translations'] = require dirname(__DIR__, 3).'/public/install/lang/ko.php';

        $result = runDatabaseSeedingSSE();

        $this->assertFalse($result['success'] ?? true);
        $this->assertArrayNotHasKey('error', $result, "'error' 키는 실패 경로가 읽지 않는다 — 사유가 버려진다");
        $this->assertArrayHasKey('message_key', $result, '실패 경로가 읽는 키로 사유를 돌려야 한다');
        $this->assertMatchesRegularExpression(
            '/^[a-z0-9_]+$/',
            (string) ($result['message_key'] ?? ''),
            '사유는 번역 키여야 한다 (원문 하드코딩 금지)'
        );
        $this->assertNotSame(
            $result['message_key'],
            $result['message'] ?? null,
            '번역이 해석되지 않으면 lang() 이 키 문자열을 그대로 돌려준다 — 키가 언어 파일에 있어야 한다'
        );
    }

    /**
     * 필수 태스크는 예외로 죽어도 skippable 이 아니다 — 표시 정보를 싣는 것과
     * 건너뛰기를 허용하는 것은 별개다.
     *
     * @scenario task=db_migrate, task_outcome=failed_db, skip_request=required
     *
     * @effects exception_error_event_carries_failure_path_display_fields
     *
     * @return void 반환값 없음
     */
    public function test_exception_error_event_marks_required_task_as_not_skippable(): void
    {
        $this->seedState($this->completedThroughCleanup(), ['current_task' => 'db_migrate']);
        getProgressEmitter()->drain();

        recordInstallationRunException(new \RuntimeException('boom'));

        $errors = array_values(array_filter(getProgressEmitter()->drain(), fn ($e) => $e['event'] === 'error'));
        $data = $errors[0]['data'];

        $this->assertSame('db_migrate', $data['task'] ?? null);
        $this->assertNull($data['target'] ?? null);
        $this->assertFalse($data['skippable'] ?? true);
    }

    /**
     * R3 — 토큰은 state.json 어디에도 기록되지 않는다 (runtime.php 전용).
     *
     * @scenario installation_mode=stepwise
     *
     * @effects installer_stepwise_token_stays_out_of_state_file
     *
     * @return void 반환값 없음
     */
    public function test_stepwise_token_never_reaches_state_file(): void
    {
        $this->seedState($this->completedThroughCleanup());
        runNextInstallationTask();

        $raw = (string) file_get_contents(self::$base.'/storage/installer-state.json');
        $this->assertStringNotContainsString('tok', $raw);
        $this->assertStringNotContainsString('stepwise_token', $raw);
    }
}

/**
 * 시드용 태스크 목록 — seedState 이전에는 state 가 없어 buildInstallationTaskList 가
 * 기본 선택 확장(빈 목록)으로 계산되므로, 같은 선택 확장으로 한 번 시드한 뒤 목록을 얻는다.
 *
 * @return array<int, array<string, mixed>> 태스크 목록
 */
function buildInstallationTaskListForSeed(InstallerStepwiseRunnerTest $test): array
{
    $seed = (new \ReflectionClass($test))->getMethod('seedState');
    $seed->setAccessible(true);
    $seed->invoke($test, []);

    return buildInstallationTaskList();
}

/**
 * 성공을 돌려주지만 완료 마커는 남기지 않는 태스크 — 저장 실패(디스크 풀·부분 쓰기)의
 * 관측을 재현한다 (11차 #A5). 마커를 지우는 것으로 「markTaskCompleted 가 false 를
 * 돌려줬는데 아무도 안 봤다」와 같은 상태를 만든다.
 *
 * task-runner 는 태스크 정의의 function 이름을 그대로 부르므로 FQN 으로 전달한다.
 */
function markerLosingTaskForSeed(): array
{
    $state = getInstallationState();
    $state['completed_tasks'] = array_values(array_filter(
        (array) ($state['completed_tasks'] ?? []),
        static fn ($key) => $key !== 'db_migrate',
    ));
    saveInstallationState($state);

    return ['success' => true];
}
