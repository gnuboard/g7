<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Traits;

use App\Helpers\ResponseHelper;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;

/**
 * 클레임 예외 → 응답 매핑 (클레임 컨트롤러 공용)
 *
 * 도메인 예외는 키+치환 파라미터로 422, 그 밖은 500 이다 — 인프라·코드 결함을 입력 오류로
 * 위장하지 않는다. 신청·결제 액션(`HandlesClaimActions`)과 첨부 액션이 같은 매핑을 쓰도록
 * 이 메서드들만 분리했다. 매핑을 컨트롤러마다 복제하면 한쪽만 고쳐지는 일이 생긴다.
 *
 * 500 응답의 원인 요약(`detail`)은 **관리자 면만** 싣는다(`exposesServerErrorDetail()`) — 운영자는
 * 서버 로그 없이도 조치 방향을 알아야 하지만, 회원·비회원 면에 예외 원문(쿼리·경로·PG 응답)을
 * 내보내면 내부 구조가 그대로 새어 나간다.
 */
trait MapsClaimErrors
{
    /**
     * 500 응답에 원인 요약(`detail`)을 싣는 면인지 반환합니다. (기본: 싣지 않음 — 공개 면)
     *
     * @return bool 싣는 면이면 true
     */
    protected function exposesServerErrorDetail(): bool
    {
        return false;
    }

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
     * 취소·환불 도메인 거절을 **공개 면** 422 응답으로 바꿉니다. (PG 원문 미노출)
     *
     * 사유 키가 실려 오면 그 키+파라미터로 안내한다 — 클레임 수량 잠금·상태 자격처럼 고객이 알아야
     * 할 사유다. 키가 없으면(PG 환불 거절 등) 일반 실패 문구만 낸다: 그 예외의 메시지에는 PG 가
     * 돌려준 원문(가맹점 설정·내부 코드)이 섞여 있어 고객 화면에 그대로 내보낼 수 없다. 원문은
     * 로그로 남긴다 — 운영자 면(관리자 컨트롤러)은 이 매핑을 쓰지 않고 원문을 `detail` 로 싣는다.
     *
     * @param  OrderCancellationException  $e  취소·환불 도메인 예외
     * @param  string  $action  액션명 (로그 식별)
     * @return JsonResponse 응답
     */
    protected function publicCancellationError(OrderCancellationException $e, string $action): JsonResponse
    {
        // PG 원문을 치환 파라미터로 품는 키(`pg_refund_failed`)도 여기서 걸러진다(`publicMessageKey()`).
        $messageKey = $e->publicMessageKey();

        if ($messageKey === null) {
            Log::warning("[claims] {$action} 취소·환불 거절", ['error' => $e->getMessage()]);

            return ResponseHelper::error('sirsoft-ecommerce::exceptions.order_cancel_failed', 422);
        }

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
     * 관리자 면(`exposesServerErrorDetail()` 이 true)은 원인 요약을 `errors.detail` 로 싣는다 —
     * 배열로 넘기므로 운영 환경에서도 걸러지지 않는다(문자열 오류만 차단된다).
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

        return ResponseHelper::error(
            'sirsoft-ecommerce::messages.claims.operation_failed',
            500,
            $this->exposesServerErrorDetail() ? ['detail' => $e->getMessage()] : null,
        );
    }
}
