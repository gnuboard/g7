<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Support;

use Modules\Sirsoft\Ecommerce\Listeners\EcommerceNotificationDataListener;

/**
 * 클레임 신청서 화면 주소의 SSoT.
 *
 * 같은 주소 규칙을 여러 곳이 각자 조립하면 화면 경로가 바뀔 때 한 곳만 낡고,
 * 그 경로에서만 링크가 조용히 죽는다(예외도 404 도 남지 않는다 — 프론트 라우터가
 * 없는 주소를 받으면 오류 화면으로 갈 뿐이다). 소비 지점은 다섯이다.
 *
 *  - PG 콜백 리다이렉트 (`ClaimPaymentRedirect`) — 회원/비회원 상세
 *  - 관리자 주문상세 클레임 내역 (`OrderClaimHistoryResource`) — 관리자 상세
 *  - 알림 메일·사이트내 알림 (`ClaimNotificationDataListener`) — 회원 상세 / 비회원 조회 진입
 *  - 관리자 신청서 상세의 연결 신청서 (`Admin\ClaimController` linked_claims) — 관리자 상세
 *  - 회원·비회원 신청서 상세의 연결 신청서 (`HandlesClaimActions::respondShow`) — 회원 상세 / 비회원 상세
 *
 * 비회원 상세(`GUEST_DETAIL`)는 기본 템플릿 라우트 `/{shop}/guest/claims/:claim_number` 로 서 있다. 이 화면은
 * 조회 토큰을 가진 브라우저에서만 열리므로, 토큰을 전제할 수 없는 진입(알림 링크·결제 결과 폴백)은 주문 조회
 * 화면(`guestLookup`)으로 보낸다. 같은 화면 안의 연결 신청서 링크(보완 5)는 토큰이 있으므로 상세로 보낸다.
 *
 * 비회원에게 보내는 **알림 링크**만 상세가 아니라 비회원 주문 조회 화면을 가리킨다.
 * 비회원 상세는 조회 토큰을 전제하는데 메일에서 바로 눌러 들어온 브라우저에는 그
 * 토큰이 없어, 상세 주소를 그대로 실으면 열리지 않는 화면으로 보내게 된다. 주문 알림이
 * 같은 이유로 이미 조회 화면을 가리킨다 — 그 경로를 재사용한다.
 */
final class ClaimUrl
{
    /** 회원 신청서 상세 경로 템플릿 */
    public const MEMBER_DETAIL = '/mypage/claims/{claimNumber}';

    /** 회원 신청서 목록 경로 (번호 해석 불가 시 폴백) */
    public const MEMBER_LIST = '/mypage/claims';

    /** 회원 주문 상세 경로 템플릿 (신청서 화면이 아직 없을 때 결제 결과를 보여 줄 자리) */
    public const MEMBER_ORDER_DETAIL = '/mypage/orders/{orderNumber}';

    /** 비회원 신청서 상세 경로 템플릿 (`{shopBase}` 는 상점 기준 경로로 치환) */
    public const GUEST_DETAIL = '{shopBase}/guest/claims/{claimNumber}';

    /** 관리자 신청서 상세 경로 템플릿 (앱 내 경로 — origin 은 소비 지점이 정한다) */
    public const ADMIN_DETAIL = '/admin/ecommerce/claims/{claimNumber}';

    /** 관리자 신청서 목록 경로 (번호 해석 불가 시 폴백 — 운영자는 목록에서 찾아 들어간다) */
    public const ADMIN_LIST = '/admin/ecommerce/claims';

    /**
     * 회원 신청서 상세 경로를 반환합니다.
     *
     * @param  string  $claimNumber  클레임 번호
     * @return string 앱 내 경로
     */
    public static function memberDetail(string $claimNumber): string
    {
        return strtr(self::MEMBER_DETAIL, ['{claimNumber}' => $claimNumber]);
    }

    /**
     * 회원 주문 상세 경로를 반환합니다.
     *
     * 클레임 추가결제 PG 콜백의 **폴백** 자리다. 결과는 신청서 상세(`memberDetail`)가 받지만,
     * 결제 건이 가리키는 신청서를 되찾지 못하면(삭제 등) 상세 주소를 만들 번호가 없다 —
     * 그때 주문 상세로 내려간다. 주문 상세는 클레임 추가결제 합계 행을 그리므로 결제된
     * 사실 자체는 그 자리에서 확인된다.
     *
     * @param  string  $orderNumber  주문번호
     * @return string 앱 내 경로
     */
    public static function memberOrderDetail(string $orderNumber): string
    {
        return strtr(self::MEMBER_ORDER_DETAIL, ['{orderNumber}' => $orderNumber]);
    }

    /**
     * 비회원 신청서 상세 경로를 반환합니다. (조회 토큰 보유 브라우저 전용)
     *
     * @param  string  $claimNumber  클레임 번호
     * @return string 앱 내 경로
     */
    public static function guestDetail(string $claimNumber): string
    {
        return strtr(self::GUEST_DETAIL, [
            '{shopBase}' => ShopPathResolver::base(),
            '{claimNumber}' => $claimNumber,
        ]);
    }

    /**
     * 관리자 신청서 상세 경로를 반환합니다.
     *
     * @param  string  $claimNumber  클레임 번호
     * @return string 앱 내 경로
     */
    public static function adminDetail(string $claimNumber): string
    {
        return strtr(self::ADMIN_DETAIL, ['{claimNumber}' => $claimNumber]);
    }

    /**
     * 비회원이 메일에서 눌러 들어올 진입 경로(비회원 주문 조회 화면)를 반환합니다.
     *
     * 경로 값은 주문 알림이 쓰는 것과 같은 하나여야 한다 — 두 벌이면 상점 주소 규칙이
     * 바뀔 때 클레임 알림에서만 링크가 죽는다.
     *
     * @return string 앱 내 경로
     */
    public static function guestLookup(): string
    {
        return ShopPathResolver::path(EcommerceNotificationDataListener::GUEST_ORDER_LOOKUP_SUFFIX);
    }
}
