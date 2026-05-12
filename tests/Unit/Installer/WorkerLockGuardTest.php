<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;

/**
 * 워커 동시 실행 방지 lock/heartbeat 가드 회귀 테스트
 *
 * 시나리오 B (이슈 #319 race condition 본질 차단):
 * - 환경 (예: mod_fcgid + FcgidOutputBufferSize 미설정) 에서 SSE 가 client 까지
 *   도달 못해 사용자가 폴링으로 풀백. 그 사이 SSE 워커는 백그라운드 진행.
 * - 새 폴링 워커가 진입하면 두 워커가 동일 인스톨러 state + DB 를 동시 조작.
 * - 본 가드: state.active_worker_id + state.last_heartbeat 로 동시 실행 차단.
 *
 * 검증 대상 헬퍼:
 * - acquireWorkerLock(int $staleSeconds): ['acquired'=>bool, 'worker_id'=>string|null, 'reason'=>string]
 * - refreshWorkerHeartbeat(string $workerId): bool — 자기 worker_id 일 때만 true
 * - releaseWorkerLock(string $workerId): void
 *
 * 본 테스트는 헬퍼 자체의 결정론적 동작만 검증. 실제 두 PHP 프로세스 동시 실행은
 * 매뉴얼 시나리오 검증 영역.
 */
class WorkerLockGuardTest extends TestCase
{
    private string $tempBase;
    private string $stateFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempBase = sys_get_temp_dir() . '/g7-worker-lock-test-' . bin2hex(random_bytes(4));
        mkdir($this->tempBase . '/storage', 0755, true);

        if (! defined('BASE_PATH')) {
            define('BASE_PATH', $this->tempBase);
        }

        $this->stateFile = BASE_PATH . '/storage/installer-state.json';
        @file_put_contents($this->stateFile, json_encode(['installation_status' => 'pending']));

        require_once dirname(__DIR__, 3) . '/public/install/includes/config.php';
        require_once dirname(__DIR__, 3) . '/public/install/includes/installer-state.php';
    }

    protected function tearDown(): void
    {
        // $this->stateFile 은 setUp 시점의 BASE_PATH 로 이미 결정되어 있으므로
        // 그 경로를 그대로 정리. 이전 코드는 BASE_PATH 가 self::tempBase 와 다를 때
        // (다른 테스트가 먼저 바인딩한 경우) 정리를 skip 하여 사용자 작업 디렉토리에
        // 잔여물을 남겼다.
        @unlink($this->stateFile);
        parent::tearDown();
    }

    public function test_acquire_succeeds_when_no_active_worker(): void
    {
        $result = acquireWorkerLock(15);

        $this->assertTrue($result['acquired']);
        $this->assertNotEmpty($result['worker_id']);
        $this->assertSame('available', $result['reason']);
    }

    public function test_acquire_persists_worker_id_and_heartbeat_in_state(): void
    {
        $result = acquireWorkerLock(15);

        $state = json_decode(file_get_contents($this->stateFile), true);
        $this->assertSame($result['worker_id'], $state['active_worker_id']);
        $this->assertIsInt($state['last_heartbeat']);
        $this->assertGreaterThanOrEqual(time() - 1, $state['last_heartbeat']);
    }

    public function test_acquire_fails_when_active_worker_within_stale_window(): void
    {
        $first = acquireWorkerLock(15);
        $this->assertTrue($first['acquired']);

        // 두 번째 워커 진입 시도 — 활동 중이라 거부되어야 함
        $second = acquireWorkerLock(15);

        $this->assertFalse($second['acquired']);
        $this->assertNull($second['worker_id']);
        $this->assertSame('busy', $second['reason']);

        // state 의 worker_id 는 첫 번째 그대로
        $state = json_decode(file_get_contents($this->stateFile), true);
        $this->assertSame($first['worker_id'], $state['active_worker_id']);
    }

    public function test_acquire_takes_over_when_heartbeat_stale(): void
    {
        $first = acquireWorkerLock(15);

        // heartbeat 를 stale 시점으로 강제 변경
        $state = json_decode(file_get_contents($this->stateFile), true);
        $state['last_heartbeat'] = time() - 60;
        file_put_contents($this->stateFile, json_encode($state));

        $second = acquireWorkerLock(15);

        $this->assertTrue($second['acquired']);
        $this->assertNotSame($first['worker_id'], $second['worker_id']);
        $this->assertSame('takeover_stale', $second['reason']);

        $newState = json_decode(file_get_contents($this->stateFile), true);
        $this->assertSame($second['worker_id'], $newState['active_worker_id']);
    }

    public function test_refresh_heartbeat_returns_true_for_owner(): void
    {
        $first = acquireWorkerLock(15);

        // 의도적 1초 지연 (heartbeat 갱신 detection)
        sleep(1);

        $ok = refreshWorkerHeartbeat($first['worker_id']);
        $this->assertTrue($ok);

        $state = json_decode(file_get_contents($this->stateFile), true);
        $this->assertGreaterThanOrEqual(time() - 1, $state['last_heartbeat']);
    }

    public function test_refresh_heartbeat_returns_false_for_non_owner(): void
    {
        $first = acquireWorkerLock(15);

        // 다른 worker_id 가 takeover 한 상황 시뮬레이션
        $state = json_decode(file_get_contents($this->stateFile), true);
        $state['active_worker_id'] = 'someone-else';
        file_put_contents($this->stateFile, json_encode($state));

        $ok = refreshWorkerHeartbeat($first['worker_id']);
        $this->assertFalse($ok);
    }

    public function test_release_clears_worker_id_and_heartbeat(): void
    {
        $first = acquireWorkerLock(15);

        releaseWorkerLock($first['worker_id']);

        $state = json_decode(file_get_contents($this->stateFile), true);
        $this->assertArrayNotHasKey('active_worker_id', $state);
        $this->assertArrayNotHasKey('last_heartbeat', $state);
    }

    public function test_release_no_op_when_worker_id_does_not_match(): void
    {
        $first = acquireWorkerLock(15);

        // 다른 worker 가 takeover
        $state = json_decode(file_get_contents($this->stateFile), true);
        $newWorkerId = 'taker-' . bin2hex(random_bytes(4));
        $state['active_worker_id'] = $newWorkerId;
        file_put_contents($this->stateFile, json_encode($state));

        // 원본 worker 가 release 시도 — 자기 worker_id 가 아니므로 무시
        releaseWorkerLock($first['worker_id']);

        $stateAfter = json_decode(file_get_contents($this->stateFile), true);
        $this->assertSame($newWorkerId, $stateAfter['active_worker_id']);
    }

    public function test_acquire_after_release_succeeds(): void
    {
        $first = acquireWorkerLock(15);
        releaseWorkerLock($first['worker_id']);

        $second = acquireWorkerLock(15);

        $this->assertTrue($second['acquired']);
        $this->assertSame('available', $second['reason']);
        $this->assertNotSame($first['worker_id'], $second['worker_id']);
    }

}
