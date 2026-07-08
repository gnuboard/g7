<?php

namespace Tests\Feature\Middleware;

use App\Http\Middleware\GzipEncodeResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class GzipEncodeResponseTest extends TestCase
{
    /**
     * 테스트용 대용량 JSON 데이터를 생성합니다.
     */
    private function getLargeJsonData(): array
    {
        $data = [];
        for ($i = 0; $i < 100; $i++) {
            $data[] = [
                'id' => $i,
                'name' => "Test Item {$i}",
                'description' => str_repeat('Lorem ipsum dolor sit amet. ', 10),
                'metadata' => [
                    'created_at' => '2024-01-01T00:00:00Z',
                    'updated_at' => '2024-01-02T00:00:00Z',
                    'tags' => ['tag1', 'tag2', 'tag3'],
                ],
            ];
        }

        return $data;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 테스트 라우트 등록 (대용량 JSON)
        Route::middleware(['api'])->get('/api/test-gzip-large', function () {
            return response()->json($this->getLargeJsonData());
        });

        // 테스트 라우트 등록 (소용량 JSON)
        Route::middleware(['api'])->get('/api/test-gzip-small', function () {
            return response()->json(['status' => 'ok']);
        });

        // 테스트 라우트 등록 (HTML)
        Route::middleware(['web'])->get('/test-gzip-html', function () {
            return response(str_repeat('<p>Test content</p>', 100), 200)
                ->header('Content-Type', 'text/html');
        });
    }

    /**
     * Accept-Encoding: gzip 헤더가 있으면 응답이 압축되어야 합니다.
     */
    public function test_compresses_json_response_when_client_accepts_gzip(): void
    {
        $response = $this->withHeaders([
            'Accept-Encoding' => 'gzip, deflate, br',
        ])->getJson('/api/test-gzip-large');

        $response->assertStatus(200);

        // Content-Encoding 헤더 확인
        $this->assertEquals('gzip', $response->headers->get('Content-Encoding'));

        // 압축된 컨텐츠가 gzip 매직 넘버로 시작하는지 확인 (\x1f\x8b)
        $content = $response->getContent();
        $this->assertStringStartsWith("\x1f\x8b", $content);

        // 압축 해제 후 원본 데이터 확인
        $decompressed = gzdecode($content);
        $this->assertNotFalse($decompressed);

        $jsonData = json_decode($decompressed, true);
        $this->assertIsArray($jsonData);
        $this->assertCount(100, $jsonData);
    }

    /**
     * Accept-Encoding 헤더가 없으면 응답이 압축되지 않아야 합니다.
     */
    public function test_does_not_compress_when_client_does_not_accept_gzip(): void
    {
        $response = $this->getJson('/api/test-gzip-large');

        $response->assertStatus(200);

        // Content-Encoding 헤더가 없어야 함
        $this->assertNull($response->headers->get('Content-Encoding'));

        // JSON 파싱 가능해야 함
        $response->assertJsonStructure([
            '*' => ['id', 'name', 'description', 'metadata'],
        ]);
    }

    /**
     * 작은 응답은 압축하지 않아야 합니다 (1KB 미만).
     */
    public function test_does_not_compress_small_responses(): void
    {
        $response = $this->withHeaders([
            'Accept-Encoding' => 'gzip',
        ])->getJson('/api/test-gzip-small');

        $response->assertStatus(200);

        // 작은 응답은 압축되지 않음
        $this->assertNull($response->headers->get('Content-Encoding'));
        $response->assertJson(['status' => 'ok']);
    }

    /**
     * HTML 응답도 압축되어야 합니다.
     */
    public function test_compresses_html_response(): void
    {
        $response = $this->withHeaders([
            'Accept-Encoding' => 'gzip',
        ])->get('/test-gzip-html');

        $response->assertStatus(200);
        $this->assertEquals('gzip', $response->headers->get('Content-Encoding'));
    }

    /**
     * 이미 압축된 응답은 다시 압축하지 않아야 합니다.
     */
    public function test_does_not_double_compress(): void
    {
        // 이미 Content-Encoding이 설정된 응답을 반환하는 라우트
        Route::middleware(['api'])->get('/api/test-already-compressed', function () {
            $data = json_encode(['test' => 'data']);
            $compressed = gzencode($data);

            return response($compressed, 200)
                ->header('Content-Type', 'application/json')
                ->header('Content-Encoding', 'gzip');
        });

        $response = $this->withHeaders([
            'Accept-Encoding' => 'gzip',
        ])->get('/api/test-already-compressed');

        $response->assertStatus(200);
        $this->assertEquals('gzip', $response->headers->get('Content-Encoding'));

        // 원본 압축 데이터가 유지되어야 함
        $content = $response->getContent();
        $decompressed = gzdecode($content);
        $this->assertNotFalse($decompressed);
        $this->assertEquals(['test' => 'data'], json_decode($decompressed, true));
    }

    /**
     * Vary 헤더가 올바르게 설정되어야 합니다.
     */
    public function test_sets_vary_header_correctly(): void
    {
        $response = $this->withHeaders([
            'Accept-Encoding' => 'gzip',
        ])->getJson('/api/test-gzip-large');

        $response->assertStatus(200);

        $vary = $response->headers->get('Vary');
        $this->assertNotNull($vary);
        $this->assertStringContainsString('Accept-Encoding', $vary);
    }

    /**
     * 압축 후 Content-Length가 올바르게 설정되어야 합니다.
     */
    public function test_sets_correct_content_length(): void
    {
        $response = $this->withHeaders([
            'Accept-Encoding' => 'gzip',
        ])->getJson('/api/test-gzip-large');

        $response->assertStatus(200);

        $contentLength = $response->headers->get('Content-Length');
        $actualLength = strlen($response->getContent());

        $this->assertEquals($actualLength, (int) $contentLength);
    }

    /**
     * 미들웨어 직접 테스트 - Accept-Encoding 확인.
     */
    public function test_middleware_checks_accept_encoding(): void
    {
        $middleware = new GzipEncodeResponse;

        // gzip 지원하는 요청
        $request = Request::create('/test', 'GET');
        $request->headers->set('Accept-Encoding', 'gzip');

        $response = $middleware->handle($request, function () {
            return response()->json(array_fill(0, 200, ['test' => str_repeat('data', 100)]));
        });

        $this->assertEquals('gzip', $response->headers->get('Content-Encoding'));

        // gzip 지원하지 않는 요청
        $request = Request::create('/test', 'GET');

        $response = $middleware->handle($request, function () {
            return response()->json(array_fill(0, 200, ['test' => str_repeat('data', 100)]));
        });

        $this->assertNull($response->headers->get('Content-Encoding'));
    }

    /**
     * 304 Not Modified 응답은 압축하지 않아야 합니다.
     */
    public function test_does_not_compress_304_response(): void
    {
        Route::middleware(['api'])->get('/api/test-304', function () {
            return response('', 304);
        });

        $response = $this->withHeaders([
            'Accept-Encoding' => 'gzip',
        ])->get('/api/test-304');

        $response->assertStatus(304);
        $this->assertNull($response->headers->get('Content-Encoding'));
    }

    /**
     * 압축 효율성 테스트 - 압축 후 크기가 원본보다 작아야 합니다.
     */
    public function test_compression_reduces_size(): void
    {
        // 압축 없이 응답 크기 측정
        $responseWithoutGzip = $this->getJson('/api/test-gzip-large');
        $originalSize = strlen($responseWithoutGzip->getContent());

        // 압축된 응답 크기 측정
        $responseWithGzip = $this->withHeaders([
            'Accept-Encoding' => 'gzip',
        ])->getJson('/api/test-gzip-large');
        $compressedSize = strlen($responseWithGzip->getContent());

        // 압축 후 크기가 50% 이상 감소해야 함
        $this->assertLessThan($originalSize * 0.5, $compressedSize);
    }

    /**
     * 임시 파일을 생성하고 테스트 종료 시 정리되도록 등록합니다.
     *
     * @param  string  $content  파일 내용
     * @return string 생성된 임시 파일 경로
     */
    private function makeTempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gzip_test_');
        file_put_contents($path, $content);
        $this->beforeApplicationDestroyed(function () use ($path) {
            @unlink($path);
        });

        return $path;
    }

    /**
     * BinaryFileResponse(번들 JS/CSS 서빙)도 gzip 압축되어야 합니다.
     *
     * 기존에는 BinaryFileResponse.getContent() 가 false 를 반환해 미들웨어가
     * 압축 없이 통과시켜 번들이 비압축 전송되던 사각지대를 해소한 회귀 테스트입니다.
     */
    public function test_compresses_binary_file_response(): void
    {
        // 1KB 이상의 JS 번들 파일 (압축 대상 크기 충족)
        $jsContent = str_repeat("console.log('bundle chunk');\n", 200);
        $path = $this->makeTempFile($jsContent);

        $middleware = new GzipEncodeResponse;

        $request = Request::create('/api/modules/bundle.js', 'GET');
        $request->headers->set('Accept-Encoding', 'gzip');

        $response = $middleware->handle($request, function () use ($path) {
            return response()->file($path, ['Content-Type' => 'text/javascript']);
        });

        // 압축 적용 확인
        $this->assertEquals('gzip', $response->headers->get('Content-Encoding'));

        // 압축 해제 시 원본 파일 내용 복원
        $decompressed = gzdecode($response->getContent());
        $this->assertNotFalse($decompressed);
        $this->assertEquals($jsContent, $decompressed);

        // Content-Length 는 압축된 크기와 일치
        $this->assertEquals(
            strlen($response->getContent()),
            (int) $response->headers->get('Content-Length')
        );

        // Vary: Accept-Encoding 헤더 설정
        $this->assertStringContainsString(
            'Accept-Encoding',
            $response->headers->get('Vary', '')
        );
    }

    /**
     * BinaryFileResponse 압축 시 원본 응답의 캐싱 헤더가 승계되어야 합니다.
     */
    public function test_binary_file_response_preserves_headers(): void
    {
        $cssContent = str_repeat('.selector{color:red}', 100);
        $path = $this->makeTempFile($cssContent);

        $middleware = new GzipEncodeResponse;

        $request = Request::create('/api/plugins/bundle.css', 'GET');
        $request->headers->set('Accept-Encoding', 'gzip');

        $response = $middleware->handle($request, function () use ($path) {
            $fileResponse = response()->file($path, [
                'Content-Type' => 'text/css',
                'ETag' => '"test-etag"',
            ]);
            $fileResponse->headers->set('Cache-Control', 'public, max-age=31536000, immutable');

            return $fileResponse;
        });

        $this->assertEquals('gzip', $response->headers->get('Content-Encoding'));
        $this->assertEquals('"test-etag"', $response->headers->get('ETag'));
        $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control', ''));
    }

    /**
     * 작은 BinaryFileResponse(1KB 미만)는 압축하지 않아야 합니다.
     */
    public function test_does_not_compress_small_binary_file_response(): void
    {
        $path = $this->makeTempFile('small');

        $middleware = new GzipEncodeResponse;

        $request = Request::create('/api/modules/bundle.js', 'GET');
        $request->headers->set('Accept-Encoding', 'gzip');

        $response = $middleware->handle($request, function () use ($path) {
            return response()->file($path, ['Content-Type' => 'text/javascript']);
        });

        // 1KB 미만은 압축 생략 — 원본 BinaryFileResponse 그대로 통과
        $this->assertNull($response->headers->get('Content-Encoding'));
    }

    /**
     * gzip 미지원 클라이언트에는 BinaryFileResponse 를 압축하지 않아야 합니다.
     */
    public function test_does_not_compress_binary_file_response_without_gzip_support(): void
    {
        $jsContent = str_repeat("console.log('x');\n", 200);
        $path = $this->makeTempFile($jsContent);

        $middleware = new GzipEncodeResponse;

        // Accept-Encoding 헤더 없음
        $request = Request::create('/api/modules/bundle.js', 'GET');

        $response = $middleware->handle($request, function () use ($path) {
            return response()->file($path, ['Content-Type' => 'text/javascript']);
        });

        $this->assertNull($response->headers->get('Content-Encoding'));
    }
}
