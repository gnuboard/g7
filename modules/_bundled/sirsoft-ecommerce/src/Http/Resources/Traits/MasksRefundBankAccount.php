<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources\Traits;

use App\Enums\PermissionType;
use Illuminate\Support\Facades\Auth;

/**
 * 환불 계좌 마스킹 (표시 계층 단일 출처)
 *
 * 환불 계좌는 평문으로 저장된다. 그래서 마스킹 책임이 표시 계층에 있고, 같은 컬럼을 내보내는
 * 리소스가 둘 이상이면 한쪽만 가려도 다른 쪽이 그대로 우회로가 된다 — 실제로 클레임 응답은
 * 가리고 주문 결제 응답은 평문이라, 같은 비회원 토큰으로 주문상세를 열면 마스킹이 무력화됐다.
 * 규칙을 이 트레이트 한 곳에 두고 두 리소스가 함께 쓴다.
 *
 * 운영자(주문 관리 권한 보유)는 원문을 본다 — 취소 모달이 이 값을 프리필하고 PG 환불이 같은
 * 컬럼을 읽으므로, 가리면 환불 실행 자체가 막힌다.
 */
trait MasksRefundBankAccount
{
    /**
     * 열람자가 환불 계좌 원문을 볼 자격이 있는지 판정합니다.
     *
     * @return bool 원문 열람 가능 여부
     */
    protected function canSeeRawRefundBank(): bool
    {
        return Auth::check()
            && Auth::user()->hasPermission('sirsoft-ecommerce.orders.read', PermissionType::Admin);
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

        $length = mb_strlen($account);

        return $length <= 4 ? $account : str_repeat('*', $length - 4).mb_substr($account, -4);
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

        $length = mb_strlen($holder);

        return $length <= 1 ? $holder : mb_substr($holder, 0, 1).str_repeat('*', $length - 1);
    }
}
