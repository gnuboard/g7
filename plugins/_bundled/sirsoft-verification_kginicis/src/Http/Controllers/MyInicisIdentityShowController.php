<?php

namespace Plugins\Sirsoft\VerificationKginicis\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Plugins\Sirsoft\VerificationKginicis\Http\Resources\InicisIdentityResource;
use Plugins\Sirsoft\VerificationKginicis\Services\InicisIdentityCardService;

/**
 * 마이페이지 본인인증 카드 데이터 제공 API.
 *
 * 사용자가 자기 본인확인 정보 (마스킹) 를 조회한다. record 가 없으면 data=null 반환
 * (코어 표준 null 처리 패턴 — Resource 가 null 처리 안 하므로 컨트롤러에서 분기).
 *
 * @since 1.0.0-beta.1
 */
class MyInicisIdentityShowController extends AuthBaseController
{
    /**
     * @param  InicisIdentityCardService  $cardService  본인인증 카드 데이터 Service
     */
    public function __construct(
        protected readonly InicisIdentityCardService $cardService,
    ) {
        parent::__construct();
    }

    /**
     * GET /api/me/identity/inicis — 현재 사용자의 이니시스 본인확인 정보 조회.
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        $record = $this->cardService->findForUser((int) Auth::id());

        if ($record === null) {
            return ResponseHelper::success('messages.success', null);
        }

        return ResponseHelper::success(
            'messages.success',
            (new InicisIdentityResource($record))->resolve(),
        );
    }
}
