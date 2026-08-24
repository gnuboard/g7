<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Traits;

use App\Helpers\ResponseHelper;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;

/**
 * 클레임 예외 → 응답 매핑 (클레임 컨트롤러 공용)
 *
 * 도메인 예외는 키+치환 파라미터로 422, 그 밖은 500 이다 — 인프라·코드 결함을 입력 오류로
 * 위장하지 않는다. 신청·결제 액션(`HandlesClaimActions`)과 첨부 액션이 같은 매핑을 쓰도록
 * 이 세 메서드만 분리했다. 매핑을 컨트롤러마다 복제하면 한쪽만 고쳐지는 일이 생긴다.
 */
trait MapsClaimErrors
{
    /**
     * 도메인 예외를 422 응답으로 바꿉니다. (키 + 치환 파라미터 — 원문 미노출)
     *
     * @param  OrderClaimException  $e  도메인 예외
     * @return JsonResponse 응답
     */
    protected function claimDomainError(OrderClaimException $e): JsonResponse
    {
        // getMessageKey() 는 모듈 접두를 이미 포함한 완전한 키라 error() 에 그대로 넘긴다.
        $messageKey = $e->getMessageKey();

        return ResponseHelper::error($messageKey, 422, null, $e->getMessageParams());
    }

    /**
     * 대상 부재(소유권 위반 포함) 404 응답을 만듭니다.
     *
     * @param  string  $messageKey  모듈 다국어 키 (첨부 등 대상별 문구가 다를 때 지정)
     * @return JsonResponse 응답
     */
    protected function claimNotFound(string $messageKey = 'exceptions.claim_not_found'): JsonResponse
    {
        return ResponseHelper::moduleError('sirsoft-ecommerce', $messageKey, 404);
    }

    /**
     * 그 외 예외를 500 으로 바꿉니다. (인프라·코드 결함을 입력 오류로 위장하지 않는다)
     *
     * @param  string  $action  액션명 (로그 식별)
     * @param  Exception  $e  예외
     * @return JsonResponse 응답
     */
    protected function claimServerError(string $action, Exception $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            throw $e;
        }

        Log::error("[claims] {$action} 실패", ['error' => $e->getMessage()]);

        return ResponseHelper::error('sirsoft-ecommerce::messages.claims.operation_failed', 500);
    }
}
