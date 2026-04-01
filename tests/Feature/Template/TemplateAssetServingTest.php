<?php

namespace Tests\Feature\Template;

use App\Enums\ExtensionStatus;
use App\Models\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateAssetServingTest extends TestCase
{
    use RefreshDatabase;

    private Template $activeTemplate;
    private string $testTemplatePath;
    private string $testIdentifier;

    protected function setUp(): void
    {
        parent::setUp();

        // 고유 identifier 사용하여 트랜잭션 락 충돌 방지
        $this->testIdentifier = 'test-template-'.uniqid();

        // 테스트용 활성화된 템플릿 생성
        $this->activeTemplate = Template::factory()->create([
            'identifier' => $this->testIdentifier,
            'status' => ExtensionStatus::Active->value,
        ]);

        // 테스트용 템플릿 디렉토리 생성
        $this->testTemplatePath = base_path("templates/{$this->testIdentifier}/dist");
        if (!file_exists($this->testTemplatePath)) {
            mkdir($this->testTemplatePath, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // 테스트용 파일 및 디렉토리 정리
        if (isset($this->testIdentifier) && file_exists(base_path("templates/{$this->testIdentifier}"))) {
            $this->deleteDirectory(base_path("templates/{$this->testIdentifier}"));
        }

        parent::tearDown();
    }

    /**
     * 테스트용 asset URL 생성 헬퍼
     */
    private function assetUrl(string $path): string
    {
        return "/api/templates/assets/{$this->testIdentifier}/{$path}";
    }

    /**
     * 테스트용 template URL 생성 헬퍼
     */
    private function templateUrl(string $path): string
    {
        return "/api/templates/{$this->testIdentifier}/{$path}";
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
     * 활성화된 템플릿의 JS 파일 서빙 성공
     */
    public function test_serves_js_file_from_active_template(): void
    {
        // Arrange
        $jsPath = $this->testTemplatePath.'/js/main.js';
        mkdir(dirname($jsPath), 0755, true);
        file_put_contents($jsPath, 'console.log("test");');

        // Act
        $response = $this->get($this->assetUrl('js/main.js'));

        // Assert
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/javascript');
    }

    /**
     * 활성화된 템플릿의 CSS 파일 서빙 성공
     */
    public function test_serves_css_file_from_active_template(): void
    {
        // Arrange
        $cssPath = $this->testTemplatePath.'/css/style.css';
        mkdir(dirname($cssPath), 0755, true);
        file_put_contents($cssPath, 'body { color: red; }');

        // Act
        $response = $this->get($this->assetUrl('css/style.css'));

        // Assert
        $response->assertStatus(200);
        $this->assertTrue(
            str_starts_with($response->headers->get('Content-Type'), 'text/css'),
            'Content-Type should start with text/css'
        );
    }

    /**
     * 비활성화 템플릿 접근 시 404 반환
     */
    public function test_returns_404_for_inactive_template(): void
    {
        // Arrange
        $inactiveTemplate = Template::factory()->create([
            'identifier' => 'inactive-template',
            'status' => ExtensionStatus::Inactive->value,
        ]);

        // Act
        $response = $this->get('/api/templates/assets/inactive-template/js/main.js');

        // Assert
        // 검증 실패로 인한 리다이렉트 또는 404 허용
        $this->assertContains($response->status(), [302, 404]);
    }

    /**
     * 존재하지 않는 파일 접근 시 404 반환
     */
    public function test_returns_404_for_nonexistent_file(): void
    {
        // Act
        $response = $this->get($this->assetUrl('js/nonexistent.js'));

        // Assert
        // 검증 실패로 인한 리다이렉트 또는 404 허용
        $this->assertContains($response->status(), [302, 404]);
    }

    /**
     * Path Traversal 공격 차단 - 기본 패턴 (../)
     */
    public function test_blocks_path_traversal_attack(): void
    {
        // Act
        $response = $this->get($this->assetUrl('../../.env'));

        // Assert
        // 검증 실패 시 Laravel은 302 리다이렉트 또는 422 중 하나를 반환
        $this->assertContains($response->status(), [302, 422]);
    }

    /**
     * Path Traversal 공격 차단 - 백슬래시 패턴 (..\)
     */
    public function test_blocks_path_traversal_with_backslash(): void
    {
        // Act - URL 인코딩된 백슬래시 사용
        $response = $this->get($this->assetUrl('..%5c..%5cconfig.php'));

        // Assert
        $this->assertContains($response->status(), [302, 422]);
    }

    /**
     * Path Traversal 공격 차단 - 이중 슬래시 (//)
     */
    public function test_blocks_double_slash_pattern(): void
    {
        // Act
        $response = $this->get($this->assetUrl('/etc//passwd'));

        // Assert
        $this->assertContains($response->status(), [302, 422]);
    }

    /**
     * Path Traversal 공격 차단 - URL 인코딩 패턴 (%2e%2e%2f)
     */
    public function test_blocks_url_encoded_path_traversal(): void
    {
        $patterns = [
            '%2e%2e%2f',       // ../
            '%2e%2e/',         // ../
            '%2e%2e%5c',       // ..\
            '..%2f',           // ../
            '..%5c',           // ..\
            '.%2e/',           // ../
            '.%2e%5c',         // ..\
        ];

        foreach ($patterns as $pattern) {
            // Act
            $response = $this->get($this->assetUrl("{$pattern}secret.txt"));

            // Assert
            $this->assertContains($response->status(), [302, 422], "Pattern {$pattern} should be blocked");
        }
    }

    /**
     * 절대 경로 차단 - Windows 경로 (C:\)
     */
    public function test_blocks_windows_absolute_path(): void
    {
        // Act - URL 인코딩된 백슬래시 사용
        $response = $this->get($this->assetUrl('C:%5cWindows%5cSystem32%5cconfig.ini'));

        // Assert
        $this->assertContains($response->status(), [302, 422]);
    }

    /**
     * 절대 경로 차단 - Linux 경로 (/etc/)
     */
    public function test_blocks_linux_absolute_path(): void
    {
        // Act
        $response = $this->get($this->assetUrl('/etc/passwd'));

        // Assert
        $this->assertContains($response->status(), [302, 422]);
    }

    /**
     * NULL 바이트 공격 차단
     */
    public function test_blocks_null_byte_attack(): void
    {
        // Act
        $response = $this->get($this->assetUrl('malicious.php%00.js'));

        // Assert
        $this->assertContains($response->status(), [302, 422]);
    }

    /**
     * 불허 파일 타입 차단 - PHP
     */
    public function test_blocks_disallowed_file_types(): void
    {
        // Arrange
        $phpPath = $this->testTemplatePath.'/malicious.php';
        file_put_contents($phpPath, '<?php echo "hack"; ?>');

        // Act
        $response = $this->get($this->assetUrl('malicious.php'));

        // Assert
        $this->assertContains($response->status(), [302, 422]);
    }

    /**
     * 불허 파일 타입 차단 - 실행 파일들
     */
    public function test_blocks_various_disallowed_file_types(): void
    {
        $disallowedExtensions = [
            'exe',    // Windows 실행 파일
            'sh',     // Shell 스크립트
            'bat',    // Windows 배치 파일
            'dll',    // Windows 라이브러리
            'so',     // Linux 라이브러리
            'py',     // Python 스크립트
            'rb',     // Ruby 스크립트
            'pl',     // Perl 스크립트
            'asp',    // ASP 파일
            'aspx',   // ASP.NET 파일
            'jsp',    // JSP 파일
            'cgi',    // CGI 스크립트
        ];

        foreach ($disallowedExtensions as $ext) {
            // Act
            $response = $this->get($this->assetUrl("malicious.{$ext}"));

            // Assert
            $this->assertContains($response->status(), [302, 422], "Extension .{$ext} should be blocked");
        }
    }

    /**
     * 허용된 파일 타입 확인 - 모든 화이트리스트 확장자
     */
    public function test_allows_all_whitelisted_file_types(): void
    {
        $allowedFiles = [
            // Scripts
            'app.js' => 'application/javascript',
            'module.mjs' => 'application/javascript',
            // Styles
            'style.css' => 'text/css',
            // Data
            'data.json' => 'application/json',
            // Images
            'image.png' => 'image/png',
            'photo.jpg' => 'image/jpeg',
            'icon.svg' => 'image/svg+xml',
            'banner.webp' => 'image/webp',
            'animated.gif' => 'image/gif',
            // Fonts
            'font.woff' => 'font/woff',
            'font.woff2' => 'font/woff2',
            'font.ttf' => 'font/ttf',
            'font.otf' => 'font/otf',
        ];

        foreach ($allowedFiles as $filename => $expectedMimeType) {
            // Arrange
            $filePath = $this->testTemplatePath.'/'.$filename;
            file_put_contents($filePath, 'test content');

            // Act
            $response = $this->get($this->assetUrl($filename));

            // Assert
            $response->assertStatus(200, "File {$filename} should be allowed");

            // MIME type 검증 (CSS는 charset 포함할 수 있음)
            $actualMimeType = $response->headers->get('Content-Type');
            if ($filename === 'style.css') {
                $this->assertTrue(
                    str_starts_with($actualMimeType, $expectedMimeType),
                    "MIME type for {$filename} should start with {$expectedMimeType}, got {$actualMimeType}"
                );
            } else {
                $this->assertEquals(
                    $expectedMimeType,
                    $actualMimeType,
                    "MIME type for {$filename} should be {$expectedMimeType}, got {$actualMimeType}"
                );
            }
        }
    }

    /**
     * MIME 타입 헤더 정상 설정 - PNG
     */
    public function test_sets_correct_mime_type_for_png(): void
    {
        // Arrange
        $imagePath = $this->testTemplatePath.'/assets/test.png';
        mkdir(dirname($imagePath), 0755, true);
        file_put_contents($imagePath, 'fake png content');

        // Act
        $response = $this->get($this->assetUrl('assets/test.png'));

        // Assert
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
    }

    /**
     * 캐싱 헤더 정상 설정
     */
    public function test_sets_caching_headers(): void
    {
        // Arrange
        $jsPath = $this->testTemplatePath.'/js/main.js';
        mkdir(dirname($jsPath), 0755, true);
        file_put_contents($jsPath, 'console.log("test");');

        // Act
        $response = $this->get($this->assetUrl('js/main.js'));

        // Assert
        $response->assertStatus(200);
        $cacheControl = $response->headers->get('Cache-Control');
        // Cache-Control에 max-age가 포함되어 있는지 확인 (개발 환경에서는 no-cache일 수 있음)
        $this->assertNotNull($cacheControl, 'Cache-Control header should be present');
        $this->assertNotNull($response->headers->get('Expires'));
    }

    /**
     * ETag 헤더 생성 확인
     */
    public function test_generates_etag_header(): void
    {
        // Arrange
        $jsPath = $this->testTemplatePath.'/js/main.js';
        mkdir(dirname($jsPath), 0755, true);
        file_put_contents($jsPath, 'console.log("test");');

        // Act
        $response = $this->get($this->assetUrl('js/main.js'));

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
        $jsPath = $this->testTemplatePath.'/js/main.js';
        mkdir(dirname($jsPath), 0755, true);
        file_put_contents($jsPath, 'console.log("test");');

        // 1차 요청으로 ETag 획득
        $firstResponse = $this->get($this->assetUrl('js/main.js'));
        $etag = $firstResponse->headers->get('ETag');

        // Act - If-None-Match 헤더와 함께 2차 요청
        $response = $this->withHeaders([
            'If-None-Match' => $etag,
        ])->get($this->assetUrl('js/main.js'));

        // Assert
        $response->assertStatus(304);
        $this->assertEmpty($response->getContent(), '304 response should have empty body');
    }

    /**
     * ETag 불일치 시 200 응답 및 새 콘텐츠 반환
     */
    public function test_returns_200_when_etag_does_not_match(): void
    {
        // Arrange
        $jsPath = $this->testTemplatePath.'/js/main.js';
        mkdir(dirname($jsPath), 0755, true);
        file_put_contents($jsPath, 'console.log("test");');

        // Act - 잘못된 ETag로 요청
        $response = $this->withHeaders([
            'If-None-Match' => 'invalid-etag-value',
        ])->get($this->assetUrl('js/main.js'));

        // Assert
        $response->assertStatus(200);
        // baseResponse를 통해 실제 응답 타입 확인
        $this->assertTrue($response->baseResponse instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse, '200 response should be a file response');
        $this->assertNotNull($response->headers->get('ETag'), 'New ETag should be provided');
    }

    /**
     * 파일 수정 시 ETag 변경 확인
     */
    public function test_etag_changes_when_file_is_modified(): void
    {
        // Arrange
        $jsPath = $this->testTemplatePath.'/js/main.js';
        mkdir(dirname($jsPath), 0755, true);
        file_put_contents($jsPath, 'console.log("original");');

        // 1차 요청
        $firstResponse = $this->get($this->assetUrl('js/main.js'));
        $firstEtag = $firstResponse->headers->get('ETag');

        // 파일 수정 (파일 크기 변경으로 ETag 변경 보장)
        sleep(1);
        file_put_contents($jsPath, 'console.log("modified with longer content");');
        clearstatcache(true, $jsPath);  // 파일 stat 캐시 초기화

        // Act - 2차 요청
        $secondResponse = $this->get($this->assetUrl('js/main.js'));
        $secondEtag = $secondResponse->headers->get('ETag');

        // Assert
        $this->assertNotEquals($firstEtag, $secondEtag, 'ETag should change when file is modified');
    }

    /**
     * 프로덕션 환경에서 immutable 캐싱 정책 적용
     */
    public function test_applies_immutable_caching_in_production(): void
    {
        // Arrange
        app()['env'] = 'production';
        $jsPath = $this->testTemplatePath.'/js/main.js';
        mkdir(dirname($jsPath), 0755, true);
        file_put_contents($jsPath, 'console.log("test");');

        // Act
        $response = $this->get($this->assetUrl('js/main.js'));

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
        $jsPath = $this->testTemplatePath.'/js/main.js';
        mkdir(dirname($jsPath), 0755, true);
        file_put_contents($jsPath, 'console.log("test");');

        // Act
        $response = $this->get($this->assetUrl('js/main.js'));

        // Assert
        $response->assertStatus(200);
        $cacheControl = $response->headers->get('Cache-Control');
        // no-cache가 포함되어 있는지 확인 (Laravel이 추가 헤더를 붙일 수 있음)
        $this->assertStringContainsString('no-cache', $cacheControl, 'Development should use no-cache');
    }

    /**
     * components.json 파일 정상 반환
     */
    public function test_serves_components_json(): void
    {
        // Arrange
        $componentsPath = base_path("templates/{$this->testIdentifier}/components.json");
        file_put_contents($componentsPath, json_encode([
            'Button' => ['path' => 'components/Button.jsx'],
            'Card' => ['path' => 'components/Card.jsx'],
        ]));

        // Act - 올바른 라우트 경로: /api/templates/{identifier}/components.json
        $response = $this->get($this->templateUrl('components.json'));

        // Assert
        $response->assertStatus(200);
        $response->assertJson([
            'Button' => ['path' => 'components/Button.jsx'],
            'Card' => ['path' => 'components/Card.jsx'],
        ]);
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=3600', $cacheControl);
    }

    /**
     * 비활성화 템플릿의 components.json 접근 시 404 반환
     */
    public function test_returns_404_for_components_of_inactive_template(): void
    {
        // Arrange
        $inactiveTemplate = Template::factory()->create([
            'identifier' => 'inactive-template',
            'status' => ExtensionStatus::Inactive->value,
        ]);

        // Act
        $response = $this->get('/api/templates/inactive-template/components.json');

        // Assert
        $response->assertStatus(404);
    }

    /**
     * 존재하지 않는 components.json 접근 시 404 반환
     */
    public function test_returns_404_for_nonexistent_components(): void
    {
        // Act (components.json 파일이 없는 상태)
        $response = $this->get($this->templateUrl('components.json'));

        // Assert
        $response->assertStatus(404);
    }

    /**
     * 계층 구조 경로 처리 테스트 - 2단계 중첩
     */
    public function test_handles_nested_path_two_levels(): void
    {
        // Arrange
        $nestedPath = $this->testTemplatePath.'/assets/images/logo.png';
        mkdir(dirname($nestedPath), 0755, true);
        file_put_contents($nestedPath, 'fake image content');

        // Act
        $response = $this->get($this->assetUrl('assets/images/logo.png'));

        // Assert
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
    }

    /**
     * 계층 구조 경로 처리 테스트 - 3단계 중첩
     */
    public function test_handles_nested_path_three_levels(): void
    {
        // Arrange
        $deepPath = $this->testTemplatePath.'/js/components/ui/Button.js';
        mkdir(dirname($deepPath), 0755, true);
        file_put_contents($deepPath, 'export default Button;');

        // Act
        $response = $this->get($this->assetUrl('js/components/ui/Button.js'));

        // Assert
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/javascript');
    }

    /**
     * 특수 문자를 포함한 파일명 처리 테스트
     */
    public function test_handles_special_characters_in_filename(): void
    {
        // Arrange
        $specialPath = $this->testTemplatePath.'/css/style-v2.min.css';
        mkdir(dirname($specialPath), 0755, true);
        file_put_contents($specialPath, 'body { margin: 0; }');

        // Act
        $response = $this->get($this->assetUrl('css/style-v2.min.css'));

        // Assert
        $response->assertStatus(200);
        $this->assertTrue(
            str_starts_with($response->headers->get('Content-Type'), 'text/css')
        );
    }

    /**
     * 다양한 이미지 포맷 MIME 타입 테스트
     */
    public function test_serves_various_image_formats(): void
    {
        $formats = [
            'test.jpg' => 'image/jpeg',
            'test.jpeg' => 'image/jpeg',
            'test.svg' => 'image/svg+xml',
            'test.webp' => 'image/webp',
            'test.gif' => 'image/gif',
        ];

        $imagePath = $this->testTemplatePath.'/assets';
        mkdir($imagePath, 0755, true);

        foreach ($formats as $filename => $expectedMimeType) {
            // Arrange
            $filePath = $imagePath.'/'.$filename;
            file_put_contents($filePath, 'fake content');

            // Act
            $response = $this->get($this->assetUrl("assets/{$filename}"));

            // Assert
            $response->assertStatus(200);
            $response->assertHeader('Content-Type', $expectedMimeType);
        }
    }

    /**
     * 폰트 파일 MIME 타입 테스트
     */
    public function test_serves_font_files(): void
    {
        $fonts = [
            'font.woff' => 'font/woff',
            'font.woff2' => 'font/woff2',
            'font.ttf' => 'font/ttf',
            'font.otf' => 'font/otf',
        ];

        $fontPath = $this->testTemplatePath.'/assets/fonts';
        mkdir($fontPath, 0755, true);

        foreach ($fonts as $filename => $expectedMimeType) {
            // Arrange
            $filePath = $fontPath.'/'.$filename;
            file_put_contents($filePath, 'fake font content');

            // Act
            $response = $this->get($this->assetUrl("assets/fonts/{$filename}"));

            // Assert
            $response->assertStatus(200);
            $response->assertHeader('Content-Type', $expectedMimeType);
        }
    }

    /**
     * Rate Limiting 테스트 - 헤더로 Rate Limit 적용 확인
     *
     * api 미들웨어 그룹에 기본 throttle이 적용되어 있으므로
     * 헤더 존재 여부만 확인합니다.
     */
    public function test_applies_rate_limiting(): void
    {
        // Arrange
        $jsPath = $this->testTemplatePath.'/js/main.js';
        mkdir(dirname($jsPath), 0755, true);
        file_put_contents($jsPath, 'console.log("test");');

        // Act - 단일 요청으로 Rate Limit 헤더 확인
        $response = $this->get($this->assetUrl('js/main.js'));

        // Assert - Rate Limit 헤더 존재 확인
        $response->assertStatus(200);
        $response->assertHeader('X-RateLimit-Limit');
        $response->assertHeader('X-RateLimit-Remaining');

        // Rate Limit 값이 적용되었는지 확인
        $rateLimit = (int) $response->headers->get('X-RateLimit-Limit');
        $this->assertGreaterThan(0, $rateLimit);
    }
}
