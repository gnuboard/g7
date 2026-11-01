<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Installer\Concerns\RunsInstallerSubprocess;

/**
 * 설치 작업 중 PHP Error(TypeError 등)가 나도 상태가 실패로 기록되는지 검증한다.
 *
 * 회귀: `runInstallationTasks()` 가 `catch (Exception $e)` 만 두어 `Error` 계열은 잡지 못했다.
 * 워커가 fatal 로 죽고 state 는 running 으로 고착돼, 웹은 90초 뒤 「멈춤」만 보이고 재시도할 수 없었다.
 */
class InstallerThrowableCatchTest extends TestCase
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
     * 첫 작업이 TypeError 를 던지면 state 가 failed·error_unexpected_exception 이 된다.
     *
     * 작업 함수는 function_exists 가드로 선언되므로, task-runner 를 불러오기 전에 같은 이름의
     * 함수를 먼저 정의해 오류를 주입한다.
     *
     * @effects task_error_marks_state_failed
     *
     * @return void 반환값 없음
     */
    public function test_type_error_in_task_marks_installation_failed(): void
    {
        $this->writeInstallerState([
            'installation_status' => 'running',
            'completed_tasks' => [],
            'current_task' => null,
            'config' => [],
            'selected_extensions' => ['admin_templates' => [], 'user_templates' => [], 'modules' => [], 'plugins' => [], 'language_packs' => []],
        ]);

        $result = $this->runInstallerScript(<<<'PHP'
            function checkComposerSSE(): array { updateCurrentTask('composer_check'); return strlen([]); }
            require_once $projectRoot.'/public/install/includes/task-runner.php';
            $GLOBALS['translations'] = loadTranslations('ko');
            setProgressEmitter(new NullEmitter());
            runInstallationTasks();
            $s = getInstallationState();
            echo json_encode(['status' => $s['installation_status'], 'key' => $s['error_message_key'] ?? null, 'failed' => $s['failed_task'] ?? null]);
            PHP, ['session' => []]);

        $this->assertSame(
            ['status' => 'failed', 'key' => 'error_unexpected_exception', 'failed' => 'composer_check'],
            $result['body'],
            $result['raw']
        );
    }
}
