<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources\Traits;

use App\Enums\PermissionType;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Support\RefundBankMask;

/**
 * 환불 계좌 마스킹 (표시 계층)
 *
 * 환불 계좌는 평문으로 저장된다. 그래서 마스킹 책임이 표시 계층에 있고, 같은 컬럼을 내보내는
 * 리소스가 둘 이상이면 한쪽만 가려도 다른 쪽이 그대로 우회로가 된다 — 실제로 클레임 응답은
 * 가리고 주문 결제 응답은 평문이라, 같은 비회원 토큰으로 주문상세를 열면 마스킹이 무력화됐다.
 * 가리는 모양은 `RefundBankMask` 한 곳이 정하고 두 리소스가 이 트레이트로 함께 쓴다.
 *
 * 원문은 **운영자**가 본다 — 주문 관리 권한 또는 클레임 관리 권한(`claims.*`) 가운데 하나다.
 * 취소 모달이 이 값을 프리필하고 계좌 환불 실행([환불하기])이 같은 컬럼으로 송금하므로, 환불
 * 실행만 맡은 역할(`claims.execute-refund`)이 가린 계좌만 보면 어디로 보낼지 알 수 없다.
 * 스코프 판정(그 신청서·주문을 볼 수 있는가)은 이 값을 싣기 전에 라우트·가드가 이미 끝냈다.
 */
trait MasksRefundBankAccount
{
    /**
     * 환불 계좌 원문을 볼 수 있는 관리자 권한 (하나라도 있으면 원문)
     *
     * @var array<int, string>
     */
    protected static array $rawRefundBankPermissions = [
        'sirsoft-ecommerce.orders.read',
        'sirsoft-ecommerce.claims.read',
        'sirsoft-ecommerce.claims.update-status',
        'sirsoft-ecommerce.claims.update-amounts',
        'sirsoft-ecommerce.claims.execute-refund',
    ];

    /**
     * 열람자가 환불 계좌 원문을 볼 자격이 있는지 판정합니다.
     *
     * @return bool 원문 열람 가능 여부
     */
    protected function canSeeRawRefundBank(): bool
    {
        return Auth::check()
            && Auth::user()->hasPermissions(static::$rawRefundBankPermissions, false, PermissionType::Admin);
    }

    /**
     * 계좌번호를 열람자 자격에 따라 반환합니다. (뒤 4자리만 남긴다)
     *
     * @param  string|null  $account  계좌번호
     * @return string|null 표시용 계좌번호
     */
    protected function refundBankAccountForViewer(?string $account): ?string
    {
        if ($account === null || $account === '' || $this->canSeeRawRefundBank()) {
            return $account === '' ? null : $account;
        }

        return RefundBankMask::account($account);
    }

    /**
     * 예금주명을 열람자 자격에 따라 반환합니다. (첫 글자만 남긴다)
     *
     * 계좌만 가리면 은행코드 + 실명 + 뒤 4자리 조합이 남아 특정이 가능하다.
     *
     * @param  string|null  $holder  예금주명
     * @return string|null 표시용 예금주명
     */
    protected function refundBankHolderForViewer(?string $holder): ?string
    {
        if ($holder === null || $holder === '' || $this->canSeeRawRefundBank()) {
            return $holder === '' ? null : $holder;
        }

        return RefundBankMask::holder($holder);
    }
}
