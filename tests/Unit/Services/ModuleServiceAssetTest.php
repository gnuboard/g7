<?php

namespace Tests\Unit\Services;

use App\Enums\ExtensionStatus;
use App\Models\Module;
use App\Services\ModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ModuleService::getAssetFilePath() 단위 테스트
 *
 * 모듈 에셋 파일 경로 조회 및 MIME 타입 결정을 테스트합니다.
 */
class ModuleServiceAssetTest extends TestCase
{
    use RefreshDatabase;

    private ModuleService $moduleService;

    private string $testModulePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moduleService = app(ModuleService::class);
        $this->testModulePath = base_path('modules/test-module');

        // 테스트용 디렉토리 생성
        if (! file_exists($this->testModulePath.'/dist/js')) {
            mkdir($this->testModulePath.'/dist/js', 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // 테스트용 파일 및 디렉토리 정리
        if (file_exists($this->testModulePath)) {
            $this->deleteDirectory($this->testModulePath);
        }
        parent::tearDown();
    }

    /**
     * 디렉토리 재귀 삭제 헬퍼
     */
    private function deleteDirectory(string $dir): bool
    {
        if (! file_exists($dir)) {
            return true;
        }

        if (! is_dir($dir)) {
            return unlink($dir);
        }

        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }

            if (! $this->deleteDirectory($dir.DIRECTORY_SEPARATOR.$item)) {
                return false;
            }
        }

        return rmdir($dir);
    }

    /**
     * 존재하는 파일 경로 반환 성공 테스트
     */
    public function test_returns_success_for_existing_file(): void
    {
        // Arrange
        Module::factory()->create([
            'identifier' => 'test-module',
            'status' => ExtensionStatus::Active->value,
        ]);

        $filePath = $this->testModulePath.'/dist/js/module.iife.js';
        file_put_contents($filePath, 'console.log("test");');

        // Act
        $result = $this->moduleService->getAssetFilePath('test-module', 'dist/js/module.iife.js');

        // Assert
        $this->assertTrue($result['success']);
        $this->assertEquals($filePath, $result['filePath']);
        $this->assertEquals('application/javascript', $result['mimeType']);
    }

    /**
     * JS 파일 MIME 타입 테스트
     */
    public function test_returns_correct_mime_type_for_js(): void
    {
        // Arrange
        Module::factory()->create([
            'identifier' => 'test-module',
            'status' => ExtensionStatus::Active->value,
        ]);

        $filePath = $this->testModulePath.'/dist/js/app.js';
        file_put_contents($filePath, 'console.log("test");');

        // Act
        $result = $this->moduleService->getAssetFilePath('test-module', 'dist/js/app.js');

        // Assert
        $this->assertTrue($result['success']);
        $this->assertEquals('application/javascript', $result['mimeType']);
    }

    /**
     * CSS 파일 MIME 타입 테스트
     */
    public function test_returns_correct_mime_type_for_css(): void
    {
        // Arrange
        Module::factory()->create([
            'identifier' => 'test-module',
            'status' => ExtensionStatus::Active->value,
        ]);

        $cssPath = $this->testModulePath.'/dist/css';
        if (! file_exists($cssPath)) {
            mkdir($cssPath, 0755, true);
        }
        $filePath = $cssPath.'/module.css';
        file_put_contents($filePath, '.module { color: blue; }');

        // Act
        $result = $this->moduleService->getAssetFilePath('test-module', 'dist/css/module.css');

        // Assert
        $this->assertTrue($result['success']);
        $this->assertTrue(str_starts_with($result['mimeType'], 'text/css'));
    }

    /**
     * JSON 파일 MIME 타입 테스트
     */
    public function test_returns_correct_mime_type_for_json(): void
    {
        // Arrange
        Module::factory()->create([
            'identifier' => 'test-module',
            'status' => ExtensionStatus::Active->value,
        ]);

        $filePath = $this->testModulePath.'/dist/js/module.iife.js.map';
        file_put_contents($filePath, '{"version":3,"sources":[]}');

        // Act
        $result = $this->moduleService->getAssetFilePath('test-module', 'dist/js/module.iife.js.map');

        // Assert
        $this->assertTrue($result['success']);
        $this->assertEquals('application/json', $result['mimeType']);
    }

    /**
     * 이미지 MIME 타입 테스트 (PNG)
     */
    public function test_returns_correct_mime_type_for_images(): void
    {
        // Arrange
        Module::factory()->create([
            'identifier' => 'test-module',
            'status' => ExtensionStatus::Active->value,
        ]);

        $imagesPath = $this->testModulePath.'/dist/images';
        if (! file_exists($imagesPath)) {
            mkdir($imagesPath, 0755, true);
        }
        $filePath = $imagesPath.'/logo.png';
        // 최소 PNG 헤더
        file_put_contents($filePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

        // Act
        $result = $this->moduleService->getAssetFilePath('test-module', 'dist/images/logo.png');

        // Assert
        $this->assertTrue($result['success']);
        $this->assertEquals('image/png', $result['mimeType']);
    }

    /**
     * 폰트 MIME 타입 테스트 (WOFF2)
     */
    public function test_returns_correct_mime_type_for_fonts(): void
    {
        // Arrange
        Module::factory()->create([
            'identifier' => 'test-module',
            'status' => ExtensionStatus::Active->value,
        ]);

        $fontsPath = $this->testModulePath.'/dist/fonts';
        if (! file_exists($fontsPath)) {
            mkdir($fontsPath, 0755, true);
        }
        $filePath = $fontsPath.'/roboto.woff2';
        file_put_contents($filePath, 'wOF2');  // WOFF2 magic bytes

        // Act
        $result = $this->moduleService->getAssetFilePath('test-module', 'dist/fonts/roboto.woff2');

        // Assert
        $this->assertTrue($result['success']);
        $this->assertEquals('font/woff2', $result['mimeType']);
    }

    /**
     * 존재하지 않는 모듈 오류 테스트
     */
    public function test_returns_error_for_nonexistent_module(): void
    {
        // Act
        $result = $this->moduleService->getAssetFilePath('nonexistent-module', 'dist/js/module.iife.js');

        // Assert
        $this->assertFalse($result['success']);
        $this->assertEquals('module_not_found', $result['error']);
    }

    /**
     * 비활성 모듈 오류 테스트
     */
    public function test_returns_error_for_inactive_module(): void
    {
        // Arrange
        Module::factory()->create([
            'identifier' => 'inactive-module',
            'status' => ExtensionStatus::Inactive->value,
        ]);

        // Act
        $result = $this->moduleService->getAssetFilePath('inactive-module', 'dist/js/module.iife.js');

        // Assert
        $this->assertFalse($result['success']);
        $this->assertEquals('module_not_found', $result['error']);
    }

    /**
     * 존재하지 않는 파일 오류 테스트
     */
    public function test_returns_error_for_nonexistent_file(): void
    {
        // Arrange
        Module::factory()->create([
            'identifier' => 'test-module',
            'status' => ExtensionStatus::Active->value,
        ]);

        // Act
        $result = $this->moduleService->getAssetFilePath('test-module', 'dist/js/nonexistent.js');

        // Assert
        $this->assertFalse($result['success']);
        $this->assertEquals('file_not_found', $result['error']);
    }
}
