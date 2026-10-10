<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Support;

use App\Helpers\PermissionHelper;

/**
 * 관리자 클레임 스코프 판정에 거는 권한 식별자 단일 출처 (EC-667)
 *
 * 신청서를 다루는 일은 곧 그 주문을 다루는 일이다 — 신청서에는 주문 내용과 환불 계좌가 실리고,
 * 전이·환불 실행은 그 주문의 돈과 재고를 움직인다. 그래서 클레임 판정은 **그 라우트의 클레임 권한
 * 범위와 운영자의 주문 조회 범위의 교집합**이다. 클레임 권한만 보면 주문 조회를 「본인만」 으로 묶은
 * 운영자가 클레임 기본값 「전체」 로 남의 주문 신청서를 보고 처리한다 — 주문 스코프가 우회된다.
 *
 * 주문 조회 권한을 **보유하지 않은** 운영자(클레임 전담 역할)는 클레임 권한 범위만으로 판정한다.
 * 보유하지 않은 권한은 범위 진술이 아니며(코어 `User::getEffectiveScopeForPermission()` 이 null),
 * 이 모듈은 클레임 전담 역할을 정상 구성으로 둔다(환불 계좌 원문 열람 `MasksRefundBankAccount`).
 *
 * 목록·건수·일괄 가드(저장소 `FiltersAdminClaimList`)와 상세·전이·주문 연계 가드(`ClaimScopeGuard`)가
 * 이 목록을 함께 쓴다 — 한쪽만 교집합을 걸면 목록에 없는 신청서가 상세로 열리거나 그 반대가 된다.
 */
final class ClaimScopePermissions
{
    /** 주문 조회 권한 식별자 — 클레임 판정의 상한 */
    public const ORDER_READ = 'sirsoft-ecommerce.orders.read';

    /**
     * 클레임 권한 하나에 대해 스코프를 함께 판정할 권한 식별자 목록을 반환합니다.
     *
     * @param  string  $permission  그 라우트의 클레임 권한 식별자
     * @return array<int, string> 판정 대상 권한 식별자 (클레임 권한 + 주문 조회, 중복 없음)
     */
    public static function for(string $permission): array
    {
        return array_values(array_unique([$permission, self::ORDER_READ]));
    }

    /**
     * 현재 운영자에게 실제로 범위가 걸린 판정 대상 권한만 반환합니다.
     *
     * 전체 범위(null)·미보유·비인증(큐·콘솔)인 권한은 조건을 보태지 않으므로 뺀다.
     *
     * @param  string  $permission  그 라우트의 클레임 권한 식별자
     * @return array<int, string> 범위가 걸린 권한 식별자 (비면 전체 접근)
     */
    public static function scoped(string $permission): array
    {
        return array_values(array_filter(
            self::for($permission),
            fn (string $identifier): bool => PermissionHelper::getEffectiveScope($identifier) !== null,
        ));
    }
}
