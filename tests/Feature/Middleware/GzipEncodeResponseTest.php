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
}
