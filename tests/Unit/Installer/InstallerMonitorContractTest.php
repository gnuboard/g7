<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 인스톨러 프론트엔드의 진행 모니터 계약 회귀 테스트.
 *
 * 인스톨러 JS 는 브라우저에서만 도는 순수 스크립트라 단위 실행 환경이 없다 — 계약은 소스
 * 대조로 고정한다. 여기서 고정하는 것은 「세 진행 모드가 같은 것을 한다」와 「비동기 시작
 * 경로가 취소를 확인한다」 두 축이다. 둘 다 어긋나도 예외가 나지 않고, 화면이 조용히 멈추거나
 * 서버와 어긋난 상태로만 나타난다.
 *
 * 커버 축: monitor_mode sse·polling·stepwise / stall_detection time_based·payload_based / cancel_check start·await
 *
 * 효과 요약(마커 아님 — 평문): monitors_share_time_based_stall_detection, stepwise_request_has_timeout, sse_event_parsing_is_symmetric, abort_stops_the_running_monitor, start_path_checks_cancellation, completion_keeps_passed_over_evidence
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerMonitorContractTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /**
     * 진행 모니터 소스 (주석 제거).
     *
     * 이 클래스의 20여 단언은 전부 원문 대조였다 — `AbortController`·`signal:`·
     * `safeParseEventData(`·`setResumeButtonsDisabled(true)` 같은 잠금 축이 **주석 안에만
     * 있어도** 통과했다. 앞서 세운 규칙(문자로 대조하는 게이트는 주석을
     * 지운다)이 이 파일에는 적용되지 않은 채였다.
     *
     * @return string 주석이 제거된 JS 소스
     */
    private function monitorSource(): string
    {
        return $this->stripJsComments((string) file_get_contents(dirname(__DIR__, 3).'/public/install/assets/js/installation-monitor.js'));
    }

    /**
     * 인스톨러 스크립트 소스 (주석 제거).
     *
     * @return string 주석이 제거된 JS 소스
     */
    private function installerSource(): string
    {
        return $this->stripJsComments((string) file_get_contents(dirname(__DIR__, 3).'/public/install/assets/js/installer.js'));
    }

    /**
     * 주석 제거가 실제로 판정을 가른다 (부정 픽스처).
     *
     * 실 소스가 준수 상태라 위 단언들은 늘 통과한다 — 원문 대조로 되돌려도 red 가 없다.
     * 「주석으로 감싼 배선」을 합성 입력으로 만들어 구분되는지 본다.
     *
     * 19차 G#9 — **이 메서드가 잠그는 것은 인스톨러 코드가 아니라 스트리퍼**다.
     * `public/install/**` 를 한 줄도 참조하지 않으므로 그쪽을 어떻게 바꿔도 red 가 되지 않는다.
     * 그 축의 소유자는 `tests/Unit/Concerns/ScansPhpSourceFunctionsTest.php` 이고, 여기 남은
     * 것은 **이 클래스의 다른 단언들이 기대는 전제**를 같은 파일 안에서 눈에 띄게 두기 위한
     * 것이다. 지우지 않는 이유는 그 문서적 값 때문이지 게이트로서의 값 때문이 아니다 —
     * 다음 감사가 이것을 「인스톨러 게이트」로 세지 않도록 여기 적어 둔다.
     *
     * @scenario installation_mode=stepwise
     *
     * @effects monitors_share_time_based_stall_detection
     *
     * @return void 반환값 없음
     */
    public function test_source_comparison_ignores_commented_out_wiring(): void
    {
        $live = "controller = new AbortController();\nfetch(url, { signal: controller.signal });\n";
        $commented = "// controller = new AbortController();\n/* fetch(url, { signal: controller.signal }); */\n";

        $this->assertStringContainsString('AbortController', $this->stripJsComments($live));
        $this->assertStringContainsString('signal:', $this->stripJsComments($live));

        $this->assertStringContainsString('AbortController', $commented, '원문에는 글자가 남는다 — 그래서 원문 대조로는 구분되지 않는다');
        $this->assertStringNotContainsString('AbortController', $this->stripJsComments($commented));
        $this->assertStringNotContainsString('signal:', $this->stripJsComments($commented));

        // 정규식 리터럴을 주석으로 오인해 뒤를 통째로 잃지 않는다 — 실 소스에 `/\//g` 가 있다.
        $withRegex = "const winPaths = paths.map(p => p.replace(/\\//g, '\\\\'));\nsetResumeButtonsDisabled(true);\n";
        $this->assertStringContainsString(
            'setResumeButtonsDisabled(true)',
            $this->stripJsComments($withRegex),
            '정규식 리터럴 안의 슬래시를 주석 시작으로 읽으면 그 뒤 소스가 통째로 사라진다'
        );
    }

    /**
     * 클래스 본문만 잘라낸다 (다음 `class ` 선언 전까지).
     *
     * @param  string  $source  주석을 걷어낸 설치기 스크립트 소스
     * @param  string  $className  잘라낼 클래스 이름
     * @return string 그 클래스 선언부터 다음 클래스 선언 전까지의 본문
     */
    private function classBody(string $source, string $className): string
    {
        $start = strpos($source, "class {$className}");
        $this->assertNotFalse($start, "{$className} 클래스를 찾지 못했다");

        $next = strpos($source, "\n    class ", $start + 1);

        return $next === false ? substr($source, $start) : substr($source, $start, $next - $start);
    }

    /**
     * #M16 · #M17 — 세 모니터가 **페이로드와 무관한 시간 기준** 정지 감지를 공유한다.
     *
     * 응답 내용으로만 판정하면 서버가 계속 4xx/5xx 를 주거나 응답 자체가 오지 않는 동안
     * 판정 지점에 도달하지 못해 화면이 마지막 진행률에서 영구 정지한다.
     *
     * @scenario installation_mode=stepwise
     *
     * @effects monitors_share_time_based_stall_detection
     *
     * @return void 반환값 없음
     */
    public function test_all_three_monitors_arm_the_shared_stall_timer(): void
    {
        $source = $this->monitorSource();

        $this->assertStringContainsString(
            '_armStuckTimer(',
            $this->classBody($source, 'InstallationMonitor'),
            '정지 감지는 베이스 클래스가 소유해야 세 모드가 같은 기준을 쓴다'
        );

        foreach (['SseMonitor', 'PollingMonitor', 'StepwiseMonitor'] as $monitor) {
            $this->assertStringContainsString(
                '_armStuckTimer(',
                $this->classBody($source, $monitor),
                "{$monitor} 가 정지 감지를 걸지 않는다 — 그 모드에서만 화면이 영구 정지한다"
            );
        }
    }

    /**
     * 상태 스냅샷을 받는 모니터는 전부 `onState` 를 발화한다 (14차 #10).
     *
     * `onState` 는 세 모드 중 둘에만 있었다. 지금의 소비자는 이벤트로도 같은 일을 하므로
     * 무해하지만, 폴링 쪽 주석이 스스로 적어 둔 위험 — 「계약이 한쪽에만 있으면 소비자가
     * 늘어날 때 그 모드에서만 조용히 발화하지 않는다」 — 이 그대로 남아 있었다.
     *
     * SSE 는 스트림으로 이벤트만 받아 스냅샷 자체가 없다. 그래서 발화하지 않는 것이 옳고,
     * 그 사실을 계약으로 못 박는다 — 스냅샷을 소비하는 모니터가 늘면 그때 이 검사가 red 가
     * 된다. 워커를 뺏긴 SSE 는 폴링으로 갈아타므로 그 뒤로는 후계 모니터가 발화한다.
     *
     * @scenario installation_mode=polling
     *
     * @effects installer_stepwise_state_summary_matches_polling
     *
     * @return void 반환값 없음
     */
    public function test_snapshot_consuming_monitors_all_emit_on_state(): void
    {
        $source = $this->monitorSource();

        foreach (['PollingMonitor', 'StepwiseMonitor'] as $className) {
            $body = $this->classBody($source, $className);

            $this->assertStringContainsString(
                "_invoke('onState'",
                $body,
                $className.' 는 서버 상태 스냅샷을 받는다 — 그 요약을 화면에 넘기지 않으면 '
                .'새로고침 시점에 돌던 태스크의 완료가 목록에서 영영 빠진다',
            );
        }

        $sse = $this->classBody($source, 'SseMonitor');
        $this->assertStringNotContainsString(
            "_invoke('onState'",
            $sse,
            'SseMonitor 에는 상태 스냅샷이 없다 — 발화하려면 그 모드에만 별도 상태 폴링이 생긴다. '
            .'스냅샷을 갖게 됐다면 이 단언이 아니라 계약 문서를 먼저 고칠 것',
        );
    }

    /**
     * #M17 — 단계별 실행 요청은 스스로 끝난다 (응답 없는 요청에 상한).
     *
     * @scenario installation_mode=stepwise
     *
     * @effects stepwise_request_has_timeout
     *
     * @return void 반환값 없음
     */
    public function test_stepwise_request_has_a_timeout(): void
    {
        $stepwise = $this->classBody($this->monitorSource(), 'StepwiseMonitor');

        $this->assertStringContainsString('AbortController', $stepwise, '응답이 오지 않는 요청은 fetch 가 스스로 끝내지 않는다');
        $this->assertStringContainsString('signal:', $stepwise, '타임아웃 컨트롤러가 요청에 연결돼야 의미가 있다');
        $this->assertStringContainsString('requestTimeoutMs', $stepwise);
    }

    /**
     * #L12 — SSE 이벤트 파싱 보호는 대칭이다.
     *
     * `completed` 본문이 프록시에 잘리면 예외가 디스패치 밖으로 새어 onComplete 가 발화하지
     * 않는다 — 성공한 설치가 잠시 뒤 「응답이 없습니다」로 뒤집힌다.
     *
     * @scenario installation_mode=sse
     *
     * @effects sse_event_parsing_is_symmetric
     *
     * @return void 반환값 없음
     */
    public function test_sse_event_parsing_is_symmetric(): void
    {
        $sse = $this->classBody($this->monitorSource(), 'SseMonitor');

        $this->assertStringNotContainsString(
            'JSON.parse(e.data)',
            $sse,
            '보호 없는 파싱이 남아 있으면 그 이벤트 하나가 깨질 때 콜백이 통째로 사라진다'
        );
        $this->assertStringContainsString('safeParseEventData(', $sse);
    }

    /**
     * 새 이벤트 `log_batch` 가 **서버와 클라이언트에 같은 이름**으로 있다 (D2 L6).
     *
     * 단계별 경로는 커맨드 원문을 묶어 보내는데(응답이 태스크 단위라 줄 단위일 이유가 없다),
     * 이름이 한쪽에서만 바뀌면 그 줄들은 클라이언트의 `default:` 로 떨어져 **조용히 사라진다** —
     * 오류도 경고도 없이 진행 기록의 절반이 비는 형태다. 그래서 이름을 양쪽에서 함께 잠근다.
     *
     * @scenario progress_log_volume=large
     *
     * @effects stepwise_command_output_is_written_in_one_batch
     *
     * @return void 반환값 없음
     */
    public function test_log_batch_event_name_matches_between_server_and_client(): void
    {
        $emitter = $this->sourceWithoutComments(
            dirname(__DIR__, 3).'/public/install/includes/progress-emitter.php'
        );
        $stepwise = $this->classBody($this->monitorSource(), 'StepwiseMonitor');

        $this->assertStringContainsString(
            "'log_batch'",
            $emitter,
            '서버가 묶음 이벤트를 내지 않는다 — 단계별 경로가 줄마다 파일을 쓰는 상태로 돌아간다'
        );
        $this->assertStringContainsString(
            "'log_batch'",
            $stepwise,
            '클라이언트가 묶음 이벤트를 모른다 — 그 줄들이 default 로 떨어져 화면에서 사라진다'
        );

        // 묶음의 본문 키도 같아야 한다. 이름만 맞고 키가 다르면 빈 배열을 재생한다.
        $this->assertStringContainsString("'messages'", $emitter, '서버가 묶음 본문을 messages 로 싣지 않는다');
        $this->assertStringContainsString('data.messages', $stepwise, '클라이언트가 묶음 본문을 messages 에서 읽지 않는다');
    }

    /**
     * 중단이 **왜 오래 걸리는지** 화면이 말한다 (D6).
     *
     * 서버는 중단을 즉시 확정하지 않는다 — 되돌리기가 아직 실행 중인 태스크와 겹치지 않도록
     * 그 태스크가 경계에 닿을 때까지 기다린다(제품 결정 13차 · `state-management.php` 의
     * 「작업이 물러날 때까지 기다린다」 구간). 그 대기는 태스크 길이만큼이라 실측에서 12.6 초였다.
     *
     * 버튼은 즉시(61 ms) 「중단 중」으로 바뀌지만 그것만으로는 **왜** 기다리는지 알 수 없고,
     * 사용자에게는 D2 가 고친 그 무응답과 같은 화면이 된다 — 그래서 사유를 진행 기록에 남긴다.
     * 이 배선이 사라지면 그 12초가 다시 설명 없는 침묵이 된다.
     *
     * @scenario installation_mode=stepwise, task_outcome=aborted
     *
     * @effects abort_explains_why_it_waits_for_the_running_task
     *
     * @return void 반환값 없음
     */
    public function test_abort_tells_the_operator_why_it_waits(): void
    {
        $source = $this->installerSource();
        $at = strpos($source, 'async function abortInstallation');
        $this->assertNotFalse($at, 'abortInstallation 을 찾지 못했다');

        // 한글 주석이 많은 파일이라 **바이트** 길이로 넉넉히 잡는다(글자 수의 약 3배).
        $body = substr($source, $at, 6000);

        $this->assertStringContainsString(
            "lang('abort_waiting_for_task')",
            $body,
            '중단 대기 사유를 화면에 남기지 않는다 — 실측 12.6 초가 설명 없는 침묵이 된다'
        );

        foreach (['ko', 'en'] as $code) {
            $table = require dirname(__DIR__, 3).'/public/install/lang/'.$code.'.php';
            $this->assertArrayHasKey('abort_waiting_for_task', $table, $code.' 표에 중단 대기 사유 문구가 없다');
        }
    }

    /**
     * #M13 — 중단은 진행 중인 모니터를 멈춘다.
     *
     * @scenario installation_mode=stepwise, task_outcome=aborted
     *
     * @effects abort_stops_the_running_monitor
     *
     * @return void 반환값 없음
     */
    public function test_abort_stops_the_running_monitor(): void
    {
        $source = $this->installerSource();

        $start = strpos($source, 'async function abortInstallation(');
        $this->assertNotFalse($start);
        $body = substr($source, $start, 4000);

        $this->assertStringContainsString(
            'currentMonitor.stop()',
            $body,
            '멈추지 않으면 SSE 는 재연결하고 폴링·단계별 실행은 요청을 한 번 더 보낸다 (중단 화면도 두 번 그려진다)'
        );
        $this->assertStringNotContainsString('eventSource', $source, '죽은 변수를 닫는 코드는 「멈추고 있다」는 착시를 만든다');
    }

    /**
     * #M14 · #M15 — 시작 경로가 취소를 확인하고, 재개 버튼이 연타로 두 번 뜨지 않는다.
     *
     * @scenario installation_mode=stepwise, resume_point=retry_after_failure
     *
     * @effects abort_stops_the_running_monitor, start_path_checks_cancellation
     *
     * @return void 반환값 없음
     */
    public function test_start_path_checks_cancellation_and_locks_resume_buttons(): void
    {
        $source = $this->installerSource();

        $start = strpos($source, 'async function startInstallation(');
        $this->assertNotFalse($start);
        $end = strpos($source, 'function buildMonitorCallbacks(', $start);
        $body = substr($source, $start, $end - $start);

        $this->assertGreaterThanOrEqual(
            3,
            substr_count($body, 'if (installationCancelRequested)'),
            '시작은 세 번 기다린다 — SSE probe · install-process 응답 · 단계별 실행 복원 스냅샷. '
                .'마지막 하나만 빠져 있어도 그 창의 [중단] 뒤에 모니터가 살아 남는다 (10차)'
        );

        // 19차 G#8 — 이 docblock 이 선언한 계약은 **순서**다(「재진입 가드를 내리기 전에」).
        // 그런데 종전 단언은 함수 본문 `$body` 가 아니라 **파일 전체 `$source`** 를 봤다 —
        // 순서도, 위치도 보지 않는다. `continueInstallation()` 안의 두 호출을 지우고 아무 데도
        // 불리지 않는 헬퍼에 옮겨 적어도 초록이었다. 바로 위 형제 단언은 `$body` 를 쓰므로
        // 같은 메서드 안에서 두 규칙이 갈려 있었다.
        $continue = $this->functionBody($source, 'continueInstallation');
        $this->assertNotSame('', $continue, 'continueInstallation() 을 찾지 못했다');

        $lock = strpos($continue, 'setResumeButtonsDisabled(true)');
        $release = strpos($continue, 'installationStarted = false');

        $this->assertNotFalse($lock, '재개 버튼을 잠그지 않으면 연타로 워커가 둘 뜬다');
        $this->assertNotFalse($release, '재진입 가드를 내리는 지점을 찾지 못했다');
        $this->assertLessThan(
            $release,
            $lock,
            '잠금이 가드 해제보다 **뒤**에 오면 그 사이의 두 번째 클릭이 통과한다',
        );

        $this->assertStringContainsString('setResumeButtonsDisabled(false)', $source, '실패·중단 화면에서 다시 풀지 않으면 버튼이 영구 비활성이 된다');
    }

    /**
     * #M12 — 완료 화면이 지나간 단계의 증거를 지우지 않는다.
     *
     * @scenario installation_mode=stepwise, skip_request=skippable
     *
     * @effects completion_keeps_passed_over_evidence
     *
     * @return void 반환값 없음
     */
    public function test_completion_keeps_evidence_of_passed_over_steps(): void
    {
        $source = $this->installerSource();

        // 19차 G#7 — 함수 본문은 **중괄호로** 자른다.
        //
        // 종전에는 `substr($source, $start, 3000)` 이었다. 부정 단언을 임의 고정 창으로
        // 좁히면 두 가지로 무력화된다 — ① 함수 앞에 코드·주석을 3,000자 넘게 넣어 문제 줄을
        // 창 밖으로 밀어내면 초록 ② 공백 하나만 달라도(`display  = 'none'`) 초록.
        // 형제 검사(`InstallerClientContractDriftTest`)는 이미 이 함정을 주석으로 지목하고
        // 경계를 따로 잡고 있었다 — 같은 저장소 안에 두 규칙이 공존하던 자리다.
        $body = $this->functionBody($source, 'showCompletionSection');
        $this->assertNotSame('', $body, 'showCompletionSection() 을 찾지 못했다');

        $this->assertStringContainsString('skipped_tasks', $body, '건너뜀·best-effort 실패를 읽지 않으면 100% 「완료」만 남는다');
        $this->assertStringContainsString('best_effort_failed_tasks', $body);

        // 공백·따옴표 변형에 걸리지 않도록 정규식으로 본다.
        $this->assertDoesNotMatchRegularExpression(
            '/installationCardBody\s*\.\s*style\s*\.\s*display\s*=\s*[\'"]none[\'"]/',
            $body,
            '조건 없이 접으면 지나간 단계가 최종 화면에서 사라진다'
        );
    }

    /**
     * #L14 · #L15 — 문구는 lang() 을 지나고, 브라우저 원문은 진단 상세로만 간다.
     *
     * @scenario locale=ko
     *
     * @effects installer_lang_literal_references_are_defined
     *
     * @return void 반환값 없음
     */
    public function test_messages_go_through_lang_and_raw_errors_stay_in_detail(): void
    {
        $source = $this->installerSource();

        $this->assertStringNotContainsString(
            'window.INSTALLER_LANG?.',
            $source,
            '전역 사전을 직접 읽으면 그 지점만 영어 폴백을 갖는다 — 번역은 lang() 단일 지점을 지난다'
        );
        $this->assertStringNotContainsString(
            '${error.message}',
            $source,
            '브라우저 원문을 문구에 보간하면 실패 화면 제목이 개발자 표기가 된다 — 진단 상세로만 싣는다 (§12.0 W3)'
        );
    }

    /**
     * #L16 — 결과 섹션 전환은 CSS 가 소유하고 prefers-reduced-motion 을 존중한다.
     *
     * @scenario installation_mode=stepwise, a11y_axis=live_region
     *
     * @effects installer_markup_classes_have_style_rules
     *
     * @return void 반환값 없음
     */
    public function test_result_transition_is_owned_by_css(): void
    {
        $source = $this->installerSource();
        $css = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/assets/css/installer.css');

        $this->assertStringNotContainsString(
            "style.transition = 'opacity",
            $source,
            '인라인 style 로 박으면 우선순위상 prefers-reduced-motion 이 이길 수 없다'
        );
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertStringContainsString('.result-section-enter', $css);
    }

    /**
     * #L20 — 재렌더 경로의 속성값도 이스케이프한다 (확장 식별자는 스캔된 디렉터리명에서 온다).
     *
     * @scenario installation_mode=stepwise, a11y_axis=live_region
     *
     * @effects installer_markup_classes_have_style_rules
     *
     * @return void 반환값 없음
     */
    public function test_task_list_escapes_attribute_values(): void
    {
        $source = $this->installerSource();

        foreach (['data-task-id="${task.id}"', 'data-target="${task.target'] as $unescaped) {
            $this->assertStringNotContainsString(
                $unescaped,
                $source,
                '속성값을 무가공으로 보간하면 인용부호가 섞인 식별자에서 속성 탈출이 가능하다'
            );
        }
    }

    /**
     * 진행 목록의 상태 아이콘은 같은 뜻의 텍스트를 함께 싣는다.
     *
     * 아이콘만 갈아 끼우면 화면 판독기 사용자에게 14단계가 전부 같은 이름으로만 읽힌다 —
     * 어느 단계가 완료·실패·건너뜀인지 항목 수준에서 전달되지 않는다. 요구사항 화면
     * (`getStatusIcon`)은 이미 그렇게 하고 있어 한 파일 안에서 규칙이 갈려 있었다.
     *
     * 이 파일은 닫힌 IIFE 라 함수를 꺼내 돌릴 수 없다 — 그래서 구조를 본다. 다섯 상태가
     * 전부 라벨 키를 갖고, 아이콘 주입이 `.sr-only` 를 함께 넣는지 확인한다.
     *
     * @scenario installation_mode=stepwise, a11y_axis=live_region
     *
     * @effects installer_markup_classes_have_style_rules
     *
     * @return void 반환값 없음
     */
    public function test_progress_list_icons_carry_a_text_alternative(): void
    {
        $source = $this->installerSource();

        $this->assertStringContainsString(
            'const statusPresentation = {',
            $source,
            '상태 표현 표가 사라지면 아이콘·라벨 대응을 한 지점에서 볼 수 없다'
        );

        foreach (['skipped', 'active', 'completed', 'failed', 'aborted'] as $status) {
            $this->assertMatchesRegularExpression(
                '/'.$status.':\s*\{[^}]*labelKey:\s*\'[a-z_]+\'/',
                $source,
                $status.' 상태에 라벨 키가 없으면 그 단계는 판독기에 이름 없이 읽힌다'
            );
        }

        $this->assertMatchesRegularExpression(
            '/getIconSvg\(presentation\.icon\)\s*\r?\n?\s*\+\s*`<span class="sr-only">/',
            $source,
            '아이콘만 주입하면 상태가 모양·색으로만 전달된다'
        );

        $this->assertStringContainsString(
            'aria-hidden="true" focusable="false"',
            $source,
            '장식 아이콘을 감추지 않으면 판독기가 이름 없는 도형을 한 번 더 읽는다'
        );
    }

    /**
     * 두 채널에 복제된 `setInputDisabled` 가 서로 어긋나지 않는가.
     *
     * installer.js 는 닫힌 IIFE 라 그 안의 함수가 Step 3 화면 스크립트에서 보이지 않는다 —
     * 사본이 존재하는 것 자체는 구조상 불가피하다. 문제는 그 사실을 **아무도 지키지 않았다**는
     * 것이다: 3·4·8차가 세 라운드에 걸쳐 고친 aria 동기화를 한쪽에서만 지워도 초록이었다.
     *
     * Step 3 사본은 strict CSP 로 뷰의 인라인 스크립트에서 단계 스크립트(step-3-configuration.js)로
     * 옮겨졌다. 대조 대상도 그 파일이다.
     *
     * @scenario installation_mode=stepwise, a11y_axis=live_region
     *
     * @effects installer_markup_classes_have_style_rules
     *
     * @return void 반환값 없음
     */
    public function test_duplicated_input_disable_helper_stays_in_sync(): void
    {
        $root = dirname(__DIR__, 3).'/public/install';
        $bodies = [];

        foreach ([
            'assets/js/installer.js',
            'assets/js/step-3-configuration.js',
        ] as $relative) {
            // 주석을 지운 뒤 대조한다 — 원문에는 주석 처리된 배선도 글자로 남아, 원문 대조는 「그 배선이 살아 있다」를 증명하지 못한다 (12차 메타 검사 ①).
            // (여기서는 두 사본을 그대로 비교하므로 양쪽에 같은 처리를 적용해야 의미가 보존된다)
            $source = str_ends_with($relative, '.js')
                ? $this->stripJsComments((string) file_get_contents($root.'/'.$relative))
                : $this->stripComments((string) file_get_contents($root.'/'.$relative));
            $at = strpos($source, 'function setInputDisabled(');
            $this->assertNotFalse($at, $relative.' 에 setInputDisabled 사본이 없다 — 한쪽만 남으면 그 채널의 동작이 갈린다');

            $open = strpos($source, '{', $at);
            $depth = 0;
            $end = $open;
            for ($i = $open; $i < strlen($source); $i++) {
                if ($source[$i] === '{') {
                    $depth++;
                }
                if ($source[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $end = $i;
                        break;
                    }
                }
            }

            // 들여쓰기·줄바꿈 차이는 무시하고 의미만 비교한다.
            $bodies[$relative] = preg_replace('/\s+/', ' ', substr($source, $open, $end - $open + 1));
        }

        $values = array_values($bodies);
        $this->assertSame(
            $values[0],
            $values[1],
            '두 사본의 본문이 어긋났다 — 한쪽만 고치면 그 채널의 접근성 동기화가 조용히 사라진다'
        );
        $this->assertStringContainsString('aria-disabled', $values[0], '비활성은 속성과 시각 표현을 함께 준다 (§12 W1)');
    }
}
