<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_3_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 새로 정의한 「주문 삭제」 권한(`sirsoft-ecommerce.orders.delete`)을 기본 관리자 역할(admin)에 붙입니다.
 *
 * 1.2.1 까지는 이 권한이 정의돼 있지 않았는데, 관리자 주문 삭제 API 와 1.3.0 의 개인정보 보유기간
 * 「삭제」 방식 직권 실행이 이 권한을 요구했다 — 정의가 없으니 아무도 보유할 수 없어 두 기능 모두
 * 누구도 실행할 수 없었다. 1.3.0 은 권한을 정의하고 admin 역할에 기본으로 준다.
 *
 * 업데이트의 선언형 동기화(권한·역할 할당)가 이 스텝보다 먼저 돌며 보통 이미 붙여 둔다. 이 스텝은 그 결과를
 * 한 번 더 확인하는 안전망이다 — 운영자가 admin 역할의 이 권한을 직접 뺀 기록(`roles.user_overrides`)이
 * 있으면 그 결정을 존중해 붙이지 않는다.
 *
 * idempotent: 이미 붙어 있으면 아무것도 하지 않는다. 권한 행이 없으면(동기화 실패) 경고만 남긴다.
 * V-1 안전: Illuminate\Support\Facades\* 만 사용 — 모듈 클래스를 참조하지 않는다.
 */
class GrantOrderDeletePermissionToAdmin implements DataMigration
{
    /** 1.3.0 에서 신설한 권한 식별자 */
    private const PERMISSION = 'sirsoft-ecommerce.orders.delete';

    /** 1.3.0 시점 기본 부여 역할 — module.php 의 roles 동결본 */
    private const ROLE = 'admin';

    /**
     * 마이그레이션 이름을 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'GrantOrderDeletePermissionToAdmin';
    }

    /**
     * admin 역할에 주문 삭제 권한이 없으면 붙입니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            $context->logger->warning('[ecommerce:1.3.0] 권한 테이블 미존재 — 주문 삭제 권한 부여 스킵');

            return;
        }

        $permissionId = DB::table('permissions')->where('identifier', self::PERMISSION)->value('id');

        if ($permissionId === null) {
            $context->logger->warning('[ecommerce:1.3.0] '.self::PERMISSION.' 권한 행이 없다 — 선언형 동기화를 확인하세요');

            return;
        }

        $role = DB::table('roles')->where('identifier', self::ROLE)->first(['id', 'user_overrides']);

        if ($role === null) {
            $context->logger->info('[ecommerce:1.3.0] admin 역할 미존재 — 주문 삭제 권한 부여 스킵');

            return;
        }

        if (in_array(self::PERMISSION, $this->overriddenIdentifiers($role->user_overrides), true)) {
            $context->logger->info('[ecommerce:1.3.0] 운영자가 admin 역할의 주문 삭제 권한을 조정함 — 존중하고 스킵');

            return;
        }

        $exists = DB::table('role_permissions')
            ->where('role_id', $role->id)
            ->where('permission_id', $permissionId)
            ->exists();

        if ($exists) {
            $context->logger->info('[ecommerce:1.3.0] admin 역할에 주문 삭제 권한 이미 부여됨 — 스킵 (idempotent)');

            return;
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

        $context->logger->info('[ecommerce:1.3.0] admin 역할에 주문 삭제 권한 부여 완료');
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
