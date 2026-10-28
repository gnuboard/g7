<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Installer\Concerns\RunsInstallerCli;

/**
 * `php g7-install --reinstall` 초기화 — 옵션별로 지울 것만 지우고 보존 대상은 남긴다.
 *
 * 항상: 설치 표식·state·runtime·부트스트랩 캐시·정적 게시물·.env 의 INSTALLER_COMPLETED 줄(나머지 줄 유지).
 * 옵션: vendor(.gitkeep 보존)·활성 확장(_bundled·_pending 보존, 언어팩 포함)·설정·업로드(설정 하위 보존).
 * 설정을 남기면 기존 APP_KEY 를 이어 쓴다. 하나라도 못 지우면 설치 표식을 남겨 「설치됨」으로 둔다.
 */
class InstallerReinstallTest extends TestCase
{
    use RunsInstallerCli;

    /** 기존 APP_KEY */
    private const APP_KEY = 'base64:'.'QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVowMTIzNDU=';

    /**
     * 설치된 사이트 모양을 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->makeInstallerTempBase();

        $this->putBaseFile('.env', "APP_NAME=G7\nAPP_KEY=".self::APP_KEY."\nINSTALLER_COMPLETED=true\nAPP_URL=https://g7.test\n");
        $this->markInstalled();
        foreach ([
            'modules/_bundled/sirsoft-board/module.json', 'modules/_pending/sirsoft-x/module.json', 'modules/sirsoft-board/module.json',
            'plugins/sirsoft-totp/plugin.json', 'templates/sirsoft-basic/template.json',
            'lang-packs/_bundled/g7-core-ja/language-pack.json', 'lang-packs/g7-core-ja/language-pack.json',
            'vendor/.gitkeep', 'vendor/acme/pkg/src/A.php',
            'storage/app/settings/general.json', 'storage/app/modules/sirsoft-board/settings/basic_defaults.json',
            'storage/app/modules/sirsoft-board/attachments/a.png', 'storage/app/attachments/2026/f.png', 'storage/app/public/p.png',
            'bootstrap/cache/config.php', 'bootstrap/cache/packages.php', 'public/build/ext/x.js',
            'storage/installer/runtime.php',
            'storage/framework/cache/data/.gitignore', 'storage/framework/cache/data/5a/48/5a488cb6262b9f75e4a52f1b00ec6c00e04d5d9f',
        ] as $path) {
            $this->putBaseFile($path);
        }
        $this->writeInstallerState(['installation_status' => 'completed', 'generation' => 9]);
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
     * 전부 지우는 조합 — 보존 대상(_bundled·_pending·.gitkeep·설정 디렉터리 자체)만 남는다. 새 APP_KEY.
     *
     * @effects reinstall_all_options_delete_exact_targets
     *
     * @return void 반환값 없음
     */
    public function test_all_options_delete_exact_targets_and_keep_preserved(): void
    {
        $run = $this->runCliCode('echo "__RESULT__=".json_encode(installer_cli_reinstall(["delete_vendor" => true, "delete_extensions" => true, "delete_settings" => true, "delete_uploads" => true]));');

        $this->assertTrue($run['result']['ok'], $run['raw']);
        $this->assertNull($run['result']['app_key'], '설정을 지우면 새 키로 설치한다');

        $b = $this->installerTempBase;
        foreach (['modules/sirsoft-board', 'plugins/sirsoft-totp', 'templates/sirsoft-basic', 'lang-packs/g7-core-ja', 'vendor/acme',
            'storage/app/settings/general.json', 'storage/app/modules/sirsoft-board/settings/basic_defaults.json',
            'storage/app/modules/sirsoft-board/attachments/a.png', 'storage/app/attachments/2026', 'storage/app/public/p.png',
            'bootstrap/cache/config.php', 'bootstrap/cache/packages.php', 'public/build/ext', 'storage/app/g7_installed',
            'storage/installer-state.json', 'storage/installer/runtime.php'] as $gone) {
            $this->assertFileDoesNotExist($b.'/'.$gone, $gone.' 가 남았다');
        }
        foreach (['modules/_bundled/sirsoft-board/module.json', 'modules/_pending/sirsoft-x/module.json',
            'lang-packs/_bundled/g7-core-ja/language-pack.json', 'vendor/.gitkeep'] as $kept) {
            $this->assertFileExists($b.'/'.$kept, $kept.' 는 보존 대상');
        }
        $this->assertDirectoryExists($b.'/storage/app/settings');

        $env = (string) file_get_contents($b.'/.env');
        $this->assertStringNotContainsString('INSTALLER_COMPLETED', $env);
        $this->assertStringContainsString('APP_URL=https://g7.test', $env, '나머지 줄은 그대로');
    }

