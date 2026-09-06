<?php

namespace Tests\Feature\Permission;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Services\CoreUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 커스텀 자산 관리 권한(D34)의 정의·전파 테스트
 *
 * 신규 권한은 별도 업그레이드 스텝 없이 코어 업데이트의 표준 동기화
 * (`CoreUpdateService::syncCoreRolesAndPermissions`)로 기설치 사이트에 도달한다.
 * 그 전제가 사실인지를 여기서 잠근다 — 사실이 아니면 신규 권한이 정의만 되고
 * 아무에게도 부여되지 않아, 화면은 정상인데 **모두 403** 이 된다.
 */
class CustomAssetPermissionSyncTest extends TestCase
{
    use RefreshDatabase;

    private const IDENTIFIER = 'core.extensions.custom_assets.manage';

    #[Test]
    public function 권한이_확장_공통_그룹에_정의되어_있다(): void
    {
        // 확장 타입을 가리지 않는 단일 권한이므로 특정 타입 그룹(core.templates 등)에
        // 두지 않는다. 한 타입 그룹에 있으면 나머지 두 타입에도 적용된다는 사실이
        // 화면 어디에도 드러나지 않는다.
        $categories = config('core.permissions.categories', []);
        $group = null;

        foreach ($categories as $category) {
            if (($category['identifier'] ?? null) === 'core.extensions') {
                $group = $category;
                break;
            }
        }

        $this->assertNotNull($group, 'core.extensions 권한 그룹이 없습니다.');
        $this->assertContains(self::IDENTIFIER, array_column($group['permissions'] ?? [], 'identifier'));
    }

    #[Test]
    public function 타입별_권한으로_쪼개지_않는다(): void
    {
        // 쪼개면 운영자가 셋을 다 부여해야 하고, "모듈 CSS 는 되는데 템플릿 CSS 는 안 되는"
        // 상태가 실질적 의미 없이 생긴다.
        $all = [];

        foreach (config('core.permissions.categories', []) as $category) {
            foreach ($category['permissions'] ?? [] as $permission) {
                $all[] = $permission['identifier'];
            }
        }

        foreach (['core.templates', 'core.modules', 'core.plugins'] as $prefix) {
            $this->assertNotContains($prefix.'.custom_assets.manage', $all);
        }
    }

    #[Test]
    public function 레이아웃_편집_권한과_별개의_식별자다(): void
    {
        // 같은 권한을 재사용하면 "레이아웃을 고칠 수 있다" 가 곧 "사이트 전역 스크립트를
        // 올릴 수 있다" 가 된다. 그 확대는 오류 없이 일어난다.
        $this->assertNotSame('core.templates.layouts.edit', self::IDENTIFIER);
    }

    #[Test]
    public function 표준_동기화가_권한을_만들고_관리자_역할에_부여한다(): void
    {
        // @scenario manage_actor=with_permission, manage_action=list
        // @effects custom_asset_permission_reaches_existing_sites_via_core_sync
        app(CoreUpdateService::class)->syncCoreRolesAndPermissions();

        $permission = Permission::where('identifier', self::IDENTIFIER)->first();

        $this->assertNotNull($permission, '표준 동기화가 신규 권한을 만들지 않았습니다.');
        $this->assertSame(ExtensionOwnerType::Core, $permission->extension_type);

        $admin = Role::where('identifier', 'admin')->first();
        $this->assertNotNull($admin);
        $this->assertTrue(
            $admin->permissions()->where('identifier', self::IDENTIFIER)->exists(),
            '관리자 역할(all_leaf)에 신규 권한이 부여되지 않았습니다.'
        );
    }
}
