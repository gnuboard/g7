<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;

/**
 * existing_db_action 변경 시 db_cleanup 재실행 보장 회귀 테스트
 *
 * 회귀 시나리오 (이슈 #319 부수 발견):
 * - 첫 시도에서 사용자가 'skip' 으로 진행 → db_cleanup 이 즉시 completed 마킹
 * - db_migrate 가 잔존 테이블에 막혀 fail
 * - 재시도에서 사용자가 'drop_tables' 동의
 * - install-process.php 가 state['existing_db_action']='drop_tables' 로 갱신
 *   하지만 completed_tasks 의 'db_cleanup' 마커가 그대로 남아 cleanup 함수가
 *   다시 호출되지 않음 → drop 안 됨 → db_migrate 가 동일 1050 에러로 fail
 *
 * 수정 가드: 사용자가 'drop_tables' 로 새로 동의하면 (이전과 다르면)
 *           db_cleanup 마커를 completed_tasks 에서 제거하여 재실행 보장.
 */
class ExistingDbActionGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('BASE_PATH')) {
            define('BASE_PATH', sys_get_temp_dir() . '/g7-existing-db-action-test-' . bin2hex(random_bytes(4)));
            mkdir(BASE_PATH . '/storage', 0755, true);
        }

        require_once dirname(__DIR__, 3) . '/public/install/includes/installer-state.php';
    }

    /**
     * @scenario task_kind=db_schema, marker_shape=bare_id, new_action=drop_tables, worker_liveness=no_worker
     *
     * @effects db_schema_markers_are_invalidated, filesystem_only_markers_are_preserved
     */
    public function test_drop_tables_resets_db_cleanup_when_previous_was_skip(): void
    {
        $state = [
            'existing_db_action' => 'skip',
            'completed_tasks' => ['composer_check', 'env_update', 'db_cleanup', 'db_migrate'],
        ];

        $guarded = applyExistingDbActionStateGuard($state, 'drop_tables');

        $this->assertNotContains('db_cleanup', $guarded['completed_tasks']);
        $this->assertContains('composer_check', $guarded['completed_tasks']); // 다른 task 보존
        $this->assertContains('env_update', $guarded['completed_tasks']);
    }

    /**
     * @scenario task_kind=db_schema, marker_shape=bare_id, new_action=drop_tables, worker_liveness=no_worker
     *
     * @effects db_schema_markers_are_invalidated
     */
    public function test_drop_tables_resets_db_cleanup_when_previous_was_null(): void
    {
        $state = [
            'completed_tasks' => ['composer_check', 'db_cleanup'],
        ];

        $guarded = applyExistingDbActionStateGuard($state, 'drop_tables');

        $this->assertNotContains('db_cleanup', $guarded['completed_tasks']);
    }

    /**
     * 시나리오 ③ (SSE → 폴링 이어서):
     * - 사용자가 drop_tables 동의 후 SSE 시작
     * - SSE 워커가 db_cleanup → db_migrate 일부 진행 중 SSE 구동 실패
     * - 폴링 모드로 재진입 시 install-process.php 가 동일 'drop_tables' 동의 받음
     * - state['existing_db_action']='drop_tables' (이전과 같음)
     * - 가드가 db_cleanup + db_migrate 마커를 모두 제거해야 cleanup 재실행 → 잔존
     *   테이블(이전 SSE 가 일부 만든 것 포함) drop → migrate 처음부터 → 1050 에러 회피
     *
     * @scenario task_kind=db_schema, marker_shape=bare_id, new_action=drop_tables, worker_liveness=no_worker
     *
     * @effects db_schema_markers_are_invalidated, filesystem_only_markers_are_preserved
     */
    public function test_drop_tables_resets_db_cleanup_and_migrate_even_when_already_drop_tables(): void
    {
        $state = [
            'existing_db_action' => 'drop_tables',
            'completed_tasks' => ['composer_check', 'db_cleanup', 'db_migrate'],
        ];

        $guarded = applyExistingDbActionStateGuard($state, 'drop_tables');

        $this->assertNotContains('db_cleanup', $guarded['completed_tasks']);
        $this->assertNotContains('db_migrate', $guarded['completed_tasks']);
        $this->assertContains('composer_check', $guarded['completed_tasks']); // db 외 task 보존
    }

    /**
     * @scenario task_kind=db_schema, marker_shape=bare_id, new_action=drop_tables, worker_liveness=no_worker
     *
     * @effects db_schema_markers_are_invalidated
     */
    public function test_drop_tables_resets_db_seed_marker_too(): void
    {
        $state = [
            'completed_tasks' => ['db_cleanup', 'db_migrate', 'db_seed'],
        ];

        $guarded = applyExistingDbActionStateGuard($state, 'drop_tables');

        $this->assertNotContains('db_cleanup', $guarded['completed_tasks']);
        $this->assertNotContains('db_migrate', $guarded['completed_tasks']);
        $this->assertNotContains('db_seed', $guarded['completed_tasks']);
    }

    /**
     * @scenario task_kind=db_schema, marker_shape=bare_id, new_action=skip, worker_liveness=no_worker
     *
     * @effects skip_preserves_all_markers
     */
    public function test_skip_does_not_reset_anything(): void
    {
        $state = [
            'existing_db_action' => 'drop_tables',
            'completed_tasks' => ['db_cleanup'],
        ];

        $guarded = applyExistingDbActionStateGuard($state, 'skip');

        // skip 으로 변경해도 cleanup 마커 보존 (이미 drop 했음)
        $this->assertContains('db_cleanup', $guarded['completed_tasks']);
    }

    /**
     * @scenario task_kind=db_schema, marker_shape=bare_id, new_action=drop_tables, worker_liveness=no_worker
     *
     * @effects missing_completed_tasks_yields_empty_array
     */
    public function test_handles_missing_completed_tasks_gracefully(): void
    {
        $state = ['existing_db_action' => 'skip'];

        $guarded = applyExistingDbActionStateGuard($state, 'drop_tables');

        $this->assertSame([], $guarded['completed_tasks']);
    }

    /**
     * @scenario task_kind=db_schema, marker_shape=bare_id, new_action=drop_tables, worker_liveness=no_worker
     *
     * @effects filtered_markers_are_reindexed_sequentially
     */
    public function test_returns_array_values_so_keys_are_sequential(): void
    {
        $state = [
            'existing_db_action' => 'skip',
            'completed_tasks' => ['composer_check', 'db_cleanup', 'env_update'],
        ];

        $guarded = applyExistingDbActionStateGuard($state, 'drop_tables');

        // array_filter 후 array_values 적용으로 순차 키 보장
        $this->assertSame([0, 1], array_keys($guarded['completed_tasks']));
    }

    /**
     * 회귀: 다른 워커가 활성 진행 중이면 db_* 마커 reset 금지.
     *
     * 시나리오:
     *  - SSE 워커가 db_cleanup/db_migrate/db_seed 완료 후 module/plugin 진행 중
     *  - 클라이언트가 폴링 fallback 시도 → install-process.php 호출
     *  - install-process.php 가 applyExistingDbActionStateGuard('drop_tables') 호출
     *  - 그 시점 active_worker_id 는 SSE 워커, last_heartbeat 는 fresh
     *  - 가드가 db_* 마커 제거하면 그 후 SSE 워커가 module 완료 markTaskCompleted 시
     *    state.json 에는 db_* 가 빠진 채로 module_* 만 추가됨 (UI 에 DB pending 표시 회귀)
     *
     * 의도: 다른 워커가 살아있으면 state 변경 금지 (race 회피). worker 가 죽었을 때만
     *       (heartbeat stale 또는 active_worker_id 부재) reset 하여 폴링 재진입 케이스 커버.
     *
     * @scenario task_kind=db_schema, marker_shape=id_with_target, new_action=drop_tables, worker_liveness=active_worker
     *
     * @effects active_worker_preserves_all_markers
     */
    public function test_drop_tables_does_not_reset_when_other_worker_is_active(): void
    {
        $state = [
            'existing_db_action' => 'drop_tables',
            'completed_tasks' => ['db_cleanup', 'db_migrate', 'db_seed', 'module_install:sirsoft-board'],
            'active_worker_id' => 'abc123',
            'last_heartbeat' => time(), // fresh heartbeat
        ];

        $guarded = applyExistingDbActionStateGuard($state, 'drop_tables');

        // 다른 워커 진행 중이므로 db_* 마커 보존 (race 회피)
        $this->assertContains('db_cleanup', $guarded['completed_tasks']);
        $this->assertContains('db_migrate', $guarded['completed_tasks']);
        $this->assertContains('db_seed', $guarded['completed_tasks']);
        $this->assertContains('module_install:sirsoft-board', $guarded['completed_tasks']);
    }

    /**
     * 회귀: stale 워커 (heartbeat 15초 초과) 인 경우엔 reset 정상 동작 — 죽은 워커가
     * cleanup 만 진행 후 끊긴 케이스를 폴링이 takeover 할 수 있어야 한다.
     *
     * @scenario task_kind=db_schema, marker_shape=bare_id, new_action=drop_tables, worker_liveness=stale_worker
     *
     * @effects stale_worker_allows_takeover_reset
     */
    public function test_drop_tables_resets_when_other_worker_is_stale(): void
    {
        $state = [
            'existing_db_action' => 'drop_tables',
            'completed_tasks' => ['db_cleanup', 'db_migrate'],
            'active_worker_id' => 'abc123',
            'last_heartbeat' => time() - 60, // stale heartbeat (60초 전)
        ];

        $guarded = applyExistingDbActionStateGuard($state, 'drop_tables');

        // worker 가 죽었으므로 reset 진행 (기존 의도된 동작)
        $this->assertNotContains('db_cleanup', $guarded['completed_tasks']);
        $this->assertNotContains('db_migrate', $guarded['completed_tasks']);
    }

    /**
     * 회귀: active_worker_id 부재 시 reset 정상 동작 — 처음 진입하는 폴링 워커는
     * race 우려가 없으므로 기존 동작 보존.
     *
     * @scenario task_kind=db_schema, marker_shape=bare_id, new_action=drop_tables, worker_liveness=no_worker
     *
     * @effects db_schema_markers_are_invalidated
     */
    public function test_drop_tables_resets_when_no_active_worker(): void
    {
        $state = [
            'existing_db_action' => 'drop_tables',
            'completed_tasks' => ['db_cleanup', 'db_migrate'],
            // active_worker_id 키 자체가 없음
        ];

        $guarded = applyExistingDbActionStateGuard($state, 'drop_tables');

        $this->assertNotContains('db_cleanup', $guarded['completed_tasks']);
        $this->assertNotContains('db_migrate', $guarded['completed_tasks']);
    }

    /**
     * 회귀 (이슈 #599 후속): db_cleanup 은 g7_ 접두사 테이블을 전부 DROP 하므로
     * 확장 install/activate 의 결과물(DB 등록 행)도 함께 사라진다. 그런데 기존 가드는
     * db_* 3종 마커만 제거해서, 1차 시도에서 db_cleanup 보다 먼저 설치된 확장의 마커가
     * 살아남아 재개 시 스킵됐다 → 파일만 있고 DB 행은 없는 유령 상태.
     *
     * 실제 발생 경로:
     *  - 1차 시도: template/module install 이 db_cleanup 보다 앞서 완료 (중단된 설치)
     *  - 재시도에서 drop_tables 선택 → g7_modules DROP → sirsoft-board 행 소멸
     *  - module_install:sirsoft-board 마커는 잔존 → 재설치 스킵
     *  - user_template_install:sirsoft-basic 이 의존성 미충족으로 실패
     *
     * @scenario task_kind=extension_registration, marker_shape=id_with_target, new_action=drop_tables, worker_liveness=no_worker
     *
     * @effects extension_install_markers_are_invalidated, extension_activate_markers_are_invalidated, filesystem_only_markers_are_preserved, board_installed_before_cleanup_reinstalls_on_retry, installer_never_skips_an_extension_whose_db_row_was_dropped
     */
    public function test_drop_tables_resets_extension_markers_so_they_reinstall(): void
    {
        $state = [
            'existing_db_action' => 'drop_tables',
            'completed_tasks' => [
                'composer_check',
                'composer_install',
                'env_update',
                'key_generate',
                'dependency_precheck',
                'template_install:sirsoft-admin_basic',
                'template_activate:sirsoft-admin_basic',
                'module_install:sirsoft-board',
                'module_activate:sirsoft-board',
                'db_cleanup',
                'db_migrate',
                'db_seed',
            ],
        ];

        $guarded = applyExistingDbActionStateGuard($state, 'drop_tables');

        // DB 행으로만 존재하던 확장 마커는 제거되어 재설치된다
        $this->assertNotContains('module_install:sirsoft-board', $guarded['completed_tasks']);
        $this->assertNotContains('module_activate:sirsoft-board', $guarded['completed_tasks']);
        $this->assertNotContains('template_install:sirsoft-admin_basic', $guarded['completed_tasks']);
        $this->assertNotContains('template_activate:sirsoft-admin_basic', $guarded['completed_tasks']);

        // 파일시스템/환경 효과라 DROP 과 무관한 마커는 보존 (재실행은 낭비)
        $this->assertContains('composer_check', $guarded['completed_tasks']);
        $this->assertContains('composer_install', $guarded['completed_tasks']);
        $this->assertContains('env_update', $guarded['completed_tasks']);
        $this->assertContains('key_generate', $guarded['completed_tasks']);
        $this->assertContains('dependency_precheck', $guarded['completed_tasks']);
    }

    /**
     * 회귀: 플러그인·사용자 템플릿·언어팩·마무리 단계도 DB 행 기반이므로 동일하게 재실행.
     *
     * @scenario task_kind=finalization, marker_shape=id_with_target, new_action=drop_tables, worker_liveness=no_worker
     *
     * @effects extension_install_markers_are_invalidated, extension_activate_markers_are_invalidated, language_pack_markers_are_invalidated, finalization_markers_are_invalidated, cache_clear_marker_is_preserved
     */
    public function test_drop_tables_resets_plugin_user_template_and_finalization_markers(): void
    {
        $state = [
            'completed_tasks' => [
                'plugin_install:sirsoft-ckeditor5',
                'plugin_activate:sirsoft-ckeditor5',
                'user_template_install:sirsoft-basic',
                'user_template_activate:sirsoft-basic',
                'language_pack_install:g7-core-ja',
                'create_settings_json',
                'complete_flag',
                'config_cache',
                'static_publish',
                'cache_clear',
            ],
        ];

        $guarded = applyExistingDbActionStateGuard($state, 'drop_tables');

        foreach ([
            'plugin_install:sirsoft-ckeditor5',
            'plugin_activate:sirsoft-ckeditor5',
            'user_template_install:sirsoft-basic',
            'user_template_activate:sirsoft-basic',
            'language_pack_install:g7-core-ja',
            'create_settings_json',
            'complete_flag',
            'config_cache',
            'static_publish',
        ] as $task) {
            $this->assertNotContains($task, $guarded['completed_tasks'], "{$task} 마커가 제거되어야 함");
        }

        // cache_clear 는 DB 행을 만들지 않으므로 보존
        $this->assertContains('cache_clear', $guarded['completed_tasks']);
    }

    /**
     * 가드가 참조하는 무효화 목록이 실제 task-runner 의 task id 집합과 어긋나지 않는지
     * 잠근다. 새 task 가 추가됐는데 분류를 빠뜨리면 동일 유령 상태가 재발한다.
     *
     * @scenario task_kind=filesystem_only, marker_shape=bare_id, new_action=drop_tables, worker_liveness=no_worker
     *
     * @effects every_task_id_in_runner_is_classified, filesystem_only_markers_are_preserved, bare_task_id_is_classified
     */
    public function test_every_task_id_in_runner_is_classified(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/public/install/includes/task-runner.php');
        preg_match_all("/'id' => '([a-z_]+)'/", $source, $matches);
        $taskIds = array_unique($matches[1]);

        $this->assertNotEmpty($taskIds, 'task-runner 에서 task id 를 추출하지 못했다');

        // DB 상태와 무관한 (보존되어야 하는) task — 이 목록 외에는 전부 무효화 대상이어야 한다.
        $filesystemOnly = [
            'composer_check',
            'composer_install',
            'env_update',
            'key_generate',
            'dependency_precheck',
            'cache_clear',
        ];

        foreach ($taskIds as $taskId) {
            if (in_array($taskId, $filesystemOnly, true)) {
                $this->assertFalse(
                    isTaskInvalidatedByDbCleanup($taskId),
                    "{$taskId} 는 파일시스템 효과라 보존되어야 한다"
                );
            } else {
                $this->assertTrue(
                    isTaskInvalidatedByDbCleanup($taskId),
                    "{$taskId} 가 무효화 목록에 없다 — db_cleanup 후 스킵되어 유령 상태가 된다"
                );
            }
        }
    }

    /**
     * 회귀: target 이 붙은 마커("id:target") 의 id 파싱이 정확해야 한다.
     * 하이픈·언더스코어가 섞인 실제 식별자로 검증.
     *
     * @scenario task_kind=extension_registration, marker_shape=id_with_target, new_action=drop_tables, worker_liveness=no_worker
     *
     * @effects targeted_marker_parses_task_id_before_colon, hyphenated_identifier_target_is_parsed, underscored_identifier_target_is_parsed, bare_task_id_is_classified
     */
    public function test_task_id_is_parsed_from_targeted_marker(): void
    {
        $this->assertTrue(isTaskInvalidatedByDbCleanup('module_install:sirsoft-board'));
        $this->assertTrue(isTaskInvalidatedByDbCleanup('plugin_activate:sirsoft-pay_nhnkcp'));
        $this->assertTrue(isTaskInvalidatedByDbCleanup('language_pack_install:g7-module-sirsoft-board-ja'));
        $this->assertFalse(isTaskInvalidatedByDbCleanup('composer_install'));
    }
}
