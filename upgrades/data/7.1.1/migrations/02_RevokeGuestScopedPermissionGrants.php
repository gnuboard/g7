<?php

namespace App\Upgrades\Data\V7_1_1\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 비회원(guest) 역할에 권한 범위(본인·소유역할)가 붙은 부여를 회수합니다.
 *
 * 비회원에게는 「본인」 을 판정할 신원이 없어 코어 판정 경로가 비회원의 범위를 건너뛴다. 그래서 비회원 역할에
 * 「비밀글 조회 = 본인만」 을 주면 실제로는 「전체」 가 되어 비밀번호 없이 모든 비밀글이 열렸다. 7.1.1 부터 비회원 역할은
 * 범위를 가질 수 없고(역할 수정 422), 이 스텝은 이미 저장된 부여를 정리한다.
 *
 * 회수 = 그 부여 행 삭제다. 범위를 지우고 「전체」 로 남기면 운영자가 좁히려던 권한이 오히려 넓어지므로 삭제한다 —
 * 운영자가 다시 열려면 역할 화면에서 범위 없이(전체) 준다. 회수한 식별자는 guest 의 `roles.user_overrides` 에
 * 합집합으로 기록한다. 기록하지 않으면 다음 코어·확장 동기화(ExtensionRoleSyncHelper::syncRolePermissions)가 같은
 * 권한을 범위 없이(전체) 다시 붙여 「회수」 가 「전체 승격」 이 된다.
 *
 * V-1 판단: DB·Schema 파사드와 리터럴만 쓴다(역할 식별자 'guest', 테이블 이름) — 모델·서비스를 부르지 않는다.
 *
 * idempotent: 두 번째 실행에서는 회수할 행이 없어 아무것도 바꾸지 않는다(로그만 남긴다).
 */
class RevokeGuestScopedPermissionGrants implements DataMigration
{
    /**
     * 비회원 역할 식별자 (동결 리터럴).
     */
    private const GUEST_ROLE = 'guest';

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'RevokeGuestScopedPermissionGrants';
    }

    /**
     * 비회원 역할의 범위 부여를 회수하고 회수 목록을 user_overrides 에 기록합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        foreach (['roles', 'permissions', 'role_permissions'] as $table) {
            if (! Schema::hasTable($table)) {
                $context->logger->info("[7.1.1] 비회원 권한 범위 회수 건너뜀 — {$table} 테이블이 없음");

                return;
            }
        }

        if (! Schema::hasColumn('role_permissions', 'scope_type')) {
            $context->logger->info('[7.1.1] 비회원 권한 범위 회수 건너뜀 — role_permissions.scope_type 칸이 없음');

            return;
        }

        $guest = DB::table('roles')->where('identifier', self::GUEST_ROLE)->first();
        if ($guest === null) {
            $context->logger->info('[7.1.1] 비회원 권한 범위 회수 건너뜀 — 비회원 역할이 없음');

            return;
        }

        $grants = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $guest->id)
            ->whereNotNull('role_permissions.scope_type')
            ->orderBy('permissions.identifier')
            ->get(['role_permissions.permission_id', 'permissions.identifier', 'role_permissions.scope_type']);

        if ($grants->isEmpty()) {
            $context->logger->info('[7.1.1] 비회원 역할에 권한 범위가 붙은 부여가 없습니다');

            return;
        }

        foreach ($grants as $grant) {
            $context->logger->warning('[7.1.1] 비회원 역할의 권한 범위 부여를 회수합니다 — 다시 열려면 역할 화면에서 범위 없이(전체) 주세요', [
                'permission' => $grant->identifier,
                'scope_type' => $grant->scope_type,
            ]);
        }

        $revokedIdentifiers = $grants->pluck('identifier')->all();

        DB::transaction(function () use ($context, $guest, $grants, $revokedIdentifiers): void {
            DB::table('role_permissions')
                ->where('role_id', $guest->id)
                ->whereIn('permission_id', $grants->pluck('permission_id')->all())
                ->whereNotNull('scope_type')
                ->delete();

            if (! Schema::hasColumn('roles', 'user_overrides')) {
                $context->logger->info('[7.1.1] 비회원 역할 user_overrides 기록 건너뜀 — roles.user_overrides 칸이 없음(회수한 권한을 이후 동기화가 다시 붙일 수 있음)');

                return;
            }

            $current = json_decode((string) ($guest->user_overrides ?? ''), true);
            $current = is_array($current) ? $current : [];
            $merged = array_values(array_unique(array_merge($current, $revokedIdentifiers)));

            if ($merged === $current) {
                $context->logger->info('[7.1.1] 비회원 역할 user_overrides 에 회수한 권한이 이미 모두 기록돼 있어 바꾸지 않습니다');

                return;
            }

            DB::table('roles')->where('id', $guest->id)->update(['user_overrides' => json_encode($merged, JSON_UNESCAPED_UNICODE)]);
            $context->logger->info('[7.1.1] 비회원 역할 user_overrides 에 회수한 권한 '.(count($merged) - count($current)).'건을 기록했습니다');
        });

        $context->logger->info('[7.1.1] 비회원 역할의 권한 범위 부여 '.count($revokedIdentifiers).'건을 회수했습니다', [
            'permissions' => $revokedIdentifiers,
        ]);
    }
}
