<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Support;

use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;

/**
 * 클레임 추가결제 PG 콜백 후 돌아갈 화면 주소를 만듭니다.
 *
 * 결제 플러그인의 콜백 컨트롤러는 주문 결제와 클레임 결제(CP 채번)를 같은 엔드포인트로
 * 받는다. 주문 결제는 각 플러그인의 리다이렉트 설정(`redirect_success_url` 등)으로 주문
 * 완료 화면에 돌아가지만, 클레임 결제가 돌아갈 곳은 신청서 상세 화면이고 그 주소 규칙
 * (회원 마이페이지 / 비회원 상점 경로)은 이 모듈이 소유한다. 플러그인 4종이 각자 주소를
 * 조립하면 화면 경로가 바뀔 때 네 곳이 함께 낡으므로 여기가 단일 출처다.
 *
 * 반환 주소의 쿼리 계약: 성공 `?claim_payment=success`, 실패 `?claim_payment=failed&error={code}`,
 * 사용자 중단 `?claim_payment=cancelled`. 세 경우 모두 `claim_number` 를 함께 싣는다.
 * 돌아갈 화면(유저 템플릿)이 이 파라미터로 결과 안내를 표시한다 — 안내가 없으면 구매자는
 * 결제창에서 돌아온 뒤 돈이 나갔는지 알 수 없다.
 */
final class ClaimPaymentRedirect
{
    /**
     * 회원 신청서 상세 주소 템플릿
     *
     * 값은 `ClaimUrl` 이 소유한다 — 이 상수는 기존 소비 지점(결제 플러그인 4종)의
     * 참조를 끊지 않기 위한 별칭이며, 주소 규칙을 바꿀 때는 `ClaimUrl` 만 고친다.
     */
    public const MEMBER_DETAIL_URL = ClaimUrl::MEMBER_DETAIL;

    /** 비회원 신청서 상세 주소 템플릿 (`{shopBase}` 는 상점 기준 경로로 치환) */
    public const GUEST_DETAIL_URL = ClaimUrl::GUEST_DETAIL;

    /**
     * 결제 성공 후 돌아갈 신청서 상세 주소를 반환합니다.
     *
     * @param  ClaimPayment  $payment  확정된 결제 건
     * @param  array<string, string>  $query  추가 쿼리 파라미터
     * @return string 리다이렉트 주소
     */
    public static function success(ClaimPayment $payment, array $query = []): string
    {
        return self::resolve($payment, ['claim_payment' => 'success'] + $query);
    }

    /**
     * 사용자가 결제창을 스스로 닫았을 때 돌아갈 신청서 상세 주소를 반환합니다.
     *
     * 의도된 중단은 실패가 아니다 — 주문 결제가 사용자 취소를 오류 없이 체크아웃으로
     * 돌려보내는 것과 같은 구분이다. 화면은 오류 안내 없이 재시도만 열어 두면 된다.
     *
     * @param  ClaimPayment  $payment  결제 대기 건
     * @return string 리다이렉트 주소
     */
    public static function cancelled(ClaimPayment $payment): string
    {
        return self::resolve($payment, ['claim_payment' => 'cancelled']);
    }

    /**
     * 결제 실패 후 돌아갈 신청서 상세 주소를 반환합니다.
     *
     * @param  ClaimPayment  $payment  실패한 결제 건
     * @param  string|null  $error  실패 코드 (화면 안내용 — PG 원문 메시지는 싣지 않는다)
     * @param  array<string, string>  $query  추가 쿼리 파라미터
     * @return string 리다이렉트 주소
     */
    public static function fail(ClaimPayment $payment, ?string $error = null, array $query = []): string
    {
        $params = ['claim_payment' => 'failed'] + $query;

        if ($error !== null && $error !== '') {
            $params['error'] = $error;
        }

        return self::resolve($payment, $params);
    }

    /**
     * 결제 건의 신청서 상세 주소를 만듭니다. (회원/비회원 분기)
     *
     * 신청서를 되찾지 못하면(삭제 등) 상세 대신 목록·상점 기준 경로로 보낸다 — 콜백
     * 리다이렉트는 예외를 던질 자리가 아니다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @param  array<string, string>  $query  쿼리 파라미터
     * @return string 리다이렉트 주소
     */
    private static function resolve(ClaimPayment $payment, array $query): string
    {
        $payment->loadMissing(['payable', 'order']);

        $claim = $payment->payable;
        $claimNumber = $claim instanceof OrderExchange
            ? $claim->exchange_number
            : $claim?->return_number;

        // 주문을 읽지 못하면(soft-delete 등) 넓은 쪽이 아니라 좁은 쪽으로 닫는다 — `?->` 결과가
        // null 인 것을 "회원이 아니다" 로 읽으면 로그인으로 보호되던 회원 신청서가 비회원 조회
        // 경로로 넘어간다. 회원 경로는 인증이 막아 주므로 오판의 대가가 작다.
        $order = $payment->order;
        $isGuest = $order !== null && $order->user_id === null;

        // 신청서 상세 화면은 아직 서지 않았다(회원 S13 / 비회원 S14). 그 주소로 보내면 프론트
        // 라우터가 받아 줄 라우트가 없어 404 가 열리고, 결제를 마치고 돌아온 구매자는 돈이
        // 나갔는지조차 확인하지 못한다 — 실패·취소도 사유 없이 같은 404 다(2026-08-31 실측).
        // 그래서 결과를 보여 줄 수 있는 화면으로 돌아간다: 회원은 주문 상세(클레임 추가결제
        // 합계 행이 이미 그려진다), 비회원은 주문 조회 화면(상세는 조회 토큰을 전제하는데
        // 결제창에서 돌아온 브라우저에 그 토큰이 있다고 보장할 수 없다 — 알림 링크가 같은
        // 이유로 이미 조회 화면을 가리킨다).
        //
        // 신청서 상세가 서면 이 세 줄만 `ClaimUrl::{member,guest}Detail($claimNumber)` 로
        // 되돌린다 — 주소 규칙은 `ClaimUrl` 에 그대로 남아 있다.
        $orderNumber = (string) ($order?->order_number ?? '');

        if ($isGuest) {
            $path = ClaimUrl::guestLookup();
        } elseif ($orderNumber !== '') {
            $path = ClaimUrl::memberOrderDetail($orderNumber);
        } else {
            // 주문을 읽지 못하면 목록으로 — 이 경로는 결제 건이 가리키는 주문이 사라진 때뿐이다.
            $path = ClaimUrl::MEMBER_LIST;
        }

        // 어느 신청서의 결제였는지 화면이 알아야 결과 안내에 그 번호를 실을 수 있다. 신청서
        // 상세로 돌아가던 때에는 주소가 그 번호를 품고 있었으나, 주문 상세로 돌아가면 주소에
        // 남지 않는다 — 쿼리로 옮긴다.
        if ($claimNumber !== null && $claimNumber !== '') {
            $query['claim_number'] = $claimNumber;
        }

        $queryString = http_build_query($query);

        return $queryString === '' ? $path : $path.'?'.$queryString;
    }
}
