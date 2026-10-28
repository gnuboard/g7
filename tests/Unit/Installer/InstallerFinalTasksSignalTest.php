<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;

/**
 * 설치 마무리 작업 완료 신호(tasks_finished) 계약 — 폴링 모드 완료 경합 회귀 가드.
 *
 * 회귀: `complete_flag` 가 installation_status 를 completed 로 바꾼 뒤에도 `config_cache`·`route_cache`·
 * `static_publish` 가 남는다. 폴링 화면은 completed 를 보자마자 finalize-env 를 불러 상태 파일을 지웠고,
 * 워커는 다음 heartbeat 에서 「lock lost」로 끝났다. 결과: 설정 캐시가 .env 확정 전 값(installer_completed=false)으로
 * 남고(finalize 의 재굽기는 config:cache 가 파일을 지운 틈이라 건너뜀) 라우트 캐시·정적 게시가 빠졌다.
 * SSE 는 마지막 작업 뒤에 completed 이벤트를 보내므로 무관했다.
 *
 * 트러블슈팅 `[case:backend-59]` 의 서버 쪽 회귀 그물이다.
 *
 * 정적 소스 분석으로 검증한다 — 작업 실행기는 SSE 워커 안에서만 돌고 artisan 자식 프로세스를 띄운다.
 * 폴링 모니터의 판정은 vitest `installation-monitor-polling.test.ts` 가 실제 코드로 잠근다.
 */
class InstallerFinalTasksSignalTest extends TestCase
{
    /**
     * 저장소 루트 기준 파일 내용을 읽는다.
     *
     * @param  string  $relative  루트 기준 경로
     * @return string 파일 내용
     */
    private function source(string $relative): string
    {
        $path = dirname(__DIR__, 3).'/'.$relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * complete_flag 는 마무리 작업이 남았음(false)을, 작업 목록이 모두 끝나면 끝났음(true)을 completed 이벤트 전에 기록한다.
     *
     * @effects task_runner_marks_final_tasks_pending_then_finished
     *
     * @return void 반환값 없음
     */
    public function test_task_runner_marks_final_tasks_pending_then_finished(): void
    {
        $source = $this->source('public/install/includes/task-runner.php');

        $this->assertMatchesRegularExpression(
            "/\\\$state\['installation_status'\] = 'completed';.*?\\\$state\['tasks_finished'\] = false;.*?saveInstallationState\(\\\$state\)/s",
            $source,
            'complete_flag 가 completed 와 함께 tasks_finished=false 를 저장해야 한다',
        );

        $finished = strpos($source, "\$state['tasks_finished'] = true;");
        $completedEvent = strpos($source, "sendSSEEvent('completed'");
        $this->assertNotFalse($finished, '작업 목록이 끝나면 tasks_finished=true 를 저장해야 한다');
        $this->assertNotFalse($completedEvent);
        $this->assertLessThan($completedEvent, $finished, 'tasks_finished=true 는 completed 이벤트보다 먼저 저장한다');
        $this->assertGreaterThan(strpos($source, "'id' => 'static_publish'"), $finished, '마지막 마무리 작업(static_publish) 등록 뒤 — 작업 루프 끝에서 기록한다');
    }

    /**
     * 상태 조회 API 가 tasks_finished 를 내보낸다 — 필드가 없는 이전 상태 파일은 끝난 것으로 본다.
     *
     * @effects state_api_exposes_tasks_finished
     *
     * @return void 반환값 없음
     */
    public function test_state_api_exposes_tasks_finished(): void
    {
        $this->assertStringContainsString(
            "'tasks_finished' => (bool) (\$state['tasks_finished'] ?? true)",
            $this->source('public/install/api/state-management.php'),
        );
    }

    /**
     * 설치 화면 재진입(새로고침)에서 completed 라도 마무리 작업이 남았으면 기다린 뒤 완료 화면(→ finalize)을 연다.
     *
     * @effects installer_reentry_waits_for_final_tasks
     *
     * @return void 반환값 없음
     */
    public function test_installer_reentry_waits_for_final_tasks(): void
    {
        $this->assertMatchesRegularExpression(
            "/if \(state\.status === 'completed'\) \{\s*showCompletionSection\(await waitForFinalTasks\(state\)\);/",
            $this->source('public/install/assets/js/installer.js'),
        );
    }
}
