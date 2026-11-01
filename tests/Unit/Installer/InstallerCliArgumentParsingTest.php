<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Installer\Concerns\RunsInstallerCli;

/**
 * 터미널 설치 인자 해석 — 입력 우선순위(명령줄 > 환경변수 > --config > 웹 기본값)·형식 오류·비밀값 경로.
 *
 * 형식 오류는 어떤 상태도 바꾸기 전에 종료 2 로 끝나야 하고, 비밀값은 명령줄·설정 파일로 받지 않는다
 * (프로세스 목록·셸 기록·저장소에 남는다).
 */
class InstallerCliArgumentParsingTest extends TestCase
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
     * 명령줄 > 환경변수 > --config > 웹 기본값.
     *
     * @effects cli_input_precedence_cli_env_config_default
     *
     * @return void 반환값 없음
     */
    public function test_input_precedence(): void
    {
        $config = $this->putBaseFile('cfg.json', json_encode(['app_name' => 'from-config', 'db_prefix' => 'cfg_', 'app_url' => 'https://cfg.test']));

        $run = $this->runCliCode(
            '$r = g7_cli_collect_input(g7_cli_parse_args(["--config='.addslashes($config).'", "--app-name=from-cli"])["options"], ["G7_INSTALL_APP_NAME" => "from-env", "G7_INSTALL_DB_PREFIX" => "env_"]);'
            .'echo "__RESULT__=".json_encode(["app_name" => $r["config"]["app_name"], "db_prefix" => $r["config"]["db_prefix"], "app_url" => $r["config"]["app_url"], "db_port" => $r["config"]["db_write_port"], "errors" => $r["errors"]]);'
        );

        $this->assertSame('from-cli', $run['result']['app_name'], '명령줄이 환경변수·설정 파일보다 앞선다');
        $this->assertSame('env_', $run['result']['db_prefix'], '환경변수가 설정 파일보다 앞선다');
        $this->assertSame('https://cfg.test', $run['result']['app_url'], '설정 파일이 웹 기본값보다 앞선다');
        $this->assertNotSame('', (string) $run['result']['db_port'], '아무도 주지 않은 값은 웹 기본값');
        $this->assertSame([], $run['result']['errors']);
    }

    /**
     * 알 수 없는 옵션은 종료 2 (상태 파일을 만들지 않는다).
     *
     * @effects cli_unknown_option_exits_invalid
     *
     * @return void 반환값 없음
     */
    public function test_unknown_option_exits_2_without_state(): void
    {
        $run = $this->runCli(['--output=json', '--no-such-option']);

        $this->assertSame(2, $run['exit']);
        $this->assertSame('cli_error_unknown_option', $this->eventsNamed($run['events'], 'error')[0]['message_key'] ?? null);
        $this->assertNull($this->readInstallerState());
    }

    /**
     * 비밀값 옵션(`--admin-password` 등)은 명령줄에서 거부하고 대신 쓸 환경변수를 안내한다 — 종료 2.
     *
     * @effects cli_rejects_secret_on_command_line
     *
     * @return void 반환값 없음
     */
    public function test_secret_option_on_command_line_exits_2(): void
    {
        $run = $this->runCli(['--output=json', '--admin-password=Secret-123!', '--db-password', 'Spaced-Secret-9']);

        $this->assertSame(2, $run['exit']);
        $errors = $this->eventsNamed($run['events'], 'error');
        $this->assertCount(2, $errors, '띄어 쓴 값은 「알 수 없는 인자」로 따로 보고하지 않는다');
        $this->assertSame('cli_error_secret_on_command_line', $errors[0]['message_key']);
        $this->assertStringContainsString('G7_INSTALL_ADMIN_PASSWORD', $errors[0]['message']);
        $this->assertStringNotContainsString('Secret-123!', $run['raw'], '거부 안내에도 값이 실리면 안 된다');
        $this->assertStringNotContainsString('Spaced-Secret-9', $run['raw'], '띄어 쓴 비밀값도 출력에 실리면 안 된다');
    }

    /**
     * `--config` JSON 의 비밀 키는 거부한다.
     *
     * @effects cli_config_file_rejects_secret_keys
     *
     * @return void 반환값 없음
     */
    public function test_config_file_secret_key_is_rejected(): void
    {
        $config = $this->putBaseFile('cfg.json', json_encode(['admin_password' => 'x', 'app_name' => 'ok', 'nope' => 1]));

        $run = $this->runCli(['--output=json', '--config='.$config]);

        $this->assertSame(2, $run['exit']);
        $keys = array_column($this->eventsNamed($run['events'], 'error'), 'message_key');
        $this->assertContains('cli_error_config_secret_key', $keys);
        $this->assertContains('cli_error_config_unknown_key', $keys);
    }

    /**
     * 비밀값 파일은 끝 개행 하나만 지운다 (값 안의 공백·두 번째 개행은 그대로).
     *
     * @effects cli_secret_file_strips_single_trailing_newline
     *
     * @return void 반환값 없음
     */
    public function test_secret_file_strips_one_trailing_newline(): void
    {
        $file = $this->putBaseFile('pw.txt', " pass word \n\n");

        $run = $this->runCliCode(
            '$r = g7_cli_collect_input(g7_cli_parse_args(["--admin-password-file='.addslashes($file).'"])["options"], []);'
            .'echo "__RESULT__=".json_encode($r["secrets"]["admin_password"] ?? null);'
        );

        $this->assertSame(" pass word \n", $run['result']);
    }

    /**
     * 값이 필요한 옵션에 값이 없거나 플래그에 엉뚱한 값이면 종료 2.
     *
     * @effects cli_option_value_shape_errors
     *
     * @return void 반환값 없음
     */
    public function test_value_shape_errors_exit_2(): void
    {
        $run = $this->runCli(['--output=json', '--no-interaction=maybe', '--app-url']);

        $this->assertSame(2, $run['exit']);
        $keys = array_column($this->eventsNamed($run['events'], 'error'), 'message_key');
        $this->assertSame(['cli_error_flag_value', 'cli_error_option_needs_value'], $keys);
    }

    /**
     * 설치 언어는 ko·en 만 — 지원하지 않는 값(옵션·환경변수)은 자동 판정으로 바꾸지 않고 종료 2(3.4).
     *
     * @effects cli_rejects_unsupported_locale
     *
     * @return void 반환값 없음
     */
    public function test_unsupported_locale_exits_2_without_state(): void
    {
        foreach ([[['--output=json', '--locale=ja'], []], [['--output=json'], ['G7_INSTALL_LOCALE' => 'xx']]] as [$args, $env]) {
            $run = $this->runCli($args, $env);

            $this->assertSame(2, $run['exit'], $run['raw']);
            $this->assertSame('cli_error_option_value', $this->eventsNamed($run['events'], 'error')[0]['message_key'] ?? null);
            $this->assertNull($this->readInstallerState());
        }

        // 지원 언어는 형식 검사를 통과한다 — 다음 관문(약관 미동의)에서 멈춘다.
        $ok = $this->runCli(['--output=json', '--locale=en', '--no-interaction']);
        $this->assertSame('cli_error_license_not_accepted', $this->eventsNamed($ok['events'], 'error')[0]['message_key'] ?? null, $ok['raw']);
    }

    /**
     * 사이트 기본 언어(ko·en)·자산 URL 방식(extension·extensionless)도 지원 밖 값을 기본값으로 바꾸지 않고 종료 2(3.4) —
     * 옵션·환경변수·설정 파일 세 입력 채널 모두.
     *
     * @effects cli_rejects_unsupported_enum_values
     *
     * @return void 반환값 없음
     */
    public function test_unsupported_site_language_and_asset_url_mode_exit_2_without_state(): void
    {
        $configPath = $this->installerTempBase.'/g7-enum-config.json';
        file_put_contents($configPath, json_encode(['site_language' => 'ja']));

        $cases = [
            [['--output=json', '--site-language=ja'], [], '--site-language'],
            [['--output=json'], ['G7_INSTALL_SITE_LANGUAGE' => 'xx'], '--site-language'],
            [['--output=json', '--config='.$configPath], [], '--site-language'],
            [['--output=json', '--asset-url-mode=extensionles'], [], '--asset-url-mode'],
            [['--output=json'], ['G7_INSTALL_ASSET_URL_MODE' => 'php'], '--asset-url-mode'],
        ];
        foreach ($cases as [$args, $env, $option]) {
            $run = $this->runCli($args, $env);

            $this->assertSame(2, $run['exit'], $run['raw']);
            $error = $this->eventsNamed($run['events'], 'error')[0] ?? [];
            $this->assertSame('cli_error_option_value', $error['message_key'] ?? null, $run['raw']);
            $this->assertStringContainsString($option, $run['raw']);
            $this->assertNull($this->readInstallerState());
        }

        // 지원 값은 형식 검사를 통과한다 — 다음 관문(약관 미동의)에서 멈춘다.
        $ok = $this->runCli(['--output=json', '--site-language=en', '--asset-url-mode=extensionless', '--no-interaction']);
        $this->assertSame('cli_error_license_not_accepted', $this->eventsNamed($ok['events'], 'error')[0]['message_key'] ?? null, $ok['raw']);
    }

    /**
     * 공개 설치 문서(INSTALL.md)의 옵션·환경변수 표가 옵션 명세의 환경변수를 빠짐없이 싣는다 — 모집단은 g7_cli_option_spec().
     *
     * @effects cli_install_doc_lists_every_environment_variable
     *
     * @return void 반환값 없음
     */
    public function test_install_doc_lists_every_environment_variable(): void
    {
        $run = $this->runCliCode('echo "__RESULT__=".json_encode(array_values(array_filter(array_column(g7_cli_option_spec(), "env"))));');
        $envNames = $run['result'];
        $this->assertGreaterThanOrEqual(40, count($envNames), '옵션 명세에서 환경변수를 뽑지 못했다');

        $doc = (string) file_get_contents(dirname(__DIR__, 3).'/INSTALL.md');
        $missing = array_values(array_filter($envNames, static fn (string $env): bool => ! str_contains($doc, '`'.$env.'`')));
        $this->assertSame([], $missing, 'INSTALL.md 옵션·환경변수 표에 없는 환경변수');
    }
}
