<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * 기본 역할 「이커머스 관리자」에 사이트 알림을 보고 읽음 처리하는 코어 권한을 붙이는 설치 데이터 시더.
 *
 * 이 모듈은 내보내기 완료·실패, 새 주문, 상품 문의, 클레임 알림을 이 역할에게도 사이트 알림(종 아이콘)으로 보낸다. 알림 목록·읽지
 * 않은 수 API 는 코어 권한 `core.notifications.read`, 읽음 처리는 `core.notifications.update` 를 요구하므로, 이 권한이 없으면 알림이
 * 쌓이기만 하고 볼 수 없다. 권한 선언부(`getPermissions()`)는 이 모듈 소유 권한만 역할에 이어 주므로 코어 권한은 여기서 붙인다.
 * 설치 때 역할이 만들어진 뒤에 돈다(모듈 설치의 시더 단계). 기존 사이트는 1.4.0 업그레이드 단계가 같은 일을 한다.
 *
 * 운영자가 역할 관리에서 이 권한을 직접 조정한 기록(`roles.user_overrides`)이 있으면 그 결정을 존중해 붙이지 않는다.
 * idempotent: 이미 붙어 있으면 건드리지 않는다. 지우는 일은 하지 않는다.
 */
class ManagerNotificationPermissionSeeder extends Seeder
{
    /** 대상 역할 */
    public const ROLE = 'sirsoft-ecommerce.manager';

    /** 붙일 코어 권한 — 알림 조회·읽음 처리(삭제는 주지 않는다) */
    public const PERMISSIONS = ['core.notifications.read', 'core.notifications.update'];

    /**
     * 역할에 알림 권한이 없으면 붙입니다.
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        $role = Role::query()->where('identifier', self::ROLE)->first();
        if ($role === null) {
            return;
        }

        $overrides = array_filter((array) ($role->user_overrides ?? []), fn ($value) => is_string($value));

        foreach (self::PERMISSIONS as $identifier) {
            if (in_array($identifier, $overrides, true)) {
                continue;
            }

            $permission = Permission::query()->where('identifier', $identifier)->first();
            if ($permission === null || $role->permissions()->whereKey($permission->id)->exists()) {
                continue;
            }

            $role->permissions()->attach($permission->id, [
                'scope_type' => null,
                'granted_at' => now(),
                'granted_by' => null,
            ]);
        }
    }
}
