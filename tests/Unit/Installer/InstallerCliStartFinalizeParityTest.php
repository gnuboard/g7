<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Installer\Concerns\RunsInstallerCli;

/**
 * 터미널 설치의 시작·마무리가 웹 설치와 같은 고정·정리를 한다.
 *
 * 시작: 웹 설치 시작(api/install-process.php)은 실행 방식을 진입 때 한 번 판정해 `execution_mode_resolved`·
 * `php_binary_resolved` 로 state 에 고정하고(계획 서명에 포함), vendor 방식을 그 실행 방식으로 보정한다. 터미널도
 * 같은 값을 같은 통로(installer_begin_installation 의 state_extra)로 넘겨야 한 설치 안에서 판정이 갈리지 않는다.
 *
 * 마무리: 웹 마무리 엔드포인트(api/finalize-env.php)는 .env 확정 뒤 웹 노출 점검 잔여물을 지운다. 터미널 확정도
 * 같은 정리를 하고, 확정이 예외로 끝나도 정리한다.
 *
 * 설치 계획 확정·.env 확정 본체는 같은 이름의 함수를 먼저 정의해 대역으로 바꾼다(본체는 function_exists 가드).
 */
class InstallerCliStartFinalizeParityTest extends TestCase
{
    use RunsInstallerCli;

    /** 설치 계획 확정 대역 — 받은 config·opts 를 전역에 남기고 성공을 돌려준다 */
    private const CAPTURING_BEGIN = <<<'PHP'
        function installer_begin_installation(array $state, array $config, array $secrets, string $action, ?string $token, array $opts = []): array { $GLOBALS['__begin'] = ['config' => $config, 'opts' => $opts]; return ['ok' => true, 'warning' => null]; }
        PHP;

    /**
     * 임시 BASE_PATH 를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->makeInstallerTempBase();
        $this->putBaseFile('.env', "APP_KEY=\n");
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
     * 설치 시작이 실행 방식·PHP 경로를 state_extra 로 고정하고 vendor 방식을 실행 방식으로 보정한다.
     *
     * 명시 선택은 감지 없이 그대로 쓴다 — inprocess 면 composer 를 골랐어도 bundled, subprocess 면 고른 그대로.
     * 고정한 사실은 웹과 같은 문구로 설치 로그에 남는다.
     *
     * @effects cli_install_start_pins_execution_mode_and_php_binary
     *
     * @return void 반환값 없음
     */
    public function test_install_start_pins_execution_mode_like_web(): void
    {
        $begin = function (array $config): array {
            $run = $this->runCliCode(
                'g7_cli_begin_installation([], '.var_export($config, true).', [], "skip", null);'
                .'echo "__RESULT__=".json_encode($GLOBALS["__begin"] + ["log" => (string) @file_get_contents(BASE_PATH."/storage/logs/installation.log")], JSON_UNESCAPED_UNICODE);',
                self::CAPTURING_BEGIN
            );
            $this->assertIsArray($run['result'], $run['raw']);

            return $run['result'];
        };

        $inprocess = $begin(['execution_mode' => 'inprocess', 'vendor_mode' => 'composer', 'php_binary' => '/opt/php83/bin/php']);
        $this->assertSame('cli', $inprocess['opts']['installation_mode'] ?? null);
        $this->assertSame('inprocess', $inprocess['opts']['state_extra']['execution_mode_resolved'] ?? null, '실행 방식이 state 에 고정되지 않았다');
        $this->assertSame('/opt/php83/bin/php', $inprocess['opts']['state_extra']['php_binary_resolved'] ?? null, '운영자가 지정한 PHP 경로가 실행 경로로 고정되지 않았다');
        $this->assertSame('bundled', $inprocess['config']['vendor_mode'] ?? null, 'inprocess 인데 composer 가 그대로 넘어갔다');
        $this->assertStringContainsString('실행 방식: CLI 없이 실행 (직접 선택)', $inprocess['log'], '고정한 실행 방식이 설치 로그에 남지 않았다');

        $subprocess = $begin(['execution_mode' => 'subprocess', 'vendor_mode' => 'composer', 'php_binary' => 'php']);
        $this->assertSame('subprocess', $subprocess['opts']['state_extra']['execution_mode_resolved'] ?? null);
        $this->assertSame('php', $subprocess['opts']['state_extra']['php_binary_resolved'] ?? null);
        $this->assertSame('composer', $subprocess['config']['vendor_mode'] ?? null, 'subprocess 에서는 고른 vendor 방식을 바꾸지 않는다');
    }

    /**
     * .env 확정 뒤 웹 노출 점검 잔여물(sentinel)을 지우고, 확정이 예외로 끝나도 지운다. 다른 파일은 건드리지 않는다.
     *
     * @effects cli_finalize_sweeps_exposure_probe_leftovers
     *
     * @return void 반환값 없음
     */
    public function test_finalize_sweeps_exposure_probe_leftovers_on_every_path(): void
    {
        $this->putBaseFile('storage/installer/exposure-probe-0123abcd', 'x');
        $this->putBaseFile('storage/installer/symlink-probe-4567efgh', 'x');
        $keep = $this->putBaseFile('storage/installer/keep.txt', 'x');

        $run = $this->runCliCode(
            '$first = g7_cli_finalize_env();'
            .'$afterSuccess = array_values(array_filter(scandir(BASE_PATH."/storage/installer"), static fn ($e) => str_contains($e, "-probe-")));'
            .'file_put_contents(BASE_PATH."/storage/installer/exposure-probe-89ab", "x");'
            .'$GLOBALS["__finalize_throws"] = true; $thrown = false;'
            .'try { g7_cli_finalize_env(); } catch (RuntimeException $e) { $thrown = true; }'
            .'$afterThrow = array_values(array_filter(scandir(BASE_PATH."/storage/installer"), static fn ($e) => str_contains($e, "-probe-")));'
            .'echo "__RESULT__=".json_encode(["success" => $first["success"], "after_success" => $afterSuccess, "thrown" => $thrown, "after_throw" => $afterThrow, "log" => (string) @file_get_contents(BASE_PATH."/storage/logs/installation.log")], JSON_UNESCAPED_UNICODE);',
            'function installer_finalize_env(): array { if (! empty($GLOBALS["__finalize_throws"])) { throw new RuntimeException("finalize failed"); } return ["success" => true]; }'
        );

        $this->assertIsArray($run['result'], $run['raw']);
        $this->assertTrue($run['result']['success']);
        $this->assertSame([], $run['result']['after_success'], '확정 뒤 노출 점검 잔여물이 남았다');
        $this->assertTrue($run['result']['thrown'], '확정 예외가 삼켜졌다 — 정리는 하되 실패는 호출자에게 그대로 가야 한다');
        $this->assertSame([], $run['result']['after_throw'], '확정이 예외로 끝난 경로에서 잔여물이 남았다');
        $this->assertStringContainsString('노출 점검 잔여 파일 정리 완료 (2개)', $run['result']['log'], '정리 사실이 설치 로그에 남지 않았다');
        $this->assertFileExists($keep, '정리 대상이 아닌 파일을 지웠다');
    }
}
