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

    /**
     * 모듈 서비스를 꺼내고 테스트용 모듈의 dist/js 디렉토리를 만든다.
     *
     * @return void 반환값 없음
     */
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

    /**
     * 테스트용 모듈 디렉토리를 통째로 지운다.
     *
     * @return void 반환값 없음
     */
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
     *
     * @param  string  $dir  지울 디렉토리 또는 파일 경로
     * @return bool 삭제에 성공했거나 이미 없으면 true
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
     *
     * @scenario case=serve
     *
     * @effects module_asset_existing_file_resolves_to_its_path
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=mime
     *
     * @effects module_asset_mime_type_follows_the_extension
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=mime
     *
     * @effects module_asset_mime_type_follows_the_extension
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=mime
     *
     * @effects module_asset_mime_type_follows_the_extension
     *
     * @return void 반환값 없음
     */
    public function test_returns_correct_mime_type_for_json(): void
    {
        // Arrange
        Module::factory()->create([
            'identifier' => 'test-module',
            'status' => ExtensionStatus::Active->value,
        ]);

        $filePath = $this->testModulePath.'/dist/js/manifest.json';
        file_put_contents($filePath, '{"version":3,"sources":[]}');

        // Act
        $result = $this->moduleService->getAssetFilePath('test-module', 'dist/js/manifest.json');

        // Assert
        $this->assertTrue($result['success']);
        $this->assertEquals('application/json', $result['mimeType']);
    }

    /**
     * 소스맵(.map)은 로컬 환경 밖에서 **서빙되지 않는다**.
     *
     * 소스맵에는 원본 코드 전문(sourcesContent)이 담겨 있어 서빙 허용 자체를 로컬 환경으로
     * 한정했다(공개 #72). 그 한정을 소유하는 것은 확장자 화이트리스트
     * (`AllowedModuleFileType::getAllowedExtensions()`)이고, 서비스가 그 목록을 2차로
     * 확인한다.
     *
     * 종전에는 「MIME 표에 `map` 이 없다」로 이 조치를 고정했는데, 그 형태에는 사각이 있었다 —
     * 모듈 통로에는 확장자 2차 확인이 아예 없어 소스맵이 화이트리스트를 우회한 호출부에서
     * `application/octet-stream` 으로 **그대로 나갔고**, 단언은 「JSON 이 아니다」라 초록이었다.
     * 그래서 약한 축(MIME 표기)이 아니라 강한 축(서빙 거부)을 잰다. 되돌리면
     * (서비스에서 화이트리스트 2차 확인을 빼면) 이 단언이 red 다.
     *
     * 로컬 환경에서 표가 `application/json` 을 돌려주는 것은 의도다 — 그래야 devtools 가
     * 소스맵을 읽어 그 완화가 목적을 달성한다. 그 축은 세 통로 공유 trait 이 소유하고
     * `ExtensionAssetServiceContainmentParityTest` 가 고정한다.
     *
     * @scenario case=file_type
     *
     * @effects module_source_map_is_not_served_outside_local
     *
     * @return void 반환값 없음
     */
    public function test_source_map_is_not_served_outside_local(): void
    {
        // Arrange
        Module::factory()->create([
            'identifier' => 'test-module',
            'status' => ExtensionStatus::Active->value,
        ]);

        $filePath = $this->testModulePath.'/dist/js/module.iife.js.map';
        file_put_contents($filePath, '{"version":3,"sources":[]}');

        // 테스트 환경은 `local` 이 아니다 — 그 전제가 깨지면 이 단언의 의미가 바뀐다.
        $this->assertFalse($this->app->environment('local'), '테스트 환경이 local 이면 이 축은 반대 계약을 잰다');

        // Act
        $result = $this->moduleService->getAssetFilePath('test-module', 'dist/js/module.iife.js.map');

        // Assert
        $this->assertFalse($result['success']);
        $this->assertEquals('file_type_not_allowed', $result['error']);
        $this->assertNull($result['mimeType']);
    }

    /**
     * 이미지 MIME 타입 테스트 (PNG)
     *
     * @scenario case=mime
     *
     * @effects module_asset_mime_type_follows_the_extension
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=mime
     *
     * @effects module_asset_mime_type_follows_the_extension
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=missing
     *
     * @effects module_asset_returns_module_not_found_for_missing_or_inactive_module
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=missing
     *
     * @effects module_asset_returns_module_not_found_for_missing_or_inactive_module
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=missing
     *
     * @effects module_asset_returns_file_not_found_for_missing_file
     *
     * @return void 반환값 없음
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
