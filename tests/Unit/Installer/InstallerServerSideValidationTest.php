<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Installer\Concerns\RunsInstallerSubprocess;

/**
 * 설치 화면이 브라우저에서만 하던 검사를 서버도 하는지 검증한다 (HTTP 직접 호출·터미널 우회 차단).
 *
 * 회귀: Step 2 다음 단계 POST 는 요구사항을 다시 검사하지 않았고, Step 3 는 사이트 이름·관리자 이름 필수,
 * 테이블 접두사 형식, vendor 설치 방식 가용성을 서버에서 보지 않았다. 확장 저장은 식별자 형식과
 * 관리자 템플릿 1개 이상만 확인해, 없는 확장·의존 누락·관리자 템플릿 여러 개가 그대로 저장됐다.
 */
class InstallerServerSideValidationTest extends TestCase
{
    use RunsInstallerSubprocess;

    /** 소유 세션 토큰 */
    private const OWNER = 'oooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooo';

    /**
     * 임시 루트에 최소 번들 확장 픽스처를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $base = $this->makeInstallerTempBase();
        $manifests = [
            'templates/_bundled/admin-a/template.json' => ['identifier' => 'admin-a', 'type' => 'admin', 'version' => '1.0.0'],
            'templates/_bundled/admin-b/template.json' => ['identifier' => 'admin-b', 'type' => 'admin', 'version' => '1.0.0'],
            'modules/_bundled/mod-shop/module.json' => ['identifier' => 'mod-shop', 'version' => '1.3.0'],
            'plugins/_bundled/pay-x/plugin.json' => ['identifier' => 'pay-x', 'version' => '1.0.0', 'dependencies' => ['modules' => ['mod-shop' => '>=1.0.0']]],
        ];
        foreach ($manifests as $path => $data) {
            @mkdir(dirname($base.'/'.$path), 0777, true);
            file_put_contents($base.'/'.$path, json_encode($data));
        }
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
     * Step 3 를 서브프로세스에서 제출하고 오류 필드 목록을 돌려준다.
     *
     * @param  array<string, string>  $post  덮어쓸 POST 값
     * @return array<int, string> 오류 필드명
     */
    private function step3ErrorFields(array $post): array
    {
        $result = $this->runInstallerScript(<<<'PHP'
            require_once $projectRoot.'/public/install/includes/request-handler.php';
            $formData = [];
            $errors = [];
            handleStep3Post('ko', $formData, $errors);
            echo json_encode(array_keys($errors));
            PHP, [
            'session' => ['csrf_token' => 'tok'],
            'post' => array_merge([
                'csrf_token' => 'tok', 'db_write_host' => 'h', 'db_write_database' => 'd', 'db_write_username' => 'u',
                'db_prefix' => 'g7_', 'app_name' => 'G7', 'app_url' => 'http://localhost',
                'admin_name' => 'A', 'admin_email' => 'a@example.com', 'admin_language' => 'ko',
                'admin_password' => 'pass-12345', 'admin_password_confirm' => 'pass-12345', 'vendor_mode' => 'auto',
            ], $post),
        ]);

        $decoded = json_decode((string) strtok($result['raw'], "\n"), true);
        $this->assertIsArray($decoded, $result['raw']);

        return $decoded;
    }

    /**
     * 사이트 이름·관리자 이름 빈 값은 서버가 거부한다.
     *
     * @effects server_rejects_empty_site_and_admin_name
     *
     * @return void 반환값 없음
     */
    public function test_step3_rejects_empty_app_name_and_admin_name(): void
    {
        $fields = $this->step3ErrorFields(['app_name' => '  ', 'admin_name' => '']);

        $this->assertContains('app_name', $fields);
        $this->assertContains('admin_name', $fields);
    }

    /**
     * 테이블 접두사 형식(소문자 시작·허용 문자·길이)을 서버가 검사한다.
     *
     * @effects server_rejects_invalid_db_prefix
     *
     * @return void 반환값 없음
     */
    public function test_step3_rejects_invalid_db_prefix(): void
    {
        $this->assertContains('db_prefix', $this->step3ErrorFields(['db_prefix' => '1g7_']));
        $this->assertContains('db_prefix', $this->step3ErrorFields(['db_prefix' => 'G7-']));
        $this->assertContains('db_prefix', $this->step3ErrorFields(['db_prefix' => 'abcdefg']));
        $this->assertNotContains('db_prefix', $this->step3ErrorFields(['db_prefix' => 'abcde_']));
    }

