<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Installer\Concerns\RunsInstallerSubprocess;

/**
 * vendor 방식 「자동」은 Composer 를 실행할 수 없으면 번들 vendor 로 넘어가야 한다.
 *
 * 회귀: 「패키지 관리자 확인」은 `composer --version` 을 실제로 실행해 「번들로 자동 폴백 예정」이라고 알리지만,
 * 「필수 패키지 설치」는 `canExecuteComposerForInstall()`(proc_open 가용성만 봄)을 믿고 `composer install` 을
 * 실행해 Composer 없는 서버의 설치가 「Composer 의존성 설치에 실패했습니다」로 멈췄다(웹·터미널 공통).
 */
class InstallerAutoVendorFallbackTest extends TestCase
{
    use RunsInstallerSubprocess;

    /**
     * 임시 루트를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->makeInstallerTempBase();
    }

    /**
     * 임시 루트 제거.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->removeInstallerTempBase();

        parent::tearDown();
    }

    /**
     * Composer 를 실행할 수 없고 번들 zip 이 있으면 「자동」의 확인·설치 두 단계가 모두 번들로 간다.
     *
     * Composer 부재는 `getComposerCommand()` 를 없는 파일을 실행하는(종료 1) 명령으로 먼저 정의해 주입한다(작업 함수는
     * function_exists 가드로 선언된다). 번들 zip 은 내용 없는 파일이라 번들 추출은 무결성 검사에서 멈추지만,
     * 판정 대상은 「어느 경로로 갔는가」다.
     *
     * @effects installer_auto_vendor_falls_back_to_bundle_without_composer
     *
     * @return void 반환값 없음
     */
    public function test_auto_mode_uses_bundle_when_composer_cannot_run(): void
    {
        $this->writeInstallerState([
            'installation_status' => 'running',
            'completed_tasks' => [],
            'current_task' => null,
            'config' => ['vendor_mode' => 'auto', 'composer_binary' => '', 'php_binary' => 'php'],
            'selected_extensions' => ['admin_templates' => [], 'user_templates' => [], 'modules' => [], 'plugins' => [], 'language_packs' => []],
        ]);

        $result = $this->runInstallerScript(<<<'PHP'
            function getComposerCommand(): string { return escapeshellarg(PHP_BINARY).' '.escapeshellarg(BASE_PATH.'/no-such-composer.phar'); }
            require_once $projectRoot.'/public/install/includes/task-runner.php';
            file_put_contents(BASE_PATH.'/vendor-bundle.zip', '');
            $GLOBALS['translations'] = loadTranslations('ko');
            $events = new class implements ProgressEmitter {
                public array $starts = [];
                public function emit(string $event, array $data): void { if ($event === 'task_start') { $this->starts[] = $data['name'] ?? ''; } }
                public function isConnectionAborted(): bool { return false; }
            };
            setProgressEmitter($events);
            $check = checkComposerSSE();
            $install = installComposerDependenciesSSE();
            echo json_encode([
                'check' => $check['success'],
                'install_message' => (string) ($install['message'] ?? ''),
                'starts' => $events->starts,
            ], JSON_UNESCAPED_UNICODE);
            PHP, ['session' => []]);

        $body = $result['body'];
        $this->assertIsArray($body, $result['raw']);
        $this->assertTrue($body['check'], '확인 단계는 번들 폴백 예정으로 통과한다');
        $this->assertContains('Vendor 번들 추출', $body['starts'], '설치 단계가 번들 추출로 가야 한다: '.$result['raw']);
        $this->assertStringContainsString('vendor-bundle.zip', $body['install_message'], 'composer install 이 아니라 번들 검사에서 멈춰야 한다');
    }

    /**
     * Composer 를 실행할 수 있으면 「자동」은 지금처럼 Composer 경로를 탄다(번들로 가지 않는다).
     *
     * @effects installer_auto_vendor_keeps_composer_when_runnable
     *
     * @return void 반환값 없음
     */
    public function test_auto_mode_keeps_composer_when_runnable(): void
    {
        $this->writeInstallerState([
            'installation_status' => 'running',
            'completed_tasks' => [],
            'current_task' => null,
            'config' => ['vendor_mode' => 'auto', 'composer_binary' => '', 'php_binary' => 'php'],
            'selected_extensions' => ['admin_templates' => [], 'user_templates' => [], 'modules' => [], 'plugins' => [], 'language_packs' => []],
        ]);

        $result = $this->runInstallerScript(<<<'PHP'
            function getComposerCommand(): string { return escapeshellarg(PHP_BINARY).' '.escapeshellarg(BASE_PATH.'/fake-composer.php'); }
            require_once $projectRoot.'/public/install/includes/task-runner.php';
            file_put_contents(BASE_PATH.'/fake-composer.php', '<?php exit(0);');
            file_put_contents(BASE_PATH.'/vendor-bundle.zip', '');
            $GLOBALS['translations'] = loadTranslations('ko');
            $events = new class implements ProgressEmitter {
                public array $starts = [];
                public function emit(string $event, array $data): void { if ($event === 'task_start') { $this->starts[] = $data['name'] ?? ''; } }
                public function isConnectionAborted(): bool { return false; }
            };
            setProgressEmitter($events);
            installComposerDependenciesSSE();
            echo json_encode(['starts' => $events->starts], JSON_UNESCAPED_UNICODE);
            PHP, ['session' => []]);

        $body = $result['body'];
        $this->assertIsArray($body, $result['raw']);
        $this->assertNotContains('Vendor 번들 추출', $body['starts'], $result['raw']);
        $this->assertContains(self::composerTaskName(), $body['starts'], $result['raw']);
    }

    /**
     * 「필수 패키지 설치」 작업 이름(ko).
     *
     * @return string 작업 이름
     */
    public static function composerTaskName(): string
    {
        $lang = require dirname(__DIR__, 3).'/public/install/lang/ko.php';

        return (string) $lang['task_composer_install'];
    }
}
