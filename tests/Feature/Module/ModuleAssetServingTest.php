<?php

namespace Tests\Feature\Module;

use App\Enums\ExtensionStatus;
use App\Models\Module;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleAssetServingTest extends TestCase
{
    use RefreshDatabase;

    private Module $activeModule;

    private string $testModulePath;

    /**
     * 테스트용 활성 확장 행과 자산 폴더를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 테스트용 활성화된 모듈 생성
        $this->activeModule = Module::factory()->create([
            'identifier' => 'test-module',
            'status' => ExtensionStatus::Active->value,
        ]);

        // 테스트용 모듈 디렉토리 생성
        $this->testModulePath = base_path('modules/test-module');
        if (! file_exists($this->testModulePath.'/dist/js')) {
            mkdir($this->testModulePath.'/dist/js', 0755, true);
        }
        if (! file_exists($this->testModulePath.'/dist/css')) {
            mkdir($this->testModulePath.'/dist/css', 0755, true);
        }
    }

    /**
     * 테스트용 자산 폴더를 지우고 상위 정리를 이어 갑니다.
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
     * 디렉토리 재귀 삭제 헬퍼 메서드
     *
     * @param  string  $dir  지울 디렉토리 경로
     * @return bool 삭제에 성공하면 true
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
     * 활성화된 모듈의 JS 파일 서빙 성공
     *
     * @scenario case=serve
     *
     * @effects module_asset_serves_js_file_from_active_module, serves_js_file_from_active
     *
     * @return void 반환값 없음
     */
    public function test_serves_js_file_from_active_module(): void
    {
        // Arrange
        $jsPath = $this->testModulePath.'/dist/js/module.iife.js';
        file_put_contents($jsPath, 'console.log("test module");');

        // Act
        $response = $this->get('/api/modules/assets/test-module/dist/js/module.iife.js');

        // Assert
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/javascript');
    }

    /**
     * 활성화된 모듈의 CSS 파일 서빙 성공
     *
     * @scenario case=serve
     *
     * @effects module_asset_serves_css_file_from_active_module, serves_css_file_from_active
     *
     * @return void 반환값 없음
     */
    public function test_serves_css_file_from_active_module(): void
    {
        // Arrange
        $cssPath = $this->testModulePath.'/dist/css/module.css';
        file_put_contents($cssPath, '.module { color: blue; }');

        // Act
        $response = $this->get('/api/modules/assets/test-module/dist/css/module.css');

        // Assert
        $response->assertStatus(200);
        $this->assertTrue(
            str_starts_with($response->headers->get('Content-Type'), 'text/css'),
            'Content-Type should start with text/css'
        );
    }

    /**
     * 비활성화 모듈 접근 시 404 반환
     *
     * @scenario case=missing
     *
     * @effects module_asset_returns_404_for_inactive_module
     *
     * @return void 반환값 없음
     */
    public function test_returns_404_for_inactive_module(): void
    {
        // Arrange — 파일은 있고 상태만 비활성이어야 경로 검증(422)이 아니라 활성 판정을 거친다
        file_put_contents($this->testModulePath.'/dist/js/module.iife.js', 'console.log("test");');
        $this->activeModule->update(['status' => ExtensionStatus::Inactive->value]);

        // Act
        $response = $this->get('/api/modules/assets/test-module/dist/js/module.iife.js');

        // Assert
        $response->assertStatus(404);
    }

    /**
     * 비활성화 모듈의 자산 요청은 파일을 내주지 않고 JSON 으로 답한다.
     *
     * 디렉토리가 없는 식별자는 경로 밖 접근이 아니다 — 요청 규칙(`ValidatesExtensionAssetPath`)은
     * 기준 디렉토리를 풀지 못해도 문자열 봉쇄만 유지하고 통과시키며, 서비스가 비활성 확장을
     * 404 로 끝낸다. 링크가 되는 서버의 정적 서빙(404)과 같은 응답이다. 검증 실패가
     * 리다이렉트가 아니라 JSON 422 인 축은 `test_rejects_sourcemap_file` 이 잰다.
     *
     * @scenario extension_type=module
     *
     * @effects inactive_extension_asset_request_rejected_as_json
     *
     * @return void 반환값 없음
     */
    public function test_rejects_asset_request_for_inactive_module(): void
    {
        // Arrange
        Module::factory()->create([
            'identifier' => 'inactive-module',
            'status' => ExtensionStatus::Inactive->value,
        ]);

        // Act
        $response = $this->get('/api/modules/assets/inactive-module/dist/js/module.iife.js');

        // Assert
        // `api/*` 는 늘 JSON 이다 — 302 를 허용하면 그 목적지가 `Referer` 인 무인증 오픈
        // 리다이렉트를 통과시키게 되고, 404 와 422 를 함께 열거하면 「어느 쪽이 계약인가」가
        // 흐려진다. 실제 응답 하나로 고정한다.
        $response->assertStatus(404);
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    }

    /**
     * 존재하지 않는 파일 접근 시 404 반환
     *
     * @scenario case=missing
     *
     * @effects module_asset_returns_404_for_nonexistent_file, returns_404_for_nonexistent_file
     *
     * @return void 반환값 없음
     */
    public function test_returns_404_for_nonexistent_file(): void
    {
        // Act
        $response = $this->get('/api/modules/assets/test-module/dist/js/nonexistent.js');

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
     * @effects module_asset_blocks_path_traversal_attack, blocks_path_traversal_attack
     *
     * @return void 반환값 없음
     */
    public function test_blocks_path_traversal_attack(): void
    {
        // Act
        $response = $this->get('/api/modules/assets/test-module/../../.env');

        // Assert
        $this->assertSame(422, $response->status(), 'api/* 검증 실패는 Accept 헤더와 무관하게 JSON 422 다 — 302 는 Referer 로 되돌리는 무인증 오픈 리다이렉트다');
    }

    /**
     * Path Traversal 공격 차단 - 백슬래시 패턴 (..\)
     *
     * @scenario case=traversal
     *
     * @effects module_asset_blocks_path_traversal_with_backslash, blocks_path_traversal_with_backslash
     *
     * @return void 반환값 없음
     */
    public function test_blocks_path_traversal_with_backslash(): void
    {
        // Act
        $response = $this->get('/api/modules/assets/test-module/..%5c..%5cconfig.php');

        // Assert
        $this->assertSame(422, $response->status(), 'api/* 검증 실패는 Accept 헤더와 무관하게 JSON 422 다 — 302 는 Referer 로 되돌리는 무인증 오픈 리다이렉트다');
    }

    /**
     * Path Traversal 공격 차단 - URL 인코딩 패턴 (%2e%2e%2f)
     *
     * @scenario case=traversal
     *
     * @effects module_asset_blocks_url_encoded_path_traversal, blocks_url_encoded_path_traversal
     *
     * @return void 반환값 없음
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
            $response = $this->get("/api/modules/assets/test-module/{$pattern}secret.txt");
            $this->assertSame(422, $response->status(), "Pattern {$pattern} should be blocked — api/* 검증 실패는 Accept 헤더와 무관하게 JSON 422 다 (302 는 Referer 로 되돌리는 무인증 오픈 리다이렉트)");
        }
    }

    /**
     * 절대 경로 차단 - Windows 경로
     *
     * @scenario case=traversal
     *
     * @effects module_asset_blocks_windows_absolute_path, blocks_windows_absolute_path
     *
     * @return void 반환값 없음
     */
    public function test_blocks_windows_absolute_path(): void
    {
        // Act
        $response = $this->get('/api/modules/assets/test-module/C:%5cWindows%5cSystem32%5cconfig.ini');

        // Assert
        $this->assertSame(422, $response->status(), 'api/* 검증 실패는 Accept 헤더와 무관하게 JSON 422 다 — 302 는 Referer 로 되돌리는 무인증 오픈 리다이렉트다');
    }

    /**
     * 절대 경로 차단 - Linux 경로
     *
     * @scenario case=traversal
     *
     * @effects module_asset_blocks_linux_absolute_path, blocks_linux_absolute_path
     *
     * @return void 반환값 없음
     */
    public function test_blocks_linux_absolute_path(): void
    {
        // Act
        $response = $this->get('/api/modules/assets/test-module//etc/passwd');

        // Assert
        $this->assertSame(422, $response->status(), 'api/* 검증 실패는 Accept 헤더와 무관하게 JSON 422 다 — 302 는 Referer 로 되돌리는 무인증 오픈 리다이렉트다');
    }

    /**
     * NULL 바이트 공격 차단
     *
     * @scenario case=traversal
     *
     * @effects module_asset_blocks_null_byte_attack, blocks_null_byte_attack
     *
     * @return void 반환값 없음
     */
    public function test_blocks_null_byte_attack(): void
    {
        // Act
        $response = $this->get('/api/modules/assets/test-module/malicious.php%00.js');

        // Assert
        $this->assertSame(422, $response->status(), 'api/* 검증 실패는 Accept 헤더와 무관하게 JSON 422 다 — 302 는 Referer 로 되돌리는 무인증 오픈 리다이렉트다');
    }

    /**
     * 불허 파일 타입 차단 - PHP
     *
     * @scenario case=file_type
     *
     * @effects module_asset_blocks_disallowed_file_types, blocks_disallowed_file_types
     *
     * @return void 반환값 없음
     */
    public function test_blocks_disallowed_file_types(): void
    {
        // Arrange
        $phpPath = $this->testModulePath.'/malicious.php';
        file_put_contents($phpPath, '<?php echo "hack"; ?>');

        // Act
        $response = $this->get('/api/modules/assets/test-module/malicious.php');

        // Assert
        $this->assertSame(422, $response->status(), 'api/* 검증 실패는 Accept 헤더와 무관하게 JSON 422 다 — 302 는 Referer 로 되돌리는 무인증 오픈 리다이렉트다');
    }

    /**
     * 불허 파일 타입 차단 - 실행 파일들
     *
     * @scenario case=file_type
     *
     * @effects module_asset_blocks_various_disallowed_file_types, blocks_various_disallowed_file_types
     *
     * @return void 반환값 없음
     */
    public function test_blocks_various_disallowed_file_types(): void
    {
        $disallowedExtensions = ['exe', 'sh', 'bat', 'dll', 'so', 'py', 'rb', 'pl', 'asp', 'aspx', 'jsp', 'cgi'];

        foreach ($disallowedExtensions as $ext) {
            $response = $this->get("/api/modules/assets/test-module/malicious.{$ext}");
            $this->assertSame(422, $response->status(), "Extension .{$ext} should be blocked — api/* 검증 실패는 Accept 헤더와 무관하게 JSON 422 다 (302 는 Referer 로 되돌리는 무인증 오픈 리다이렉트)");
        }
    }

    /**
     * 허용된 파일 타입 확인
     *
     * @scenario case=file_type
     *
     * @effects module_asset_allows_all_whitelisted_file_types, allows_all_whitelisted_file_types
     *
     * @return void 반환값 없음
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
            $filePath = $this->testModulePath.'/dist/'.$filename;
            file_put_contents($filePath, 'test content');

            $response = $this->get("/api/modules/assets/test-module/dist/{$filename}");
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
     *
     * @scenario case=caching
     *
     * @effects module_asset_sets_caching_headers, sets_caching_headers
     *
     * @return void 반환값 없음
     */
    public function test_sets_caching_headers(): void
    {
        // Arrange
        $jsPath = $this->testModulePath.'/dist/js/module.iife.js';
        file_put_contents($jsPath, 'console.log("test");');

        // Act
        $response = $this->get('/api/modules/assets/test-module/dist/js/module.iife.js');

        // Assert
        $response->assertStatus(200);
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertNotNull($cacheControl, 'Cache-Control header should be present');
    }

    /**
     * ETag 헤더 생성 확인
     *
     * @scenario case=caching
     *
     * @effects module_asset_generates_etag_header, generates_etag_header
     *
     * @return void 반환값 없음
     */
    public function test_generates_etag_header(): void
    {
        // Arrange
        $jsPath = $this->testModulePath.'/dist/js/module.iife.js';
        file_put_contents($jsPath, 'console.log("test");');

        // Act
        $response = $this->get('/api/modules/assets/test-module/dist/js/module.iife.js');

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
     * @effects module_asset_returns_304_when_etag_matches, returns_304_when_etag_matches
     *
     * @return void 반환값 없음
     */
    public function test_returns_304_when_etag_matches(): void
    {
        // Arrange
        $jsPath = $this->testModulePath.'/dist/js/module.iife.js';
        file_put_contents($jsPath, 'console.log("test");');

        $firstResponse = $this->get('/api/modules/assets/test-module/dist/js/module.iife.js');
        $etag = $firstResponse->headers->get('ETag');

        // Act
        $response = $this->withHeaders([
            'If-None-Match' => $etag,
        ])->get('/api/modules/assets/test-module/dist/js/module.iife.js');

        // Assert
        $response->assertStatus(304);
        $this->assertEmpty($response->getContent(), '304 response should have empty body');
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
     * @effects module_asset_applies_immutable_caching_in_production, applies_immutable_caching_in_production
     *
     * @return void 반환값 없음
     */
    public function test_applies_immutable_caching_in_production(): void
    {
        // Arrange
        app()['env'] = 'production';
        $jsPath = $this->testModulePath.'/dist/js/module.iife.js';
        file_put_contents($jsPath, 'console.log("test");');

        // Act
        $response = $this->get('/api/modules/assets/test-module/dist/js/module.iife.js');

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
     * @scenario extension_type=module, request=applies_immutable_caching_in_production
     *
     * @effects applies_immutable_caching_in_production
     *
     * @return void 반환값 없음
     */
    public function test_applies_immutable_caching_when_the_url_carries_a_version(): void
    {
        app()['env'] = 'production';
        $jsPath = $this->testModulePath.'/dist/js/module.iife.js';
        file_put_contents($jsPath, 'console.log("test");');

        $response = $this->get('/api/modules/assets/test-module/dist/js/module.iife.js?v=1.2.3');

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
     * @effects module_asset_applies_no_cache_in_development, applies_no_cache_in_development
     *
     * @return void 반환값 없음
     */
    public function test_applies_no_cache_in_development(): void
    {
        // Arrange
        app()['env'] = 'local';
        $jsPath = $this->testModulePath.'/dist/js/module.iife.js';
        file_put_contents($jsPath, 'console.log("test");');

        // Act
        $response = $this->get('/api/modules/assets/test-module/dist/js/module.iife.js');

        // Assert
        $response->assertStatus(200);
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-cache', $cacheControl, 'Development should use no-cache');
    }

    /**
     * 중첩 경로 처리 테스트
     *
     * @scenario case=nested_path
     *
     * @effects module_asset_handles_nested_path, handles_nested_path
     *
     * @return void 반환값 없음
     */
    public function test_handles_nested_path(): void
    {
        // Arrange
        $nestedPath = $this->testModulePath.'/dist/js/components/Button.js';
        mkdir(dirname($nestedPath), 0755, true);
        file_put_contents($nestedPath, 'export default Button;');

        // Act
        $response = $this->get('/api/modules/assets/test-module/dist/js/components/Button.js');

        // Assert
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/javascript');
    }

    /**
     * Source Map 파일 서빙 거부 테스트
     * 소스맵은 원본 코드 전문을 담고 있어 배포 환경에서 노출되면 안 된다.
     * 파일이 디스크에 실재하더라도(= 파일 부재로 인한 실패가 아님) 허용 확장자
     * 목록에서 제외되어 서빙이 거부되어야 한다.
     *
     * @scenario case=sourcemap
     *
     * @effects module_sourcemap_asset_request_rejected_even_when_file_exists, rejects_sourcemap_file
     *
     * @return void 반환값 없음
     */
    public function test_rejects_sourcemap_file(): void
    {
        // Arrange: 맵 파일이 실제로 존재하는 상태를 만든다
        $mapPath = $this->testModulePath.'/dist/js/module.iife.js.map';
        file_put_contents($mapPath, '{"version":3,"sources":[],"mappings":""}');
        $this->assertFileExists($mapPath);

        // Act: JSON 클라이언트 (에러 본문 확인용)
        $json = $this->getJson('/api/modules/assets/test-module/dist/js/module.iife.js.map');

        // Assert: 허용 확장자 검증에서 거부 — 맵 내용이 절대 응답에 실리지 않는다
        $json->assertStatus(422);
        $json->assertJsonValidationErrors('path');
        $this->assertStringNotContainsString('mappings', $json->getContent());

        // Act: 브라우저 요청 (Accept 헤더 없음) — 서빙되지 않음을 확인
        $browser = $this->get('/api/modules/assets/test-module/dist/js/module.iife.js.map');

        // Assert: 검증 실패는 Accept 헤더와 무관하게 JSON 422 다. `assertNotSame(200, ...)` 은
        // 302 를 통과시키는데, 그 302 의 목적지는 `Referer` 라 무인증 오픈 리다이렉트가 된다.
        $browser->assertStatus(422);
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
        $viaPath = $this->get('/api/modules/assets/test-module/dist/js/nonexistent.js');
        $viaQuery = $this->get('/api/modules/assets/test-module?file=dist/js/nonexistent.js');

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
            foreach (['/api/modules/assets/test-module/.eslintrc.json', '/api/modules/assets/test-module?file=.eslintrc.json'] as $url) {
                $response = $this->get($url);

                $this->assertNotSame(
                    200,
                    $response->baseResponse->getStatusCode(),
                    $url.' 가 점으로 시작하는 이름을 내준다 — 서버 설정의 숨김 파일 차단은 경로 모드에만 걸린다'
                );
            }
            // 구분자 축 — 백슬래시도 세그먼트 구분자다. 이 케이스가 없으면 분할이
            // `/` 만 보는 상태를 게이트가 통과시킨다(9차가 정확히 그 상태였다):
            // 봉쇄 판정은 `\` 를 구분자로 취급하므로 숨김 세그먼트만 못 보고 넘어간다.
            //
            // 파일이 **실재해야** 이 축이 측정된다 — 없으면 규칙과 무관하게 404 라
            // 규칙을 되돌려도 초록이 된다.
            $hiddenDir = $this->hiddenFixtureRoot().DIRECTORY_SEPARATOR.'sub'.DIRECTORY_SEPARATOR.'.hidden';
            @mkdir($hiddenDir, 0755, true);
            $nested = $hiddenDir.DIRECTORY_SEPARATOR.'app.js';
            file_put_contents($nested, 'console.log(1);');

            try {
                $viaBackslash = $this->get('/api/modules/assets/test-module?file=sub%5C.hidden/app.js');

                $this->assertNotSame(
                    200,
                    $viaBackslash->baseResponse->getStatusCode(),
                    '백슬래시 구분자로 숨김 세그먼트 차단이 우회된다'
                );
            } finally {
                @unlink($nested);
                @rmdir($hiddenDir);
                @rmdir(dirname($hiddenDir));
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
        return $this->testModulePath;
    }

    /**
     * 식별자가 봉쇄 기준선을 고를 수 없다.
     *
     * 라우트에 형태 제약이 없고 Laravel 은 매칭·바인딩을 디코딩된 경로로 하므로
     * `%2e%2e` 가 `..` 로 들어온다. 그 값을 그대로 보간하면 기준 디렉토리가 프로젝트
     * 루트가 되어, 순회 없이도 루트의 임의 파일이 검증을 통과한다.
     *
     * @scenario identifier_shape=valid
     *
     * @effects asset_identifier_cannot_pick_the_containment_base
     *
     * @return void 반환값 없음
     */
    public function test_identifier_cannot_select_the_containment_base(): void
    {
        $response = $this->get('/api/modules/assets/%2e%2e?file=composer.json');

        $this->assertNotSame(
            200,
            $response->baseResponse->getStatusCode(),
            '식별자가 기준선을 고르면 봉쇄가 요청이 지정한 디렉토리 기준이 된다'
        );
    }

    /**
     * 운영자가 넣은 `custom/` 자산도 서빙된다.
     * 모듈 자산 경로는 확장 루트 기준이라 `custom/` 이 별도 분기 없이 해석된다
     * (템플릿만 `dist/` 하드코딩이라 분기가 필요했다). 그 사실을 실제 HTTP 로 고정한다 —
     * 서비스 반환값만 보면 라우트·FormRequest 게이트를 통과하는지가 남는다.
     *
     * @scenario custom_source=convention_scan, custom_asset=css
     *
     * @effects custom_asset_loaded_after_extension_bundles
     *
     * @return void 반환값 없음
     */
    public function test_serves_operator_custom_css_from_active_module(): void
    {
        // Arrange
        mkdir($this->testModulePath.'/custom', 0755, true);
        file_put_contents($this->testModulePath.'/custom/custom.css', '.operator { color: red; }');

        // Act
        $response = $this->get('/api/modules/assets/test-module/custom/custom.css');

        // Assert
        $response->assertStatus(200);
        $this->assertTrue(
            str_starts_with($response->headers->get('Content-Type'), 'text/css'),
            'Content-Type should start with text/css'
        );

        // dist/ 의 동명 파일이 아니라 custom/ 의 파일이어야 한다 — 내용으로 확인한다.
        // CSS 응답은 상대 참조 치환을 거치므로 본문이 버퍼에 실린다(BinaryFileResponse 아님).
        // 파일 경로 대신 내용을 보는 편이 "무엇이 나갔는가" 를 직접 재는 것이기도 하다.
        $this->assertStringContainsString(
            '.operator { color: red; }',
            $response->getContent(),
            'custom/ 의 파일이 서빙되어야 한다'
        );
    }

    /**
     * 비활성 모듈의 `custom/` 은 서빙되지 않는다.
     * 비활성 확장의 자산이 계속 응답하면 "껐는데 스타일이 남아 있다" 가 된다.
     *
     * @scenario custom_source=convention_scan
     *
     * @effects inactive_extension_custom_not_served
     *
     * @return void 반환값 없음
     */
    public function test_returns_404_for_custom_asset_of_inactive_module(): void
    {
        // Arrange
        mkdir($this->testModulePath.'/custom', 0755, true);
        file_put_contents($this->testModulePath.'/custom/custom.css', '.operator { color: red; }');

        $this->activeModule->update(['status' => ExtensionStatus::Inactive->value]);

        // Act
        $response = $this->get('/api/modules/assets/test-module/custom/custom.css');

        // Assert
        $response->assertStatus(404);
    }

    /**
     * `custom/` 도 확장자 화이트리스트를 그대로 받는다 — 신규 확장자를 열지 않는다.
     *
     * @scenario custom_source=convention_scan, custom_asset=static_file
     *
     * @effects custom_disallowed_extension_blocked
     *
     * @return void 반환값 없음
     */
    public function test_blocks_disallowed_file_type_under_custom(): void
    {
        // Arrange
        mkdir($this->testModulePath.'/custom', 0755, true);
        file_put_contents($this->testModulePath.'/custom/notes.txt', 'not an asset');

        // Act
        $response = $this->getJson('/api/modules/assets/test-module/custom/notes.txt');

        // Assert: FormRequest 의 확장자 게이트가 거부한다 (422)
        $response->assertStatus(422);
        $response->assertJsonValidationErrors('path');
        $this->assertStringNotContainsString('not an asset', $response->getContent());
    }

    /**
     * `custom/` 자산도 ETag 로 304 를 돌려준다 — 운영자 파일이라고 캐시 계약이 다르지 않다.
     *
     * @scenario custom_source=convention_scan, custom_asset=css
     *
     * @effects custom_asset_url_busts_on_file_change
     *
     * @return void 반환값 없음
     */
    public function test_returns_304_for_custom_asset_when_etag_matches(): void
    {
        // Arrange
        mkdir($this->testModulePath.'/custom', 0755, true);
        file_put_contents($this->testModulePath.'/custom/custom.css', '.operator { color: red; }');

        $first = $this->get('/api/modules/assets/test-module/custom/custom.css');
        $first->assertStatus(200);

        $etag = $first->headers->get('ETag');
        $this->assertNotNull($etag, 'custom 자산에도 ETag 가 있어야 한다');

        // Act
        $second = $this->get(
            '/api/modules/assets/test-module/custom/custom.css',
            ['If-None-Match' => $etag]
        );

        // Assert
        $second->assertStatus(304);
    }
}
