<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Concerns;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException;

/**
 * 카카오 관리 API(kapi) 위임 액션을 공통 래핑하는 트레이트.
 *
 * kapi 실패(자격증명 미설정·반려·차단 등)는 BizppurioApiException 으로 전달되므로, 이를 catch
 * 하여 카카오가 준 실패 사유(message)와 결과 코드를 그대로 422 로 반환한다. 운영자가 반려/차단
 * 사유를 화면에서 바로 확인할 수 있게 한다. 알림톡 템플릿 관리(Phase 5)와 연동(Phase 6)
 * 컨트롤러가 공유한다.
 */
trait GuardsKakaoRequests
{
    /**
     * kapi 위임 콜백을 실행하고 실패 시 카카오 사유를 422 로 반환합니다.
     *
     * @param  callable():JsonResponse  $callback  kapi 위임 액션
     * @return JsonResponse 성공 응답 또는 카카오 사유가 담긴 422
     */
    private function guard(callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (BizppurioApiException $e) {
            return ResponseHelper::error(
                'sirsoft-message_bizppurio::messages.error.kakao_request_failed',
                422,
                [
                    'kakao_message' => $e->getMessage(),
                    'result_code' => $e->getResultCode(),
                ],
            );
        }
    }
}
