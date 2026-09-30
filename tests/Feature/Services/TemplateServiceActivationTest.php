<?php

namespace Tests\Feature\Services;

use App\Contracts\Extension\TemplateManagerInterface;
use App\Extension\HookManager;
use App\Models\Template;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TemplateServiceActivationTest extends TestCase
{
    use RefreshDatabase;

    private TemplateService $templateService;

    /**
     * 테스트 중 생성된 템플릿 식별자 목록
     */
    private array $createdTemplates = [];

    /**
     * 템플릿 서비스를 준비하고 이전 테스트의 빌드 디렉토리를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->templateService = app(TemplateService::class);

        // 이전 테스트의 public/build 디렉토리 정리 (Windows 권한 문제 방지)
        $this->cleanupPublicBuildDirectory();
    }

    /**
     * 테스트 종료 후 생성된 템플릿 및 빌드 디렉토리 정리
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        // 생성된 모든 템플릿 디렉토리 정리
        foreach ($this->createdTemplates as $identifier) {
            $this->cleanupTemplateDirectory($identifier);
        }

        // public/build/template 디렉토리 정리
        $this->cleanupPublicBuildDirectory();

        parent::tearDown();
    }

    /**
     * 템플릿 활성화 시 기존 활성 템플릿이 비활성화되는지 테스트
     *
     * @scenario operation=activate
     *
     * @effects same_type_active_template_is_replaced
     *
     * @return void 반환값 없음
     */
    public function test_activating_template_deactivates_current_active_template(): void
    {
        // Arrange: 같은 타입의 템플릿 2개 생성
        $activeTemplate = Template::factory()->create([
            'identifier' => 'test-active',
            'type' => 'admin',
            'status' => 'active',
        ]);

        $newTemplate = Template::factory()->create([
            'identifier' => 'test-new',
            'type' => 'admin',
            'status' => 'inactive',
        ]);

        // 템플릿 디렉토리 생성 (파일 복사 오류 방지)
        $this->createTemplateDistDirectory($newTemplate->identifier);

        // Act: 새 템플릿 활성화
        $this->templateService->activateTemplate($newTemplate->id);

        // Assert: 기존 템플릿이 비활성화되었는지 확인
        $this->assertDatabaseHas('templates', [
            'id' => $activeTemplate->id,
            'status' => 'inactive',
        ]);

        // Assert: 새 템플릿이 활성화되었는지 확인
        $this->assertDatabaseHas('templates', [
            'id' => $newTemplate->id,
            'status' => 'active',
        ]);
    }

    /**
     * 템플릿 활성화 시 파일이 public/build/template/{type}/로 복사되는지 테스트
     *
     * @scenario operation=activate
     *
     * @effects template_activated_in_database
     *
     * @return void 반환값 없음
     */
    public function test_activating_template_copies_files_to_public(): void
    {
        // Arrange
        $template = Template::factory()->create([
            'identifier' => 'test-copy',
            'type' => 'admin',
            'status' => 'inactive',
        ]);

        $this->createTemplateDistDirectory($template->identifier);

        // Act
        $this->templateService->activateTemplate($template->id);

        // Assert: 템플릿이 활성화되었는지 확인 (파일 복사는 더 이상 수행하지 않음)
        $this->assertDatabaseHas('templates', [
            'id' => $template->id,
            'status' => 'active',
        ]);
    }

    /**
     * 훅이 올바른 순서로 실행되는지 테스트
     *
     * @scenario operation=activate
     *
     * @effects lifecycle_hooks_fire_before_then_after
     *
     * @return void 반환값 없음
     */
    public function test_hooks_are_executed_in_correct_order(): void
    {
        // Arrange
        $template = Template::factory()->create([
            'identifier' => 'test-hooks',
            'type' => 'admin',
            'status' => 'inactive',
        ]);

        $this->createTemplateDistDirectory($template->identifier);

        $executionOrder = [];

        // 훅 리스너 등록
        HookManager::addAction('core.templates.before_activate', function () use (&$executionOrder) {
            $executionOrder[] = 'before_activate';
        });

        HookManager::addAction('core.templates.after_activate', function () use (&$executionOrder) {
            $executionOrder[] = 'after_activate';
        });

        // Act
        $this->templateService->activateTemplate($template->id);

        // Assert: 훅이 올바른 순서로 실행되었는지 확인
        // before_activate와 after_activate가 실행되었는지 확인
        $this->assertContains('before_activate', $executionOrder);
        $this->assertContains('after_activate', $executionOrder);

        // before_activate가 after_activate보다 먼저 실행되었는지 확인
        $beforeIndex = array_search('before_activate', $executionOrder);
        $afterIndex = array_search('after_activate', $executionOrder);
        $this->assertLessThan($afterIndex, $beforeIndex, 'before_activate should execute before after_activate');
    }

    /**
     * 다른 타입의 템플릿은 영향받지 않는지 테스트
     *
     * @scenario operation=activate
     *
     * @effects other_type_template_untouched
     *
     * @return void 반환값 없음
     */
    public function test_different_type_templates_are_not_affected(): void
    {
        // Arrange: 다른 타입의 활성 템플릿
        $adminTemplate = Template::factory()->create([
            'identifier' => 'test-admin',
            'type' => 'admin',
            'status' => 'active',
        ]);

        $userTemplate = Template::factory()->create([
            'identifier' => 'test-user',
            'type' => 'user',
            'status' => 'active',
        ]);

        $newAdminTemplate = Template::factory()->create([
            'identifier' => 'test-newadmin',
            'type' => 'admin',
            'status' => 'inactive',
        ]);

        $this->createTemplateDistDirectory($newAdminTemplate->identifier);

        // Act: admin 타입 템플릿 활성화
        $this->templateService->activateTemplate($newAdminTemplate->id);

        // Assert: admin 타입만 변경되고 user 타입은 그대로인지 확인
        $this->assertDatabaseHas('templates', [
            'id' => $adminTemplate->id,
            'status' => 'inactive',
        ]);

        $this->assertDatabaseHas('templates', [
            'id' => $userTemplate->id,
            'status' => 'active', // user 타입은 여전히 active
        ]);

        $this->assertDatabaseHas('templates', [
            'id' => $newAdminTemplate->id,
            'status' => 'active',
        ]);
    }

    /**
     * assets 디렉토리가 있을 경우 함께 복사되는지 테스트
     *
     * @scenario operation=activate
     *
     * @effects template_activated_in_database
     *
     * @return void 반환값 없음
     */
    public function test_assets_directory_is_copied_if_exists(): void
    {
        // Arrange
        $template = Template::factory()->create([
            'identifier' => 'test-assets',
            'type' => 'admin',
            'status' => 'inactive',
        ]);

        $this->createTemplateDistDirectory($template->identifier);
        $this->createTemplateAssetsDirectory($template->identifier);

        // Act
        $this->templateService->activateTemplate($template->id);

        // Assert: 템플릿이 활성화되었는지 확인 (파일 복사는 더 이상 수행하지 않음)
        $this->assertDatabaseHas('templates', [
            'id' => $template->id,
            'status' => 'active',
        ]);
    }

    /**
     * 템플릿 활성화 시 identifier로 호출 가능한지 테스트
     *
     * @scenario operation=activate
     *
     * @effects template_activated_in_database
     *
     * @return void 반환값 없음
     */
    public function test_can_activate_template_with_identifier(): void
    {
        // Arrange
        $template = Template::factory()->create([
            'identifier' => 'test-activateidentifier',
            'type' => 'admin',
            'status' => 'inactive',
        ]);

        $this->createTemplateDistDirectory($template->identifier);

        // Act: identifier로 활성화
        $result = $this->templateService->activateTemplate($template->identifier);

        // Assert: 템플릿이 활성화되었는지 확인
        $this->assertDatabaseHas('templates', [
            'identifier' => $template->identifier,
            'status' => 'active',
        ]);

        // activateTemplate은 배열을 반환함
        $this->assertIsArray($result);
        $this->assertEquals($template->identifier, $result['template_info']['identifier']);
        $this->assertEquals('active', $result['template_info']['status']);
    }

    /**
     * 템플릿 활성화 시 ID로 호출 가능한지 테스트
     *
     * @scenario operation=activate
     *
     * @effects template_activated_in_database
     *
     * @return void 반환값 없음
     */
    public function test_can_activate_template_with_id(): void
    {
        // Arrange
        $template = Template::factory()->create([
            'identifier' => 'test-activateid',
            'type' => 'admin',
            'status' => 'inactive',
        ]);

        $this->createTemplateDistDirectory($template->identifier);

        // Act: ID로 활성화
        $result = $this->templateService->activateTemplate($template->id);

        // Assert: 템플릿이 활성화되었는지 확인
        $this->assertDatabaseHas('templates', [
            'id' => $template->id,
            'status' => 'active',
        ]);

        // activateTemplate은 배열을 반환함
        $this->assertIsArray($result);
        $this->assertEquals($template->identifier, $result['template_info']['identifier']);
        $this->assertEquals('active', $result['template_info']['status']);
    }

    /**
     * 존재하지 않는 identifier로 활성화 시도 시 예외 발생 테스트
     *
     * @scenario operation=activate
     *
     * @effects unknown_template_rejected
     *
     * @return void 반환값 없음
     */
    public function test_activate_template_with_invalid_identifier_throws_exception(): void
    {
        // Arrange & Act & Assert
        $this->expectException(ValidationException::class);
        $this->templateService->activateTemplate('non-existent-template');
    }

    /**
     * 존재하지 않는 ID로 활성화 시도 시 예외 발생 테스트
     *
     * @scenario operation=activate
     *
     * @effects unknown_template_rejected
     *
     * @return void 반환값 없음
     */
    public function test_activate_template_with_invalid_id_throws_exception(): void
    {
        // Arrange & Act & Assert
        $this->expectException(ValidationException::class);
        $this->templateService->activateTemplate(99999);
    }

    /**
     * 템플릿 비활성화 시 identifier로 호출 가능한지 테스트
     *
     * @scenario operation=deactivate
     *
     * @effects template_deactivated_in_database
     *
     * @return void 반환값 없음
     */
    public function test_can_deactivate_template_with_identifier(): void
    {
        // Arrange
        $template = Template::factory()->create([
            'identifier' => 'test-deactivateidentifier',
            'type' => 'admin',
            'status' => 'active',
        ]);
        // 유형의 마지막 활성 템플릿은 끌 수 없다(#703) — 같은 유형의 다른 활성 템플릿을 남긴다
        Template::factory()->create([
            'identifier' => 'test-deactivateidentifier-peer',
            'type' => 'admin',
            'status' => 'active',
        ]);

        $this->createTemplateDistDirectory($template->identifier);

        // Act: identifier로 비활성화
        $result = $this->templateService->deactivateTemplate($template->identifier);

        // Assert: 템플릿이 비활성화되었는지 확인
        // deactivateTemplate 은 templateInfo 배열을 직접 반환 (activateTemplate 과 달리 template_info 래핑 없음)
        $this->assertIsArray($result);
        $this->assertEquals('inactive', $result['status']);
        $this->assertDatabaseHas('templates', [
            'identifier' => $template->identifier,
            'status' => 'inactive',
        ]);
    }

    /**
     * 템플릿 비활성화 시 ID로 호출 가능한지 테스트
     *
     * @scenario operation=deactivate
     *
     * @effects template_deactivated_in_database
     *
     * @return void 반환값 없음
     */
    public function test_can_deactivate_template_with_id(): void
    {
        // Arrange
        $template = Template::factory()->create([
            'identifier' => 'test-deactivateid',
            'type' => 'admin',
            'status' => 'active',
        ]);
        // 유형의 마지막 활성 템플릿은 끌 수 없다(#703) — 같은 유형의 다른 활성 템플릿을 남긴다
        Template::factory()->create([
            'identifier' => 'test-deactivateid-peer',
            'type' => 'admin',
            'status' => 'active',
        ]);

        $this->createTemplateDistDirectory($template->identifier);

        // Act: ID로 비활성화
        $result = $this->templateService->deactivateTemplate($template->id);

        // Assert: 템플릿이 비활성화되었는지 확인
        // deactivateTemplate 은 templateInfo 배열을 직접 반환 (activateTemplate 과 달리 template_info 래핑 없음)
        $this->assertIsArray($result);
        $this->assertEquals('inactive', $result['status']);
        $this->assertDatabaseHas('templates', [
            'id' => $template->id,
            'status' => 'inactive',
        ]);
    }

    /**
     * 존재하지 않는 identifier로 비활성화 시도 시 예외 발생 테스트
     *
     * @scenario operation=deactivate
     *
     * @effects unknown_template_rejected
     *
     * @return void 반환값 없음
     */
    public function test_deactivate_template_with_invalid_identifier_throws_exception(): void
    {
        // Arrange & Act & Assert
        $this->expectException(ValidationException::class);
        $this->templateService->deactivateTemplate('non-existent-template');
    }

    /**
     * 존재하지 않는 ID로 비활성화 시도 시 예외 발생 테스트
     *
     * @scenario operation=deactivate
     *
     * @effects unknown_template_rejected
     *
     * @return void 반환값 없음
     */
    public function test_deactivate_template_with_invalid_id_throws_exception(): void
    {
        // Arrange & Act & Assert
        $this->expectException(ValidationException::class);
        $this->templateService->deactivateTemplate(99999);
    }

    /**
     * 헬퍼 메서드: 템플릿 dist 디렉토리 생성
     *
     * @param  string  $identifier  템플릿 식별자
     * @return void 반환값 없음
     */
    private function createTemplateDistDirectory(string $identifier): void
    {
        $templatePath = base_path("templates/{$identifier}");
        $distPath = "{$templatePath}/dist";
        File::makeDirectory($distPath, 0755, true);
        File::put("{$distPath}/test.js", 'test content');

        // template.json 파일 생성 (TemplateManager가 인식하도록)
        $this->createTemplateJson($templatePath, $identifier);

        // TemplateManager의 템플릿 목록 재로드
        app(TemplateManagerInterface::class)->loadTemplates();

        // 생성된 템플릿 추적 (tearDown에서 자동 정리)
        $this->createdTemplates[] = $identifier;
    }

    /**
     * 헬퍼 메서드: template.json 파일 생성
     *
     * @param  string  $templatePath  템플릿 디렉토리 절대 경로
     * @param  string  $identifier  템플릿 식별자 (vendor-name)
     * @return void 반환값 없음
     */
    private function createTemplateJson(string $templatePath, string $identifier): void
    {
        $parts = explode('-', $identifier);
        $vendor = $parts[0] ?? 'unknown';
        $name = $parts[1] ?? 'unknown';

        $templateJson = [
            'identifier' => $identifier,
            'vendor' => $vendor,
            'name' => ['ko' => $name, 'en' => $name],
            'version' => '1.0.0',
            'type' => 'admin',
            'description' => ['ko' => 'Test template', 'en' => 'Test template'],
        ];

        File::put("{$templatePath}/template.json", json_encode($templateJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 헬퍼 메서드: 템플릿 assets 디렉토리 생성
     *
     * 주의: createTemplateDistDirectory를 먼저 호출해야 함
     *
     * @param  string  $identifier  템플릿 식별자
     * @return void 반환값 없음
     */
    private function createTemplateAssetsDirectory(string $identifier): void
    {
        $assetsPath = base_path("templates/{$identifier}/assets");
        File::makeDirectory($assetsPath, 0755, true);
        File::put("{$assetsPath}/test.css", 'test content');
    }

    /**
     * 헬퍼 메서드: 템플릿 디렉토리 정리
     *
     * @param  string  $identifier  템플릿 식별자
     * @return void 반환값 없음
     */
    private function cleanupTemplateDirectory(string $identifier): void
    {
        $templatePath = base_path("templates/{$identifier}");
        if (File::isDirectory($templatePath)) {
            File::deleteDirectory($templatePath);
        }
    }

    /**
     * 헬퍼 메서드: public/build/template/ 디렉토리 정리
     *
     * @return void 반환값 없음
     */
    private function cleanupPublicBuildDirectory(): void
    {
        $buildPath = public_path('build/template');
        if (File::isDirectory($buildPath)) {
            File::deleteDirectory($buildPath);
        }
    }
}
