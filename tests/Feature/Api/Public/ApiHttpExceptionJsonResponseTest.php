<?php

namespace Tests\Feature\Api\Public;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * `api/*` 의 HTTP 예외(`abort(404)`·`abort(403)`·`abort(503)`)는 `Accept` 헤더와 무관하게 JSON 이다 (DB 미사용).
 *
 * 파일 서빙 헬퍼(`BaseApiController::fileResponse()`)는 판정과 서빙 사이에 파일이 사라지면
 * `abort(404)`, 읽을 수 없으면 `abort(403)` 을 던진다. 그 요청은 `<img src>` 라
 * `Accept: image/*` 로 오는데, 프레임워크 기본값은 그 요청에 **HTML 오류 페이지**를 돌려줬다 —
 * 같은 라우트가 `Accept` 에 따라 JSON 과 HTML 을 갈라 내고, 링크가 되는 서버의 정적 서빙
 * (빈 본문 404)과도 갈렸다. 형제 렌더러(검증 실패·인증·IDV·등급 상한·코어 버전)는 전부
 * `api/*` 를 JSON 으로 고정하는데 이 종류만 빠져 있었다.
 *
 * @scenario api_validation_client=browser
 */
class ApiHttpExceptionJsonResponseTest extends TestCase
{
    /**
     * `api` 미들웨어 아래에 404, 403, 503(재시도 안내 헤더 포함)을 던지는 임시 라우트를 등록해 렌더러 한 곳만 재도록 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 실제 서빙 통로와 같은 형태의 `abort()` 를 던지는 임시 라우트 — 파일 존재 여부에 의존하지
        // 않고 렌더러 한 곳만 잰다. 재시도 안내 헤더(`Retry-After`)를 실은 형태도 함께 둔다.
        Route::middleware('api')->prefix('api/_contract')->group(function (): void {
            Route::get('missing', fn () => abort(404));
            Route::get('unreadable', fn () => abort(403));
            Route::get('not-ready', fn () => abort(503, 'not ready', ['Retry-After' => '120']));
        });
    }

    /**
     * 브라우저 형태 요청(`Accept: image/*`)의 404·403 도 JSON 이다 — HTML 오류 페이지가 아니다.
     *
     * @scenario api_validation_client=browser
     *
     * @effects api_http_exception_is_json_regardless_of_accept
     *
     * @return void 반환값 없음
     */
    public function test_browser_style_request_gets_json_for_abort_404_and_403(): void
    {
        foreach (['missing' => 404, 'unreadable' => 403] as $path => $status) {
            $response = $this->get("/api/_contract/{$path}", ['Accept' => 'image/*']);

            $response->assertStatus($status);
            $this->assertStringStartsWith(
                'application/json',
                (string) $response->headers->get('Content-Type'),
                "{$status} 가 JSON 이 아니다 — Accept 헤더에 따라 HTML 오류 페이지가 나간다"
            );
            $response->assertJson(['success' => false]);
            $this->assertNotSame('', $response->json('message'), '메시지 없는 abort() 도 상태 코드의 기본 문구를 실어야 한다');
        }
    }

    /**
     * 예외가 실은 헤더(`Retry-After`)는 JSON 응답에도 그대로 승계된다 — `api/*` 의 지연·레이트리밋
     * 응답이 봇에 재시도 간격을 알리는 통로다.
     *
     * @scenario api_validation_client=browser
     *
     * @effects api_http_exception_is_json_regardless_of_accept
     *
     * @return void 반환값 없음
     */
    public function test_exception_headers_survive_the_json_rendering(): void
    {
        $response = $this->get('/api/_contract/not-ready', ['Accept' => 'text/html']);

        $response->assertStatus(503);
        $response->assertHeader('Retry-After', '120');
        $response->assertJson(['success' => false, 'message' => 'not ready']);
    }

    /**
     * 존재하지 않는 `api/*` 주소도 같다 — 라우트 미매칭 404 역시 HTTP 예외다.
     *
     * @scenario api_validation_client=browser
     *
     * @effects api_http_exception_is_json_regardless_of_accept
     *
     * @return void 반환값 없음
     */
    public function test_unmatched_api_route_is_json_for_browser_style_requests(): void
    {
        $response = $this->get('/api/_contract/definitely-not-a-route', ['Accept' => 'image/*']);

        $response->assertNotFound();
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    }

    /**
     * XHR(`Accept: application/json`) 도 같은 렌더러가 채운다 — `{success:false}` + 비어있지 않은 메시지.
     *
     * 종전 조건(`is('api/*') && !expectsJson()`)은 XHR 요청을 이 렌더러 밖으로 흘려, 프레임워크
     * 기본값이 본문 없는 HTTP 예외를 `{"message":""}`(빈 문자열)로 돌려줬다 — 브라우저 형태 요청은
     * `{success,message}` 를 받고 XHR 은 `{message:""}` 를 받아 같은 라우트의 응답 형태가 갈렸다.
     *
     * @scenario api_validation_client=xhr
     *
     * @effects api_http_exception_is_json_regardless_of_accept
     *
     * @return void 반환값 없음
     */
    public function test_xhr_request_gets_success_false_and_non_empty_message(): void
    {
        $response = $this->getJson('/api/_contract/missing');

        $response->assertNotFound();
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $response->assertJson(['success' => false]);
        $this->assertNotSame('', $response->json('message'), 'XHR 도 상태 코드의 기본 문구를 실어야 한다 — 빈 문자열 금지');
    }
}
