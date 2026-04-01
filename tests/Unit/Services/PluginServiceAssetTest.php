<?php

namespace Tests\Unit\Services;

use App\Enums\ExtensionStatus;
use App\Models\Plugin;
use App\Services\PluginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PluginService::getAssetFilePath() 단위 테스트
 *
 * 플러그인 에셋 파일 경로 조회 및 MIME 타입 결정을 테스트합니다.
 */
class PluginServiceAssetTest extends TestCase
{
    use RefreshDatabase;

    private PluginService $pluginService;

    private string $testPluginPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pluginService = app(PluginService::class);
        $this->testPluginPath = base_path('plugins/test-plugin');

        // 테스트용 디렉토리 생성
        if (! file_exists($this->testPluginPath.'/dist/js')) {
            mkdir($this->testPluginPath.'/dist/js', 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // 테스트용 파일 및 디렉토리 정리
        if (file_exists($this->testPluginPath)) {
            $this->deleteDirectory($this->testPluginPath);
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
        Plugin::factory()->create([
            'identifier' => 'test-plugin',
            'status' => ExtensionStatus::Active->value,
        ]);

        $filePath = $this->testPluginPath.'/dist/js/plugin.iife.js';
        file_put_contents($filePath, 'console.log("test plugin");');

        // Act
        $result = $this->pluginService->getAssetFilePath('test-plugin', 'dist/js/plugin.iife.js');

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
        Plugin::factory()->create([
            'identifier' => 'test-plugin',
            'status' => ExtensionStatus::Active->value,
        ]);

        $filePath = $this->testPluginPath.'/dist/js/app.js';
        file_put_contents($filePath, 'console.log("test");');

        // Act
        $result = $this->pluginService->getAssetFilePath('test-plugin', 'dist/js/app.js');

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
        Plugin::factory()->create([
            'identifier' => 'test-plugin',
            'status' => ExtensionStatus::Active->value,
        ]);

        $cssPath = $this->testPluginPath.'/dist/css';
        if (! file_exists($cssPath)) {
            mkdir($cssPath, 0755, true);
        }
        $filePath = $cssPath.'/plugin.css';
        file_put_contents($filePath, '.plugin { color: green; }');

        // Act
        $result = $this->pluginService->getAssetFilePath('test-plugin', 'dist/css/plugin.css');

        // Assert
        $this->assertTrue($result['success']);
        $this->assertTrue(str_starts_with($result['mimeType'], 'text/css'));
    }

    /**
     * 존재하지 않는 플러그인 오류 테스트
     */
    public function test_returns_error_for_nonexistent_plugin(): void
    {
        // Act
        $result = $this->pluginService->getAssetFilePath('nonexistent-plugin', 'dist/js/plugin.iife.js');

        // Assert
        $this->assertFalse($result['success']);
        $this->assertEquals('plugin_not_found', $result['error']);
    }

    /**
     * 비활성 플러그인 오류 테스트
     */
    public function test_returns_error_for_inactive_plugin(): void
    {
        // Arrange
        Plugin::factory()->create([
            'identifier' => 'inactive-plugin',
            'status' => ExtensionStatus::Inactive->value,
        ]);

        // Act
        $result = $this->pluginService->getAssetFilePath('inactive-plugin', 'dist/js/plugin.iife.js');

        // Assert
        $this->assertFalse($result['success']);
        $this->assertEquals('plugin_not_found', $result['error']);
    }

    /**
     * 존재하지 않는 파일 오류 테스트
     */
    public function test_returns_error_for_nonexistent_file(): void
    {
        // Arrange
        Plugin::factory()->create([
            'identifier' => 'test-plugin',
            'status' => ExtensionStatus::Active->value,
        ]);

        // Act
        $result = $this->pluginService->getAssetFilePath('test-plugin', 'dist/js/nonexistent.js');

        // Assert
        $this->assertFalse($result['success']);
        $this->assertEquals('file_not_found', $result['error']);
    }
}
