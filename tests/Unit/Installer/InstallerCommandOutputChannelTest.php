<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 커맨드 원문이 진행 기록이 아니라 「명령 상세」 접기로 간다 (14차 #3 · §12.0 W3).
 *
 * ## 왜 이 축인가
 *
 * §12.0 은 W3 확정 선택으로 「진행 기록은 **사람 말**로 쓴다 (명령·클래스 이름 금지) —
 * 인스톨러 로그의 독자는 운영자다」를 못 박았다. 10차가 그 규칙으로 수동 복구 명령을
 * 진행 기록에서 걷어내 실패 화면의 접기 영역으로 옮겼는데, **형제인 커맨드 stdout 은 함께
 * 옮기지 않았다.** 그래서 `migrate`·`db:seed`·확장 설치가 뱉는 마이그레이션 파일명과
 * `INFO Running migrations` 류가 그대로 진행 기록 상자에 찍히고 있었다 — 세 모드 공통.
 *
 * 진행 기록(storage/logs/installation.log)은 SSE·폴링·단계별 실행이 공유하는 단일 채널이라
 * 채널을 나누면 세 모드의 재생 경로가 전부 갈라진다. 그래서 줄에 표식을 붙여 화면이
 * 분류한다 — 로그 파일에는 그대로 남아 진단이 보존된다.
 *
 * 이 구조는 **서버와 화면이 같은 리터럴을 쓸 때만** 성립한다. 한쪽만 바뀌면 예외도 오류도
 * 없이 표식 문자열이 운영자 화면에 그대로 노출되거나(화면이 못 알아봄), 명령 원문이 다시
 * 진행 기록으로 새어 나온다(서버가 안 붙임). 그래서 두 소스를 대조한다.
 *
 * 커버 축: channel progress_log·command_detail / emitter stream·batch / side server·client
 */
class InstallerCommandOutputChannelTest extends TestCase
{
    use ScansPhpSourceFunctions;

    private function installRoot(): string
    {
        return dirname(__DIR__, 3).'/public/install';
    }

    /**
     * 서버가 정한 표식 리터럴 — config.php 의 정의를 소스에서 직접 읽는다.
     *
     * 상수를 `define` 으로 가져오면 이 테스트가 BASE_PATH 를 실 저장소 루트로 박아
     * 형제 인스톨러 테스트를 전부 skip 시킨다 (R7 · 7차 후속 주의).
     */
    private function serverPrefix(): string
    {
        $config = $this->sourceWithoutComments($this->installRoot().'/includes/config.php');

        $this->assertMatchesRegularExpression(
            "/define\('INSTALLER_COMMAND_OUTPUT_PREFIX',\s*'([^']+)'\)/",
            $config,
            'config.php 가 표식을 정의하지 않는다 — 이 채널의 기준점이 사라졌다',
        );

        preg_match("/define\('INSTALLER_COMMAND_OUTPUT_PREFIX',\s*'([^']+)'\)/", $config, $m);

        return $m[1];
    }

    /**
     * 서버와 화면이 같은 리터럴을 쓴다.
     *
     * @effects installer_command_output_prefix_is_shared_by_server_and_client
     */
    public function test_prefix_literal_is_shared_by_server_and_client(): void
    {
        $prefix = $this->serverPrefix();
        $this->assertNotSame('', $prefix);

        $js = $this->sourceWithoutComments($this->installRoot().'/assets/js/installer.js');

        $this->assertMatchesRegularExpression(
            "/const\s+COMMAND_OUTPUT_PREFIX\s*=\s*'".preg_quote($prefix, '/')."'/",
            $js,
            "화면이 서버와 다른 표식을 쓰면 분류가 통째로 어긋난다 — 표식 문자열이 운영자\n"
            .'화면에 그대로 노출되거나 명령 원문이 진행 기록으로 새어 나온다.',
        );
    }

    /**
     * 커맨드 출력을 내보내는 지점은 전부 표식을 거친다 (스트림·배치 양쪽).
     *
     * 표식이 붙는 자리가 갈리면 한 진행 모드에서만 원문이 새어 나간다 — 폴링은 배치
     * 기록(addLogBatch), SSE·단계별 실행은 스트림 emit 을 쓰기 때문이다.
     *
     * @effects installer_command_output_never_enters_the_progress_log
     */
    public function test_every_command_output_emitter_goes_through_the_marked_channel(): void
    {
        $runner = $this->sourceWithoutComments($this->installRoot().'/includes/task-runner.php');

        // 원문을 그대로 log 이벤트로 흘리는 형태가 남아 있으면 안 된다.
        $this->assertDoesNotMatchRegularExpression(
            "/foreach\s*\(\\\$output\s+as\s+\\\$line\)\s*\{[^}]*sendSSEEvent\(\s*'log'\s*,\s*\[\s*'message'\s*=>\s*\\\$line/s",
            $runner,
            "커맨드 출력을 표식 없이 진행 기록으로 보내는 지점이 남아 있다 (§12.0 W3)\n"
            .'— sendCommandOutputSSE() 를 거치게 할 것.',
        );

        $this->assertStringContainsString(
            'function sendCommandOutputSSE',
            $runner,
            '표식을 붙이는 단일 지점이 없다',
        );

        // 배치 경로(폴링)도 같은 표식을 붙인다.
        $this->assertStringContainsString(
            'INSTALLER_COMMAND_OUTPUT_PREFIX.$line',
            $runner,
            '배치 기록 경로가 표식을 빠뜨리면 폴링 모드에서만 원문이 진행 기록에 남는다',
        );
    }

    /**
     * 화면에 그 원문이 갈 자리가 실제로 있다 — 접기 영역과 분류 배선.
     *
     * 표식만 붙이고 화면이 분류하지 않으면 표식 문자열째로 진행 기록에 찍힌다.
     *
     * @effects installer_command_output_has_a_collapsible_home
     */
    public function test_client_routes_marked_lines_into_the_collapsible_detail(): void
    {
        $js = $this->sourceWithoutComments($this->installRoot().'/assets/js/installer.js');
        // 19차 G#4 — 뷰도 주석을 지운 뒤 대조한다.
        //
        // 같은 메서드의 `$js` 는 `stripJsComments()` 를 지나는데 뷰만 원문이었다. 그래서
        // `command-output-*` 블록을 PHP 블록 주석으로 감싸는 것만으로 이 검사가 초록으로
        // 남고, 분류된 커맨드 원문은 갈 자리가 없어 조용히 사라진다 — docblock 이 예고한
        // 「예외도 오류도 남지 않는다」가 그대로 성립한다. 10차 #G4 가 세우고 §16.14.6 이
        // 재확인한 「문자로 대조하는 게이트는 주석을 지운다」 규약의 유일한 미적용 지점이었다.
        //
        // G3 회차 14 W4-8 — 뷰는 **두 언어**다. `stripComments()` 는 PHP 주석만 지우므로
        // `<script>` 안의 JS 주석(`// appendCommandOutput(`)이 남아 같은 사각이 반쪽 열려
        // 있었다. 공용 진입점(`sourceWithoutComments()`)이 PHP 주석을 지운 뒤 script 구간을
        // JS 규칙으로 한 번 더 지운다.
        $view = $this->sourceWithoutComments($this->installRoot().'/views/5-installation.php');

        // 실행 중 경로와 새로고침 복원 경로 **양쪽**이 분류한다 — 한쪽만 하면
        // 「돌 때는 안 보이는데 새로고침하면 보이는」 줄이 생긴다.
        foreach (['function addLogMessage', 'function displayLogs'] as $fn) {
            $at = strpos($js, $fn);
            $this->assertNotFalse($at, $fn.' 을 찾지 못했다');
            $body = substr($js, $at, 1200);
            $this->assertStringContainsString(
                'commandOutputBody(',
                $body,
                $fn.' 이 커맨드 원문을 분류하지 않는다 — 그 경로에서만 진행 기록에 원문이 남는다',
            );
        }

        $this->assertStringContainsString('function appendCommandOutput', $js, '접기 영역에 붙이는 함수가 없다');

        foreach (['command-output-section', 'command-output-log', 'command-output-content'] as $id) {
            $this->assertStringContainsString(
                'id="'.$id.'"',
                $view,
                '화면에 '.$id.' 가 없다 — 분류된 줄이 갈 자리가 없으면 그대로 사라진다',
            );
        }
    }
}