    /**
     * 사용할 수 없는 vendor 설치 방식(번들 파일 없음)을 고르면 서버가 거부한다.
     *
     * @effects server_rejects_unavailable_vendor_mode
     *
     * @return void 반환값 없음
     */
    public function test_step3_rejects_unavailable_vendor_mode(): void
    {
        $this->assertContains('vendor_mode', $this->step3ErrorFields(['vendor_mode' => 'bundled']));
        $this->assertNotContains('vendor_mode', $this->step3ErrorFields(['vendor_mode' => 'auto']));
    }

    /**
     * Step 2 다음 단계 요청은 요구사항 차단 항목이 있으면 Step 3 로 넘어가지 않는다.
     *
     * @effects server_rechecks_requirements_on_step2
     *
     * @return void 반환값 없음
     */
    public function test_step2_post_does_not_advance_when_requirements_block(): void
    {
        $this->writeInstallerState(['current_step' => 2, 'step_status' => ['2' => 'pending']]);

        $result = $this->runInstallerScript(<<<'PHP'
            require_once $projectRoot.'/public/install/includes/request-handler.php';
            $GLOBALS['translations'] = loadTranslations('ko');
            $_POST = ['proceed' => '1'];
            $error = null;
            handleStep2Post($error);
            echo json_encode(['error' => $error]);
            PHP, ['session' => []]);

        $state = $this->readInstallerState();
        $this->assertSame(2, (int) ($state['current_step'] ?? 2), $result['raw']);
        $this->assertNotEmpty($result['body']['error'] ?? null, $result['raw']);
    }

    /**
     * 확장 저장을 소유 세션으로 직접 호출한다.
     *
     * @param  array<string, mixed>  $body  요청 본문
     * @return array{status: int, body: array<string, mixed>|null, raw: string, session: array<string, mixed>} 응답
     */
    private function saveExtensions(array $body): array
    {
        $this->writeInstallerState([
            'current_step' => 4,
            'owner_hash' => hash('sha256', self::OWNER),
            'generation' => 1,
            'selected_extensions' => [],
        ]);

        return $this->runInstallerEndpoint('save-extensions.php', [
            'session' => ['install_owner' => self::OWNER, 'csrf_token' => 'tok', 'g7_locale' => 'ko'],
            'headers' => ['HTTP_X_CSRF_TOKEN' => 'tok'],
            'json_body' => $body,
        ]);
    }

    /**
     * 없는 확장 식별자는 422 로 거부된다.
     *
     * @effects save_extensions_rejects_unknown_identifier
     *
     * @return void 반환값 없음
     */
    public function test_save_extensions_rejects_unknown_identifier(): void
    {
        $response = $this->saveExtensions(['admin_templates' => ['admin-a'], 'modules' => ['no-such-module']]);

        $this->assertSame(422, $response['status'], $response['raw']);
        $this->assertSame('error_extension_not_found', $response['body']['message_key'] ?? null, $response['raw']);
    }

    /**
     * 관리자 템플릿을 둘 이상 고르면 422 로 거부된다.
     *
     * @effects save_extensions_rejects_multiple_admin_templates
     *
     * @return void 반환값 없음
     */
    public function test_save_extensions_rejects_multiple_admin_templates(): void
    {
        $response = $this->saveExtensions(['admin_templates' => ['admin-a', 'admin-b']]);

        $this->assertSame(422, $response['status'], $response['raw']);
        $this->assertSame('error_admin_template_single', $response['body']['message_key'] ?? null, $response['raw']);
    }

    /**
     * 의존 확장이 빠진 선택은 서버가 의존 확장을 채워 저장하고, 순서는 의존 확장이 먼저다.
     *
     * @effects save_extensions_adds_missing_dependency
     *
     * @return void 반환값 없음
     */
    public function test_save_extensions_auto_adds_missing_dependency(): void
    {
        $response = $this->saveExtensions(['admin_templates' => ['admin-a'], 'plugins' => ['pay-x']]);

        $this->assertSame(200, $response['status'], $response['raw']);
        $this->assertSame(['mod-shop'], $this->readInstallerState()['selected_extensions']['modules'] ?? null, $response['raw']);
        $this->assertSame(['modules:mod-shop', 'plugins:pay-x'], array_values(array_filter(
            $response['body']['data']['install_order'] ?? [],
            fn ($k) => ! str_starts_with($k, 'admin_templates:')
        )), $response['raw']);
    }

    /**
     * 관리자 템플릿 0개는 종전처럼 400 이다 (회귀 방지).
     *
     * @effects save_extensions_requires_admin_template
     *
     * @return void 반환값 없음
     */
    public function test_save_extensions_without_admin_template_stays_400(): void
    {
        $response = $this->saveExtensions(['admin_templates' => []]);

        $this->assertSame(400, $response['status'], $response['raw']);
    }
}
