<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Installer\Concerns\RunsInstallerCli;

/**
 * 터미널 송출기 — `--output=json` 의 이벤트·필드가 웹 SSE 송출과 같고, 중단 신호가 작업 중단 판정으로 이어진다.
 *
 * 설치 작업(`runInstallationTasks`)은 송출기만 바꿔 같은 이벤트를 낸다 — 자동화 도구가 웹 진행 화면과 같은
 * 이름·필드로 진행을 읽을 수 있어야 한다.
 */
class InstallerCliEmitterTest extends TestCase
{
    use RunsInstallerCli;

    /**
     * 임시 BASE_PATH 를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->makeInstallerTempBase();
    }

    /**
     * 임시 BASE_PATH 를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->removeInstallerTempBase();
        parent::tearDown();
    }

    /**
     * JSON 줄의 이벤트 이름·데이터 = SSE 의 `event:`·`data:` (한글·특수문자 포함).
     *
     * @effects cli_json_emitter_matches_sse_event_fields
     *
     * @return void 반환값 없음
     */
    public function test_json_lines_match_sse_payload(): void
    {
        $samples = [
            ['task_start', ['task' => 'db_migrate', 'name' => '데이터베이스 마이그레이션']],
            ['task_complete', ['task' => 'db_migrate', 'message' => '완료 "ok" / 100%']],
            ['log', ['message' => '로그 줄']],
            ['error', ['message' => '실패', 'error' => "상세\n둘째 줄", 'task' => 'composer_install']],
            ['completed', ['message' => '설치 완료', 'redirect' => '/admin']],
        ];

        // SseEmitter 는 내보낼 때마다 출력 버퍼를 비우므로(ob_flush) 버퍼로 가로채지 않고 표준 출력에서 읽는다.
        $run = $this->runCliCode(
            '$samples = '.var_export($samples, true).';'
            .'$sse = new SseEmitter(); foreach ($samples as [$e, $d]) { $sse->emit($e, $d); }'
            .'$json = new JsonLinesEmitter(); foreach ($samples as [$e, $d]) { $json->emit($e, $d); }'
        );

        $sse = [];
        preg_match_all('/^event: (\S+)\r?\ndata: (.*)\r?\n/m', $run['raw'], $blocks, PREG_SET_ORDER);
        foreach ($blocks as $block) {
            $sse[] = ['event' => $block[1]] + json_decode($block[2], true);
        }

        $this->assertCount(count($samples), $sse);
        $this->assertSame($sse, $run['events']);
    }

    /**
     * 심장박동(heartbeat)은 화면용이라 터미널 출력에 내지 않는다.
     *
     * @effects cli_emitter_suppresses_heartbeat
     *
     * @return void 반환값 없음
     */
    public function test_heartbeat_is_not_printed(): void
    {
        $run = $this->runCliCode('(new JsonLinesEmitter())->emit("heartbeat", ["ts" => 1]); (new JsonLinesEmitter())->emit("log", ["message" => "x"]);');

        $this->assertSame(['log'], array_column($run['events'], 'event'));
    }

    /**
     * 하위 명령 원문(`[cmd] ` 표식 log)은 사람이 읽는 출력에서 표식을 떼고 진행 줄과 구분해 찍는다.
     *
     * 웹 진행 화면은 이 줄을 진행 기록이 아니라 「명령 상세」로 보낸다. 터미널이 표식째 진행 줄로 찍으면 한 설치에
     * 수천 줄인 명령 원문이 진행 기록을 덮는다. JSON 줄은 웹 SSE 와 같은 필드를 유지한다(표식 포함).
     *
     * @effects cli_emitter_separates_command_output_lines
     *
     * @return void 반환값 없음
     */
    public function test_command_output_lines_are_set_apart_from_progress_lines(): void
    {
        $run = $this->runCliCode(
            '$text = new CliEmitter(null, false);'
            .'$text->emit("log", ["message" => INSTALLER_COMMAND_OUTPUT_PREFIX."INFO  Running migrations."]);'
            .'$text->emit("log", ["message" => "진행 줄"]);'
            .'(new JsonLinesEmitter())->emit("log", ["message" => INSTALLER_COMMAND_OUTPUT_PREFIX."raw line"]);'
        );

        $this->assertStringContainsString("      │ INFO  Running migrations.\n", $run['raw'], '명령 원문이 구분되지 않았다');
        $this->assertStringNotContainsString('[cmd] INFO', $run['raw'], '표식이 그대로 찍혔다');
        $this->assertStringContainsString("    진행 줄\n", $run['raw'], '일반 진행 줄의 형태가 바뀌었다');
        $this->assertSame([['event' => 'log', 'message' => '[cmd] raw line']], $run['events'], 'JSON 줄은 웹 SSE 와 같은 message 를 유지한다');
    }

    /**
     * 중단 신호 → `isConnectionAborted()` 참 (작업 실행기가 중단으로 멈춘다).
     *
     * @effects cli_emitter_abort_flag_maps_to_connection_aborted
     *
     * @return void 반환값 없음
     */
    public function test_abort_flag_maps_to_connection_aborted(): void
    {
        $run = $this->runCliCode(
            '$e = new JsonLinesEmitter(); $before = $e->isConnectionAborted(); $GLOBALS["g7_cli_abort"] = true;'
            .'echo "__RESULT__=".json_encode([$before, $e->isConnectionAborted()]);'
        );

        $this->assertSame([false, true], $run['result']);
    }
}
