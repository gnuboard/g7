<?php

namespace Tests\Feature\Plugin;

use App\Enums\ExtensionStatus;
use App\Models\Plugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PluginAssetServingTest extends TestCase
{
    use RefreshDatabase;

    private Plugin $activePlugin;
    private string $testPluginPath;

    protected function setUp(): void
    {
        parent::setUp();

        // 테스트용 활성화된 플러그인 생성
        $this->activePlugin = Plugin::factory()->create([
            'identifier' => 'test-plugin',
            'status' => ExtensionStatus::Active->value,
        ]);

        // 테스트용 플러그인 디렉토리 생성
        $this->testPluginPath = base_path('plugins/test-plugin');
        if (!file_exists($this->testPluginPath.'/dist/js')) {
            mkdir($this->testPluginPath.'/dist/js', 0755, true);
        }
        if (!file_exists($this->testPluginPath.'/dist/css')) {
            mkdir($this->testPluginPath.'/dist/css', 0755, true);
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
     * 디렉토리 재귀 삭제 헬퍼 메서드
     */
    private function deleteDirectory(string $dir): bool
    {
        if (!file_exists($dir)) {
            return true;
        }

        if (!is_dir($dir)) {
            return unlink($dir);
        }

        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }

            if (!$this->deleteDirectory($dir.DIRECTORY_SEPARATOR.$item)) {
                return false;
            }
        }

        return rmdir($dir);
    }

    /**
     * 활성화된 플러그인의 JS 파일 서빙 성공
     */
    public function test_serves_js_file_from_active_plugin(): void
    {
        // Arrange
        $jsPath = $this->testPluginPath.'/dist/js/plugin.iife.js';
        file_put_contents($jsPath, 'console.log("test plugin");');

        // Act
        $response = $this->get('/api/plugins/assets/test-plugin/dist/js/plugin.iife.js');

        // Assert
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/javascript');
    }

    /**
     * 활성화된 플러그인의 CSS 파일 서빙 성공
     */
    public function test_serves_css_file_from_active_plugin(): void
    {
        // Arrange
        $cssPath = $this->testPluginPath.'/dist/css/plugin.css';
        file_put_contents($cssPath, '.plugin { color: green; }');

        // Act
        $response = $this->get('/api/plugins/assets/test-plugin/dist/css/plugin.css');

        // Assert
        $response->assertStatus(200);
        $this->assertTrue(
            str_starts_with($response->headers->get('Content-Type'), 'text/css'),
            'Content-Type should start with text/css'
        );
    }

    /**
     * 비활성화 플러그인 접근 시 404 반환
     */
    public function test_returns_404_for_inactive_plugin(): void
    {
        // Arrange
        Plugin::factory()->create([
            'identifier' => 'inactive-plugin',
            'status' => ExtensionStatus::Inactive->value,
        ]);

        // Act
        $response = $this->get('/api/plugins/assets/inactive-plugin/dist/js/plugin.iife.js');

        // Assert
        $this->assertContains($response->status(), [302, 404]);
    }

    /**
     * 존재하지 않는 파일 접근 시 404 반환
     */
    public function test_returns_404_for_nonexistent_file(): void
    {
        // Act
        $response = $this->get('/api/plugins/assets/test-plugin/dist/js/nonexistent.js');

        // Assert
        $this->assertContains($response->status(), [302, 404]);
    }

    /**
     * Path Traversal 공격 차단 - 기본 패턴 (../)
     */
    public function test_blocks_path_traversal_attack(): void
    {
        // Act
        $response = $this->get('/api/plugins/assets/test-plugin/../../.env');

        // Assert
        $this->assertContains($response->status(), [302, 422]);
    }

    /**
     * Path Traversal 공격 차단 - 백슬래시 패턴 (..\)
     */
    public function test_blocks_path_traversal_with_backslash(): void
    {
        // Act
        $response = $this->get('/api/plugins/assets/test-plugin/..%5c..%5cconfig.php');

        // Assert
        $this->assertContains($response->status(), [302, 422]);
    }

    /**
     * Path Traversal 공격 차단 - URL 인코딩 패턴
     */
    public function test_blocks_url_encoded_path_traversal(): void
    {
        $patterns = [
            '%2e%2e%2f',
            '%2e%2e/',
            '%2e%2e%5c',
            '..%2f',
            '..%5c',
            '.%2e/',
        ];

        foreach ($patterns as $pattern) {
            $response = $this->get("/api/plugins/assets/test-plugin/{$pattern}secret.txt");
            $this->assertContains($response->status(), [302, 422], "Pattern {$pattern} should be blocked");
        }
    }

    /**
     * 절대 경로 차단 - Windows 경로
     */
    public function test_blocks_windows_absolute_path(): void
    {
        // Act
        $response = $this->get('/api/plugins/assets/test-plugin/C:%5cWindows%5cSystem32%5cconfig.ini');

        // Assert
        $this->assertContains($response->status(), [302, 422]);
    }

    /**
     * 절대 경로 차단 - Linux 경로
     */
    public function test_blocks_linux_absolute_path(): void
    {
        // Act
        $response = $this->get('/api/plugins/assets/test-plugin//etc/passwd');

        // Assert
        $this->assertContains($response->status(), [302, 422]);
    }

    /**
     * NULL 바이트 공격 차단
     */
    public function test_blocks_null_byte_attack(): void
    {
        // Act
        $response = $this->get('/api/plugins/assets/test-plugin/malicious.php%00.js');

        // Assert
        $this->assertContains($response->status(), [302, 422]);
    }

    /**
     * 불허 파일 타입 차단 - PHP
     */
    public function test_blocks_disallowed_file_types(): void
    {
        // Arrange
        $phpPath = $this->testPluginPath.'/malicious.php';
        file_put_contents($phpPath, '<?php echo "hack"; ?>');

        // Act
        $response = $this->get('/api/plugins/assets/test-plugin/malicious.php');

        // Assert
        $this->assertContains($response->status(), [302, 422]);
    }

    /**
     * 불허 파일 타입 차단 - 실행 파일들
     */
    public function test_blocks_various_disallowed_file_types(): void
    {
        $disallowedExtensions = ['exe', 'sh', 'bat', 'dll', 'so', 'py', 'rb', 'pl', 'asp', 'aspx', 'jsp', 'cgi'];

        foreach ($disallowedExtensions as $ext) {
            $response = $this->get("/api/plugins/assets/test-plugin/malicious.{$ext}");
            $this->assertContains($response->status(), [302, 422], "Extension .{$ext} should be blocked");
        }
    }

    /**
     * 허용된 파일 타입 확인
     */
    public function test_allows_all_whitelisted_file_types(): void
    {
        $allowedFiles = [
            'app.js' => 'application/javascript',
            'style.css' => 'text/css',
            'data.json' => 'application/json',
            'image.png' => 'image/png',
            'photo.jpg' => 'image/jpeg',
            'icon.svg' => 'image/svg+xml',
            'font.woff' => 'font/woff',
            'font.woff2' => 'font/woff2',
        ];

        foreach ($allowedFiles as $filename => $expectedMimeType) {
            $filePath = $this->testPluginPath.'/dist/'.$filename;
            file_put_contents($filePath, 'test content');

            $response = $this->get("/api/plugins/assets/test-plugin/dist/{$filename}");
            $response->assertStatus(200, "File {$filename} should be allowed");

            $actualMimeType = $response->headers->get('Content-Type');
            if ($filename === 'style.css') {
                $this->assertTrue(
                    str_starts_with($actualMimeType, $expectedMimeType),
                    "MIME type for {$filename} should start with {$expectedMimeType}"
                );
            } else {
                $this->assertEquals(
                    $expectedMimeType,
                    $actualMimeType,
                    "MIME type for {$filename} should be {$expectedMimeType}"
                );
            }
        }
    }

    /**
     * 캐싱 헤더 정상 설정
     */
    public function test_sets_caching_headers(): void
    {
        // Arrange
        $jsPath = $this->testPluginPath.'/dist/js/plugin.iife.js';
        file_put_contents($jsPath, 'console.log("test");');

        // Act
        $response = $this->get('/api/plugins/assets/test-plugin/dist/js/plugin.iife.js');

        // Assert
        $response->assertStatus(200);
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertNotNull($cacheControl, 'Cache-Control header should be present');
    }

    /**
     * ETag 헤더 생성 확인
     */
    public function test_generates_etag_header(): void
    {
        // Arrange
        $jsPath = $this->testPluginPath.'/dist/js/plugin.iife.js';
        file_put_contents($jsPath, 'console.log("test");');

        // Act
        $response = $this->get('/api/plugins/assets/test-plugin/dist/js/plugin.iife.js');

        // Assert
        $response->assertStatus(200);
        $etag = $response->headers->get('ETag');
        $this->assertNotNull($etag, 'ETag header should be present');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $etag, 'ETag should be a 32-character MD5 hash');
    }

    /**
     * ETag 매칭 시 304 Not Modified 응답
     */
    public function test_returns_304_when_etag_matches(): void
    {
        // Arrange
        $jsPath = $this->testPluginPath.'/dist/js/plugin.iife.js';
        file_put_contents($jsPath, 'console.log("test");');

        $firstResponse = $this->get('/api/plugins/assets/test-plugin/dist/js/plugin.iife.js');
        $etag = $firstResponse->headers->get('ETag');

        // Act
        $response = $this->withHeaders([
            'If-None-Match' => $etag,
        ])->get('/api/plugins/assets/test-plugin/dist/js/plugin.iife.js');

        // Assert
        $response->assertStatus(304);
        $this->assertEmpty($response->getContent(), '304 response should have empty body');
    }

    /**
     * 프로덕션 환경에서 immutable 캐싱 정책 적용
     */
    public function test_applies_immutable_caching_in_production(): void
    {
        // Arrange
        app()['env'] = 'production';
        $jsPath = $this->testPluginPath.'/dist/js/plugin.iife.js';
        file_put_contents($jsPath, 'console.log("test");');

        // Act
        $response = $this->get('/api/plugins/assets/test-plugin/dist/js/plugin.iife.js');

        // Assert
        $response->assertStatus(200);
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('immutable', $cacheControl, 'Production should use immutable caching');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=31536000', $cacheControl);
    }

    /**
     * 개발 환경에서 no-cache 정책 적용
     */
    public function test_applies_no_cache_in_development(): void
    {
        // Arrange
        app()['env'] = 'local';
        $jsPath = $this->testPluginPath.'/dist/js/plugin.iife.js';
        file_put_contents($jsPath, 'console.log("test");');

        // Act
        $response = $this->get('/api/plugins/assets/test-plugin/dist/js/plugin.iife.js');

        // Assert
        $response->assertStatus(200);
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-cache', $cacheControl, 'Development should use no-cache');
    }

    /**
     * 플러그인 라우트 접근 가능 테스트
     */
    public function test_plugin_route_is_accessible(): void
    {
        // Arrange
        $jsPath = $this->testPluginPath.'/dist/js/plugin.iife.js';
        file_put_contents($jsPath, 'console.log("test");');

        // Act
        $response = $this->get('/api/plugins/assets/test-plugin/dist/js/plugin.iife.js');

        // Assert
        $response->assertStatus(200);
    }

    /**
     * 중첩 경로 처리 테스트
     */
    public function test_handles_nested_path(): void
    {
        // Arrange
        $nestedPath = $this->testPluginPath.'/dist/js/components/Button.js';
        mkdir(dirname($nestedPath), 0755, true);
        file_put_contents($nestedPath, 'export default Button;');

        // Act
        $response = $this->get('/api/plugins/assets/test-plugin/dist/js/components/Button.js');

        // Assert
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/javascript');
    }

    /**
     * Source Map 파일 서빙 테스트
     */
    public function test_serves_sourcemap_file(): void
    {
        // Arrange
        $mapPath = $this->testPluginPath.'/dist/js/plugin.iife.js.map';
        file_put_contents($mapPath, '{"version":3,"sources":[],"mappings":""}');

        // Act
        $response = $this->get('/api/plugins/assets/test-plugin/dist/js/plugin.iife.js.map');

        // Assert
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/json');
    }
}
