<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Plugins\Sirsoft\VerificationNhnkcp\Http\Resources\KcpIdentityResource;
use Plugins\Sirsoft\VerificationNhnkcp\Services\KcpIdentityCardService;

/**
 * 마이페이지 본인확인 카드 데이터 제공 API.
 *
 * 사용자가 자기 본인확인 정보(마스킹)를 조회한다. record 가 없으면 data=null 을 반환한다
 * (코어 표준 null 처리 패턴 — Resource 가 null 을 처리하지 않으므로 컨트롤러에서 분기).
 *
 * @since 1.0.0
 */
class MyKcpIdentityShowController extends AuthBaseController
{
    /**
     * @param  KcpIdentityCardService  $cardService  본인확인 카드 데이터 Service
     */
    public function __construct(
        protected readonly KcpIdentityCardService $cardService,
    ) {
        parent::__construct();
    }

    /**
     * GET /api/plugins/sirsoft-verification_nhnkcp/me/identity/nhnkcp
     * — 현재 사용자의 KCP 본인확인 정보 조회.
     *
     * @return JsonResponse 본인확인 카드 데이터 (미인증 시 data: null)
     */
    public function show(): JsonResponse
    {
        $record = $this->cardService->findForUser((int) Auth::id());

        if ($record === null) {
            return ResponseHelper::success('messages.success', null);
        }

        return ResponseHelper::success(
            'messages.success',
            (new KcpIdentityResource($record))->resolve(),
        );
    }
}
