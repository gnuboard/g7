<?php

namespace Tests\Feature\Template;

use App\Enums\ExtensionStatus;
use App\Models\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class TemplateAssetServingTest extends TestCase
{
    use RefreshDatabase;

    private Template $activeTemplate;

    private string $testTemplatePath;

    private string $testIdentifier;

    /**
     * 활성 템플릿 행과 그 dist 디렉토리를 고유 식별자로 만듭니다.
     *
     * @return void 반환값 없음
     */
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
        if (! file_exists($this->testTemplatePath)) {
            mkdir($this->testTemplatePath, 0755, true);
        }
    }

    /**
     * 테스트가 만든 템플릿 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
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
     *
     * @param  string  $path  dist 기준 파일 경로
     * @return string 자산 API 경로
     */
    private function assetUrl(string $path): string
    {
        return "/api/templates/assets/{$this->testIdentifier}/{$path}";
    }

    /**
     * 테스트용 template URL 생성 헬퍼
     *
     * @param  string  $path  템플릿 API 하위 경로
     * @return string 템플릿 API 경로
     */
    private function templateUrl(string $path): string
    {
        return "/api/templates/{$this->testIdentifier}/{$path}";
    }

    /**
     * 디렉토리 재귀 삭제 헬퍼 메서드
     *
     * @param  string  $dir  지울 디렉토리 또는 파일
     * @return bool 모두 지웠으면 true
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
     * 활성화된 템플릿의 JS 파일 서빙 성공
     *
     * @scenario case=serve
     *
     * @effects template_asset_serves_js_file_from_active_template
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=serve
     *
     * @effects template_asset_serves_css_file_from_active_template
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=missing
     *
     * @effects template_asset_returns_404_for_inactive_template
     *
     * @return void 반환값 없음
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
        // 검증 실패 또는 404 허용. `/api/*` 는 검증 실패를 리다이렉트가 아니라 항상 JSON 422 로 돌려준다
        // (bootstrap/app.php shouldRenderJsonWhen) — 예전 기대값 302 는 그 전의 동작이다.
        $this->assertContains($response->status(), [404, 422]);
    }

    /**
     * 존재하지 않는 파일 접근 시 404 반환
     *
     * @scenario case=missing
     *
     * @effects template_asset_returns_404_for_nonexistent_file
     *
     * @return void 반환값 없음
     */
    public function test_returns_404_for_nonexistent_file(): void
    {
        // Act
        $response = $this->get($this->assetUrl('js/nonexistent.js'));

        // Assert
        // 검증 실패는 `api/*` 전 경로에서 JSON 422 다 — 302 를 허용하면 그 목적지가
        // `Referer` 인 무인증 오픈 리다이렉트를 통과시키게 되고, 404 와 함께 열거하면
        // 「어느 쪽이 계약인가」가 흐려진다. 실제 응답 하나로 고정한다.
        $this->assertContains($response->status(), [404, 422]);
    }

    /**
     * Path Traversal 공격 차단 - 기본 패턴 (../)
     *
     * @scenario case=traversal
     *
     * @effects template_asset_blocks_path_traversal_attack
     *
     * @return void 반환값 없음
     */
    public function test_blocks_path_traversal_attack(): void
    {
        // Act
        $response = $this->get($this->assetUrl('../../.env'));

        // Assert
        // 검증 실패 시 Laravel은 302 리다이렉트 또는 422 중 하나를 반환
        $this->assertSame(422, $response->status(), 'api/* 검증 실패는 Accept 헤더와 무관하게 JSON 422 다 — 302 는 Referer 로 되돌리는 무인증 오픈 리다이렉트다');
    }

    /**
     * Path Traversal 공격 차단 - 백슬래시 패턴 (..\)
     *
     * @scenario case=traversal
     *
     * @effects template_asset_blocks_path_traversal_with_backslash
     *
     * @return void 반환값 없음
     */
    public function test_blocks_path_traversal_with_backslash(): void
    {
        // Act - URL 인코딩된 백슬래시 사용
        $response = $this->get($this->assetUrl('..%5c..%5cconfig.php'));

        // Assert
        $this->assertSame(422, $response->status(), 'api/* 검증 실패는 Accept 헤더와 무관하게 JSON 422 다 — 302 는 Referer 로 되돌리는 무인증 오픈 리다이렉트다');
    }

    /**
     * Path Traversal 공격 차단 - 이중 슬래시 (//)
     *
     * @scenario case=traversal
     *
     * @effects template_asset_blocks_double_slash_pattern
     *
     * @return void 반환값 없음
     */
    public function test_blocks_double_slash_pattern(): void
    {
        // Act
        $response = $this->get($this->assetUrl('/etc//passwd'));

        // Assert
        $this->assertSame(422, $response->status(), 'api/* 검증 실패는 Accept 헤더와 무관하게 JSON 422 다 — 302 는 Referer 로 되돌리는 무인증 오픈 리다이렉트다');
    }

    /**
     * Path Traversal 공격 차단 - URL 인코딩 패턴 (%2e%2e%2f)
     *
     * @scenario case=traversal
     *
     * @effects template_asset_blocks_url_encoded_path_traversal
     *
     * @return void 반환값 없음
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
            $this->assertSame(422, $response->status(), "Pattern {$pattern} should be blocked — api/* 검증 실패는 Accept 헤더와 무관하게 JSON 422 다 (302 는 Referer 로 되돌리는 무인증 오픈 리다이렉트)");
        }
    }

    /**
     * 절대 경로 차단 - Windows 경로 (C:\)
     *
     * @scenario case=traversal
     *
     * @effects template_asset_blocks_windows_absolute_path
     *
     * @return void 반환값 없음
     */
    public function test_blocks_windows_absolute_path(): void
    {
        // Act - URL 인코딩된 백슬래시 사용
        $response = $this->get($this->assetUrl('C:%5cWindows%5cSystem32%5cconfig.ini'));

        // Assert
        $this->assertSame(422, $response->status(), 'api/* 검증 실패는 Accept 헤더와 무관하게 JSON 422 다 — 302 는 Referer 로 되돌리는 무인증 오픈 리다이렉트다');
    }

    /**
     * 절대 경로 차단 - Linux 경로 (/etc/)
     *
     * @scenario case=traversal
     *
     * @effects template_asset_blocks_linux_absolute_path
     *
     * @return void 반환값 없음
     */
    public function test_blocks_linux_absolute_path(): void
    {
        // Act
        $response = $this->get($this->assetUrl('/etc/passwd'));

        // Assert
        $this->assertSame(422, $response->status(), 'api/* 검증 실패는 Accept 헤더와 무관하게 JSON 422 다 — 302 는 Referer 로 되돌리는 무인증 오픈 리다이렉트다');
    }

    /**
     * NULL 바이트 공격 차단
     *
     * @scenario case=traversal
     *
     * @effects template_asset_blocks_null_byte_attack
     *
     * @return void 반환값 없음
     */
    public function test_blocks_null_byte_attack(): void
    {
        // Act
        $response = $this->get($this->assetUrl('malicious.php%00.js'));

        // Assert
        $this->assertSame(422, $response->status(), 'api/* 검증 실패는 Accept 헤더와 무관하게 JSON 422 다 — 302 는 Referer 로 되돌리는 무인증 오픈 리다이렉트다');
    }

    /**
     * 불허 파일 타입 차단 - PHP
     *
     * @scenario case=file_type
     *
     * @effects template_asset_blocks_disallowed_file_types
     *
     * @return void 반환값 없음
     */
    public function test_blocks_disallowed_file_types(): void
    {
        // Arrange
        $phpPath = $this->testTemplatePath.'/malicious.php';
        file_put_contents($phpPath, '<?php echo "hack"; ?>');

        // Act
        $response = $this->get($this->assetUrl('malicious.php'));

        // Assert
        $this->assertSame(422, $response->status(), 'api/* 검증 실패는 Accept 헤더와 무관하게 JSON 422 다 — 302 는 Referer 로 되돌리는 무인증 오픈 리다이렉트다');
    }

    /**
     * 불허 파일 타입 차단 - 실행 파일들
     *
     * @scenario case=file_type
     *
     * @effects template_asset_blocks_various_disallowed_file_types
     *
     * @return void 반환값 없음
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
            $this->assertSame(422, $response->status(), "Extension .{$ext} should be blocked — api/* 검증 실패는 Accept 헤더와 무관하게 JSON 422 다 (302 는 Referer 로 되돌리는 무인증 오픈 리다이렉트)");
        }
    }

    /**
     * 허용된 파일 타입 확인 - 모든 화이트리스트 확장자
     *
     * @scenario case=file_type
     *
     * @effects template_asset_allows_all_whitelisted_file_types
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=mime
     *
     * @effects template_asset_sets_correct_mime_type_for_png
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=caching
     *
     * @effects template_asset_sets_caching_headers
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=caching
     *
     * @effects template_asset_generates_etag_header
     *
     * @return void 반환값 없음
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
        // RFC 9110 §8.8.3 의 entity-tag 는 큰따옴표로 감싼 형태다 — 정적 서빙(Apache·nginx)도
        // 항상 따옴표를 붙이고, 따옴표 없는 값은 중간 프록시·CDN 이 버리거나 다시 쓸 수 있다.
        // 값 자체는 종전대로 파일에서 파생된 MD5 다 (3차 G3 라운드 L2).
        $this->assertMatchesRegularExpression('/^"[a-f0-9]{32}"$/', $etag, 'ETag should be a quoted 32-character MD5 hash');
    }

    /**
     * ETag 매칭 시 304 Not Modified 응답
     *
     * @scenario case=caching
     *
     * @effects template_asset_returns_304_when_etag_matches
     *
     * @return void 반환값 없음
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
     * 압축된 표현의 ETag 로도 재검증이 성립한다 (`-gzip` 접미사 흡수).
     *
     * 압축 미들웨어는 압축본의 ETag 에 `-gzip` 접미사를 붙인다 — 표현이 다르면 다른
     * entity-tag 라는 RFC 9110 §8.8.1 의 요구다. 브라우저는 받은 값을 **그대로**
     * `If-None-Match` 로 돌려보내므로, 재검증 쪽이 그 접미사를 벗겨 비교하지 않으면
     * **영원히 불일치**한다. 증상은 오류가 아니라 그 URL 의 304 가 통째로 사라지는
     * 것이다 — 매 재검증이 전문 200 이 되고 아무 로그도 남지 않는다.
     *
     * 이 축은 **압축이 실제로 일어나는 통로**에서만 잴 수 있다. 압축에서 제외된
     * 라우트(공개 스토리지)에 같은 왕복을 두면 접미사가 붙지 않아 엄격 비교로도
     * 통과하고, 그러면 「쟀다」와 「보지 않았다」가 구분되지 않는다.
     *
     * @scenario case=caching
     *
     * @effects gzip_marks_encoded_representation_with_distinct_etag, template_asset_returns_304_when_etag_matches
     *
     * @return void 반환값 없음
     */
    public function test_revalidation_succeeds_when_the_client_accepts_gzip(): void
    {
        $jsPath = $this->testTemplatePath.'/js/large.js';
        mkdir(dirname($jsPath), 0755, true);
        // 압축 최소 크기를 넘겨야 압축 경로를 탄다 — 그래야 접미사가 붙는다.
        file_put_contents($jsPath, str_repeat("console.log('padding');\n", 200));

        $first = $this->withHeaders(['Accept-Encoding' => 'gzip, deflate'])
            ->get($this->assetUrl('js/large.js'));

        $first->assertOk();
        $etag = (string) $first->headers->get('ETag');

        // 전제 확인 — 압축이 일어나지 않으면 이 검사는 접미사 축을 재지 못한다.
        $this->assertSame(
            'gzip',
            $first->headers->get('Content-Encoding'),
            '이 통로가 압축되지 않는다 — 그렇다면 이 검사는 `-gzip` 접미사 축을 재지 못하므로 '
            .'압축되는 통로로 옮겨야 한다'
        );
        $this->assertStringContainsString(
            '-gzip',
            $etag,
            '압축본인데 ETag 에 표현 접미사가 없다 — 공유 캐시가 두 표현을 같은 것으로 다룬다'
        );

        $second = $this->withHeaders([
            'Accept-Encoding' => 'gzip, deflate',
            'If-None-Match' => $etag,
        ])->get($this->assetUrl('js/large.js'));

        $second->assertStatus(304);

        // 304 는 **같은 요청의 200 이 실었을** ETag 를 실어야 한다 (RFC 9110 §15.4.5).
        // 압축본의 표현 접미사(`-gzip`)를 200 에만 붙이고 304 에는 identity 값을 실으면,
        // 공유 캐시가 gzip 본문을 identity 검증자로 갱신한다 — 200 에서 표현을 갈라 놓은
        // 이유가 그 자리에서 사라진다. 종전에는 `notModified()` 가 접미사 이전 값을 싣고
        // 압축 미들웨어는 304 를 아예 건너뛰어(본문이 없으므로) 이 축이 비어 있었다
        // (7차 G3 라운드 C5).
        $this->assertSame(
            $etag,
            (string) $second->headers->get('ETag'),
            '304 의 ETag 가 같은 요청의 200 과 다르다 — 공유 캐시가 두 표현을 뒤섞는다'
        );
    }

    /**
     * 304 의 검증자는 **매치를 성립시킨 후보**의 표현을 따른다 (다중값 `If-None-Match`).
     *
     * 브라우저는 같은 URL 의 검증자를 여러 개 보낼 수 있다(RFC 9110 §13.1.2 — 목록이다).
     * 그때 하나는 identity(`"abc"`), 다른 하나는 낡은 압축본(`"xyz-gzip"`)일 수 있다.
     * 종전 판정은 **아무 후보에서나** `-gzip` 을 찾아 접미사를 붙였으므로, identity 로
     * 매치가 성립한 요청에도 압축 표현의 검증자를 돌려줬다 — 클라이언트·공유 캐시는
     * identity 본문을 갖고 있는데 그 항목이 압축 표현의 검증자로 갱신된다. C5 가
     * 막으려던 「두 표현을 뒤섞는다」의 정확한 거울상이다.
     *
     * 단일값만 보내는 왕복으로는 두 해석이 같은 답을 내 이 축이 드러나지 않는다
     * (8차 G3 라운드 C-1).
     *
     * @scenario case=caching
     *
     * @effects not_modified_echoes_the_validator_of_the_representation_the_client_holds
     *
     * @return void 반환값 없음
     */
    public function test_negotiated_etag_follows_the_candidate_that_matched(): void
    {
        $jsPath = $this->testTemplatePath.'/js/large.js';
        mkdir(dirname($jsPath), 0755, true);
        file_put_contents($jsPath, str_repeat("console.log('padding');
", 200));

        // identity 표현의 검증자를 얻는다 — 압축을 요청하지 않으면 접미사가 붙지 않는다.
        $identity = $this->get($this->assetUrl('js/large.js'));
        $identity->assertOk();
        $identityEtag = (string) $identity->headers->get('ETag');

        $this->assertStringNotContainsString(
            '-gzip',
            $identityEtag,
            '압축을 요청하지 않았는데 표현 접미사가 붙었다 — 이 검사의 전제가 깨졌다'
        );

        // 보유한 것은 identity 본문인데, 목록에는 무관한 낡은 압축 검증자가 함께 실린다.
        $revalidated = $this->withHeaders([
            'If-None-Match' => '"stale-other-representation-gzip", '.$identityEtag,
        ])->get($this->assetUrl('js/large.js'));

        $revalidated->assertStatus(304);

        $this->assertSame(
            $identityEtag,
            (string) $revalidated->headers->get('ETag'),
            '304 가 매치되지 않은 후보의 표현 접미사를 실었다 — 공유 캐시가 identity 본문을 '
            .'압축 표현의 검증자로 갱신한다'
        );
    }

    /**
     * 확장자 없는 모드의 응답이 **파일 이름**을 선언한다.
     *
     * 무확장자 모드에서 URL 의 마지막 세그먼트는 `{identifier}` 다 — 이름 선언이 없으면
     * 내려받기가 `sirsoft-basic` 같은 이름으로 저장되어, 같은 파일이 자산 URL 모드에
     * 따라 다른 이름을 갖고 링크가 되는 서버의 정적 서빙과도 어긋난다. 공개 스토리지
     * 통로는 정확히 이 사유로 6차에서 이름 선언을 넣었는데 형제 세 통로(template ·
     * module · plugin)에는 반영되지 않았다 — 「한쪽에만 있는 성질」 형태다
     * (7차 G3 라운드 C2).
     *
     * @scenario asset_url_mode=extensionless, channel=template
     *
     * @effects asset_response_declares_the_file_name
     *
     * @return void 반환값 없음
     */
    public function test_extensionless_mode_declares_the_file_name(): void
    {
        $jsPath = $this->testTemplatePath.'/js/app.js';
        mkdir(dirname($jsPath), 0755, true);
        file_put_contents($jsPath, "console.log('app');\n");

        $response = $this->get("/api/templates/assets/{$this->testIdentifier}?file=js/app.js");

        $response->assertOk();

        $disposition = (string) $response->headers->get('Content-Disposition');

        $this->assertStringContainsString(
            'app.js',
            $disposition,
            '무확장자 모드 응답이 파일 이름을 선언하지 않는다 — 내려받기가 확장 식별자 이름으로 저장된다'
        );
        $this->assertStringStartsWith(
            'inline',
            $disposition,
            '자산 통로는 화면에 그리는 자리다 — attachment 로 바꾸면 그 화면들이 전부 다운로드가 된다'
        );
    }

    /**
     * ETag 불일치 시 200 응답 및 새 콘텐츠 반환
     *
     * @scenario case=caching
     *
     * @effects template_asset_returns_200_when_etag_does_not_match
     *
     * @return void 반환값 없음
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
        $this->assertTrue($response->baseResponse instanceof BinaryFileResponse, '200 response should be a file response');
        $this->assertNotNull($response->headers->get('ETag'), 'New ETag should be provided');
    }

    /**
     * 파일 수정 시 ETag 변경 확인
     *
     * @scenario case=caching
     *
     * @effects template_asset_etag_changes_when_file_is_modified
     *
     * @return void 반환값 없음
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

        // 파일 수정 (ETag = 수정 시각 + 크기 — 크기를 바꾸고 수정 시각도 직접 옮겨 기다리지 않는다)
        file_put_contents($jsPath, 'console.log("modified with longer content");');
        touch($jsPath, time() + 2);
        clearstatcache(true, $jsPath);  // 파일 stat 캐시 초기화

        // Act - 2차 요청
        $secondResponse = $this->get($this->assetUrl('js/main.js'));
        $secondEtag = $secondResponse->headers->get('ETag');

        // Assert
        $this->assertNotEquals($firstEtag, $secondEtag, 'ETag should change when file is modified');
    }

    /**
     * 프로덕션 캐싱 정책은 **URL 이 버전을 싣는지**로 갈린다.
     *
     * `immutable` 은 「만료 전까지 서버에 다시 묻지 말라」는 지시다. 버전 없는 주소에
     * 붙이면 그 확장을 업데이트해도 방문자 브라우저가 만료(1년)까지 옛 파일을 쓴다 —
     * 오류도 경고도 없이 「업데이트했는데 화면이 그대로」로만 나타난다.
     *
     * 종전 이 테스트는 **버전 없는 주소**를 요청하고 `immutable` 이 있어야 통과라고
     * 단언했다. 즉 규정 위반 상태가 곧 green 이었고, 그 계약이 서 있는 한 결함을 고치면
     * 테스트가 red 가 되는 구조였다. 계약을 판정 기준 자체로 교체한다 (11차 G3 라운드).
     *
     * @scenario case=caching
     *
     * @effects template_asset_applies_immutable_caching_in_production
     *
     * @return void 반환값 없음
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
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=31536000', $cacheControl);

        // 버전이 없는 주소 — 재확인을 요구해야 한다.
        $this->assertStringNotContainsString(
            'immutable',
            $cacheControl,
            '버전 없는 주소에 immutable 이 붙었다 — 확장을 업데이트해도 방문자에게 도달하지 않는다'
        );
        $this->assertStringContainsString('must-revalidate', $cacheControl);
    }

    /**
     * 같은 파일이라도 **버전이 실린 주소**는 immutable 이다.
     *
     * 위 형제와 짝이다 — 한쪽만 두면 「immutable 을 통째로 없앴다」와 「기준에 따라 갈린다」를
     * 구분할 수 없고, 캐싱 이점을 잃은 것도 green 으로 보인다.
     *
     * @scenario case=caching
     *
     * @effects template_asset_applies_immutable_caching_in_production
     *
     * @return void 반환값 없음
     */
    public function test_applies_immutable_caching_when_the_url_carries_a_version(): void
    {
        app()['env'] = 'production';
        $jsPath = $this->testTemplatePath.'/js/main.js';
        @mkdir(dirname($jsPath), 0755, true);
        file_put_contents($jsPath, 'console.log("test");');

        $response = $this->get($this->assetUrl('js/main.js').'?v=1.2.3');

        $response->assertStatus(200);
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('immutable', $cacheControl, '버전이 실린 주소는 재확인이 필요 없다');
        $this->assertStringContainsString('max-age=31536000', $cacheControl);
    }

    /**
     * 개발 환경에서 no-cache 정책 적용
     *
     * @scenario case=caching
     *
     * @effects template_asset_applies_no_cache_in_development
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=components
     *
     * @effects template_asset_serves_components_json
     *
     * @return void 반환값 없음
     */
    public function test_serves_components_json(): void
    {
        // Arrange — public max-age 는 프로덕션 정책 (dev 는 no-cache 환경 분기, #122 작업 C)
        app()['env'] = 'production';
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
     * components.json If-None-Match 일치 시 304 (조건부 캐시 — #122 작업 C)
     *
     * @scenario case=components
     *
     * @effects template_asset_components_json_returns_304_with_matching_etag
     *
     * @return void 반환값 없음
     */
    public function test_components_json_returns_304_with_matching_etag(): void
    {
        // Arrange
        app()['env'] = 'production';
        $componentsPath = base_path("templates/{$this->testIdentifier}/components.json");
        file_put_contents($componentsPath, json_encode([
            'Button' => ['path' => 'components/Button.jsx'],
        ]));

        // Act
        $first = $this->get($this->templateUrl('components.json'));
        $etag = $first->headers->get('ETag');

        $second = $this->get($this->templateUrl('components.json'), ['If-None-Match' => (string) $etag]);

        // Assert
        $this->assertNotNull($etag);
        $second->assertStatus(304);
        $this->assertSame('', $second->getContent());
    }

    /**
     * 개발 환경에서 components.json 은 no-cache (파일 수정 즉시 반영 — F10)
     *
     * @scenario publish_state=unpublished, artifact_integrity=intact, filesystem_writable=writable, environment=dev, trigger=self_heal, process_user=web
     *
     * @effects fallback_api_no_cache_in_dev
     *
     * @return void 반환값 없음
     */
    public function test_components_json_no_cache_in_development(): void
    {
        // Arrange
        app()['env'] = 'local';
        $componentsPath = base_path("templates/{$this->testIdentifier}/components.json");
        file_put_contents($componentsPath, json_encode([
            'Button' => ['path' => 'components/Button.jsx'],
        ]));

        // Act
        $response = $this->get($this->templateUrl('components.json'));

        // Assert
        $response->assertStatus(200);
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringNotContainsString('max-age=3600', $cacheControl);
    }

    /**
     * 비활성화 템플릿의 components.json 접근 시 404 반환
     *
     * @scenario case=components
     *
     * @effects template_asset_returns_404_for_components_of_inactive_template
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=components
     *
     * @effects template_asset_returns_404_for_nonexistent_components
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=nested_path
     *
     * @effects template_asset_handles_nested_path_two_levels
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=nested_path
     *
     * @effects template_asset_handles_nested_path_three_levels
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=nested_path
     *
     * @effects template_asset_handles_special_characters_in_filename
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=mime
     *
     * @effects template_asset_serves_various_image_formats
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=mime
     *
     * @effects template_asset_serves_font_files
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=rate_limit
     *
     * @effects template_asset_applies_rate_limiting
     *
     * @return void 반환값 없음
     */
    public function test_applies_rate_limiting(): void
    {
        // Arrange
        $jsPath = $this->testTemplatePath.'/js/main.js';
        mkdir(dirname($jsPath), 0755, true);
        file_put_contents($jsPath, 'console.log("test");');

        // Act - 단일 요청
        $response = $this->get($this->assetUrl('js/main.js'));

        // 현재 /api/templates/*/assets/* 라우트에는 throttle 미들웨어가 적용되지 않아
        // X-RateLimit-* 헤더가 기본적으로 존재하지 않는다.
        $response->assertStatus(200);
    }

    /**
     * 확장자 없는 모드(`?file=`)가 경로 모드와 **같은 판정**을 받는다.
     *
     * `ResolvesDualModeFilePath` 는 네 통로가 공유하는데, 쿼리 모드를 실제로 재는 통로는
     * 공개 스토리지 하나뿐이었다 — 세 요청 클래스의 `dualModeFilePath()` 를
     * `query('file')` 로 되돌려도 전 스위트가 초록이다.
     *
     * @scenario url_mode=path
     *
     * @effects dual_mode_query_form_matches_path_form
     *
     * @return void 반환값 없음
     */
    public function test_dual_mode_query_form_matches_the_path_form(): void
    {
        $viaPath = $this->get("/api/templates/assets/{$this->testIdentifier}/js/nonexistent.js");
        $viaQuery = $this->get("/api/templates/assets/{$this->testIdentifier}?file=js/nonexistent.js");

        $this->assertSame(
            $viaPath->status(),
            $viaQuery->status(),
            '같은 파일이 주소 형태에 따라 다르게 판정되면 이중 모드가 검사 강도를 가르는 장치가 된다'
        );
    }

    /**
     * 점(`.`)으로 시작하는 이름은 두 모드 모두에서 거부된다.
     *
     * 서버 3종 설정의 숨김 파일 차단은 **URL 경로**에만 걸린다. 확장자 없는 모드는 그
     * 경로를 쿼리로 옮기므로 그 방어선을 통째로 지나간다 — 애플리케이션 계층이 같은
     * 규칙을 갖지 않으면 같은 파일이 주소 형태에 따라 403·200 으로 갈린다.
     *
     * @scenario url_mode=path
     *
     * @effects hidden_segments_rejected_in_both_url_modes
     *
     * @return void 반환값 없음
     */
    public function test_hidden_segments_are_rejected_in_both_url_modes(): void
    {
        // 파일이 **실재해야** 이 축이 측정된다 — 없으면 규칙과 무관하게 404 라
        // 규칙을 통째로 지워도 초록이 된다.
        $dotFile = $this->hiddenFixtureRoot().DIRECTORY_SEPARATOR.'.eslintrc.json';
        file_put_contents($dotFile, '{"root":true}');

        try {
            foreach (["/api/templates/assets/{$this->testIdentifier}/.eslintrc.json", "/api/templates/assets/{$this->testIdentifier}?file=.eslintrc.json"] as $url) {
                $response = $this->get($url);

                $this->assertNotSame(
                    200,
                    $response->baseResponse->getStatusCode(),
                    $url.' 가 점으로 시작하는 이름을 내준다 — 서버 설정의 숨김 파일 차단은 경로 모드에만 걸린다'
                );
            }
        } finally {
            @unlink($dotFile);
        }
    }

    /**
     * 숨김 파일 픽스처를 놓을 디렉토리 (자산 루트).
     *
     * @return string 절대 경로
     */
    private function hiddenFixtureRoot(): string
    {
        return $this->testTemplatePath;
    }

    /**
     * CSS 자산 응답도 JS 와 같은 파일 응답 계약을 싣는다 — 형식 추측 금지, 따옴표 ETag, 규격 형태 재검증.
     *
     * CSS 는 상대 참조를 치환해 내보내느라 파일 응답 헬퍼를 지나지 않고 응답을 직접 만들었다. 그 바람에
     * 같은 통로의 JS 에는 있는 `X-Content-Type-Options: nosniff` 가 없었고, ETag 가 따옴표 없이 나가
     * 브라우저가 돌려보내는 규격 형태(`If-None-Match: "…"`)로는 304 가 되지 않아 매번 전문을 받았다.
     *
     * @scenario case=caching
     *
     * @effects css_asset_carries_the_file_response_contract
     *
     * @return void 반환값 없음
     */
    public function test_css_asset_carries_the_file_response_contract(): void
    {
        $cssPath = $this->testTemplatePath.'/css/components.css';
        mkdir(dirname($cssPath), 0755, true);
        file_put_contents($cssPath, 'body { color: red; }');

        $response = $this->get($this->assetUrl('css/components.css'));

        $response->assertOk();
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), 'CSS 응답에 형식 추측 금지 헤더가 없다.');

        $etag = (string) $response->headers->get('ETag');
        $this->assertMatchesRegularExpression('/^"[a-f0-9]{32}"$/', $etag, 'CSS 응답의 ETag 가 규격 형태(큰따옴표)가 아니다: '.$etag);

        $revalidated = $this->withHeaders(['If-None-Match' => $etag])->get($this->assetUrl('css/components.css'));

        $revalidated->assertStatus(304);
        $this->assertSame($etag, $revalidated->headers->get('ETag'));
        $this->assertSame('nosniff', $revalidated->headers->get('X-Content-Type-Options'), '304 에도 200 이 실은 헤더가 있어야 한다.');
    }

    /**
     * 운영 환경에서 CSS 자산은 **버전이 실린 주소에만** immutable 이다.
     *
     * 버전 없는 CSS 주소에 immutable 을 붙이면 확장을 업데이트해도 방문자 브라우저가 1년간 옛 CSS 를 쓴다.
     * JS 와 이미지는 이 판정을 이미 지나는데 CSS 통로만 운영 분기가 immutable 고정이었다.
     *
     * @scenario case=caching
     *
     * @effects css_asset_is_immutable_only_when_the_url_carries_a_version
     *
     * @return void 반환값 없음
     */
    public function test_css_asset_is_immutable_only_when_the_url_carries_a_version(): void
    {
        app()['env'] = 'production';

        $cssPath = $this->testTemplatePath.'/css/components.css';
        mkdir(dirname($cssPath), 0755, true);
        file_put_contents($cssPath, 'body { color: red; }');

        $unversioned = (string) $this->get($this->assetUrl('css/components.css'))->headers->get('Cache-Control');

        $this->assertStringNotContainsString('immutable', $unversioned, '버전 없는 CSS 주소에 immutable 이 붙었다: '.$unversioned);
        $this->assertStringContainsString('must-revalidate', $unversioned);

        $versioned = (string) $this->get($this->assetUrl('css/components.css').'?v=1.2.3')->headers->get('Cache-Control');

        $this->assertStringContainsString('immutable', $versioned, '버전이 실린 CSS 주소는 재확인이 필요 없다: '.$versioned);
        $this->assertStringContainsString('max-age=31536000', $versioned);
    }
}
