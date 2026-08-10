<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Step 3 저장 시점의 바이너리 경로 형태 강제 회귀 테스트 (KVE-2026-1677)
 *
 * `php_binary` / `composer_binary` 는 설치 워커에서 실제 명령의 실행 바이너리가 된다.
 * 실행 시점(task-runner)과 진단 시점(check-configuration)에도 같은 정책이 걸려 있지만,
 * 형태 위반 값이 애초에 `.env` / 설치 상태 파일에 기록되지 않도록 저장 시점에서도 막는다.
 * 세 지점 중 하나라도 빠지면 "저장은 되는데 실행만 폴백" 같은 어긋난 상태가 남는다.
 *
 * 본 테스트는 저장/리다이렉트 분기에 도달하지 않도록 DB 검증을 일부러 실패시켜
 * (`db_write_host` 공백) 상태 파일·세션을 건드리지 않는다. 그래서 `STATE_PATH` 를
 * 정의하지 않으며, 인접 Installer 테스트의 상수 선점과도 무관하다.
 *
 * @scenario vector=handleStep3Post, payload_class=argument_injection_dash_r, expected_outcome=return_invalid_message
 *
 * @effects step3_post_rejects_disallowed_binary_paths_before_save
 */
class InstallerStep3BinaryPathStoreGuardTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__.'/stubs/lang_stub.php';

        $projectRoot = dirname(__DIR__, 3);
        if (! defined('BASE_PATH')) {
            define('BASE_PATH', $projectRoot);
        }
        if (! defined('SUPPORTED_LANGUAGES')) {
            define('SUPPORTED_LANGUAGES', ['ko' => '한국어', 'en' => 'English']);
        }

        require_once $projectRoot.'/public/install/includes/functions.php';
        require_once $projectRoot.'/public/install/includes/binary-path-policy.php';
        require_once $projectRoot.'/public/install/includes/request-handler.php';
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    /**
     * Step 3 POST 를 실행하고 에러 배열을 돌려줍니다.
     *
     * @param  array<string, string>  $post  POST 본문 (DB 검증은 일부러 실패시킨다)
     * @return array<string, string> 에러 배열
     */
    private function runStep3(array $post): array
    {
        // 저장/리다이렉트 분기에 도달하지 않도록 DB 검증을 실패시킨다.
        $_POST = $post + [
            'db_write_host' => '',
            'db_write_database' => '',
            'db_write_username' => '',
            'admin_email' => 'not-an-email',
            'admin_language' => 'ko',
            'admin_password' => '',
            'admin_password_confirm' => '',
        ];

        $formData = [];
        $errors = [];
        handleStep3Post('ko', $formData, $errors);

        return $errors;
    }

    /**
     * PHP 자리에 코드 실행 플래그를 넣으면 저장 시점에 거부된다.
     *
     * @return void
     */
    public function test_step3_rejects_php_binary_with_code_execution_flag(): void
    {
        $errors = $this->runStep3(['php_binary' => '-r file_put_contents("g7","x");']);

        $this->assertArrayHasKey(
            'php_binary',
            $errors,
            'PHP 자리의 선두 하이픈 토큰이 저장 시점에 통과했다 — 형태 위반 값이 설치 상태에 기록된다'
        );
    }

    /**
     * Composer 자리에 셸 메타문자가 있으면 저장 시점에 거부된다.
     *
     * @return void
     */
    public function test_step3_rejects_composer_binary_with_shell_metachars(): void
    {
        $errors = $this->runStep3(['composer_binary' => 'sh -c "id > /tmp/g7"']);

        $this->assertArrayHasKey('composer_binary', $errors);
    }

    /**
     * Composer 자리의 공백 분리 입력에서 둘째 토큰이 임의 스크립트면 거부된다.
     *
     * `escapeshellarg` 는 "하나의 인자" 임만 보장하므로, 인터프리터 + 임의 스크립트
     * 조합은 그 스크립트를 그대로 실행한다. 이 자리를 저장 시점에서도 닫는다.
     *
     * @return void
     */
    public function test_step3_rejects_interpreter_plus_arbitrary_script_pair(): void
    {
        $errors = $this->runStep3(['composer_binary' => '/usr/bin/php /tmp/evil.php']);

        $this->assertArrayHasKey(
            'composer_binary',
            $errors,
            'Composer 자리의 둘째 토큰이 임의 스크립트인데 저장 시점에 통과했다'
        );
    }

    /**
     * 정상 입력은 저장 시점 검증을 통과한다 (비파괴 확인).
     *
     * @param  array<string, string>  $post  POST 본문
     * @param  string  $field  통과해야 하는 필드
     *
     * @scenario vector=handleStep3Post, payload_class=normal, expected_outcome=accept_normal
     *
     * @effects step3_post_accepts_legitimate_binary_paths
     *
     * @return void
     */
    #[DataProvider('legitimateBinaryInputProvider')]
    public function test_step3_accepts_legitimate_binary_paths(array $post, string $field): void
    {
        $errors = $this->runStep3($post);

        $this->assertArrayNotHasKey(
            $field,
            $errors,
            '정상 경로가 저장 시점에 거부됐다 — 설치가 불가능해진다: '.json_encode($post)
        );
    }

    /**
     * 저장 시점 검증을 통과해야 하는 정상 입력 목록.
     *
     * @return array<string, array{array<string, string>, string}>
     */
    public static function legitimateBinaryInputProvider(): array
    {
        return [
            'PHP 미입력' => [[], 'php_binary'],
            'PHP 기본값' => [['php_binary' => 'php'], 'php_binary'],
            'PHP 절대경로' => [['php_binary' => '/usr/bin/php8.2'], 'php_binary'],
            'PHP Windows 절대경로' => [['php_binary' => 'C:\\php\\php.exe'], 'php_binary'],
            'Composer 미입력' => [[], 'composer_binary'],
            'Composer 기본값' => [['composer_binary' => 'composer'], 'composer_binary'],
            'Composer 절대경로' => [['composer_binary' => '/usr/local/bin/composer'], 'composer_binary'],
            'Composer phar' => [['composer_binary' => '/opt/composer.phar'], 'composer_binary'],
            'PHP+phar 쌍' => [['composer_binary' => '/usr/bin/php /opt/composer.phar'], 'composer_binary'],
        ];
    }
}
