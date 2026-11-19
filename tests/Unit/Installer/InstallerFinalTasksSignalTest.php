<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

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
    use ScansPhpSourceFunctions;

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

        return $this->sourceWithoutComments($path);
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
     * completed 갈래는 기다리기 전에 남은 단계별 태스크를 한 번 이어받고, 기다린 뒤의 상태로 작업 목록과
     * 진행 기록까지 그린 다음 완료 화면을 연다. 그래서 한 줄 표기가 아니라 「갈래 안에서 기다린 결과가
     * 완료 화면으로 가고, 그 전에는 완료 화면을 열지 않는다」를 잰다.
     *
     * @effects installer_reentry_waits_for_final_tasks
     *
     * @return void 반환값 없음
     */
    public function test_installer_reentry_waits_for_final_tasks(): void
    {
        // 주석을 걷어낸 원문으로 잰다 — 갈래 안의 이유 주석이 `showCompletionSection()` 을 글자로 언급한다.
        $source = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/assets/js/installer.js');

        $fn = strpos($source, 'async function checkAndStartInstallation()');
        $this->assertNotFalse($fn, '재진입 처리 함수를 찾지 못했다 — 이 검사의 기준점이 사라졌다');

        $start = strpos($source, "if (state.status === 'completed') {", $fn);
        $this->assertNotFalse($start, '재진입 처리의 completed 갈래를 찾지 못했다 — 이 검사의 기준점이 사라졌다');

        $end = strpos($source, 'return;', $start);
        $this->assertNotFalse($end, 'completed 갈래의 끝(return)을 찾지 못했다');
        $branch = substr($source, $start, $end - $start);

        $this->assertMatchesRegularExpression(
            '/\b(?:const|let)\s+([A-Za-z_$][\w$]*)\s*=\s*await\s+waitForFinalTasks\(state\);/',
            $branch,
            'completed 갈래가 마무리 작업 완료를 기다리지 않는다 — 폴링 워커가 도는 중에 finalize-env 가 상태 파일을 지운다',
        );
        preg_match('/\b(?:const|let)\s+([A-Za-z_$][\w$]*)\s*=\s*await\s+waitForFinalTasks\(state\);/', $branch, $m, PREG_OFFSET_CAPTURE);
        $waitedVar = $m[1][0];
        $waitAt = $m[0][1];

        $showAt = strpos($branch, 'showCompletionSection('.$waitedVar.')');
        $this->assertNotFalse($showAt, '완료 화면이 기다린 뒤의 상태('.$waitedVar.')로 열리지 않는다');
        $this->assertGreaterThan($waitAt, $showAt, '완료 화면은 기다린 뒤에 연다');

        $earlyShow = strpos($branch, 'showCompletionSection(');
        $this->assertSame($showAt, $earlyShow, '기다리기 전에 완료 화면을 여는 호출이 있다 — finalize-env 가 워커보다 먼저 돈다');
    }
}
