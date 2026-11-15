<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Exceptions;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * KCP REST API (거래등록 / 결과조회) 통신 오류 시 발생.
 *
 * 연결 타임아웃, SSL 검증 실패, HTTP 5xx, JSON 파싱 실패 등 네트워크/원격 응답 이상이 원인.
 *
 * @since 1.0.0
 */
class RemoteCallException extends RuntimeException
{
    /**
     * 호출 실패 시점의 컨텍스트를 보존한다 (PII 는 절대 보존하지 않는다).
     *
     * @param  string  $detail  호출 실패 상세 (통신 오류 메시지 또는 HTTP status 텍스트)
     * @param  int  $httpStatus  HTTP status code (0 = 통신 자체 실패)
     * @param  Throwable|null  $previous  체인 예외
     */
    public function __construct(
        public readonly string $detail,
        public readonly int $httpStatus = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            __('sirsoft-verification_nhnkcp::exceptions.remote_call_failed'),
            $httpStatus,
            $previous,
        );
    }

    /**
     * 잡히지 않고 API 응답까지 올라온 통신 실패를 503 으로 답합니다.
     *
     * 거래등록(본인인증 시작)은 코어 컨트롤러를 거쳐 호출되므로 플러그인이 직접 잡을 자리가 없다. 그대로 두면
     * 일시적인 KCP 장애가 500(서버 오류)으로 보인다 — 다시 시도하라는 안내와 함께 503 으로 돌려준다(1.1.4).
     * 웹 요청은 프레임워크 기본 처리에 맡긴다.
     *
     * @param  Request  $request  현재 요청
     * @return JsonResponse|null API 요청이면 503 응답, 아니면 null(기본 처리)
     */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return null;
        }

        return ResponseHelper::error('sirsoft-verification_nhnkcp::exceptions.remote_call_failed', 503, [
            'failure_code' => 'REMOTE_CALL_FAILED',
        ]);
    }
}
