<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 22차 스윕 회귀 가드 — 「한 사실, 두 판정기」와 「참→거짓 전이를 소비자가 놓침」 축
 *
 * 22차가 연 축의 반복 형태: 판정 함수는 SSoT 로 모여 있는데, 그 함수를 부를지 말지를
 * 정하는 조건과 결과를 **되돌릴 때**의 처리가 지점마다 달랐다. 이 클래스는 그 어긋남을
 * 소스 계약으로 잠근다 (행위 재현이 세션·DOM·리다이렉트에 결합된 지점들이라 소스 계약이
 * 실효 지점이다 — 각 항목의 행위 근거는 계획서 §16.28 기록).
 *
 * - **#22-1 (상)**: `.env` 준비 판정이 세 지점(사전 점검 / [파일 확인] 재검사 / 서버 시작)
 *   에서 두 규칙(존재+쓰기 vs 존재만)으로 갈렸다 — 재검사 버튼이 자기 게이트를 무력화하고,
 *   설치가 완주하고도 finalize 의 `.env` 머지가 조용히 실패해 인스톨러가 영구히 열린다.
 * - **#22-3**: 재개 어휘 목록이 [초기화]가 실제로 기록하는 `not_started` 를 빠뜨렸다
 *   (19차 S-5 와 같은 결함이 다른 값에서 잔존).
 * - **#22-4**: 서버는 완료 마커를 **되돌릴 수 있는 사실**로 다루는데(removeTaskCompleted),
 *   화면의 완료 표시는 단조 증가만 했다 — 롤백된 태스크가 초록으로 남는다.
 * - **#22-5**: 실행(getPhpBinary — 형태 검사+폴백)과 `.env` 기록(generateEnvContent —
 *   무검사 원문)이 같은 값을 다르게 판정했다.
 * - **#22-7**: 언어 변경의 상태 저장 실패가 무음 폐기됐다 (17차 #C2 규율 미적용 지점).
 * - **#22-8**: SSE 입구의 두 모드 게이트 중 한쪽만 message_key 를 실었다.
 * - **#22-9**: 합성 키(taskId:target) 분해가 JS 안에서 두 규칙(첫 콜론 vs 전체 split)이었다.
 * - **N-5**: 디스크 여유 경고 문구가 카드 값 슬롯 계약(짧은 라벨)을 깼다.
 * - **N-6·N-8**: 롤 토큰 꼬리 무제한 · 전개 출력의 미전개 롤 무신호.
 */
class InstallerStateTransitionConsistencyTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /**
     * 주석을 지운 소스 — 이 클래스의 모든 소스 계약은 이 지점을 지난다.
     *
     * @param  string  $relative  저장소 루트 기준 상대 경로
     * @return string 주석을 지운 소스
     */
    private function src(string $relative): string
    {
        return $this->sourceWithoutComments(dirname(__DIR__, 3).'/'.$relative);
    }

    /**
     * #22-1: 세 지점이 같은 술어(존재 + 쓰기 가능)를 쓴다.
     *
     * @effects installer_state_transitions_are_consistently_judged
     *
     * @return void 반환값 없음
     */
    public function test_env_readiness_judged_identically_at_all_three_gates(): void
    {
        $js = $this->src('public/install/assets/js/installer.js');
        $server = $this->src('public/install/api/install-process.php');
        $ko = $this->src('public/install/lang/ko.php');

        // 사전 점검(거부형 `=== false`)과 재검사(허용형 `!== false`) 모두 env_writable 을
        // 소비해야 한다 — 재검사가 존재만 보면 그 버튼이 게이트의 우회로다.
        $this->assertGreaterThanOrEqual(
            3,
            substr_count($js, 'check.env_writable'),
            '사전 점검과 [파일 확인] 재검사가 같은 술어를 써야 한다 (#22-1)'
        );
        $this->assertStringContainsString('check.env_exists && check.env_writable !== false', $js, '재검사의 시작 조건이 존재+쓰기 가능이어야 한다 (#22-1)');
        $this->assertStringContainsString('is_writable(BASE_PATH', $server, '서버 시작 게이트가 쓰기 가능성을 보지 않는다 (#22-1) — 화면만이 유일한 지점이면 fetch 예외가 곧 우회로다');
        $this->assertStringContainsString('error_env_not_writable_start', $server);
        $this->assertStringContainsString('error_env_not_writable_start', $ko);
    }

    /**
     * #22-3: 재개 어휘가 [초기화]의 실제 기록값을 포함한다.
     *
     * @effects installer_state_transitions_are_consistently_judged
     *
     * @return void 반환값 없음
     */
    public function test_resume_vocabulary_includes_reset_status(): void
    {
        $index = $this->src('public/install/index.php');

        $this->assertStringContainsString("'not_started'", $index, '[초기화] 후 세션이 끊기면 저장된 진행(step_status)이 있는데 환영 화면부터 다시 걷는다 (#22-3)');
    }

    /**
     * #22-4: 화면의 완료 표시가 「마커 제거」 전이를 반영한다.
     *
     * @scenario task_outcome=failed_db
     *
     * @effects installer_state_transitions_are_consistently_judged
     *
     * @return void 반환값 없음
     */
    public function test_completed_display_reverts_removed_markers(): void
    {
        $js = $this->src('public/install/assets/js/installer.js');

        $start = strpos($js, 'function displayCompletedTasks(');
        $this->assertNotFalse($start);
        $body = substr($js, $start, 3000);

        $this->assertStringContainsString('task-completed', $body, '완료 표시가 단조 증가만 한다 (#22-4) — 롤백된 태스크가 초록으로 남는다');
        $this->assertStringContainsString("'pending'", $body, '목록에서 사라진 마커를 pending 으로 되돌려야 한다 (#22-4)');
    }

    /**
     * #22-5: `.env` 기록도 실행과 같은 형태 검사·폴백을 지난다.
     *
     * @scenario php_binary_source=configured
     *
     * @effects installer_state_transitions_are_consistently_judged
     *
     * @return void 반환값 없음
     */
    public function test_env_php_binary_record_shares_shape_gate(): void
    {
        $functions = $this->src('public/install/includes/functions.php');

        $this->assertStringContainsString('installer_binary_path_shape_ok(', $functions, '.env 기록이 실행(getPhpBinary)과 다른 판정으로 원문을 박는다 (#22-5) — 설치는 A 로 끝나고 설치본은 B 를 쓰라고 기록된다');
    }

    /**
     * #22-7: 언어 변경의 상태 저장 실패는 무음 폐기되지 않는다.
     *
     * @scenario locale=en
     *
     * @effects state_persist_failure_is_reported_not_swallowed
     *
     * @return void 반환값 없음
     */
    public function test_language_change_reports_state_save_failure(): void
    {
        // 배선 단언은 문자열 채널까지 벗긴 소스로 잰다 (G3 회차 15).
        $functions = $this->sourceWithoutCommentsAndStrings(dirname(__DIR__, 3).'/public/install/includes/functions.php');

        $start = strpos($functions, 'function handleLanguageChange(');
        $this->assertNotFalse($start);
        $body = substr($functions, $start, 1600);

        $this->assertStringContainsString('installerSaveStateOrReport(', $body, '저장 실패가 무음이면 화면·워커 로그·설치본 기본 언어가 서로 다른 언어를 쓴다 (#22-7, 17차 #C2 규율)');
    }

    /**
     * #22-8: SSE 입구의 두 모드 게이트가 같은 응답 봉투(message_key 포함)를 쓴다.
     *
     * @scenario installation_mode=sse
     *
     * @effects installer_state_transitions_are_consistently_judged
     *
     * @return void 반환값 없음
     */
    public function test_sse_mode_gates_share_response_envelope(): void
    {
        $worker = $this->src('public/install/api/install-worker.php');

        $rejectPos = strpos($worker, 'installerSseWorkerRejected(');
        $this->assertNotFalse($rejectPos);
        $branch = substr($worker, $rejectPos, 900);

        $this->assertStringContainsString('message_key', $branch, '같은 사실(이 입구의 모드가 아니다)이 모드에 따라 다른 봉투로 나간다 (#22-8)');
    }

    /**
     * #22-9: 합성 키 분해는 단일 헬퍼(첫 콜론 기준)를 지난다 — 서버(explode limit 2)와 동형.
     *
     * @scenario task=module
     *
     * @effects installer_state_transitions_are_consistently_judged
     *
     * @return void 반환값 없음
     */
    public function test_composite_key_split_is_single_ruled(): void
    {
        $js = $this->src('public/install/assets/js/installer.js');

        $this->assertGreaterThanOrEqual(3, substr_count($js, 'splitCompositeTaskKey('), '합성 키 분해 규칙이 지점마다 다르다 (#22-9)');
        $this->assertStringNotContainsString(".split(':')", $js, "전체 split(':') 은 콜론이 둘 이상인 키에서 target 을 자른다 (#22-9)");
    }

    /**
     * N-5: 디스크 여유 경고는 카드 값 슬롯이 아니라 별도 안내로 나간다.
     *
     * @effects installer_disk_headroom_warning_stays_out_of_the_value_slot
     *
     * @return void 반환값 없음
     */
    public function test_disk_headroom_warning_leaves_value_slot(): void
    {
        $api = $this->src('public/install/api/check-configuration.php');
        $js = $this->src('public/install/assets/js/installer.js');

        $this->assertStringContainsString("'headroom_message'", $api, '경고 문구가 message 에 합성되면 카드 값 슬롯(짧은 라벨 계약)에 문단이 들어간다 (N-5)');
        $this->assertStringContainsString('disk.headroom_message', $js);

        // low_headroom 분기만 본다 — 「확인 불가」 분기의 disk.message 소비는 짧은 라벨이라 정상.
        $branchStart = strpos($js, 'disk.low_headroom === true');
        $this->assertNotFalse($branchStart);
        $this->assertStringNotContainsString(
            'disk.message',
            substr($js, $branchStart, 500),
            '여유 부족 분기가 값 슬롯에 서버 합성 문장을 넣으면 안 된다 (N-5)'
        );
    }

    /**
     * N-6·N-8: 롤 토큰 꼬리 제한 + 전개 출력의 미전개 롤 강등.
     *
     * @effects installer_role_expansion_edges_are_bounded
     *
     * @return void 반환값 없음
     */
    public function test_role_expansion_edges_are_bounded(): void
    {
        $functions = $this->src('public/install/includes/functions.php');

        $this->assertStringNotContainsString('(?:@\S+)?', $functions, '롤 토큰 꼬리가 무제한이면 SQL 보간의 신뢰 경계가 서버 출력 하나로 얇아진다 (N-6)');
        $this->assertStringContainsString("expanded['roles']", $functions, '전개 출력의 새 롤 부여가 무신호로 사라지면 권한 부족을 조용히 놓친다 (N-8) — 확인 불가는 확인 불가로 표시한다');
    }

    /**
     * 마이너: markTaskCompleted 의 방어가 형제(markTaskBestEffortFailed)와 같다.
     *
     * @scenario task_outcome=done
     *
     * @effects installer_state_transitions_are_consistently_judged
     *
     * @return void 반환값 없음
     */
    public function test_mark_task_completed_has_sibling_defenses(): void
    {
        $state = $this->src('public/install/includes/installer-state.php');

        $start = strpos($state, 'function markTaskCompleted(');
        $this->assertNotFalse($start);
        $body = substr($state, $start, 1600);

        $this->assertStringContainsString('?? null', $body, 'current_task 를 ?? 없이 읽으면 파싱 성공한 구형 상태에서 notice 가 난다 (17차 #C8 형제)');
        $this->assertStringContainsString(', true)', $body, 'in_array 비엄격 비교는 형제 함수의 방어와 갈린다');
    }
}
