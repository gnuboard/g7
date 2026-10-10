<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Concerns;

use App\Helpers\ResponseHelper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException;

/**
 * 카카오 관리 API(kapi) 위임 액션을 공통 래핑하는 트레이트.
 *
 * kapi 실패(자격증명 미설정·반려·차단 등)는 BizppurioApiException 으로 전달되므로, 이를 catch
 * 하여 카카오가 준 실패 사유(message)와 결과 코드를 그대로 422 로 반환한다. 운영자가 반려/차단
 * 사유를 화면에서 바로 확인할 수 있게 한다. 알림톡 템플릿 관리(Phase 5)와 연동(Phase 6)
 * 컨트롤러가 공유한다.
 *
 * errors 키는 `bizppurio_message` 로 고정한다 — 이 플러그인의 다른 kapi 경로
 * (BizppurioTemplateController·TokenCheckController)와 화면 소비처, API 문서가 모두 그 키를
 * 읽는다. 이 트레이트만 다른 키를 쓰면 카테고리·발신프로필 조회 실패에서만 사유 원문이
 * 버려지고 고정 문구만 노출된다.
 *
 * 비즈뿌리오 서버에 닿지 못한 경우(타임아웃·DNS·프록시 실패)는 BizppurioApiException 이 아니라
 * ConnectionException 으로 올라온다. 이것도 연결 실패 안내 422 로 돌려준다 — 연결 확인
 * (TokenCheckController)과 같은 규약이다. 원인은 경고 로그로 남긴다.
 */
trait GuardsKakaoRequests
{
    /**
     * kapi 위임 콜백을 실행하고 실패 시 카카오 사유 또는 연결 실패 안내를 422 로 반환합니다.
     *
     * @param  callable():JsonResponse  $callback  kapi 위임 액션
     * @return JsonResponse 성공 응답 또는 422(카카오 사유, 연결 실패 안내)
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
                    'bizppurio_message' => $e->getMessage(),
                    'result_code' => $e->getResultCode(),
                ],
            );
        } catch (ConnectionException $e) {
            return $this->kakaoConnectionFailed($e);
        }
    }

    /**
     * 비즈뿌리오 서버 연결 실패를 경고 로그로 남기고 연결 실패 안내 422 를 반환합니다.
     *
     * @param  ConnectionException  $e  연결 실패 예외
     * @return JsonResponse 연결 실패 안내 422
     */
    private function kakaoConnectionFailed(ConnectionException $e): JsonResponse
    {
        Log::warning('[Bizppurio] 카카오 관리 API 연결 실패', ['error' => $e->getMessage()]);

        return ResponseHelper::error('sirsoft-message_bizppurio::messages.error.connection_failed', 422);
    }
}
