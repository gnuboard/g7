<?php

namespace Tests\Feature\Api\Auth;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 로그인 사용자 응답이 본인의 레이아웃 편집 권한 판정(can_edit_layouts)을 싣는지 검증합니다.
 *
 * 운영 화면의 편집 진입 단추는 이 값이 참인 사람에게만 보인다. 판정은 편집기 API 가드
 * (`permission:admin,core.templates.layouts.edit`)와 같은 `User::hasPermission()` 이어야 한다 —
 * 응답의 `permissions` 목록은 사용 중지한 역할의 권한까지 담아, 그것으로 판정하면 편집기에서
 * 거절될 사람에게도 단추가 보인다.
 *
 * 시나리오 매니페스트: tests/scenarios/layout-editor-edit-entry-fab.yaml
 */
class AuthUserCanEditLayoutsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 권한을 만들거나 찾습니다.
     *
     * @param  string  $identifier  권한 식별자
     * @return Permission 권한
     */
    private function permission(string $identifier): Permission
    {
        return Permission::firstOrCreate(
            ['identifier' => $identifier],
            [
                'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'description' => json_encode(['ko' => '', 'en' => '']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ]
        );
    }

    /**
     * 관리자 화면에 들어갈 수 있는 사용자를 만들고, 필요하면 레이아웃 편집 권한을 가진 역할을 붙입니다.
     *
     * @param  bool  $grantLayoutEdit  레이아웃 편집 권한을 줄지
     * @param  bool  $grantRoleActive  권한을 준 역할의 사용 여부
     * @return User 생성된 사용자
     */
    private function makeAdmin(bool $grantLayoutEdit, bool $grantRoleActive = true): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '', 'en' => '']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );
        $adminRole->permissions()->syncWithoutDetaching([$this->permission('admin.can_edit_layouts_test')->id]);
        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        if ($grantLayoutEdit) {
            $editorRole = Role::create([
                'identifier' => 'layout-editor-'.uniqid(),
                'name' => json_encode(['ko' => '레이아웃 편집자', 'en' => 'Layout editor']),
                'description' => json_encode(['ko' => '', 'en' => '']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => $grantRoleActive,
            ]);
            $editorRole->permissions()->syncWithoutDetaching([$this->permission('core.templates.layouts.edit')->id]);
            $user->roles()->attach($editorRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        }

        return $user;
    }

    /**
     * 사용자 토큰으로 엔드포인트를 부릅니다.
     *
     * @param  User  $user  사용자
     * @param  string  $uri  요청 주소
     * @return TestResponse 응답
     */
    private function getAs(User $user, string $uri): TestResponse
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$user->createToken('test-token')->plainTextToken,
            'Accept' => 'application/json',
        ])->getJson($uri);
        $this->app['auth']->forgetGuards();

        return $response;
    }

    /**
     * 관리자 화면과 사용자 사이트의 로그인 사용자 응답 모두 권한 보유자에게 참, 비보유자에게 거짓을 싣는다.
     *
     * @scenario case=auth_user_ability_admin_and_user
     *
     * @effects auth_user_exposes_layout_edit_ability
     *
     * @return void 반환값 없음
     */
    public function test_auth_user_responses_expose_layout_edit_ability(): void
    {
        $editor = $this->makeAdmin(true);
        $plain = $this->makeAdmin(false);

        foreach (['/api/admin/auth/user', '/api/auth/user'] as $uri) {
            $this->assertTrue($this->getAs($editor, $uri)->assertOk()->json('data.can_edit_layouts'), "{$uri} 권한 보유자");
            $this->assertFalse($this->getAs($plain, $uri)->assertOk()->json('data.can_edit_layouts'), "{$uri} 권한 비보유자");
        }
    }

    /**
     * 사용 중지한 역할로만 받은 권한은 편집기 가드처럼 판정하지 않는다 — permissions 목록에는 남아 있어도 거짓이다.
     *
     * @scenario case=auth_user_inactive_role_permission
     *
     * @effects auth_user_layout_edit_ability_matches_editor_guard
     *
     * @return void 반환값 없음
     */
    public function test_inactive_role_permission_is_not_layout_edit_ability(): void
    {
        $user = $this->makeAdmin(true, false);

        $response = $this->getAs($user, '/api/admin/auth/user')->assertOk();

        $this->assertFalse($response->json('data.can_edit_layouts'));
        // 같은 사용자가 편집기 API 가드에서 거절되는지 — 두 판정이 같은 기준이어야 한다
        $this->getAs($user, '/api/admin/templates/sirsoft-admin_basic/editor/routes')->assertForbidden();
    }
}
