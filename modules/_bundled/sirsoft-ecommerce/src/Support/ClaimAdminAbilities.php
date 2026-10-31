<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Support;

use App\Helpers\PermissionHelper;
use App\Models\User;

/**
 * 관리자 클레임 화면의 능력(can_*) 매핑 단일 출처
 *
 * 목록 응답(`ClaimAdminCollection`)과 상세 응답(`ClaimController::show*()`)이 같은 축을
 * 그린다 — 상태 전이 버튼·금액 수정·환불 실행은 두 화면 모두에 있다. 매핑을 양쪽에
 * 따로 적으면 한쪽만 고쳐졌을 때 같은 권한의 운영자에게 화면마다 다른 버튼이 보인다.
 *
 * 값은 라우트 미들웨어가 거는 권한 식별자와 같아야 한다(SSoT) — 여기서 더 넓게 열면
 * 버튼은 보이는데 요청이 403 이 되고, 좁게 잡으면 쓸 수 있는 기능이 잠긴다.
 */
final class ClaimAdminAbilities
{
    /**
     * 능력 → 권한 식별자 매핑을 반환합니다.
     *
     * @return array<string, string> 능력 매핑
     */
    public static function map(): array
    {
        return [
            'can_create' => 'sirsoft-ecommerce.claims.create',
            'can_update_status' => 'sirsoft-ecommerce.claims.update-status',
            'can_update_amounts' => 'sirsoft-ecommerce.claims.update-amounts',
            'can_execute_refund' => 'sirsoft-ecommerce.claims.execute-refund',
        ];
    }

    /**
     * 사용자의 능력 불리언 맵을 반환합니다.
     *
     * 목록 컬렉션은 페이지네이터를 감싸므로 스코프 검사가 걸리지 않는다 — 상세도 같은
     * 판정으로 맞춘다. 스코프 축은 서비스 계층과 라우트 미들웨어가 담당한다.
     *
     * @param  User|null  $user  현재 사용자 (null = 비인증)
     * @return array<string, bool> 능력 불리언 맵
     */
    public static function forUser(?User $user): array
    {
        $abilities = [];

        foreach (self::map() as $key => $identifier) {
            $abilities[$key] = PermissionHelper::check($identifier, $user);
        }

        return $abilities;
    }
}
