<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 태스크 키의 표시 이름 변환 계약 (§12.0 W3 — 진행 기록의 독자는 운영자다).
 *
 * 확장 태스크의 키는 §16.9.2 #16 이후 "종류:대상" 합성 키다. 진행·롤백·중단 기록이 그 키를
 * 그대로 내보내면 운영자 화면에 `module_install:sirsoft-board` 같은 개발자 표기가 남는다.
 * 변환은 `installerTaskKeyDisplayName()` 단일 지점이 담당하며, 이 클래스는 그 함수의 계약과
 * **로그 호출부가 실제로 그 함수를 경유하는지**를 함께 고정한다 — 함수만 있고 호출부가
 * 우회하면 결함은 그대로 남는다(라운드5 #10 이 7곳을 옮겼는데 중단 API 한 줄이 빠졌다).
 *
 * 커버 축: task_key_shape bare·composite·unknown·empty_target
 *
 * @effects installer_task_key_renders_as_operator_facing_name, installer_abort_log_routes_task_key_through_display_name
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerTaskDisplayNameTest extends TestCase
{
    use CleansTemporaryBasePath;
    use ScansPhpSourceFunctions;

    private static string $base = '';

    private static string $skipReason = '';

    private static bool $booted = false;

    private static function bootInstallerRuntime(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();

        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                self::$skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — 실 상태 파일 파괴 방지를 위해 skip.';

                return;
            }
            self::$base = (string) BASE_PATH;
        } else {
            self::$base = sys_get_temp_dir().'/g7-installer-display-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$base);
        }

        foreach (['storage/logs', 'storage/installer'] as $dir) {
            if (! is_dir(self::$base.'/'.$dir)) {
                @mkdir(self::$base.'/'.$dir, 0755, true);
            }
        }

        $root = dirname(__DIR__, 3);
        require_once $root.'/public/install/includes/config.php';
        require_once $root.'/public/install/includes/functions.php';
        require_once $root.'/public/install/includes/installer-state.php';

        // 실제 번역 파일을 적재한다 — 스텁을 쓰면 "키를 그대로 돌려주는" 폴백 경로가
        // 정상 변환과 구분되지 않아 단언이 무의미해진다.
        $GLOBALS['translations'] = loadTranslations('ko');
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::bootInstallerRuntime();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }
    }

    public function test_bare_task_key_renders_as_translated_name(): void
    {
        $name = installerTaskKeyDisplayName('db_migrate');

        $this->assertNotSame('db_migrate', $name, '번역된 이름이어야 한다 (원 키 그대로면 미변환)');
        $this->assertStringNotContainsString('task_', $name, '번역 키 원문이 새면 안 된다');
    }

    public function test_composite_task_key_renders_name_with_target(): void
    {
        $name = installerTaskKeyDisplayName('module_install:sirsoft-board');

        $this->assertStringNotContainsString('module_install:', $name, '합성 키가 그대로 노출되면 안 된다');
        $this->assertStringContainsString('(sirsoft-board)', $name, '대상은 괄호로 덧붙인다');
    }

    public function test_unknown_task_key_falls_back_to_the_raw_id(): void
    {
        // 번역이 없는 키는 원 id 로 떨어진다 — 진단 가능성을 위해 "task_" 접두사는 붙이지 않는다
        $this->assertSame('no_such_task', installerTaskKeyDisplayName('no_such_task'));
    }

    public function test_composite_key_with_empty_target_omits_the_parentheses(): void
    {
        $name = installerTaskKeyDisplayName('db_migrate:');

        $this->assertStringNotContainsString('(', $name);
        $this->assertStringNotContainsString(':', $name);
    }

    /**
     * 중단 API 의 진행 기록이 current_task 를 표시 이름으로 바꿔 넣는지 — 소스 대조.
     *
     * 이 로그는 폼이 아니라 코드 경로라 단위 실행으로 재현하기 어렵다. 우회(원시 키 직접
     * 대입)가 되살아나는 것만 막으면 충분하므로 호출 형태를 고정한다.
     */
    public function test_abort_log_routes_current_task_through_the_display_name_helper(): void
    {
        // 주석을 지운 뒤 대조한다 — 원문에는 주석 처리된 배선도 글자로 남아, 원문 대조는 「그 배선이 살아 있다」를 증명하지 못한다 (12차 메타 검사 ①).
        $src = $this->stripComments((string) file_get_contents(dirname(__DIR__, 3).'/public/install/api/state-management.php'));

        $this->assertMatchesRegularExpression(
            "/'abort_api_current_task',\s*\[\s*'task'\s*=>\s*isset\(\\\$state\['current_task'\]\)\s*\?\s*installerTaskKeyDisplayName\(/",
            $src,
            '중단 API 의 진행 기록은 태스크 키를 표시 이름으로 바꿔 넣어야 한다 (§12.0 W3)'
        );
    }
}
