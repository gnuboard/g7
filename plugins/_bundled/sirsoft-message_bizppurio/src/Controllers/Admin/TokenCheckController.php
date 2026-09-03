<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException;
use Plugins\Sirsoft\MessageBizppurio\Services\BizppurioTokenService;

/**
 * 비즈뿌리오 인증 토큰 컨트롤러 — 설정 화면 "연결 확인" 버튼이 호출.
 *
 * 관리자가 설정 화면에서 저장한 계정/비밀번호가 실제로 유효한지 그 자리에서
 * 확인할 수 있게 한다. 캐시를 거치지 않고 매번 `/v1/token` 을 새로 호출해
 * 검증하며, 성공 시 발급된 토큰을 캐시에 반영한다(BizppurioTokenService::
 * verifyCredentials 참고).
 */
class TokenCheckController extends AdminBaseController
{
    /**
     * @param  BizppurioTokenService  $tokenService  비즈뿌리오 인증 토큰 서비스
     */
    public function __construct(
        private readonly BizppurioTokenService $tokenService,
    ) {
        parent::__construct();
    }

    /**
     * 저장된 자격증명으로 토큰 발급을 즉시 재검증합니다.
     *
     * 비즈뿌리오 서버 자체에 연결할 수 없는 경우(타임아웃·DNS 실패 등)는
     * BizppurioApiException 이 아닌 ConnectionException 으로 던져지므로 별도
     * catch 하여 500 대신 422 로 응답한다.
     *
     * @return JsonResponse 성공 시 200(발급 확인), 실패 시 422(비즈뿌리오 실패 사유 또는 연결 실패 안내)
     */
    public function check(): JsonResponse
    {
        try {
            $this->tokenService->verifyCredentials();

            return ResponseHelper::success('sirsoft-message_bizppurio::messages.token_check.success');
        } catch (BizppurioApiException $e) {
            // 비즈뿌리오가 준 실패 사유 원문은 메시지 키가 아니라 관리자 전용 errors
            // 페이로드로 전달한다 (키 자리에 원문 전달 금지 — docs/backend/exceptions.md).
            return ResponseHelper::error(
                'sirsoft-message_bizppurio::messages.token_check.failed',
                422,
                [
                    'bizppurio_message' => $e->getMessage(),
                    'result_code' => $e->getResultCode(),
                ],
            );
        } catch (ConnectionException) {
            return ResponseHelper::error(
                'sirsoft-message_bizppurio::messages.error.connection_failed',
                422,
            );
        }
    }
}
