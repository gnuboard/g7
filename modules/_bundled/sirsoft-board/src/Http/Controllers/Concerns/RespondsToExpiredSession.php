<?php

namespace Modules\Sirsoft\Board\Http\Controllers\Concerns;

use App\Http\Middleware\OptionalSanctumMiddleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 본인 확인 실패 응답 — 만료 토큰이면 세션 만료(401), 아니면 권한 없음(403) (#883 후속)
 *
 * `optional.sanctum` 은 만료 토큰을 비회원으로 통과시킨다. 비회원도 쓰기·업로드 권한이 있는 게시판에서 토큰이 만료된 회원이 본인 글을
 * 다루면 본인 확인이 비회원 기준으로 실패해 「권한이 없습니다」가 나왔다. 다시 로그인하면 되는 상황이므로 401 로 알려, 토큰을 실은
 * 요청의 401 을 받는 화면이 토큰 갱신 → 세션 만료 안내로 이어 가게 한다.
 */
trait RespondsToExpiredSession
{
    /**
     * 본인 확인 실패 응답을 만듭니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  string  $messageKey  토큰이 만료되지 않았을 때의 403 문구 키
     * @return JsonResponse 401(토큰 만료) 또는 403
     */
    protected function ownershipDenied(Request $request, string $messageKey): JsonResponse
    {
        if (OptionalSanctumMiddleware::hadExpiredToken($request)) {
            return $this->error('auth.token_expired', 401);
        }

        return $this->error($messageKey, 403);
    }
}
