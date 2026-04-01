<?php

namespace Tests\Feature;

use App\Contracts\Extension\TemplateManagerInterface;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TemplateSystemIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected TemplateManagerInterface $templateManager;

    protected string $testTemplatesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->templateManager = app(TemplateManagerInterface::class);
        $this->testTemplatesPath = base_path('templates');

        // 기존 테스트 템플릿 정리
        if (File::exists($this->testTemplatesPath.'/test-dependent')) {
            File::deleteDirectory($this->testTemplatesPath.'/test-dependent');
        }

        // 테스트용 템플릿 디렉토리 생성
        $this->createTestTemplateStructure();
    }

    protected function tearDown(): void
    {
        // 테스트 후 정리
        $this->cleanupTestTemplates();

        // test-dependent도 제거
        if (File::exists($this->testTemplatesPath.'/test-dependent')) {
            File::deleteDirectory($this->testTemplatesPath.'/test-dependent');
        }

        parent::tearDown();
    }

    /**
     * 템플릿 스캔 테스트
     */
    public function test_can_scan_templates(): void
    {
        $scannedTemplates = $this->templateManager->scanTemplates();

        $this->assertIsArray($scannedTemplates);
        $this->assertArrayHasKey('test-admin', $scannedTemplates);
        // 경로 구분자는 OS에 따라 다를 수 있으므로 정규화하여 비교
        $expectedPath = str_replace('/', DIRECTORY_SEPARATOR, $this->testTemplatesPath.'/test-admin');
        $this->assertEquals(
            $expectedPath,
            $scannedTemplates['test-admin']['path']
        );
    }

    /**
     * 템플릿 로드 테스트
     */
    public function test_can_load_templates(): void
    {
        $this->templateManager->loadTemplates();

        $templates = $this->templateManager->getAllTemplates();

        $this->assertIsArray($templates);
        $this->assertArrayHasKey('test-admin', $templates);
    }

    /**
     * 템플릿 설치 테스트
     */
    public function test_can_install_template(): void
    {
        $this->templateManager->loadTemplates();

        $result = $this->templateManager->installTemplate('test-admin');

        $this->assertTrue($result);
        $this->assertDatabaseHas('templates', [
            'identifier' => 'test-admin',
            'vendor' => 'test',
            'status' => 'inactive',
        ]);

        // name과 description은 JSON 타입이므로 별도 확인
        $template = Template::where('identifier', 'test-admin')->first();
        $this->assertIsArray($template->name);
        $this->assertEquals('Test Admin Template', $template->name['ko']);
        $this->assertEquals('Test Admin Template', $template->name['en']);
    }

    /**
     * 템플릿 활성화 테스트
     */
    public function test_can_activate_template(): void
    {
        $this->templateManager->loadTemplates();
        $this->templateManager->installTemplate('test-admin');

        $result = $this->templateManager->activateTemplate('test-admin');

        $this->assertTrue($result);
        $this->assertDatabaseHas('templates', [
            'identifier' => 'test-admin',
            'status' => 'active',
        ]);
    }

    /**
     * 템플릿 비활성화 테스트
     */
    public function test_can_deactivate_template(): void
    {
        $this->templateManager->loadTemplates();
        $this->templateManager->installTemplate('test-admin');
        $this->templateManager->activateTemplate('test-admin');

        $result = $this->templateManager->deactivateTemplate('test-admin');

        $this->assertTrue($result);
        $this->assertDatabaseHas('templates', [
            'identifier' => 'test-admin',
            'status' => 'inactive',
        ]);

        // public/build/template/{type} 디렉토리가 정리되었는지 확인 (type별 분리)
        $this->assertFalse(File::exists(public_path('build/template/admin')));
    }

    /**
     * 템플릿 제거 테스트
     */
    public function test_can_uninstall_template(): void
    {
        $this->templateManager->loadTemplates();
        $this->templateManager->installTemplate('test-admin');

        $result = $this->templateManager->uninstallTemplate('test-admin');

        $this->assertTrue($result);
        // 일반 삭제 확인
        $this->assertDatabaseMissing('templates', [
            'identifier' => 'test-admin',
        ]);
    }

    /**
     * 의존성 검증 테스트 - 모듈 의존성 충족
     */
    public function test_validates_module_dependencies_success(): void
    {
        // 의존하는 모듈 생성
        $module = Module::create([
            'identifier' => 'test-module',
            'vendor' => 'test',
            'name' => [
                'ko' => 'Test Module',
                'en' => 'Test Module',
            ],
            'version' => '1.2.0',
            'description' => [
                'ko' => 'Test module for dependency testing',
                'en' => 'Test module for dependency testing',
            ],
            'status' => 'active',
        ]);

        // 모듈이 정상적으로 생성되었는지 확인
        $this->assertDatabaseHas('modules', [
            'identifier' => 'test-module',
            'status' => 'active',
        ]);

        // 템플릿 생성 후 로드
        $this->createTestTemplateWithDependencies([
            'modules' => [
                'test-module' => '>=1.0.0',
            ],
        ]);

        // 템플릿 재로드하여 새 템플릿 인식
        $this->templateManager->loadTemplates();

        // 템플릿이 로드되었는지 확인
        $template = $this->templateManager->getTemplate('test-dependent');
        $this->assertNotNull($template, 'test-dependent 템플릿이 로드되지 않았습니다');

        // 의존성 확인
        $dependencies = $template['dependencies'] ?? [];
        $this->assertArrayHasKey('modules', $dependencies);
        $this->assertArrayHasKey('test-module', $dependencies['modules']);

        // 의존성이 충족되므로 설치 성공
        $result = $this->templateManager->installTemplate('test-dependent');

        $this->assertTrue($result);
    }

    /**
     * 의존성 검증 테스트 - 모듈 없음
     */
    public function test_validates_module_dependencies_fails_when_module_missing(): void
    {
        $this->createTestTemplateWithDependencies([
            'modules' => [
                'nonexistent-module' => '>=1.0.0',
            ],
        ]);

        $this->templateManager->loadTemplates();

        $this->expectException(\Exception::class);
        $this->templateManager->installTemplate('test-dependent');
    }

    /**
     * 의존성 검증 테스트 - 버전 불일치
     */
    public function test_validates_module_dependencies_fails_when_version_mismatch(): void
    {
        // 낮은 버전의 모듈 생성
        Module::create([
            'identifier' => 'test-module',
            'vendor' => 'test',
            'name' => [
                'ko' => 'Test Module',
                'en' => 'Test Module',
            ],
            'version' => '0.9.0',
            'description' => [
                'ko' => 'Test module with low version',
                'en' => 'Test module with low version',
            ],
            'status' => 'active',
        ]);

        $this->createTestTemplateWithDependencies([
            'modules' => [
                'test-module' => '>=1.0.0',
            ],
        ]);

        $this->templateManager->loadTemplates();

        $this->expectException(\Exception::class);
        $this->templateManager->installTemplate('test-dependent');
    }

    /**
     * 의존성 검증 테스트 - 플러그인 의존성 충족
     */
    public function test_validates_plugin_dependencies_success(): void
    {
        // PHP의 require_once로 인해 이미 로드된 test-dependent 클래스를 재정의할 수 없으므로
        // 모듈과 플러그인 의존성을 모두 충족하는 시나리오로 테스트
        Module::create([
            'identifier' => 'test-module',
            'vendor' => 'test',
            'name' => [
                'ko' => 'Test Module',
                'en' => 'Test Module',
            ],
            'version' => '1.5.0',
            'description' => [
                'ko' => 'Test module for plugin dependency',
                'en' => 'Test module for plugin dependency',
            ],
            'status' => 'active',
        ]);

        Plugin::create([
            'identifier' => 'test-plugin',
            'vendor' => 'test',
            'name' => [
                'ko' => 'Test Plugin',
                'en' => 'Test Plugin',
            ],
            'version' => '2.1.0',
            'description' => [
                'ko' => 'Test plugin for dependency testing',
                'en' => 'Test plugin for dependency testing',
            ],
            'status' => 'active',
        ]);

        // 템플릿 생성 - 모듈과 플러그인 의존성 모두 포함
        $this->createTestTemplateWithDependencies([
            'modules' => [
                'test-module' => '>=1.0.0',
            ],
            'plugins' => [
                'test-plugin' => '^2.0',
            ],
        ]);

        // 템플릿 재로드하여 새 템플릿 인식
        $this->templateManager->loadTemplates();

        // 의존성이 모두 충족되므로 설치 성공
        $result = $this->templateManager->installTemplate('test-dependent');

        $this->assertTrue($result);
    }

    /**
     * 의존성 검증 테스트 - 복합 의존성
     */
    public function test_validates_multiple_dependencies(): void
    {
        Module::create([
            'identifier' => 'test-module',
            'vendor' => 'test',
            'name' => [
                'ko' => 'Test Module',
                'en' => 'Test Module',
            ],
            'version' => '1.5.0',
            'description' => [
                'ko' => 'Test module for multiple dependencies',
                'en' => 'Test module for multiple dependencies',
            ],
            'status' => 'active',
        ]);

        Plugin::create([
            'identifier' => 'test-plugin',
            'vendor' => 'test',
            'name' => [
                'ko' => 'Test Plugin',
                'en' => 'Test Plugin',
            ],
            'version' => '3.2.1',
            'description' => [
                'ko' => 'Test plugin for multiple dependencies',
                'en' => 'Test plugin for multiple dependencies',
            ],
            'status' => 'active',
        ]);

        // 템플릿 생성 후 로드
        $this->createTestTemplateWithDependencies([
            'modules' => [
                'test-module' => '~1.2',
            ],
            'plugins' => [
                'test-plugin' => '^3.0',
            ],
        ]);

        // 템플릿 재로드하여 새 템플릿 인식
        $this->templateManager->loadTemplates();

        $result = $this->templateManager->installTemplate('test-dependent');

        $this->assertTrue($result);
    }

    /**
     * 잘못된 템플릿 디렉토리명 테스트
     */
    public function test_ignores_invalid_template_directory_names(): void
    {
        // 잘못된 형식의 디렉토리 생성
        File::makeDirectory($this->testTemplatesPath.'/InvalidName', 0755, true);
        File::put(
            $this->testTemplatesPath.'/InvalidName/template.json',
            json_encode(['identifier' => 'invalid'])
        );

        $scannedTemplates = $this->templateManager->scanTemplates();

        // 잘못된 형식의 템플릿은 스캔되지 않음
        $this->assertArrayNotHasKey('InvalidName', $scannedTemplates);
    }

    /**
     * 템플릿 JSON 데이터 로딩 테스트
     */
    public function test_loads_template_json_data_correctly(): void
    {
        $this->templateManager->loadTemplates();

        $template = $this->templateManager->getTemplate('test-admin');

        $this->assertIsArray($template);
        $this->assertEquals('test-admin', $template['identifier']);
        $this->assertEquals('test', $template['vendor']);
        // name과 description은 다국어 배열 구조
        $this->assertIsArray($template['name']);
        $this->assertEquals('Test Admin Template', $template['name']['ko']);
        $this->assertEquals('Test Admin Template', $template['name']['en']);
        $this->assertEquals('1.0.0', $template['version']);
        $this->assertEquals('admin', $template['type']);
        $this->assertArrayHasKey('_paths', $template);
    }

    /**
     * 템플릿 경로 정보 테스트
     */
    public function test_template_has_correct_path_information(): void
    {
        $this->templateManager->loadTemplates();

        $template = $this->templateManager->getTemplate('test-admin');

        $this->assertArrayHasKey('_paths', $template);
        $this->assertArrayHasKey('components_manifest', $template['_paths']);
        $this->assertArrayHasKey('routes', $template['_paths']);
        $this->assertArrayHasKey('components_bundle', $template['_paths']);
        $this->assertArrayHasKey('assets', $template['_paths']);
        $this->assertArrayHasKey('lang', $template['_paths']);
        $this->assertArrayHasKey('layouts', $template['_paths']);

        // 경로가 실제로 존재하는지 확인
        $this->assertFileExists($template['_paths']['components_manifest']);
        $this->assertFileExists($template['_paths']['routes']);
        $this->assertFileExists($template['_paths']['components_bundle']);
        $this->assertDirectoryExists($template['_paths']['assets']);
    }

    /**
     * components.json 파일 존재 및 구조 테스트
     */
    public function test_template_has_valid_components_manifest(): void
    {
        $this->templateManager->loadTemplates();

        $template = $this->templateManager->getTemplate('test-admin');
        $componentsPath = $template['_paths']['components_manifest'];

        $this->assertFileExists($componentsPath);

        $componentsData = json_decode(File::get($componentsPath), true);
        $this->assertIsArray($componentsData);
        $this->assertArrayHasKey('components', $componentsData);
        $this->assertArrayHasKey('Button', $componentsData['components']);
        $this->assertArrayHasKey('Input', $componentsData['components']);
    }

    /**
     * routes.json 파일 존재 및 구조 테스트
     */
    public function test_template_has_valid_routes_definition(): void
    {
        $this->templateManager->loadTemplates();

        $template = $this->templateManager->getTemplate('test-admin');
        $routesPath = $template['_paths']['routes'];

        $this->assertFileExists($routesPath);

        $routesData = json_decode(File::get($routesPath), true);
        $this->assertIsArray($routesData);
        $this->assertArrayHasKey('routes', $routesData);
        $this->assertCount(2, $routesData['routes']);
        $this->assertEquals('/dashboard', $routesData['routes'][0]['path']);
    }

    /**
     * 템플릿 메타데이터 테스트
     */
    public function test_template_has_metadata(): void
    {
        $this->templateManager->loadTemplates();

        $template = $this->templateManager->getTemplate('test-admin');

        $this->assertArrayHasKey('metadata', $template);
        $this->assertArrayHasKey('author', $template['metadata']);
        $this->assertArrayHasKey('license', $template['metadata']);
        $this->assertEquals('Test Author', $template['metadata']['author']);
        $this->assertEquals('MIT', $template['metadata']['license']);
    }

    /**
     * 테스트용 템플릿 구조 생성
     */
    protected function createTestTemplateStructure(): void
    {
        if (! File::exists($this->testTemplatesPath)) {
            File::makeDirectory($this->testTemplatesPath, 0755, true);
        }

        // test-admin 템플릿 생성
        $templatePath = $this->testTemplatesPath.'/test-admin';
        File::makeDirectory($templatePath, 0755, true);
        File::makeDirectory($templatePath.'/dist', 0755, true);
        File::makeDirectory($templatePath.'/assets', 0755, true);
        File::makeDirectory($templatePath.'/layouts', 0755, true);
        File::makeDirectory($templatePath.'/lang/ko', 0755, true);
        File::makeDirectory($templatePath.'/lang/en', 0755, true);

        // template.json 파일 생성
        File::put($templatePath.'/template.json', $this->getTestTemplateJsonContent());

        // components.json 생성
        File::put($templatePath.'/components.json', json_encode([
            'components' => [
                'Button' => 'dist/components.js',
                'Input' => 'dist/components.js',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // routes.json 생성
        File::put($templatePath.'/routes.json', json_encode([
            'routes' => [
                ['path' => '/dashboard', 'component' => 'Dashboard'],
                ['path' => '/settings', 'component' => 'Settings'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // 테스트 파일 생성 (실제 빌드 결과물 구조)
        File::put($templatePath.'/dist/components.iife.js', '// Test React components bundle');
        File::put($templatePath.'/dist/components.iife.js.map', '{"version":3,"sources":[]}');
        File::put($templatePath.'/assets/style.css', '/* Test styles */');

        // 에러 레이아웃 파일 생성
        File::makeDirectory($templatePath.'/layouts/errors', 0755, true);
        $errorLayoutContent = json_encode([
            'version' => '1.0.0',
            'layout_name' => 'error_template',
            'meta' => ['title' => 'Error', 'description' => 'Error page'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        File::put($templatePath.'/layouts/errors/error_404.json', $errorLayoutContent);
        File::put($templatePath.'/layouts/errors/error_403.json', $errorLayoutContent);
        File::put($templatePath.'/layouts/errors/error_500.json', $errorLayoutContent);
    }

    /**
     * 의존성이 있는 테스트 템플릿 생성
     */
    protected function createTestTemplateWithDependencies(array $dependencies): void
    {
        $templatePath = $this->testTemplatesPath.'/test-dependent';
        File::makeDirectory($templatePath, 0755, true);
        File::makeDirectory($templatePath.'/dist', 0755, true);
        File::makeDirectory($templatePath.'/layouts', 0755, true);

        $templateData = [
            'identifier' => 'test-dependent',
            'vendor' => 'test',
            'name' => [
                'ko' => 'Test Dependent Template',
                'en' => 'Test Dependent Template',
            ],
            'version' => '1.0.0',
            'type' => 'admin',
            'description' => [
                'ko' => 'Template with dependencies for testing',
                'en' => 'Template with dependencies for testing',
            ],
            'dependencies' => $dependencies,
            'error_config' => [
                'layouts' => [
                    '404' => 'error_404',
                    '403' => 'error_403',
                    '500' => 'error_500',
                ],
            ],
        ];

        File::put(
            $templatePath.'/template.json',
            json_encode($templateData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        // components.json 생성
        File::put($templatePath.'/components.json', json_encode([
            'components' => [],
        ], JSON_PRETTY_PRINT));

        // routes.json 생성
        File::put($templatePath.'/routes.json', json_encode([
            'routes' => [],
        ], JSON_PRETTY_PRINT));

        // 테스트 파일 생성 (실제 빌드 결과물 구조)
        File::put($templatePath.'/dist/components.iife.js', '// Test components');
        File::put($templatePath.'/dist/components.iife.js.map', '{"version":3,"sources":[]}');

        // 에러 레이아웃 파일 생성
        File::makeDirectory($templatePath.'/layouts/errors', 0755, true);
        $errorLayoutContent = json_encode([
            'version' => '1.0.0',
            'layout_name' => 'error_template',
            'meta' => ['title' => 'Error', 'description' => 'Error page'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        File::put($templatePath.'/layouts/errors/error_404.json', $errorLayoutContent);
        File::put($templatePath.'/layouts/errors/error_403.json', $errorLayoutContent);
        File::put($templatePath.'/layouts/errors/error_500.json', $errorLayoutContent);
    }

    /**
     * 테스트 템플릿 JSON 내용 생성
     */
    protected function getTestTemplateJsonContent(): string
    {
        $templateData = [
            'identifier' => 'test-admin',
            'vendor' => 'test',
            'name' => [
                'ko' => 'Test Admin Template',
                'en' => 'Test Admin Template',
            ],
            'version' => '1.0.0',
            'type' => 'admin',
            'description' => [
                'ko' => 'Test template for integration testing',
                'en' => 'Test template for integration testing',
            ],
            'github_url' => 'https://github.com/test/test-admin',
            'dependencies' => [
                'modules' => [],
                'plugins' => [],
            ],
            'metadata' => [
                'author' => 'Test Author',
                'license' => 'MIT',
            ],
            'error_config' => [
                'layouts' => [
                    '404' => 'error_404',
                    '403' => 'error_403',
                    '500' => 'error_500',
                ],
            ],
        ];

        return json_encode($templateData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * 테스트 템플릿 정리
     */
    protected function cleanupTestTemplates(): void
    {
        if (File::exists($this->testTemplatesPath.'/test-admin')) {
            File::deleteDirectory($this->testTemplatesPath.'/test-admin');
        }

        if (File::exists($this->testTemplatesPath.'/test-dependent')) {
            File::deleteDirectory($this->testTemplatesPath.'/test-dependent');
        }

        if (File::exists($this->testTemplatesPath.'/InvalidName')) {
            File::deleteDirectory($this->testTemplatesPath.'/InvalidName');
        }

        if (File::exists(public_path('build/template'))) {
            File::deleteDirectory(public_path('build/template'));
        }
    }
}
