<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Concerns;

use Plugins\Sirsoft\PayKginicis\Exceptions\KgInicisRejectedException;

/**
 * 결제대행사 호출 실패를 화면·기록에 남길 사유로 바꾼다
 *
 * 실패 사유는 쇼핑몰 취소 안내(회원·비회원·관리자 응답), 일본 CBT 대사 기록(관리자 주문 상세 「환불 오류」),
 * 현금영수증 이력(관리자 주문 상세 결제 정보·구매자 응답)에 실린다. KG이니시스가 돌려준 거절 사유(resultMsg —
 * 서비스가 KgInicisRejectedException 으로 던짐)는 운영자·구매자가 조치에 쓰는 정보라 그대로 둔다. 통신 예외
 * (연결·시간 초과) 원문에는 결제대행사 API 주소와 cURL 오류 문구가, DB 예외 원문에는 SQL·DB 접속 정보가 담기므로
 * 운영 모드에서는 일반 문구로 바꾼다(원문은 호출 측이 서버 로그에 남긴다). 디버그 모드는 코어 관례(SafeExceptionMessage)대로 원문을 둔다.
 */
trait DescribesRefundFailure
{
    /**
     * 환불 실패 사유를 고릅니다.
     *
     * @param  \Throwable  $e  환불 중 잡은 예외
     * @return string 화면·기록에 실을 사유
     */
    protected function refundFailureReason(\Throwable $e): string
    {
        return $this->pgFailureReason($e, 'refund.pg_failed');
    }

    /**
     * 결제대행사 호출 실패 사유를 고릅니다.
     *
     * @param  \Throwable  $e  처리 중 잡은 예외
     * @param  string  $genericKey  거절이 아닐 때 실을 일반 문구 키(`sirsoft-pay_kginicis::messages.` 아래)
     * @return string 화면·기록에 실을 사유
     */
    protected function pgFailureReason(\Throwable $e, string $genericKey): string
    {
        if ($e instanceof KgInicisRejectedException || config('app.debug')) {
            return $e->getMessage();
        }

        return __('sirsoft-pay_kginicis::messages.'.$genericKey);
    }
}
