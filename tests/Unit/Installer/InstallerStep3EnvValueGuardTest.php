<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Step 3 저장 시점의 .env 값 사전 거부 회귀 테스트 (KVE-2026-2042).
 *
 * `app_name` / `app_url` / `core_update_github_url` 은 서버측 검증 없이 `$_POST` 에서
 * 그대로 `.env` 로 흘렀다. `.env` 는 줄 단위 형식이라 값에 개행을 섞으면 그 뒤가 새로운
 * 환경변수 줄로 해석되어, 설치 창이 열려 있는 동안 임의 환경변수를 주입할 수 있었다.
 *
 * 직렬화기(serializeEnvValue)가 최종 관문이지만 그 단계의 실패는 설치 진행 중 예외로
 * 드러나 어느 입력이 문제인지 알기 어렵다. 그래서 저장 시점에도 필드별로 거부한다.
 *
 * 저장/리다이렉트 분기에 도달하지 않도록 DB 검증을 일부러 실패시켜 상태 파일·세션을
 * 건드리지 않는다.
 *
 * @scenario vector=handleStep3Post, payload_class=env_line_injection, expected_outcome=return_invalid_message
 *
 * @effects step3_post_rejects_env_line_breaks_before_save
 */
class InstallerStep3EnvValueGuardTest extends TestCase
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
     * 개행이 섞인 .env 대상 필드는 저장 시점에 거부된다.
     *
     * @param  string  $field  필드명
     * @param  string  $payload  주입 시도 값
     */
    #[Test]
    #[DataProvider('lineBreakFieldProvider')]
    public function it_rejects_line_breaks_in_env_bound_fields(string $field, string $payload): void
    {
        $errors = $this->runStep3([$field => $payload]);

        $this->assertArrayHasKey(
            $field,
            $errors,
            "{$field} 에 개행이 섞였는데 저장 시점에 통과했다 — .env 에 임의 변수 줄이 주입된다"
        );
    }

    /**
     * 개행 주입 벡터 (필드 × CR/LF).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function lineBreakFieldProvider(): array
    {
        return [
            'app_name + LF' => ['app_name', "그누보드7\nAPP_DEBUG=true"],
            'app_name + CR' => ['app_name', "그누보드7\rAPP_DEBUG=true"],
            'app_url + LF' => ['app_url', "https://example.com\nAPP_DEBUG=true"],
            'app_url + CRLF' => ['app_url', "https://example.com\r\nAPP_DEBUG=true"],
            'github_url + LF' => ['core_update_github_url', "https://github.com/gnuboard/g7\nAPP_DEBUG=true"],
            'app_name + NUL' => ['app_name', "그누보드7\0APP_DEBUG=true"],
        ];
    }

    /**
     * 스킴 없는 URL 은 거부된다.
     */
    #[Test]
    public function it_rejects_url_fields_without_an_http_scheme(): void
    {
        $errors = $this->runStep3(['app_url' => 'javascript:alert(1)']);

        $this->assertArrayHasKey('app_url', $errors);
    }

    /**
     * 정상 값은 이 게이트에 걸리지 않는다 (회귀 방지).
     */
    #[Test]
    public function it_accepts_normal_values(): void
    {
        $errors = $this->runStep3([
            'app_name' => '그누보드7',
            'app_url' => 'https://example.com',
            'core_update_github_url' => 'https://github.com/gnuboard/g7',
        ]);

        $this->assertArrayNotHasKey('app_name', $errors);
        $this->assertArrayNotHasKey('app_url', $errors);
        $this->assertArrayNotHasKey('core_update_github_url', $errors);
    }

    /**
     * 값이 비어 있으면 URL 형태 검사는 건너뛴다 (선택 입력 보존).
     */
    #[Test]
    public function it_skips_url_shape_check_for_empty_values(): void
    {
        $errors = $this->runStep3([
            'app_url' => '',
            'core_update_github_url' => '',
        ]);

        $this->assertArrayNotHasKey('app_url', $errors);
        $this->assertArrayNotHasKey('core_update_github_url', $errors);
    }
}
