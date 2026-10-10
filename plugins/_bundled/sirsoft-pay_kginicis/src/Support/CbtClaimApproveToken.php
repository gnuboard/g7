<?php

namespace Plugins\Sirsoft\PayKginicis\Support;

/**
 * CBT(엔화) 클레임 추가결제 승인 재료(sid)를 서버 콜백이 넘겼음을 증명하는 서명입니다.
 *
 * CBT 승인 재료는 sid 하나이고 승인 응답에는 금액·주문번호가 없다(매뉴얼상 미사용). 그래서
 * 클라이언트 승인 API(`claim-payments/{no}/approve`)가 콜백 원문을 그대로 받으면, 싼 결제 건으로
 * 연 결제창의 sid 를 비싼 결제 건의 승인에 실어 보낼 수 있었다 — KG 는 싼 금액으로 승인하고
 * 모듈은 비싼 결제 건을 결제완료로 확정한다.
 *
 * 콜백 컨트롤러는 결제 건 번호와 sid 를 서버 키(`app.key`)로 서명해 함께 넘기고, 승인 리스너는
 * 이 서명이 맞을 때만 PG 를 부른다. 서버 키는 클라이언트가 알 수 없으므로 승인 API 로는 이
 * 서명을 만들 수 없다.
 *
 * @since 1.2.0
 */
final class CbtClaimApproveToken
{
    /**
     * 결제 건 번호와 sid 에 대한 서명을 만듭니다.
     *
     * @param  string  $paymentNumber  클레임 결제 건 번호 (CP…)
     * @param  string  $sid  CBT 세션 ID
     * @return string 서명 (hex)
     */
    public static function sign(string $paymentNumber, string $sid): string
    {
        return hash_hmac('sha256', 'kginicis-cbt-claim-approve|'.$paymentNumber.'|'.$sid, (string) config('app.key'));
    }

    /**
     * 서명이 이 결제 건·sid 에 대해 서버가 만든 것인지 확인합니다.
     *
     * @param  string  $paymentNumber  클레임 결제 건 번호 (CP…)
     * @param  string  $sid  CBT 세션 ID
     * @param  string|null  $token  콜백에 실려 온 서명
     * @return bool 일치하면 true
     */
    public static function verify(string $paymentNumber, string $sid, ?string $token): bool
    {
        if ($token === null || $token === '' || $sid === '') {
            return false;
        }

        return hash_equals(self::sign($paymentNumber, $sid), $token);
    }
}
