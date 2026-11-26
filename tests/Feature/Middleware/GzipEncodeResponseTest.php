<?php

namespace Tests\Feature\Middleware;

use App\Http\Middleware\GzipEncodeResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * 압축 미들웨어의 응답 계약 — HEAD·206 제외 · 압축 표현의 ETag 접미사 · `Accept-Encoding` 가중치.
 *
 * `public_storage_route_streams_large_files_without_buffering` 은 이 클래스가 대표하지 않는다 —
 * 그 효과는 공개 스토리지 **통로**를 실제로 지나야 재어지므로 `PublicStorageServeTest` 가
 * 소유한다. 여기서는 미들웨어 단위의 조건만 잰다.
 */
class GzipEncodeResponseTest extends TestCase
{
    /**
     * 테스트용 대용량 JSON 데이터를 생성합니다.
     *
     * @return array<int, array<string, mixed>> 압축 하한을 넘는 항목 100개짜리 배열
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

    /**
     * 대용량 JSON, 소용량 JSON, HTML 을 돌려주는 시험 라우트 셋을 등록한다.
     *
     * @return void 반환값 없음
     */
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
     *
     * @scenario encoding=gzip
     *
     * @effects gzip_compresses_compressible_responses_for_accepting_clients
     *
     * @return void 반환값 없음
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
     *
     * @scenario encoding=gzip
     *
     * @effects gzip_leaves_responses_uncompressed_without_gzip_acceptance
     *
     * @return void 반환값 없음
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
     *
     * @scenario encoding=gzip
     *
     * @effects gzip_skips_responses_below_the_size_threshold
     *
     * @return void 반환값 없음
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
     *
     * @scenario encoding=gzip
     *
     * @effects gzip_compresses_compressible_responses_for_accepting_clients
     *
     * @return void 반환값 없음
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
     *
     * @scenario encoding=gzip
     *
     * @effects gzip_does_not_double_compress_encoded_responses
     *
     * @return void 반환값 없음
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
     *
     * @scenario encoding=gzip
     *
     * @effects gzip_varies_on_accept_encoding_for_compressible_responses
     *
     * @return void 반환값 없음
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
     *
     * @scenario encoding=gzip
     *
     * @effects gzip_declares_the_encoded_content_length
     *
     * @return void 반환값 없음
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
     *
     * @scenario encoding=gzip
     *
     * @effects gzip_compresses_compressible_responses_for_accepting_clients, gzip_leaves_responses_uncompressed_without_gzip_acceptance
     *
     * @return void 반환값 없음
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
     *
     * @scenario encoding=gzip, conditional_request=if_none_match
     *
     * @effects gzip_skips_not_modified_responses
     *
     * @return void 반환값 없음
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
     *
     * @scenario encoding=gzip
     *
     * @effects gzip_compresses_compressible_responses_for_accepting_clients
     *
     * @return void 반환값 없음
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
     *
     * @scenario encoding=gzip, response_kind=binary
     *
     * @effects gzip_compresses_compressible_responses_for_accepting_clients
     *
     * @return void 반환값 없음
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
     *
     * @scenario encoding=gzip, response_kind=binary
     *
     * @effects gzip_marks_encoded_representation_with_distinct_etag
     *
     * @return void 반환값 없음
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
        // ETag 는 **표현**을 식별한다 — content-coding 이 다르면 다른 표현이므로 같은 값을
        // 쓸 수 없다(RFC 9110 §8.8.1). 종전에는 원본 값을 그대로 승계해 같은 URL 이
        // Accept-Encoding 에 따라 다른 본문을 같은 ETag 로 내보냈다.
        $this->assertEquals('"test-etag-gzip"', $response->headers->get('ETag'));
        $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control', ''));
        $this->assertStringContainsString('Accept-Encoding', $response->headers->get('Vary', ''));
    }

    /**
     * HEAD 요청은 압축 경로에 들어가지 않는다.
     *
     * `BinaryFileResponse` 는 `prepare()` 에서 본문 전송을 끄지만(maxlen=0), 압축 경로는
     * 그 응답을 **일반 Response 로 치환**하면서 파일을 통째로 읽어 본문에 싣는다. 치환된
     * 응답은 prepare() 를 다시 거치지 않고 `Response::send()` 에는 HEAD 가드가 없다.
     *
     * @scenario encoding=gzip, response_kind=binary, range_request=head
     *
     * @effects gzip_skips_head_and_partial_responses
     *
     * @return void 반환값 없음
     */
    public function test_head_request_is_not_compressed_so_no_body_is_attached(): void
    {
        $path = $this->makeTempFile(str_repeat('.selector{color:red}', 100));

        $middleware = new GzipEncodeResponse;

        $request = Request::create('/api/plugins/bundle.css', 'HEAD');
        $request->headers->set('Accept-Encoding', 'gzip');

        $response = $middleware->handle($request, function () use ($path) {
            return response()->file($path, ['Content-Type' => 'text/css']);
        });

        $this->assertInstanceOf(
            BinaryFileResponse::class,
            $response,
            'HEAD 응답이 일반 Response 로 치환되면 파일 본문이 그대로 실려 나간다'
        );
        $this->assertNull($response->headers->get('Content-Encoding'));
    }

    /**
     * 부분 응답(206)은 압축하지 않는다.
     *
     * 헤더를 승계하면 `Content-Range` 는 부분 범위를 가리키는데 본문은 **파일 전체**의
     * gzip 이 되어 둘이 정면으로 어긋난다. 미디어 시킹과 재개 다운로드가 이 경로를 쓴다.
     *
     * @scenario encoding=gzip, response_kind=binary, range_request=partial
     *
     * @effects gzip_skips_head_and_partial_responses
     *
     * @return void 반환값 없음
     */
    public function test_partial_content_response_is_not_compressed(): void
    {
        $path = $this->makeTempFile(str_repeat('.selector{color:red}', 100));

        $middleware = new GzipEncodeResponse;

        $request = Request::create('/api/plugins/bundle.css', 'GET');
        $request->headers->set('Accept-Encoding', 'gzip');

        $response = $middleware->handle($request, function () use ($path) {
            $fileResponse = response()->file($path, ['Content-Type' => 'text/css']);
            $fileResponse->setStatusCode(206);
            $fileResponse->headers->set('Content-Range', 'bytes 0-99/2000');

            return $fileResponse;
        });

        $this->assertNull(
            $response->headers->get('Content-Encoding'),
            '206 을 압축하면 Content-Range 와 본문이 어긋난다'
        );
        $this->assertSame('bytes 0-99/2000', $response->headers->get('Content-Range'));
    }

    /**
     * 두 판정 항이 **각각** 막는다 — `206` 만, `Content-Range` 만 있을 때도.
     *
     * 위 케이스는 상태코드와 헤더를 **둘 다** 세운다. 그래서 구현의 `$status === 206`
     * 이나 `headers->has('Content-Range')` 중 어느 한 항을 지워도 초록이었다. 실제 응답은
     * 둘 중 하나만 갖는 형태로도 나간다(범위 응답을 만드는 상류가 무엇이냐에 따라).
     *
     * @scenario range_request=status_only
     *
     * @effects partial_content_guard_holds_on_each_signal, gzip_skips_head_and_partial_responses
     *
     * @return void 반환값 없음
     */
    public function test_each_partial_content_signal_blocks_compression_on_its_own(): void
    {
        $path = $this->makeTempFile(str_repeat('.selector{color:red}', 100));

        foreach ([
            'status_only' => static function ($fileResponse): void {
                $fileResponse->setStatusCode(206);
            },
            'header_only' => static function ($fileResponse): void {
                $fileResponse->headers->set('Content-Range', 'bytes 0-99/2000');
            },
        ] as $label => $mark) {
            $middleware = new GzipEncodeResponse;

            $request = Request::create('/api/plugins/bundle.css', 'GET');
            $request->headers->set('Accept-Encoding', 'gzip');

            $response = $middleware->handle($request, function () use ($path, $mark) {
                $fileResponse = response()->file($path, ['Content-Type' => 'text/css']);
                $mark($fileResponse);

                return $fileResponse;
            });

            $this->assertNull(
                $response->headers->get('Content-Encoding'),
                $label.': 이 신호 하나만으로도 압축이 막혀야 한다 — 두 항을 함께 세운 케이스만 있으면 어느 한 항을 지워도 초록이다'
            );
        }
    }

    /**
     * 압축하지 않은 응답에도 `Vary: Accept-Encoding` 이 붙는다.
     *
     * 종전에는 실제로 압축한 응답에만 붙어, 같은 URL 이 상한 초과·소용량·미지원
     * 클라이언트에서는 Vary 없이 나갔다 — 공유 캐시가 인코딩 구분 없이 재사용한다.
     *
     * @scenario encoding=gzip
     *
     * @effects gzip_varies_on_accept_encoding_for_compressible_responses
     *
     * @return void 반환값 없음
     */
    public function test_uncompressed_compressible_response_still_varies_on_accept_encoding(): void
    {
        $middleware = new GzipEncodeResponse;

        $request = Request::create('/api/whatever', 'GET'); // Accept-Encoding 없음

        $response = $middleware->handle($request, function () {
            return response()->json(['ok' => true]);
        });

        $this->assertNull($response->headers->get('Content-Encoding'), '압축하지 않은 응답이다');
        $this->assertStringContainsString(
            'Accept-Encoding',
            $response->headers->get('Vary', ''),
            '압축 여부에 따라 Vary 유무가 갈리면 공유 캐시가 잘못된 표현을 재사용한다'
        );
    }

    /**
     * 작은 BinaryFileResponse(1KB 미만)는 압축하지 않아야 합니다.
     *
     * @scenario encoding=gzip, response_kind=binary
     *
     * @effects gzip_skips_responses_below_the_size_threshold
     *
     * @return void 반환값 없음
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
     * 상한을 넘는 파일 응답은 압축하지 않고 스트리밍 그대로 흘려보내야 합니다 (R35).
     *
     * BinaryFileResponse 압축은 파일 **전체를 메모리에 적재**한 뒤 한 벌 더 만든다. 서빙
     * 대상이 코어·확장 번들(≤1MB)일 때는 무해했지만, 공개 스토리지 서빙 라우트
     * (`/api/system/public-storage/...`)가 생기면서 대상이 **운영자·사용자 업로드물**로
     * 넓어졌다 — 압축 대상 MIME(json·txt·css·js·html·xml)의 대용량 파일 하나로
     * memory_limit 를 넘긴다. 계획서 §7.6 R35 「신규 서빙 라우트도 스트리밍」.
     *
     * @scenario encoding=gzip, response_kind=binary
     *
     * @effects gzip_skips_file_responses_above_the_size_cap
     *
     * @return void 반환값 없음
     */
    public function test_does_not_compress_file_response_above_size_cap(): void
    {
        // 상한(8MB) 초과 — 압축 대상 MIME 이지만 적재하지 않아야 한다.
        $path = $this->makeTempFile(str_repeat('a', 8 * 1024 * 1024 + 1));

        $middleware = new GzipEncodeResponse;

        $request = Request::create('/api/system/public-storage/big.txt', 'GET');
        $request->headers->set('Accept-Encoding', 'gzip');

        $response = $middleware->handle($request, function () use ($path) {
            return response()->file($path, ['Content-Type' => 'text/plain']);
        });

        $this->assertNull(
            $response->headers->get('Content-Encoding'),
            '상한 초과 파일을 압축하면 전체 적재로 퇴행한다 (R35)'
        );
        $this->assertInstanceOf(
            BinaryFileResponse::class,
            $response,
            '상한 초과 응답은 스트리밍 형태 그대로 나가야 한다'
        );
    }

    /**
     * gzip 미지원 클라이언트에는 BinaryFileResponse 를 압축하지 않아야 합니다.
     *
     * @scenario encoding=gzip, response_kind=binary
     *
     * @effects gzip_leaves_responses_uncompressed_without_gzip_acceptance
     *
     * @return void 반환값 없음
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

    /**
     * 압축본은 범위 요청을 광고하지 않는다.
     *
     * `BinaryFileResponse::prepare()` 는 안전 메서드 요청에 `Accept-Ranges: bytes` 를
     * 세운다. 라우터가 미들웨어보다 **먼저** `prepare()` 를 돌리므로 그 헤더는 압축
     * 경로에 들어올 때 이미 붙어 있고, 종전 구현은 `headers->all()` 로 통째 승계해
     * 압축본에도 그대로 실었다.
     *
     * 그 광고는 사실이 아니다 — 같은 URL 에 `Range` 가 오면 이 미들웨어가 206 을
     * 압축에서 제외해 **identity 206** 을 내보내므로, 압축본 길이로 오프셋을 계산한
     * 클라이언트가 다른 표현의 바이트를 이어붙인다. 정적 서빙은 이 상태를 만들지
     * 않는다 — nginx `gzip` 필터와 Apache `mod_deflate` 는 압축 시 이 헤더를 **제거**
     * 한다. 즉 남겨 두면 같은 사이트가 압축 주체에 따라 다른 것을 광고한다.
     *
     * @scenario encoding=gzip, range_advertised=bytes
     *
     * @effects compressed_response_drops_the_range_advertisement
     *
     * @return void 반환값 없음
     */
    public function test_compressed_response_does_not_advertise_byte_ranges(): void
    {
        $jsContent = str_repeat("console.log('bundle chunk');\n", 200);
        $path = $this->makeTempFile($jsContent);

        $middleware = new GzipEncodeResponse;

        $request = Request::create('/api/modules/bundle.js', 'GET');
        $request->headers->set('Accept-Encoding', 'gzip');

        $response = $middleware->handle($request, function () use ($path, $request) {
            $fileResponse = response()->file($path, ['Content-Type' => 'text/javascript']);
            // 라우터가 하는 일을 그대로 재현한다 — 이 단계를 생략하면 헤더가 애초에
            // 없어 검사가 「쟀다」와 「보지 않았다」를 구분하지 못한다.
            $fileResponse->prepare($request);

            return $fileResponse;
        });

        $this->assertSame('gzip', $response->headers->get('Content-Encoding'));
        $this->assertNull(
            $response->headers->get('Accept-Ranges'),
            '압축본이 범위 요청을 광고한다 — 그 오프셋은 identity 표현의 것이라 재개 다운로드가 다른 표현을 이어붙인다'
        );
    }

    /**
     * 압축하지 않은 응답의 범위 광고는 그대로 둔다 (반대 방향 — 회귀 0).
     *
     * @scenario encoding=gzip, range_advertised=bytes, response_kind=binary
     *
     * @effects compressed_response_drops_the_range_advertisement
     *
     * @return void 반환값 없음
     */
    public function test_uncompressed_response_keeps_the_range_advertisement(): void
    {
        $jsContent = str_repeat("console.log('x');\n", 200);
        $path = $this->makeTempFile($jsContent);

        $middleware = new GzipEncodeResponse;

        // gzip 미지원 클라이언트 — 압축 경로를 타지 않는다.
        $request = Request::create('/api/modules/bundle.js', 'GET');

        $response = $middleware->handle($request, function () use ($path, $request) {
            $fileResponse = response()->file($path, ['Content-Type' => 'text/javascript']);
            $fileResponse->prepare($request);

            return $fileResponse;
        });

        $this->assertNull($response->headers->get('Content-Encoding'));
        $this->assertSame(
            'bytes',
            $response->headers->get('Accept-Ranges'),
            '압축하지 않은 응답까지 범위 광고를 잃으면 재개 다운로드가 통째로 사라진다'
        );
    }

    /**
     * 범위 광고 제거는 **응답 클래스가 아니라 압축 여부**가 정한다.
     *
     * 종전에는 `Accept-Ranges` 제거가 `BinaryFileResponse` 치환 경로에만 있었다. 일반
     * 응답 경로도 같은 압축을 수행하고 같은 표현 축을 만드는데 그 정리를 하지 않아,
     * 그 경로로 오는 응답이 `Accept-Ranges` 를 갖는 순간(스트림이 아닌 본문 응답에
     * 그 헤더를 세우는 지점이 생기거나, 상위 미들웨어가 세우면) 압축본이 identity
     * 오프셋을 광고하게 된다 — 재개 다운로드가 다른 표현의 바이트를 이어붙인다.
     *
     * 판정은 "두 경로가 같은 자리를 쓰는가" 이므로, 일반 응답에 그 헤더를 실어 두고
     * 압축 뒤 사라지는지 잰다.
     *
     * @scenario encoding=gzip, range_advertised=bytes
     *
     * @effects compressed_response_drops_the_range_advertisement
     *
     * @return void 반환값 없음
     */
    public function test_compressed_plain_response_drops_the_range_advertisement(): void
    {
        $middleware = new GzipEncodeResponse;

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Accept-Encoding', 'gzip');

        $response = $middleware->handle($request, function () {
            $plain = response()->json($this->getLargeJsonData());
            // 이 헤더가 없으면 검사가 「쟀다」와 「보지 않았다」를 구분하지 못한다.
            $plain->headers->set('Accept-Ranges', 'bytes');

            return $plain;
        });

        $this->assertSame('gzip', $response->headers->get('Content-Encoding'));
        $this->assertNotInstanceOf(
            BinaryFileResponse::class,
            $response,
            '이 케이스는 일반 응답 압축 경로를 재야 한다',
        );
        $this->assertNull(
            $response->headers->get('Accept-Ranges'),
            '일반 응답 압축 경로가 범위 광고를 그대로 승계한다 — 파일 응답 경로와 비대칭이다',
        );

        // 같은 자리가 소유하는 나머지 두 축도 함께 성립해야 한다 (자리 이동 회귀 가드).
        $this->assertStringContainsString(
            'Accept-Encoding',
            (string) $response->headers->get('Vary'),
        );
    }

    /**
     * `Accept-Encoding` 은 RFC 9110 §12.5.3 의 가중치를 따라 읽는다.
     *
     * 종전에는 `str_contains('gzip')` 이라 `gzip;q=0`(명시 거부)에도 압축본을 보냈다 —
     * 거부한 클라이언트가 gzip 바이트를 받는다. 반대로 `*` 는 「명시하지 않은 모든 코딩」이라
     * gzip 이 따로 거부되지 않은 한 압축해야 한다.
     *
     * @scenario accept_encoding=weighted
     *
     * @effects gzip_honours_accept_encoding_q_values
     *
     * @return void 반환값 없음
     */
    public function test_accept_encoding_q_values_are_honoured(): void
    {
        $cases = [
            'gzip;q=0' => false,
            'identity;q=1, gzip;q=0' => false,
            'gzip;q=0, *' => false,
            '*' => true,
            'br, *;q=0.5' => true,
            'gzip;q=0.5, identity;q=1' => true,
            'GZIP' => true,
            'gzip;q=0.000' => false,
            // 경계·별칭·공백·와일드카드 거부
            '*;q=0' => false,                 // 와일드카드 자체를 거부하면 gzip 도 거부다
            'gzip;q=0.001' => true,           // 0 초과는 수용
            'identity' => false,              // gzip 도 * 도 없으면 압축하지 않는다
            'gzip ; q = 0' => false,          // 파라미터 주변 공백 허용
            'x-gzip;q=0' => false,            // 별칭도 같은 코딩
            // A13 — qvalue 문법 엄격화(§12.4.2)
            'gzip;q=1.000' => true,
            'gzip;q=2' => true,               // 규격 밖 값은 파라미터 무시 → 기본 가중치 1
            'gzip;q=.5' => true,              // 선행 0 없는 형태도 규격 밖 → 기본 가중치 1
            // 별칭·중복 병합은 fail-closed(min): 어느 이름이든 q=0 이면 거부
            'gzip;q=1, x-gzip;q=0' => false,  // 같은 코딩의 한 이름이 명시 거부면 거부
            'gzip;q=0, x-gzip;q=1' => false,  // 종전 max() 는 이 명시 거부를 압축으로 뒤집었다
            'gzip;q=0, x-gzip;q=0' => false,
            'gzip, gzip;q=0' => false,        // 같은 이름 중복도 fail-closed
            'gzip;q=0, gzip' => false,        // 순서 무관
            'x-gzip;q=0, gzip;q=1' => false,  // 별칭이 먼저 거부해도 거부
            'gzip;q=0.5, x-gzip;q=1' => true, // 둘 다 양수면 압축(가장 낮은 값도 0 초과)
            // 와일드카드도 같은 규칙(min)이다. 종전에는 마지막 값이
            // 앞의 명시 거부를 덮어써, `*` 축만 fail-open 이었다.
            '*;q=0, *' => false,              // 와일드카드 중복도 fail-closed
            '*, *;q=0' => false,              // 순서 무관
            '*;q=0.5, *' => true,             // 둘 다 양수면 압축
            'gzip, *;q=0' => true,            // gzip 이 명시되면 와일드카드는 보지 않는다
            '*;q=0, gzip' => true,            // 순서 무관 — 구체성 우선(RFC 9110 §12.5.3)
            'identity;q=0, *' => true,        // identity 거부는 gzip 판정과 무관
            // **한 멤버 안**의 `q` 중복도 같은 규칙(min)이다.
            // 종전에는 이 한 단계 아래만 last-write-wins 라, 바깥 두 축을 fail-closed 로
            // 접어 놓고 파라미터 하나로 명시 거부가 되살아났다.
            'gzip;q=0;q=1' => false,          // 명시 거부가 뒤 파라미터로 뒤집히지 않는다
            'gzip;q=1;q=0' => false,          // 순서 무관
            '*;q=0;q=1' => false,             // 와일드카드 축도 같다
            'gzip;q=0.5;q=1' => true,         // 둘 다 양수면 압축
        ];

        foreach ($cases as $header => $expectCompressed) {
            $middleware = new GzipEncodeResponse;

            $request = Request::create('/api/test-gzip-q', 'GET');
            $request->headers->set('Accept-Encoding', $header);

            $response = $middleware->handle($request, function () {
                return response()->json($this->getLargeJsonData());
            });

            $this->assertSame(
                $expectCompressed ? 'gzip' : null,
                $response->headers->get('Content-Encoding'),
                "Accept-Encoding: {$header} — ".($expectCompressed ? '압축해야 한다' : '명시 거부인데 압축했다')
            );
        }
    }

    /**
     * 라우트가 `SKIP_ATTRIBUTE` 로 압축 제외를 선언하면, 압축 대상 유형·크기·클라이언트 조건이
     * 전부 성립해도 압축하지 않는다 — 다만 `Vary` 는 유형이 정하므로 그대로 실린다.
     *
     * 사용자 업로드물 통로(코어 첨부·게시판·확장 이미지)는 이 표식으로 압축을 피한다. 종전에는
     * 그 통로가 압축을 피한 것이 스트림 응답 클래스를 미들웨어가 건너뛰는 우연이었고, 표식은
     * 공개 스토리지 한 곳에만 있었다.
     *
     * @scenario encoding=gzip
     *
     * @effects declared_compression_skip_blocks_gzip_regardless_of_response_shape
     *
     * @return void 반환값 없음
     */
    public function test_route_declared_skip_attribute_blocks_compression_regardless_of_response_shape(): void
    {
        $middleware = new GzipEncodeResponse;

        $request = Request::create('/api/upload-served', 'GET');
        $request->headers->set('Accept-Encoding', 'gzip');
        $request->attributes->set(GzipEncodeResponse::SKIP_ATTRIBUTE, true);

        $response = $middleware->handle($request, function () {
            return response(str_repeat('<p>uploaded html</p>', 200), 200)
                ->header('Content-Type', 'text/html');
        });

        $this->assertNull($response->headers->get('Content-Encoding'), '선언된 제외는 응답 형태와 무관하게 압축을 막아야 한다');
        $this->assertStringContainsString('Accept-Encoding', (string) $response->headers->get('Vary'));

        // 같은 응답에서 선언만 빼면 압축된다 — 이 케이스가 재는 것이 선언 축임을 확인한다.
        $plain = Request::create('/api/upload-served', 'GET');
        $plain->headers->set('Accept-Encoding', 'gzip');

        $compressed = $middleware->handle($plain, function () {
            return response(str_repeat('<p>uploaded html</p>', 200), 200)
                ->header('Content-Type', 'text/html');
        });

        $this->assertSame('gzip', $compressed->headers->get('Content-Encoding'));
    }

    /**
     * 웹서버에 전송을 위임한 파일 응답은 압축하지 않는다 — 헬퍼의 압축 제외 선언이 없어도 응답 모양으로 막는다.
     *
     * 압축 경로는 파일을 읽어 gzip 본문으로 치환한다. 위임 헤더가 남은 채로 치환하면 웹서버는 그 헤더를 소비해 **원본
     * 바이트**를 보내면서 앱이 붙인 `Content-Encoding: gzip` 을 그대로 실어, 브라우저가 깨진 본문을 받는다.
     *
     * @scenario case=gzip_guard
     *
     * @effects delegated_response_is_never_gzip_encoded
     *
     * @return void 반환값 없음
     */
    public function test_does_not_compress_a_delegated_file_response(): void
    {
        $path = $this->makeTempFile(str_repeat('.selector{color:red}', 200));
        $middleware = new GzipEncodeResponse;

        $request = Request::create('/api/plugins/bundle.css', 'GET');
        $request->headers->set('Accept-Encoding', 'gzip');

        $delegated = null;
        $response = $middleware->handle($request, function () use ($path, &$delegated) {
            $delegated = response()->file($path, ['Content-Type' => 'text/css']);
            $delegated->headers->set('X-Sendfile', $path);

            return $delegated;
        });

        $this->assertSame($delegated, $response, '위임 응답은 다른 응답으로 치환되면 안 된다');
        $this->assertNull($response->headers->get('Content-Encoding'));
        $this->assertStringContainsString('Accept-Encoding', (string) $response->headers->get('Vary'), 'Vary 는 유형이 정한다');

        // 같은 응답에서 위임 헤더만 빼면 압축된다 — 이 케이스가 재는 것이 위임 축임을 확인한다.
        $plain = Request::create('/api/plugins/bundle.css', 'GET');
        $plain->headers->set('Accept-Encoding', 'gzip');

        $compressed = $middleware->handle($plain, function () use ($path) {
            return response()->file($path, ['Content-Type' => 'text/css']);
        });

        $this->assertSame('gzip', $compressed->headers->get('Content-Encoding'));
    }
}
