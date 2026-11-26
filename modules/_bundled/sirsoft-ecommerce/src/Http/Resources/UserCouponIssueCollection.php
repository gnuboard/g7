<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

/**
 * 마이페이지 쿠폰함 발급 건 컬렉션
 *
 * 모양(`data` + `pagination`)은 관리자 발급 내역 컬렉션과 같다 — 행만 쿠폰 블록이 더해진 리소스다.
 */
class UserCouponIssueCollection extends CouponIssueCollection
{
    /**
     * The resource that this resource collects.
     *
     * @var string
     */
    public $collects = UserCouponIssueResource::class;
}
