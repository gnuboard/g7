<?php

namespace Tests\Feature\Api\Public;

use App\Http\Controllers\Api\Base\BaseApiController;
use App\Http\Middleware\GzipEncodeResponse;
use App\Http\Responses\NotModifiedResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * `BaseApiController::streamedFileResponse()` 의 인자 계약 테스트 (DB 미사용).
 *
 * 14차 G3 라운드가 이 헬퍼에 두 인자를 더했다 — `$mimeType`(`Vary` 판정 근거) 과
 * `$skipCompression`(압축 제외 선언). 둘 다 **없어도 동작하던** 축이라 HTTP 계약 테스트로는
 * 보이지 않는다: 근거 없는 `Vary` 판정은 호출부가 `Content-Type` 을 세우지 않을 때만
 * 200/304 를 가르고, 압축 제외는 미들웨어가 응답 클래스를 보고 건너뛰는 우연에 가려진다.
 * 그래서 헬퍼를 직접 불러 인자 하나하나를 잠근다.
 *
 * @scenario response_kind=streamed, conditional_request=none
 */
class StreamedFileResponseContractTest extends TestCase
{
    /**
     * 헬퍼를 노출하는 테스트 전용 컨트롤러를 만듭니다.
     *
     * @return object `serve(...)` 로 `streamedFileResponse()` 를 그대로 부르는 객체
     */
    private function controller(): object
    {
        return new class extends BaseApiController
        {
            /**
             * 받은 스트림 응답을 max-age 3600 으로 `streamedFileResponse()` 에 그대로 넘겨 헬퍼의 결과를 돌려준다.
             *
             * @param  StreamedResponse  $response  헬퍼에 넘길 원본 스트림 응답
             * @param  string  $etagSource  ETag 를 만들 근거 문자열
             * @param  array<string, mixed>  $args  `streamedFileResponse()` 의 명명 인자
             * @return StreamedResponse|Response 200 스트림 응답 또는 304 응답
             */
            public function serve(StreamedResponse $response, string $etagSource, array $args = []): StreamedResponse|Response
            {
                return $this->streamedFileResponse($response, $etagSource, 3600, ...$args);
            }
        };
    }

    /**
     * 주어진 헤더의 요청을 컨테이너에 바인딩합니다 (헬퍼는 `request()` 를 읽는다).
     *
     * @param  array<string, string>  $headers  요청 헤더
     * @return Request 컨테이너에 바인딩한 요청 객체
     */
    private function bindRequest(array $headers = []): Request
    {
        $request = Request::create('/api/stream-fixture', 'GET');
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        $this->app->instance('request', $request);

        return $request;
    }

    /**
     * 본문 `fixture` 를 내보내는 200 스트림 응답을 주어진 헤더로 만든다.
     *
     * @param  array<string, string>  $headers  응답에 세울 헤더
     * @return StreamedResponse 픽스처 스트림 응답
     */
    private function stream(array $headers = []): StreamedResponse
    {
        return new StreamedResponse(static function (): void {
            echo 'fixture';
        }, 200, $headers);
    }

    /**
     * `Content-Type` 을 어디서도 알 수 없으면 **내려받기**(`application/octet-stream`)다 — 추정하지 않는다.
     *
     * 제품 결정(16차 G3 라운드): 종전에는 이 자리가 `LogicException` 을 던졌는데, 그 예외는
     * 개발자에게 「인자를 넘겨라」를 말할 뿐 방문자에게는 무인증 표면의 500 이었고, 행
     * `mime_type` 이 빈 첨부는 코드 결함이 아니라 데이터 상태다. 정적 서빙이 미지 확장자에
     * 주는 것과 같은 유형으로 내려보내며, 내용을 스니핑하지 않는다. 200/304 의 `Vary` 는 그
     * 확정값(압축 대상 아님)을 같은 근거로 쓴다.
     *
     * @scenario response_kind=streamed, conditional_request=if_none_match
     *
     * @effects unknown_mime_is_served_as_octet_stream_download
     *
     * @return void 반환값 없음
     */
    public function test_unknown_mime_type_falls_back_to_octet_stream_download(): void
    {
        $this->bindRequest();
        $full = $this->controller()->serve($this->stream(), 'src');

        $this->assertSame(200, $full->getStatusCode());
        $this->assertSame(BaseApiController::UNKNOWN_MIME_TYPE, $full->headers->get('Content-Type'));
        $this->assertSame('nosniff', $full->headers->get('X-Content-Type-Options'));

        $this->bindRequest(['If-None-Match' => (string) $full->headers->get('ETag')]);
        $notModified = $this->controller()->serve($this->stream(), 'src');

        $this->assertSame(304, $notModified->getStatusCode());
        $this->assertNull($notModified->headers->get('Vary'), 'octet-stream 은 압축 대상이 아니므로 304 에도 Vary 가 없어야 한다');
    }

