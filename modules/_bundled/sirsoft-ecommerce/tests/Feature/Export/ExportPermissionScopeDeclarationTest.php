<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Export;

use App\Extension\ModuleManager;
use App\Helpers\PermissionHelper;
use Modules\Sirsoft\Ecommerce\Tests\ExportTestCase;

/**
 * 내보내기 권한의 범위 선언 테스트
 *
 * 범위는 코어 표준 권한 범위다 — 최고관리자는 모든 내보내기를, 각 요청자는 자기가 요청한 내보내기만 조회·다운로드하고,
 * 역할별 범위는 관리자가 역할 관리에서 언제든 바꾼다(2026-10-22 코어 담당자 결정). 설치가 이 선언을 권한 행과 역할
 * 할당으로 옮기므로 선언 자체를 잠근다.
 */
class ExportPermissionScopeDeclarationTest extends ExportTestCase
{
    /**
     * 내보내기 권한 카테고리 선언을 반환합니다.
     *
     * @return array<string, mixed> 카테고리 선언
     */
    private function exportsCategory(): array
    {
        return collect(app(ModuleManager::class)->getModule(self::IDENTIFIER)->getPermissions()['categories'])
            ->firstWhere('identifier', 'exports');
    }

    /**
     * 동작별 역할 선언을 「역할 → 범위」 표로 반환합니다.
     *
     * @param  string  $action  동작
     * @return array<string, string|null> 역할 식별자 → 범위(null 이면 전체)
     */
    private function rolesOf(string $action): array
    {
        $permission = collect($this->exportsCategory()['permissions'])->firstWhere('action', $action);

        return collect($permission['roles'])->mapWithKeys(fn ($role) => is_string($role)
            ? [$role => null]
            : [$role['role'] => $role['scope_type'] ?? null])->all();
    }

    /**
     * 주인은 요청자이고, 조회·다운로드·삭제의 기본 범위는 관리자 전체·이커머스 관리자 본인만이다. 별도 권한
     * 「다른 운영자의 내보내기 관리」(all)는 없다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, permission=self_scope
     *
     * @effects export_scope_defaults_admin_full_manager_self
     */
    public function test_scope_defaults_admin_full_and_manager_self(): void
    {
        $category = $this->exportsCategory();
        $this->assertSame('export', $category['resource_route_key']);
        $this->assertSame('requested_by', $category['owner_key']);
        $this->assertNotContains('all', array_column($category['permissions'], 'action'));

        foreach (['read', 'download', 'delete'] as $action) {
            $this->assertSame(['admin' => null, 'sirsoft-ecommerce.manager' => 'self'], $this->rolesOf($action), $action);
        }
        $this->assertSame(['admin' => null, 'sirsoft-ecommerce.manager' => null], $this->rolesOf('create'));
    }

    /**
     * 설치와 같게 「요청」 외 동작에만 범위 열이 실린다 — 「요청」은 만들 때라 판정할 작업이 없다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, permission=self_scope
     *
     * @effects export_scope_defaults_admin_full_manager_self
     */
    public function test_scope_columns_follow_declaration(): void
    {
        foreach (['read', 'download', 'delete'] as $action) {
            $this->assertSame('requested_by', PermissionHelper::getOwnerKey(self::IDENTIFIER.'.exports.'.$action), $action);
        }
        $this->assertNull(PermissionHelper::getOwnerKey(self::IDENTIFIER.'.exports.create'));
    }
}