    /**
     * 옵션 없이 — 확장·설정·업로드·vendor 는 남고 기존 APP_KEY 를 이어 쓴다.
     *
     * @effects reinstall_without_options_keeps_data_and_app_key
     *
     * @return void 반환값 없음
     */
    public function test_without_options_keeps_data_and_returns_existing_app_key(): void
    {
        $run = $this->runCliCode('echo "__RESULT__=".json_encode(installer_cli_reinstall([]));');

        $this->assertTrue($run['result']['ok']);
        $this->assertSame(self::APP_KEY, $run['result']['app_key']);
        $b = $this->installerTempBase;
        foreach (['modules/sirsoft-board/module.json', 'vendor/acme/pkg/src/A.php', 'storage/app/settings/general.json', 'storage/app/public/p.png'] as $kept) {
            $this->assertFileExists($b.'/'.$kept);
        }
        $this->assertFileDoesNotExist($b.'/storage/app/g7_installed');
    }

    /**
     * 옵션 없이도 애플리케이션 파일 캐시는 비운다 — 남으면 옛 사이트의 「활성 확장 목록」 캐시가 빈 DB 위에서
     * 살아 있어, 이번 설치에 넣지 않은 확장의 라우트를 오토로드 없이 실어 artisan 부팅이 죽는다(롤백도 실패).
     * 저장소 골격 `data/.gitignore` 는 남긴다.
     *
     * @effects reinstall_clears_application_file_cache
     *
     * @return void 반환값 없음
     */
    public function test_without_options_still_clears_application_file_cache(): void
    {
        $run = $this->runCliCode('echo "__RESULT__=".json_encode(installer_cli_reinstall([]));');

        $this->assertTrue($run['result']['ok'], $run['raw']);
        $b = $this->installerTempBase;
        $this->assertFileDoesNotExist($b.'/storage/framework/cache/data/5a', '옛 사이트의 캐시 항목이 남았다');
        $this->assertFileExists($b.'/storage/framework/cache/data/.gitignore', '저장소 골격은 보존');
        $this->assertContains('app_cache', array_column($run['result']['rows'], 'group'), '삭제 결과 표에 그룹이 보인다');
    }

    /**
     * 업로드만 지우면 확장 저장소의 settings 하위는 남는다.
     *
     * @effects reinstall_uploads_keep_extension_settings
     *
     * @return void 반환값 없음
     */
    public function test_uploads_option_keeps_extension_settings(): void
    {
        $run = $this->runCliCode('echo "__RESULT__=".json_encode(installer_cli_reinstall(["delete_uploads" => true]));');

        $this->assertTrue($run['result']['ok']);
        $this->assertFileExists($this->installerTempBase.'/storage/app/modules/sirsoft-board/settings/basic_defaults.json');
        $this->assertFileDoesNotExist($this->installerTempBase.'/storage/app/modules/sirsoft-board/attachments/a.png');
    }

    /**
     * 미설치 사이트·--drop-existing-tables 없음은 종료 2 (아무것도 지우지 않는다).
     *
     * @effects reinstall_guards_exit_2
     *
     * @return void 반환값 없음
     */
    public function test_guards_exit_2(): void
    {
        $run = $this->runCli(['--output=json', '--reinstall', '--no-interaction']);
        $this->assertSame(2, $run['exit']);
        $this->assertSame('cli_error_reinstall_requires_drop', $this->eventsNamed($run['events'], 'error')[0]['message_key'] ?? null);
        $this->assertFileExists($this->installerTempBase.'/storage/app/g7_installed');

        unlink($this->installerTempBase.'/storage/app/g7_installed');
        file_put_contents($this->installerTempBase.'/.env', "APP_KEY=\n");
        $run = $this->runCli(['--output=json', '--reinstall', '--drop-existing-tables', '--no-interaction']);
        $this->assertSame(2, $run['exit']);
        $this->assertSame('cli_error_reinstall_not_installed', $this->eventsNamed($run['events'], 'error')[0]['message_key'] ?? null);
    }

