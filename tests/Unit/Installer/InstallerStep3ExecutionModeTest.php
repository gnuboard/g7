<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;

/**
 * Step 3 저장 시점의 실행 모드·Vendor 방식 정합 (D33 · A-4 · R9).
 *
 * 실행 모드는 허용 어휘로만 저장되고(미지원 값은 auto), inprocess 를 고르면 composer 는
 * 성립하지 않으므로 Vendor 설치 방식이 bundled 로 고정된다. 화면(JS)이 같은 규칙을 갖지만
 * 저장 시점에 서버가 다시 판정해야 화면을 우회한 입력이 상태에 남지 않는다.
 *
 * InstallerStep3BinaryPathStoreGuardTest 와 같은 방식으로 DB 검증을 일부러 실패시켜
 * 저장/리다이렉트 분기에 도달하지 않는다.
 *
 * BASE_PATH 는 실 저장소 루트가 아니라 temp 로 둔다 (R7) — 실 루트를 define 하면 같은 프로세스에서
 * 뒤에 도는 temp 전제 인스톨러 테스트(stepwise·executor)가 전부 skip 된다. 이 테스트가 BASE_PATH 로
 * 읽는 것은 동봉 묶음 존재 여부(감지 경로)뿐이라 temp 로 충분하다.
 *
 * 커버 축: execution_mode_choice auto·subprocess·inprocess·invalid / vendor_mode_choice auto·composer·bundled
 *
 * @effects step3_post_normalizes_execution_mode, step3_post_forces_bundled_vendor_when_inprocess
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerStep3ExecutionModeTest extends TestCase
{
    use CleansTemporaryBasePath;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__.'/stubs/lang_stub.php';

        $projectRoot = dirname(__DIR__, 3);
        if (! defined('BASE_PATH')) {
            $base = sys_get_temp_dir().'/g7-installer-step3-'.bin2hex(random_bytes(4));
            @mkdir($base, 0755, true);
            define('BASE_PATH', $base);
        }
        if (! defined('SUPPORTED_LANGUAGES')) {
            define('SUPPORTED_LANGUAGES', ['ko' => '한국어', 'en' => 'English']);
        }
        // config.php 를 require 하면 BASE_PATH 가 실 저장소 루트로 박히므로(격리 규약) 이
        // 클래스가 쓰는 상수만 같은 값으로 세운다 — SUPPORTED_LANGUAGES 와 같은 이유다.
        if (! defined('MAX_APP_NAME_LENGTH')) {
            define('MAX_APP_NAME_LENGTH', 100);
        }

        require_once $projectRoot.'/public/install/includes/functions.php';
        require_once $projectRoot.'/public/install/includes/binary-path-policy.php';
        require_once $projectRoot.'/public/install/includes/request-handler.php';
    }

    protected function setUp(): void
    {
        parent::setUp();

        // R7 — 다른 인스톨러 테스트가 실 저장소 루트를 BASE_PATH 로 먼저 define 했으면 그 경로의
        // 파일을 건드릴 수 있으므로 temp 하위가 아니면 skip 한다 (이 클래스는 쓰기 없음 — 문언 일치용 가드)
        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
        if (strpos($resolved, $tempPrefix) !== 0) {
            $this->markTestSkipped('BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — 격리 실행: php vendor/bin/phpunit --filter=InstallerStep3ExecutionModeTest');
        }
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $post  POST 본문
     * @return array{form: array<string, mixed>, errors: array<string, mixed>} 처리 후 formData 와 검증 오류
     */
    private function runStep3WithErrors(array $post): array
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

        return ['form' => $formData, 'errors' => $errors];
    }

    /**
     * @param  array<string, string>  $post  POST 본문
     * @return array<string, mixed> 처리 후 formData
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

        return $formData;
    }

    public function test_invalid_execution_mode_is_normalized_to_auto(): void
    {
        $formData = $this->runStep3(['execution_mode' => 'shell', 'vendor_mode' => 'bundled']);

        $this->assertSame('auto', $formData['execution_mode']);
    }

    public function test_missing_execution_mode_defaults_to_auto(): void
    {
        $formData = $this->runStep3(['vendor_mode' => 'bundled']);

        $this->assertSame('auto', $formData['execution_mode']);
    }

    public function test_inprocess_forces_bundled_vendor_even_if_composer_was_posted(): void
    {
        $formData = $this->runStep3(['execution_mode' => 'inprocess', 'vendor_mode' => 'composer']);

        $this->assertSame('inprocess', $formData['execution_mode']);
        $this->assertSame('bundled', $formData['vendor_mode'], 'inprocess 에서 composer 가 저장되면 설치 도중에 실패한다 (A-4)');
    }

    public function test_subprocess_keeps_posted_vendor_mode(): void
    {
        $formData = $this->runStep3(['execution_mode' => 'subprocess', 'vendor_mode' => 'composer']);

        $this->assertSame('subprocess', $formData['execution_mode']);
        $this->assertSame('composer', $formData['vendor_mode']);
    }

    /**
     * 성립하지 않는 명시 선택은 저장되지 않고 사유가 붙는다 (A-4 대칭).
     *
     * 화면이 고를 수 없게 막는 것만으로는 부족하다 — 재감지가 조용히 실패한 뒤, Step 3 저장과
     * 설치 시작 사이에 환경이 바뀐 뒤, 개발자 도구로 disabled 를 지운 뒤에는 서버가 유일한
     * 판정자다. 여기서 막지 않으면 실패가 설치 중반(태스크 실행)에야 드러난다.
     *
     * BASE_PATH 가 temp 라 동봉 묶음이 없다 — inprocess 가 성립하지 않는 조건이 환경과 무관하게
     * 고정된다. 판정 자체의 전 조합은 InstallerExecutionModeHelperTest 가 순수 함수로 덮는다.
     */
    public function test_explicit_inprocess_is_rejected_when_the_bundle_cannot_be_used(): void
    {
        $result = $this->runStep3WithErrors(['execution_mode' => 'inprocess', 'vendor_mode' => 'bundled']);

        $this->assertArrayHasKey(
            'execution_mode',
            $result['errors'],
            '동봉 묶음을 쓸 수 없는데 inprocess 를 저장하면 설치 중반에 실패한다 — 저장 시점에 막아야 한다',
        );
        $this->assertNotSame('', trim((string) $result['errors']['execution_mode']));
    }

    /**
     * `auto` 는 감지가 정하므로 저장 게이트가 걸리지 않는다 — 정상 경로 불변 단언.
     */
    public function test_auto_execution_mode_is_never_rejected(): void
    {
        $result = $this->runStep3WithErrors(['execution_mode' => 'auto', 'vendor_mode' => 'auto']);

        $this->assertArrayNotHasKey('execution_mode', $result['errors']);
    }
}
