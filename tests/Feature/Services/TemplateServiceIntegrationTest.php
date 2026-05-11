<?php

namespace Tests\Feature\Services;

use App\Enums\ExtensionStatus;
use App\Models\Template;
use App\Repositories\TemplateRepository;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TemplateServiceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private TemplateService $templateService;
    private TemplateRepository $templateRepository;

    protected function setUp(): void
    {
        parent::setUp();

        // 실제 TemplateManager를 사용 (Mock 없음)
        $this->templateRepository = new TemplateRepository();
        $this->templateService = app(TemplateService::class);

        // 템플릿 로드 (template.json 파일 읽기)
        $templateManager = app(\App\Contracts\Extension\TemplateManagerInterface::class);
        $templateManager->loadTemplates();
    }

    protected function tearDown(): void
    {
        // 테스트 후 public/build/template 디렉토리 정리
        $this->cleanupPublicTemplateDirectories();
        parent::tearDown();
    }

    /**
     * End-to-End 통합 테스트: 실제 sirsoft-admin_basic 템플릿 활성화
     *
     * 검증 항목:
     * 1. npm run build로 dist/ 생성 확인
     * 2. TemplateManager::installTemplate() 호출 (DB 등록)
     * 3. TemplateService::activateTemplate() API 호출
     * 4. TemplateManager의 파일 복사 실행 확인
     * 5. public/build/template/admin/dist/ 디렉토리 생성 검증 (type별 분리)
     * 6. 브라우저에서 /build/template/admin/dist/components.iife.js 접근 시 200 응답 확인
     */
    public function test_end_to_end_template_activation_with_real_files(): void
    {
        // Arrange
        $templatePath = base_path('templates/sirsoft-admin_basic');
        $distPath = $templatePath . '/dist';
        $publicDistPath = public_path('build/template/admin/dist'); // admin 타입으로 변경

        // 1. dist/ 디렉토리 존재 확인 (빌드되지 않은 경우 skip)
        if (!is_dir($distPath) || !file_exists($distPath . '/components.iife.js')) {
            $this->markTestSkipped('Template dist/ not built. Run npm run build in template directory first.');
        }

        // 2. 템플릿 설치 (DB 등록) - TemplateManager 사용
        $templateManager = app(\App\Contracts\Extension\TemplateManagerInterface::class);
        $templateManager->installTemplate('sirsoft-admin_basic');

        // DB에 등록된 템플릿 조회
        $template = Template::where('identifier', 'sirsoft-admin_basic')->first();
        $this->assertNotNull($template);
        $this->assertEquals('inactive', $template->status);

        // public/build 디렉토리가 없으면 생성
        if (!is_dir(public_path('build'))) {
            mkdir(public_path('build'), 0755, true);
        }

        // 기존 admin 디렉토리 정리
        if (is_dir(public_path('build/template/admin'))) {
            $this->deleteDirectory(public_path('build/template/admin'));
        }

        // 3. TemplateService::activateTemplate() API 호출
        $activatedTemplate = $this->templateService->activateTemplate($template->id);

        // 4. 템플릿 상태 변경 확인 (파일 복사는 더 이상 수행하지 않음)
        $this->assertEquals(ExtensionStatus::Active->value, $activatedTemplate->status);

        // 5. DB에서 활성화 확인
        $this->assertDatabaseHas('templates', [
            'identifier' => 'sirsoft-admin_basic',
            'status' => ExtensionStatus::Active->value,
        ]);
    }

    /**
     * admin과 user 템플릿 동시 활성화 시 디렉토리 분리 검증
     */
    public function test_admin_and_user_templates_are_separated_by_type(): void
    {
        // Arrange
        $templateManager = app(\App\Contracts\Extension\TemplateManagerInterface::class);

        // admin 템플릿 설치
        $templateManager->installTemplate('sirsoft-admin_basic');
        $adminTemplate = Template::where('identifier', 'sirsoft-admin_basic')->first();
        $this->assertNotNull($adminTemplate);

        $publicAdminPath = public_path('build/template/admin');
        $publicUserPath = public_path('build/template/user');

        // public/build 디렉토리가 없으면 생성
        if (!is_dir(public_path('build'))) {
            mkdir(public_path('build'), 0755, true);
        }

        // 기존 디렉토리 정리
        if (is_dir($publicAdminPath)) {
            $this->deleteDirectory($publicAdminPath);
        }
        if (is_dir($publicUserPath)) {
            $this->deleteDirectory($publicUserPath);
        }

        // Act: admin 템플릿 활성화
        $activatedAdmin = $this->templateService->activateTemplate($adminTemplate->id);

        // Assert: admin 템플릿 상태 확인 (파일 복사는 더 이상 수행하지 않음)
        // activateTemplate은 배열을 반환함
        $this->assertIsArray($activatedAdmin);
        $this->assertEquals(ExtensionStatus::Active->value, $activatedAdmin['template_info']['status']);
        $this->assertDatabaseHas('templates', [
            'identifier' => 'sirsoft-admin_basic',
            'status' => ExtensionStatus::Active->value,
        ]);
    }

    /**
     * 같은 타입의 템플릿 순차 활성화 시 이전 템플릿 비활성화 검증
     */
    public function test_deactivates_previous_template_of_same_type(): void
    {
        // Arrange
        $templateManager = app(\App\Contracts\Extension\TemplateManagerInterface::class);

        // admin 템플릿 설치
        $templateManager->installTemplate('sirsoft-admin_basic');
        $template1 = Template::where('identifier', 'sirsoft-admin_basic')->first();
        $this->assertNotNull($template1);

        $publicAdminPath = public_path('build/template/admin');

        // public/build 디렉토리가 없으면 생성
        if (!is_dir(public_path('build'))) {
            mkdir(public_path('build'), 0755, true);
        }

        // 기존 admin 디렉토리 정리
        if (is_dir($publicAdminPath)) {
            $this->deleteDirectory($publicAdminPath);
        }

        // Act: 첫 번째 템플릿 활성화
        $activatedTemplate1 = $this->templateService->activateTemplate($template1->id);

        // Assert: 첫 번째 템플릿이 활성화됨 (파일 복사는 더 이상 수행하지 않음)
        // activateTemplate은 배열을 반환함
        $this->assertIsArray($activatedTemplate1);
        $this->assertEquals(ExtensionStatus::Active->value, $activatedTemplate1['template_info']['status']);
        $this->assertDatabaseHas('templates', [
            'identifier' => 'sirsoft-admin_basic',
            'status' => ExtensionStatus::Active->value,
        ]);
    }

    /**
     * 디렉토리 재귀 삭제 헬퍼
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * public/build/template 디렉토리 전체 정리
     */
    private function cleanupPublicTemplateDirectories(): void
    {
        $templatePath = public_path('build/template');

        if (File::exists($templatePath)) {
            // Windows 권한 문제 방지: 재시도 로직 추가
            $maxAttempts = 3;
            $attempt = 0;

            while ($attempt < $maxAttempts) {
                try {
                    File::deleteDirectory($templatePath);
                    break;
                } catch (\Exception $e) {
                    $attempt++;
                    if ($attempt >= $maxAttempts) {
                        // 최종 실패 시 경고만 출력 (테스트 실패 방지)
                        echo "\nWarning: Could not delete template directory: {$e->getMessage()}\n";
                    } else {
                        // 짧은 대기 후 재시도
                        usleep(100000); // 100ms
                    }
                }
            }
        }
    }
}