    /**
     * 하나라도 못 지우면 실패이고 설치 표식은 남는다 — 반쯤 지운 사이트가 「미설치」로 보이지 않게.
     *
     * Windows 는 읽기 전용 파일을 지우지 못한다는 점으로 실패를 만든다(PHP 는 열린 파일도 지울 수 있다).
     *
     * @effects reinstall_failure_keeps_installed_marker
     *
     * @return void 반환값 없음
     */
    public function test_failure_keeps_installed_marker(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->markTestSkipped('읽기 전용 파일 삭제 실패는 Windows 동작 — Linux 는 WSL 실측(R5)으로 덮는다');
        }

        $readonly = $this->installerTempBase.'/storage/app/public/p.png';
        chmod($readonly, 0444);
        try {
            $run = $this->runCliCode('echo "__RESULT__=".json_encode(installer_cli_reinstall(["delete_uploads" => true]));');

            // 명령 전체로도 — 초기화가 실패하면 설치로 넘어가지 않고 종료 1(5.4). [3] 입력·DB 검사는 통과로 주입한다.
            $cli = $this->runCli(['--output=json', '--no-interaction', '--accept-license', '--reinstall', '--drop-existing-tables', '--delete-uploads'], [], <<<'PHP'
                function installer_collect_requirements(): array { return ['blocking' => false, 'all_required_passed' => true]; }
                function g7_cli_print_requirements($out, $req, $webUser): void {}
                function g7_cli_collect_configuration($out, array $input, string $uiLang, bool $interactive) { return ['config' => ['app_name' => 'x'], 'action' => 'drop_tables', 'drop' => null]; }
                function g7_cli_select_extensions($out, array $input, string $uiLang, bool $interactive) { echo "__INSTALL_CONTINUED__\n"; return 2; }
                PHP);
        } finally {
            chmod($readonly, 0666);
        }

        $this->assertSame(1, $cli['exit'], $cli['raw']);
        $this->assertStringNotContainsString('__INSTALL_CONTINUED__', $cli['raw'], '초기화 실패 뒤 설치로 넘어갔다');

        $this->assertFalse($run['result']['ok'], $run['raw']);
        $this->assertContains('uploads', array_column(array_filter($run['result']['rows'], static fn ($r) => $r['failed'] !== []), 'group'));
        $this->assertFileExists($this->installerTempBase.'/storage/app/g7_installed');
        $this->assertStringContainsString('INSTALLER_COMPLETED', (string) file_get_contents($this->installerTempBase.'/.env'));
    }

    /**
     * 입력 오류(약관 미동의 등)로 설치가 멈추면 아무것도 지우지 않는다 — 초기화는 환경설정·DB 검사를 통과한 뒤.
     *
     * @effects reinstall_deletes_nothing_when_input_invalid
     *
     * @return void 반환값 없음
     */
    public function test_invalid_input_deletes_nothing(): void
    {
        $run = $this->runCli(['--output=json', '--reinstall', '--drop-existing-tables', '--delete-extensions', '--no-interaction'], [], <<<'PHP'
            function installer_collect_requirements(): array { return ['blocking' => false, 'all_required_passed' => true]; }
            function g7_cli_print_requirements($out, $req, $webUser): void {}
            PHP);

        $this->assertSame(2, $run['exit']);
        $this->assertSame('cli_error_license_not_accepted', $this->eventsNamed($run['events'], 'error')[0]['message_key'] ?? null);
        $this->assertFileExists($this->installerTempBase.'/storage/app/g7_installed');
        $this->assertFileExists($this->installerTempBase.'/modules/sirsoft-board/module.json');
        $this->assertStringContainsString('INSTALLER_COMPLETED', (string) file_get_contents($this->installerTempBase.'/.env'));
    }
}