    /**
     * 형제 `fileResponse()` 도 같은 규칙 — 빈 MIME 은 `application/octet-stream` 으로 나간다.
     *
     * @scenario response_kind=binary, conditional_request=none
     *
     * @effects unknown_mime_is_served_as_octet_stream_download
     *
     * @return void 반환값 없음
     */
    public function test_file_response_helper_applies_the_same_unknown_mime_rule(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'g7_file_response_mime_');
        file_put_contents($path, 'fixture');
        $this->beforeApplicationDestroyed(static function () use ($path): void {
            @unlink($path);
        });

        $controller = new class extends BaseApiController
        {
            /**
             * 로컬 파일을 주어진 MIME 과 max-age 3600 으로 `fileResponse()` 에 넘겨 결과를 돌려준다.
             *
             * @param  string  $path  서빙할 로컬 파일 경로
             * @param  string  $mimeType  헬퍼에 넘길 MIME 문자열(빈 값 포함)
             * @return Response|BinaryFileResponse 파일 응답 또는 304 응답
             */
            public function serve(string $path, string $mimeType): Response|BinaryFileResponse
            {
                return $this->fileResponse($path, $mimeType, 3600);
            }
        };

        $this->bindRequest();

        foreach (['', '   '] as $blank) {
            $response = $controller->serve($path, $blank);

            $this->assertSame(BaseApiController::UNKNOWN_MIME_TYPE, $response->headers->get('Content-Type'), var_export($blank, true));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        }
    }

    /**
     * `$mimeType` 인자가 200 응답의 `Content-Type` 이 되고, 304 의 `Vary` 판정 근거가 된다.
     *
     * 스트림 응답에 `Content-Type` 이 없어도 인자가 있으면 200/304 가 같은 근거를 쓴다.
     *
     * @scenario response_kind=streamed, conditional_request=if_none_match
     *
     * @effects conditional_response_carries_the_full_cache_axis_of_200
     *
     * @return void 반환값 없음
     */
    public function test_mime_type_argument_drives_content_type_and_vary_on_both_responses(): void
    {
        $this->bindRequest();
        $full = $this->controller()->serve($this->stream(), 'src', ['mimeType' => 'application/xml']);

        $this->assertSame('application/xml', $full->headers->get('Content-Type'));

        $this->bindRequest(['If-None-Match' => (string) $full->headers->get('ETag')]);
        $notModified = $this->controller()->serve($this->stream(), 'src', ['mimeType' => 'application/xml']);

        $this->assertInstanceOf(NotModifiedResponse::class, $notModified);
        $this->assertSame(
            'Accept-Encoding',
            $notModified->headers->get('Vary'),
            '압축 대상 유형의 304 는 200 과 같은 Vary 축을 실어야 한다 — 근거는 인자로 받은 MIME 이다'
        );

        // 압축 대상이 아닌 유형이면 304 에도 붙지 않는다 (반대 방향 비대칭 금지).
        $this->bindRequest(['If-None-Match' => (string) $full->headers->get('ETag')]);
        $image = $this->controller()->serve($this->stream(), 'src', ['mimeType' => 'image/png']);

        $this->assertSame(304, $image->getStatusCode());
        $this->assertNull($image->headers->get('Vary'));
    }

    /**
     * 인자가 없으면 스트림 응답에 세워진 `Content-Type` 을 같은 근거로 쓴다 (기존 호출부 호환).
     *
     * @scenario response_kind=streamed, conditional_request=if_none_match
     *
     * @effects conditional_response_carries_the_full_cache_axis_of_200
     *
     * @return void 반환값 없음
     */
    public function test_response_content_type_is_the_fallback_basis(): void
    {
        $this->bindRequest();
        $full = $this->controller()->serve($this->stream(['Content-Type' => 'text/plain']), 'src');

        $this->bindRequest(['If-None-Match' => (string) $full->headers->get('ETag')]);
        $notModified = $this->controller()->serve($this->stream(['Content-Type' => 'text/plain']), 'src');

        $this->assertSame(304, $notModified->getStatusCode());
        $this->assertSame('Accept-Encoding', $notModified->headers->get('Vary'));
    }

    /**
     * `skipCompression: true` 는 컨테이너에 바인딩된 요청에 제외 표식을 단다.
     *
     * 형제 `PublicStorageController` 가 컨트롤러 진입에서 다는 것과 같은 표식이다 — 표식이
     * 없으면 이 응답이 압축을 피하는 것은 미들웨어가 응답 **클래스**를 보고 건너뛰는 우연이라,
     * 미들웨어 판정이 바뀌는 순간 사용자 업로드물 통로가 전체 적재 경로에 들어간다.
     *
     * @scenario response_kind=streamed, conditional_request=none
     *
     * @effects serving_paths_declare_compression_skip_on_the_request
     *
     * @return void 반환값 없음
     */
    public function test_skip_compression_declares_the_request_attribute(): void
    {
        $request = $this->bindRequest();

        $this->controller()->serve($this->stream(), 'src', ['mimeType' => 'image/png']);
        $this->assertNull($request->attributes->get(GzipEncodeResponse::SKIP_ATTRIBUTE), '선언하지 않으면 표식이 없어야 한다');

        $this->controller()->serve($this->stream(), 'src', ['mimeType' => 'image/png', 'skipCompression' => true]);
        $this->assertTrue($request->attributes->get(GzipEncodeResponse::SKIP_ATTRIBUTE));
    }

    /**
     * 304 경로에서도 제외 표식은 달린다 — 판정보다 앞서 선언되어야 응답 종류와 무관하다.
     *
     * @scenario response_kind=streamed, conditional_request=if_none_match
     *
     * @effects serving_paths_declare_compression_skip_on_the_request
     *
     * @return void 반환값 없음
     */
    public function test_skip_compression_is_declared_before_the_conditional_judgment(): void
    {
        $this->bindRequest();
        $full = $this->controller()->serve($this->stream(), 'src', ['mimeType' => 'text/plain']);

        $request = $this->bindRequest(['If-None-Match' => (string) $full->headers->get('ETag')]);
        $notModified = $this->controller()->serve($this->stream(), 'src', ['mimeType' => 'text/plain', 'skipCompression' => true]);

        $this->assertSame(304, $notModified->getStatusCode());
        $this->assertTrue($request->attributes->get(GzipEncodeResponse::SKIP_ATTRIBUTE));
    }

    /**
     * 다운로드(attachment) 스트림의 `Content-Disposition` 은 그대로 승계된다 — 헬퍼는
     * 그 헤더를 건드리지 않으므로 인라인·다운로드 두 통로가 같은 헬퍼를 지난다.
     *
     * @scenario response_kind=streamed, conditional_request=none
     *
     * @effects attachment_disposition_survives_the_streaming_helper
     *
     * @return void 반환값 없음
     */
    public function test_attachment_disposition_survives_the_helper(): void
    {
        $this->bindRequest();
        $full = $this->controller()->serve(
            $this->stream(['Content-Disposition' => 'attachment; filename=report.pdf']),
            'src',
            ['mimeType' => 'application/pdf']
        );

        $this->assertSame('attachment; filename=report.pdf', $full->headers->get('Content-Disposition'));
        $this->assertNotNull($full->headers->get('ETag'));
    }

    /**
     * 빈 문자열·공백만의 `$mimeType` 은 「모름」과 같다 — 응답 헤더로 폴백한다.
     *
     * 종전 `??=` 는 `null` 만 폴백해, 행 `mime_type` 이 빈 문자열인 첨부(구형 업로드·외부
     * 이관)에서 이 헬퍼가 `LogicException` 을 던져 **무인증 표면이 500** 이 됐다. 알 수 없는
     * 것은 알 수 없는 것이고, 스트림 응답이 세운 값이 있으면 그것이 근거다.
     *
     * @scenario response_kind=streamed, conditional_request=none
     *
     * @effects file_serving_response_declares_nosniff, unknown_mime_is_served_as_octet_stream_download
     *
     * @return void 반환값 없음
     */
    public function test_blank_mime_type_argument_falls_back_to_the_response_header(): void
    {
        foreach (['', '   '] as $blank) {
            $this->bindRequest();
            $full = $this->controller()->serve($this->stream(['Content-Type' => 'text/plain']), 'src', ['mimeType' => $blank]);

            $this->assertSame('text/plain', $full->headers->get('Content-Type'), var_export($blank, true).' 는 null 과 같은 「모름」이어야 한다');
        }

        // 양쪽 다 비어 있으면 추정하지 않고 내려받기다 — 제품 결정(16차 G3 라운드). 종전 기대값
        // (`LogicException`)을 뒤집었다: 알 수 없으면 500 이 아니라 `application/octet-stream`.
        $this->bindRequest();
        $full = $this->controller()->serve($this->stream(['Content-Type' => '']), 'src', ['mimeType' => '  ']);

        $this->assertSame(200, $full->getStatusCode());
        $this->assertSame(BaseApiController::UNKNOWN_MIME_TYPE, $full->headers->get('Content-Type'));
        $this->assertSame('nosniff', $full->headers->get('X-Content-Type-Options'));
    }

    /**
     * 200·304 모두 `X-Content-Type-Options: nosniff` 를 싣는다 — 헬퍼 단일 지점이 붙인다.
     *
     * 업로드된 SVG·HTML 이 앱 오리진에서 실행되는 축이다. 정적 서빙 설정(`.htaccess`·nginx
     * 조각)은 그 헤더를 붙이는데 이 통로만 없으면 링크 유무로 위험이 갈린다. 304 는 200 이
     * 실었을 헤더를 함께 싣는다(RFC 9110 §15.4.5) — 200 이 싣지 않는 축을 304 에만 붙이는
     * 것이 아니라 그 반대다.
     *
     * @scenario response_kind=streamed, conditional_request=if_none_match
     *
     * @effects file_serving_response_declares_nosniff
     *
     * @return void 반환값 없음
     */
    public function test_streamed_responses_declare_nosniff_on_200_and_304(): void
    {
        $this->bindRequest();
        $full = $this->controller()->serve($this->stream(), 'src', ['mimeType' => 'image/svg+xml']);
        $this->assertSame('nosniff', $full->headers->get('X-Content-Type-Options'));

        $this->bindRequest(['If-None-Match' => (string) $full->headers->get('ETag')]);
        $notModified = $this->controller()->serve($this->stream(), 'src', ['mimeType' => 'image/svg+xml']);

        $this->assertSame(304, $notModified->getStatusCode());
        $this->assertSame('nosniff', $notModified->headers->get('X-Content-Type-Options'));
    }

    /**
     * 형제 헬퍼 `fileResponse()` 도 같은 두 축을 갖는다 — `nosniff` 와 `skipCompression` 인자.
     *
     * 종전에는 `skipCompression` 이 스트림 헬퍼에만 있어 로컬 파일 통로(공개 스토리지)는
     * 컨트롤러가 요청 속성을 **직접** 세웠다 — 선언 수단이 통로마다 갈리면 한쪽만 바뀐다.
     *
     * @scenario response_kind=binary, conditional_request=if_none_match
     *
     * @effects file_serving_response_declares_nosniff,
     *          public_storage_response_is_not_buffered_for_compression
     *
     * @return void 반환값 없음
     */
    public function test_file_response_helper_declares_nosniff_and_accepts_skip_compression(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'g7_file_response_');
        file_put_contents($path, 'fixture');
        $this->beforeApplicationDestroyed(static function () use ($path): void {
            @unlink($path);
        });

        $controller = new class extends BaseApiController
        {
            /**
             * 로컬 파일을 `text/plain` 과 max-age 3600 으로 `fileResponse()` 에 넘겨 결과를 돌려준다.
             *
             * @param  string  $path  서빙할 로컬 파일 경로
             * @param  array<string, mixed>  $args  `fileResponse()` 의 명명 인자
             * @return Response|BinaryFileResponse 파일 응답 또는 304 응답
             */
            public function serve(string $path, array $args = []): Response|BinaryFileResponse
            {
                return $this->fileResponse($path, 'text/plain', 3600, ...$args);
            }
        };

        $request = $this->bindRequest();
        $full = $controller->serve($path);
        $this->assertSame('nosniff', $full->headers->get('X-Content-Type-Options'));
        $this->assertNull($request->attributes->get(GzipEncodeResponse::SKIP_ATTRIBUTE), '선언하지 않으면 표식이 없어야 한다');

        $request = $this->bindRequest(['If-None-Match' => (string) $full->headers->get('ETag')]);
        $notModified = $controller->serve($path, ['skipCompression' => true]);
        $this->assertSame(304, $notModified->getStatusCode());
        $this->assertSame('nosniff', $notModified->headers->get('X-Content-Type-Options'));
        $this->assertTrue($request->attributes->get(GzipEncodeResponse::SKIP_ATTRIBUTE), '304 경로에서도 선언은 판정보다 앞선다');
    }
}
