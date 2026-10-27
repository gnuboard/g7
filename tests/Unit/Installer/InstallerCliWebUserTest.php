<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Installer\Concerns\RunsInstallerCli;

/**
 * root 실행과 `--web-user` — 웹서버 계정으로 넘길 대상 산출과 실행 계정 판정.
 *
 * 실제 chown 은 WSL 실측(L1·L2)으로 본다. 여기서는 대상 목록(쓰기 필요 디렉터리·.env·runtime)·수동 명령·
 * 플랫폼별 판정(Windows 는 무시하고 경고, root 가 아닌데 --web-user 면 거부)을 잠근다.
 */
class InstallerCliWebUserTest extends TestCase
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
     * 대상 = 존재하는 쓰기 필요 디렉터리 + .env + runtime.php (없는 것은 빼고).
     *
     * @effects cli_web_user_targets_are_writable_dirs_env_runtime
     *
     * @return void 반환값 없음
     */
    public function test_targets_list_existing_writable_dirs_env_and_runtime(): void
    {
        $this->putBaseFile('.env', "APP_KEY=\n");
        $this->writeInstallerRuntimeFile(['app' => ['key' => 'x']]);

        $run = $this->runCliCode(
            '$t = installer_cli_web_user_targets(); $all = array_keys(REQUIRED_DIRECTORIES);'
            .'echo "__RESULT__=".json_encode(["dirs" => array_map(fn ($p) => substr($p, strlen(BASE_PATH) + 1), $t["directories"]), "env" => $t["env"] !== null, "runtime" => $t["runtime"] !== null, "required" => $all]);'
        );

        $this->assertTrue($run['result']['env']);
        $this->assertTrue($run['result']['runtime']);
        $this->assertNotEmpty($run['result']['dirs']);
        foreach ($run['result']['dirs'] as $dir) {
            $this->assertContains($dir, $run['result']['required'], '쓰기 필요 디렉터리 목록 밖은 넘기지 않는다');
            $this->assertDirectoryExists($this->installerTempBase.'/'.$dir);
        }
        $this->assertContains('storage', $run['result']['dirs']);
    }

    /**
     * chown 실패 시 안내할 수동 명령 — 계정:그룹, 디렉터리, .env 는 그룹만·640.
     *
     * @effects cli_web_user_manual_command_shape
     *
     * @return void 반환값 없음
     */
    public function test_manual_command_shape(): void
    {
        $run = $this->runCliCode('echo "__RESULT__=".json_encode(installer_cli_web_user_manual_command(["user" => "www-data", "group" => "www-data", "uid" => 33, "gid" => 33]));');

        $this->assertStringStartsWith('chown -R www-data:www-data ', $run['result']);
        $this->assertStringContainsString('chgrp www-data', $run['result']);
        $this->assertStringContainsString('chmod 640', $run['result']);
    }

    /**
     * 완료 요약의 .env 권한 안내는 웹 완료 화면과 같다 — 두 선택지(600 / 웹 그룹 640)와 주의 문구, 그룹은 --web-user 의 그룹.
     *
     * 종전에는 「다음 두 명령 중」이라면서 `chmod 600` 하나만 냈다. --web-user 설치는 .env 를 root:웹그룹 640 으로
     * 두므로 그 안내대로 600 을 적용하면 웹서버가 .env 를 읽지 못한다.
     *
     * @effects cli_env_hardening_hint_matches_web_completion
     *
     * @return void 반환값 없음
     */
    public function test_env_hardening_hint_lists_both_options_with_web_group(): void
    {
        $run = $this->runCliCode('echo "__RESULT__=".json_encode(g7_cli_env_hardening_lines(0640, "root", "/srv/g7/.env", "nginx"), JSON_UNESCAPED_UNICODE);');
        $lines = implode("\n", (array) $run['result']);
        $ko = require dirname(__DIR__, 3).'/public/install/lang/ko.php';

        foreach (['env_hardening_hint_title', 'env_hardening_hint_option_strict', 'env_hardening_hint_option_group', 'env_hardening_hint_note'] as $key) {
            $this->assertStringContainsString($ko[$key], $lines, $key);
        }
        $this->assertStringContainsString(sprintf($ko['env_hardening_hint_current_status'], '640', 'root'), $lines);
        $this->assertStringContainsString('chmod 600 /srv/g7/.env', $lines);
        $this->assertStringContainsString('sudo chgrp nginx /srv/g7/.env && sudo chmod 640 /srv/g7/.env', $lines);
    }

    /**
     * 없는 계정은 해석하지 않는다 (null → 종료 2).
     *
     * @effects cli_web_user_unknown_account_is_null
     *
     * @return void 반환값 없음
     */
    public function test_unknown_account_resolves_to_null(): void
    {
        $run = $this->runCliCode('echo "__RESULT__=".json_encode(g7_cli_resolve_web_user("g7-no-such-user-".bin2hex(random_bytes(3))));');

        $this->assertNull($run['result']);
    }

    /**
     * 플랫폼별 판정 — Windows 는 --web-user 를 무시하고 경고, 그 밖에서 root 가 아니면 --web-user 를 거부(종료 2).
     *
     * @effects cli_web_user_platform_rules
     *
     * @return void 반환값 없음
     */
    public function test_platform_rules_for_web_user_option(): void
    {
        $run = $this->runCli(['--output=json', '--no-interaction', '--web-user=www-data'], [], 'function g7_cli_is_root(): bool { return false; }');

        if (DIRECTORY_SEPARATOR === '\\') {
            $this->assertNotEmpty(array_filter($this->eventsNamed($run['events'], 'warning'), static fn ($w) => str_contains($w['message'], 'Windows')));
            $this->assertNotSame(7, $run['exit']);

            return;
        }

        $this->assertSame(2, $run['exit']);
        $this->assertSame('cli_error_web_user_requires_root', $this->eventsNamed($run['events'], 'error')[0]['message_key'] ?? null);
    }
}
