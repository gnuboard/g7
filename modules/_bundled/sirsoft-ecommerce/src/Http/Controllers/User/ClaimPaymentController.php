<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\User;

use App\Http\Controllers\Api\Base\AuthBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Traits\HandlesClaimActions;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\ClaimPaymentApproveRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\ClaimPaymentPayRequest;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;

/**
 * 회원 클레임 추가결제 컨트롤러
 *
 * 결제 대기 건(CP)의 결제 시작(결제 수단 확정 → PG 결제창 파라미터 / 마일리지 즉시 결제)과
 * PG 콜백 승인을 받는다. 결제 건의 소유권은 결제 건이 속한 주문의 회원으로 판정한다.
 */
class ClaimPaymentController extends AuthBaseController
{
    use HandlesClaimActions;

    public function __construct(
        private UserClaimService $claimService,
    ) {}

    /**
     * 결제 시작
     *
     * @param  ClaimPaymentPayRequest  $request  검증된 요청
     * @param  string  $paymentNumber  결제번호
     * @return JsonResponse 응답
     */
    public function pay(ClaimPaymentPayRequest $request, string $paymentNumber): JsonResponse
    {
        $this->logApiUsage('user.claim-payments.pay');

        return $this->respondPay($paymentNumber, $request->paymentMethod());
    }

    /**
     * PG 승인 콜백
     *
     * @param  ClaimPaymentApproveRequest  $request  검증된 요청
     * @param  string  $paymentNumber  결제번호
     * @return JsonResponse 응답
     */
    public function approve(ClaimPaymentApproveRequest $request, string $paymentNumber): JsonResponse
    {
        $this->logApiUsage('user.claim-payments.approve');

        return $this->respondApprove($paymentNumber, $request->callback());
    }

    /**
     * {@inheritDoc}
     */
    protected function viewer(): array
    {
        return ['user_id' => Auth::id(), 'guest_order' => null];
    }

    /**
     * {@inheritDoc}
     */
    protected function claims(): UserClaimService
    {
        return $this->claimService;
    }
}
