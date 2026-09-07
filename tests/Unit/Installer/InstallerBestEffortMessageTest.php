<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 설치기 best-effort 작업 실패 문구 테스트 (#651 C3).
 *
 * 종전에는 작업 종류와 무관하게 언어팩 전용 문구를 재사용해, 대상이 없는 `static_publish`·
 * `config_cache` 실패가 "언어팩 일부 설치에 실패했습니다:  (계속 진행)" 으로 찍혔다.
 *
 * `task-runner.php` 는 진입과 함께 작업을 실행하는 파일이라 테스트에서 include 할 수 없다 —
 * 조립 함수의 존재·호출과 문구 키의 형태를 소스로 잠근다.
 */
class InstallerBestEffortMessageTest extends TestCase
{
    /**
     * @effects installer_best_effort_message_names_the_task
     */
    #[Test]
    public function best_effort_분기는_작업_라벨_기반_문구_조립_함수를_쓴다(): void
    {
        $source = (string) file_get_contents(base_path('public/install/includes/task-runner.php'));

        $this->assertStringContainsString('function bestEffortFailureMessage(string $taskId, string $target): string', $source);
        $this->assertStringContainsString('lang("task_{$taskId}")', $source);
        $this->assertStringContainsString("lang('warning_best_effort_task_failed', ['task' => \$display])", $source);

        // 실패 분기가 새 조립 함수를 부른다 — 언어팩 전용 키를 직접 부르는 옛 줄은 남아 있지 않다
        $this->assertStringContainsString('$warnMsg = bestEffortFailureMessage((string) $taskId, (string) $target);', $source);
        $this->assertStringNotContainsString("lang('warning_language_pack_install_partial', ['identifier' => (string) \$target])", $source);
    }

    /**
     * 문구 키가 `:task` 치환 자리를 갖고, best-effort 작업 3종의 라벨 키가 양 로케일에 있다.
     *
     * @effects installer_best_effort_message_names_the_task
     */
    #[Test]
    public function 문구_키와_작업_라벨_키가_양_로케일에_있다(): void
    {
        foreach (['ko', 'en'] as $locale) {
            $messages = require base_path("public/install/lang/{$locale}.php");

            $this->assertStringContainsString(':task', $messages['warning_best_effort_task_failed'] ?? '', "{$locale}: :task 치환 자리가 없다");

            foreach (['task_static_publish', 'task_config_cache', 'task_language_pack_install'] as $key) {
                $this->assertNotEmpty($messages[$key] ?? null, "{$locale}: {$key} 라벨이 없다");
            }
        }
    }
}
