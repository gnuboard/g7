<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_4_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 기본 역할 「이커머스 관리자」(`sirsoft-ecommerce.manager`)에 사이트 알림 조회·읽음 처리 코어 권한을 붙입니다.
 *
 * 이 모듈은 이 역할에게도 사이트 알림(새 주문·상품 문의·클레임, 1.4.0 부터 내보내기 완료·실패)을 보내는데, 알림 목록·읽지 않은
 * 수 API 가 요구하는 `core.notifications.read`(읽음 처리는 `core.notifications.update`)가 이 역할에 없어 종 아이콘에서 볼 수
 * 없었다. 새 설치는 설치 데이터 시더가 같은 권한을 붙인다.
 *
 * 운영자가 이 역할의 그 권한을 직접 조정한 기록(`roles.user_overrides`)이 있으면 그 결정을 존중해 붙이지 않는다.
 * idempotent: 이미 붙어 있으면 아무것도 하지 않는다. 역할·권한 행이 없으면 건너뛴다.
 * V-1 안전: Illuminate\Support\Facades\* 만 사용 — 모듈 클래스를 참조하지 않는다.
 */
class GrantNotificationPermissionsToManager implements DataMigration
{
    /** 1.4.0 시점 대상 역할 — module.php 의 roles 동결본 */
    private const ROLE = 'sirsoft-ecommerce.manager';

    /** 1.4.0 시점 부여 권한 동결본 */
    private const PERMISSIONS = ['core.notifications.read', 'core.notifications.update'];

    /**
     * 마이그레이션 이름을 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'GrantNotificationPermissionsToManager';
    }

    /**
     * 이커머스 관리자 역할에 알림 권한이 없으면 붙입니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            $context->logger->warning('[ecommerce:1.4.0] 권한 테이블 미존재 — 알림 권한 부여 스킵');

            return;
        }

        $role = DB::table('roles')->where('identifier', self::ROLE)->first(['id', 'user_overrides']);

        if ($role === null) {
            $context->logger->info('[ecommerce:1.4.0] 이커머스 관리자 역할 미존재 — 알림 권한 부여 스킵');

            return;
        }

        $overridden = $this->overriddenIdentifiers($role->user_overrides);

        foreach (self::PERMISSIONS as $identifier) {
            if (in_array($identifier, $overridden, true)) {
                $context->logger->info("[ecommerce:1.4.0] 운영자가 이커머스 관리자 역할의 {$identifier} 를 조정함 — 존중하고 스킵");

                continue;
            }

            $permissionId = DB::table('permissions')->where('identifier', $identifier)->value('id');

            if ($permissionId === null) {
                $context->logger->warning("[ecommerce:1.4.0] {$identifier} 권한 행이 없다 — 코어 권한 동기화를 확인하세요");

                continue;
            }

            $exists = DB::table('role_permissions')
                ->where('role_id', $role->id)
                ->where('permission_id', $permissionId)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('role_permissions')->insert([
                'role_id' => $role->id,
                'permission_id' => $permissionId,
                'scope_type' => null,
                'granted_at' => now(),
                'granted_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $context->logger->info("[ecommerce:1.4.0] 이커머스 관리자 역할에 {$identifier} 부여 완료");
        }
    }

    /**
     * 역할의 운영자 조정 기록에서 권한 식별자만 뽑습니다.
     *
     * @param  mixed  $overrides  `roles.user_overrides` 원값 (JSON 문자열 또는 null)
     * @return array<int, string> 조정된 권한 식별자 목록
     */
    private function overriddenIdentifiers(mixed $overrides): array
    {
        $decoded = is_string($overrides) ? json_decode($overrides, true) : $overrides;

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, fn ($value) => is_string($value)));
    }
}
