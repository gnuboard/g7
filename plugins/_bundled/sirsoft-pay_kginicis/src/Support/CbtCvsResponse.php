<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Support;

/**
 * CBT(엔화) 승인 응답이 편의점(CVS) 결제번호 발급인지 **응답만으로** 판정합니다.
 *
 * 판정 근거는 승인 응답(/cbtapprove)이다 — 결제수단 필드가 CVS 이거나, 결제수단이 비어 와도 CVS 발급
 * 응답에만 실리는 필드(결제번호 confNo·receiptNo, 편의점 convenience, 납부기한 paymentTerm — 매뉴얼상
 * CVS 승인 응답 전용)가 하나라도 있으면 CVS 다. 브라우저 콜백의 paymethod 는 조작할 수 있으므로 CVS 를
 * **통과시키는** 근거로 쓰지 않는다 — 콜백이 CVS 를 숨기면 입금 전 결제번호 발급이 결제완료로 확정된다.
 *
 * 주문 경로(`CbtCallbackController`)와 클레임 경로(`ClaimPaymentApproveListener`)가 같은 판정을 쓴다.
 */
final class CbtCvsResponse
{
    /**
     * CVS 발급 응답에만 실리는 필드.
     */
    private const CVS_ONLY_FIELDS = ['confNo', 'receiptNo', 'convenience', 'paymentTerm'];

    /**
     * 승인 응답이 CVS 발급인지 판정합니다.
     *
     * @param  array<string, mixed>  $pgResponse  CBT 승인 응답
     * @return bool CVS 발급이면 true
     */
    public static function matches(array $pgResponse): bool
    {
        if (strtoupper(self::responsePayMethod($pgResponse)) === 'CVS') {
            return true;
        }

        foreach (self::CVS_ONLY_FIELDS as $field) {
            if (trim((string) ($pgResponse[$field] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * 승인 응답의 결제수단을 원문 그대로(앞뒤 공백만 제거) 돌려줍니다. (없으면 빈 문자열)
     *
     * 대소문자를 바꾸지 않는다 — 결제 메타에 PG 가 준 값을 그대로 기록하기 위해서다. 비교하는 쪽이
     * 정규화한다.
     *
     * @param  array<string, mixed>  $pgResponse  CBT 승인 응답
     * @return string 결제수단 (CARD·PayPay·CVS 등)
     */
    public static function responsePayMethod(array $pgResponse): string
    {
        foreach (['paymethod', 'payMethod'] as $key) {
            $value = trim((string) ($pgResponse[$key] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
