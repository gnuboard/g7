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

    public function test_handles_missing_completed_tasks_gracefully(): void
    {
        $state = ['existing_db_action' => 'skip'];

        $guarded = applyExistingDbActionStateGuard($state, 'drop_tables');

        $this->assertSame([], $guarded['completed_tasks']);
    }

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
}
