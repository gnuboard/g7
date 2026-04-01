<?php

namespace Tests\Unit\Extension;

use App\Enums\ExtensionStatus;
use App\Extension\TemplateManager;
use App\Models\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TemplateManagerDetailsTest extends TestCase
{
    use RefreshDatabase;

    private TemplateManager $templateManager;

    private string $testTemplatePath;

    private string $bundledTestPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->templateManager = app(TemplateManager::class);
        $this->testTemplatePath = base_path('templates/test-details');
        $this->bundledTestPath = base_path('templates/_bundled/test-details');

        // 이전 테스트에서 남은 디렉토리 정리
        if (File::exists($this->testTemplatePath)) {
            File::deleteDirectory($this->testTemplatePath);
        }
        if (File::exists($this->bundledTestPath)) {
            File::deleteDirectory($this->bundledTestPath);
        }
    }

    protected function tearDown(): void
    {
        if (File::exists($this->testTemplatePath)) {
            File::deleteDirectory($this->testTemplatePath);
        }
        if (File::exists($this->bundledTestPath)) {
            File::deleteDirectory($this->bundledTestPath);
        }

        parent::tearDown();
    }

    /**
     * 테스트용 template.json 파일을 생성합니다.
     *
     * @param array $overrides template.json 기본값을 덮어쓸 데이터
     */
    private function createTestTemplateStructure(array $overrides = []): void
    {
        File::makeDirectory($this->testTemplatePath.'/layouts', 0755, true);

        $templateJson = array_merge([
            'identifier' => 'test-details',
            'vendor' => 'test',
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'description' => ['ko' => '테스트용 템플릿', 'en' => 'Test template'],
            'dependencies' => ['modules' => [], 'plugins' => []],
            'github_url' => 'https://github.com/test/test-details',
            'github_changelog_url' => 'https://github.com/test/test-details/blob/main/CHANGELOG.md',
        ], $overrides);

        File::put(
            $this->testTemplatePath.'/template.json',
            json_encode($templateJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 업데이트 가능 상태일 때 관련 필드가 모두 반환되는지 검증
     */
    public function test_returns_update_fields_when_update_available(): void
    {
        // Arrange: 테스트 템플릿 파일 생성
        $this->createTestTemplateStructure();

        // DB 레코드 생성
        $template = Template::create([
            'identifier' => 'test-details',
            'vendor' => 'test',
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용 템플릿', 'en' => 'Test template'],
            'update_available' => true,
            'latest_version' => '2.0.0',
            'github_url' => 'https://github.com/test/test-details',
            'github_changelog_url' => 'https://github.com/test/test-details/blob/main/CHANGELOG.md',
        ]);

        // update_source는 fillable에 없으므로 직접 DB 업데이트
        DB::table('templates')
            ->where('id', $template->id)
            ->update(['update_source' => 'github']);

        // 템플릿 다시 로드
        $this->templateManager->loadTemplates();

        // Act
        $details = $this->templateManager->getInstalledTemplatesWithDetails();

        // Assert
        $this->assertArrayHasKey('test-details', $details);
        $detail = $details['test-details'];

        $this->assertTrue($detail['update_available']);
        $this->assertEquals('2.0.0', $detail['latest_version']);
        $this->assertEquals('1.0.0', $detail['file_version']);
        $this->assertEquals('github', $detail['update_source']);
        $this->assertEquals('https://github.com/test/test-details', $detail['github_url']);
        $this->assertEquals('https://github.com/test/test-details/blob/main/CHANGELOG.md', $detail['github_changelog_url']);
    }

    /**
     * 업데이트 없을 때 기본값이 반환되는지 검증
     */
    public function test_returns_default_update_fields_when_no_update(): void
    {
        // Arrange
        $this->createTestTemplateStructure();

        Template::create([
            'identifier' => 'test-details',
            'vendor' => 'test',
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용 템플릿', 'en' => 'Test template'],
            'update_available' => false,
        ]);

        $this->templateManager->loadTemplates();

        // Act
        $details = $this->templateManager->getInstalledTemplatesWithDetails();

        // Assert
        $detail = $details['test-details'];

        $this->assertFalse($detail['update_available']);
        $this->assertNull($detail['latest_version']);
        $this->assertEquals('1.0.0', $detail['file_version']);
        $this->assertNull($detail['update_source']);
    }

    /**
     * github_url이 template.json에만 있고 DB에 없을 때 template.json 값이 사용되는지 검증
     */
    public function test_github_url_falls_back_to_template_json(): void
    {
        // Arrange: template.json에 github_url 포함
        $this->createTestTemplateStructure([
            'github_url' => 'https://github.com/test/from-json',
        ]);

        // DB에는 github_url 없음
        Template::create([
            'identifier' => 'test-details',
            'vendor' => 'test',
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용 템플릿', 'en' => 'Test template'],
        ]);

        $this->templateManager->loadTemplates();

        // Act
        $details = $this->templateManager->getInstalledTemplatesWithDetails();

        // Assert
        $this->assertEquals('https://github.com/test/from-json', $details['test-details']['github_url']);
    }

    /**
     * update_available이 true인데 latest_version이 null일 때 _bundled 버전으로 보완되는지 검증
     */
    public function test_latest_version_fallback_to_bundled_when_null(): void
    {
        // Arrange: _bundled에 더 높은 버전 존재
        File::makeDirectory($this->bundledTestPath.'/layouts', 0755, true);
        File::put(
            $this->bundledTestPath.'/template.json',
            json_encode([
                'identifier' => 'test-details',
                'vendor' => 'test',
                'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
                'version' => '2.0.0',
                'type' => 'admin',
                'description' => ['ko' => '테스트용 템플릿', 'en' => 'Test template'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        // 활성 디렉토리의 template.json (현재 버전)
        $this->createTestTemplateStructure(['version' => '1.0.0']);

        // DB: update_available=true이지만 latest_version=null (이전 버전 코드에서 발생 가능한 상태)
        Template::create([
            'identifier' => 'test-details',
            'vendor' => 'test',
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용 템플릿', 'en' => 'Test template'],
            'update_available' => true,
            'latest_version' => null,
        ]);

        $this->templateManager->loadTemplates();

        // Act
        $details = $this->templateManager->getInstalledTemplatesWithDetails();

        // Assert: latest_version이 _bundled 버전(2.0.0)으로 보완됨
        $detail = $details['test-details'];
        $this->assertTrue($detail['update_available']);
        $this->assertEquals('2.0.0', $detail['latest_version']);
    }

    /**
     * update_available이 true인데 latest_version이 null이고 _bundled도 없을 때 파일 버전으로 보완되는지 검증
     */
    public function test_latest_version_fallback_to_file_version_when_no_bundled(): void
    {
        // Arrange: _bundled 없이 활성 디렉토리만 존재
        $this->createTestTemplateStructure(['version' => '1.0.0']);

        // DB: update_available=true이지만 latest_version=null
        Template::create([
            'identifier' => 'test-details',
            'vendor' => 'test',
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용 템플릿', 'en' => 'Test template'],
            'update_available' => true,
            'latest_version' => null,
        ]);

        $this->templateManager->loadTemplates();

        // Act
        $details = $this->templateManager->getInstalledTemplatesWithDetails();

        // Assert: latest_version이 파일 버전(1.0.0)으로 보완됨
        $detail = $details['test-details'];
        $this->assertTrue($detail['update_available']);
        $this->assertEquals('1.0.0', $detail['latest_version']);
    }

    /**
     * version 필드가 DB 레코드의 version을 사용하는지 검증 (file_version과 구분)
     */
    public function test_version_uses_db_record_value(): void
    {
        // Arrange: template.json의 version은 1.1.0 (파일 버전)
        $this->createTestTemplateStructure([
            'version' => '1.1.0',
        ]);

        // DB의 version은 1.0.0 (설치 시점 버전)
        Template::create([
            'identifier' => 'test-details',
            'vendor' => 'test',
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용 템플릿', 'en' => 'Test template'],
        ]);

        $this->templateManager->loadTemplates();

        // Act
        $details = $this->templateManager->getInstalledTemplatesWithDetails();

        // Assert: version은 DB의 값, file_version은 template.json의 값
        $detail = $details['test-details'];
        $this->assertEquals('1.0.0', $detail['version']);
        $this->assertEquals('1.1.0', $detail['file_version']);
    }
}
