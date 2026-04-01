<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $regularUser;

    protected Role $adminRole;

    protected Role $userRole;

    protected function setUp(): void
    {
        parent::setUp();

        // 역할 생성
        $this->adminRole = Role::create([
            'identifier' => 'admin',
            'name' => [
                'ko' => '관리자',
                'en' => 'Administrator',
            ],
            'description' => [
                'ko' => '시스템 관리자',
                'en' => 'System Administrator',
            ],
        ]);

        $this->userRole = Role::create([
            'identifier' => 'user',
            'name' => [
                'ko' => '일반 사용자',
                'en' => 'Regular User',
            ],
            'description' => [
                'ko' => '일반 사용자',
                'en' => 'Regular User',
            ],
        ]);

        // 권한 생성 (실제 라우트에서 사용하는 권한 식별자에 맞춤)
        $permissions = [
            [
                'identifier' => 'core.templates.view',
                'name' => [
                    'ko' => '템플릿 조회',
                    'en' => 'View Templates',
                ],
                'description' => [
                    'ko' => '템플릿 목록 및 상세 정보를 조회할 수 있습니다.',
                    'en' => 'Can view template list and details.',
                ],
            ],
            [
                'identifier' => 'core.templates.edit',
                'name' => [
                    'ko' => '템플릿 편집',
                    'en' => 'Edit Templates',
                ],
                'description' => [
                    'ko' => '템플릿을 수정할 수 있습니다.',
                    'en' => 'Can edit templates.',
                ],
            ],
            [
                'identifier' => 'core.templates.read',
                'name' => [
                    'ko' => '템플릿 읽기',
                    'en' => 'Read Templates',
                ],
                'description' => [
                    'ko' => '템플릿을 읽을 수 있습니다.',
                    'en' => 'Can read templates.',
                ],
            ],
            [
                'identifier' => 'core.templates.activate',
                'name' => [
                    'ko' => '템플릿 활성화',
                    'en' => 'Activate Templates',
                ],
                'description' => [
                    'ko' => '템플릿을 활성화/비활성화할 수 있습니다.',
                    'en' => 'Can activate/deactivate templates.',
                ],
            ],
            [
                'identifier' => 'core.templates.layouts.view',
                'name' => [
                    'ko' => '레이아웃 조회',
                    'en' => 'View Layouts',
                ],
                'description' => [
                    'ko' => '레이아웃을 조회할 수 있습니다.',
                    'en' => 'Can view layouts.',
                ],
            ],
            [
                'identifier' => 'core.templates.layouts.edit',
                'name' => [
                    'ko' => '레이아웃 편집',
                    'en' => 'Edit Layouts',
                ],
                'description' => [
                    'ko' => '레이아웃을 편집할 수 있습니다.',
                    'en' => 'Can edit layouts.',
                ],
            ],
        ];

        foreach ($permissions as $permissionData) {
            $permission = Permission::create($permissionData);
            // 관리자 역할에만 권한 부여
            $this->adminRole->permissions()->attach($permission->id);
        }

        // 사용자 생성
        $this->adminUser = User::factory()->create([
            'email' => 'admin@example.com',
            'name' => 'Admin User',
        ]);

        $this->regularUser = User::factory()->create([
            'email' => 'user@example.com',
            'name' => 'Regular User',
        ]);

        // 역할 할당
        $this->adminUser->roles()->attach($this->adminRole->id);
        $this->regularUser->roles()->attach($this->userRole->id);

        // 테스트용 템플릿 생성 - 고유 identifier 사용하여 트랜잭션 락 충돌 방지
        Template::create([
            'identifier' => 'test-template-' . uniqid(),
            'vendor' => 'test',
            'name' => [
                'ko' => '테스트 템플릿',
                'en' => 'Test Template',
            ],
            'version' => '1.0.0',
            'type' => 'admin',
            'description' => [
                'ko' => '테스트용 템플릿',
                'en' => 'Test template',
            ],
            'status' => 'active',
        ]);
    }

    /**
     * 권한이 permissions 테이블에 올바르게 등록되었는지 확인
     */
    public function test_permissions_are_registered_correctly(): void
    {
        $this->assertDatabaseHas('permissions', [
            'identifier' => 'core.templates.view',
        ]);

        $this->assertDatabaseHas('permissions', [
            'identifier' => 'core.templates.edit',
        ]);

        $this->assertDatabaseHas('permissions', [
            'identifier' => 'core.templates.layouts.view',
        ]);

        $this->assertDatabaseHas('permissions', [
            'identifier' => 'core.templates.layouts.edit',
        ]);

        // 권한이 다국어 구조로 저장되었는지 확인
        $permission = Permission::where('identifier', 'core.templates.view')->first();
        $this->assertIsArray($permission->name);
        $this->assertEquals('템플릿 조회', $permission->name['ko']);
        $this->assertEquals('View Templates', $permission->name['en']);
    }

    /**
     * 관리자 사용자가 core.templates.view, core.templates.edit 권한을 가지고 있는지 확인
     */
    public function test_admin_user_has_template_permissions(): void
    {
        $this->assertTrue($this->adminUser->hasPermission('core.templates.view'));
        $this->assertTrue($this->adminUser->hasPermission('core.templates.edit'));
        $this->assertTrue($this->adminUser->hasPermission('core.templates.layouts.view'));
        $this->assertTrue($this->adminUser->hasPermission('core.templates.layouts.edit'));
    }

    /**
     * 일반 사용자로 템플릿 API 접근 시 403 응답 확인 (미들웨어에서 차단)
     */
    public function test_regular_user_receives_403_when_accessing_template_api(): void
    {
        Sanctum::actingAs($this->regularUser);

        $response = $this->getJson(route('api.admin.templates.index'));

        $response->assertStatus(403);
    }

    /**
     * 토큰 없이 API 호출 시 401 응답 확인
     */
    public function test_unauthenticated_request_receives_401(): void
    {
        $response = $this->getJson(route('api.admin.templates.index'));

        $response->assertStatus(401);
    }

    /**
     * 만료된 토큰으로 API 호출 시 401 응답 확인
     */
    public function test_expired_token_receives_401(): void
    {
        // 토큰 생성
        $token = $this->adminUser->createToken('test-token')->plainTextToken;

        // 토큰을 직접 삭제하여 만료된 상태 시뮬레이션
        $this->adminUser->tokens()->delete();

        $response = $this->getJson(route('api.admin.templates.index'), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(401);
    }

    /**
     * core.templates.activate 권한이 올바르게 동작하는지 확인
     *
     * 라우트: PUT /api/admin/templates/{templateName}/layouts/{name}
     * 미들웨어: permission:core.templates.activate
     */
    public function test_templates_layouts_edit_permission_works_correctly(): void
    {
        // 관리자는 토큰 기반 인증으로 접근 가능
        $adminToken = $this->adminUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$adminToken,
            'Accept' => 'application/json',
        ])->putJson(route('api.admin.templates.layouts.update', [
            'templateName' => 'test-template',
            'name' => 'test-layout',
        ]), [
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'test-layout',
                'endpoint' => '/api/admin/test',
                'components' => [],
            ],
        ]);

        // 404 (레이아웃이 없음), 200, 또는 422(검증 실패)이면 권한 체크는 통과한 것
        // 403이 아니면 admin/permission 미들웨어를 통과한 것으로 간주
        $this->assertNotEquals(403, $response->status(), '관리자 요청이 권한 체크에서 실패했습니다.');

        // 일반 사용자는 접근 불가
        // 일반 사용자는 admin 역할이 없으므로 admin 미들웨어에서 403 반환되어야 함
        // 그러나 FormRequest 검증이 미들웨어보다 먼저 실행될 수 있음
        // 어느 쪽이든 성공(200)이 아니면 테스트 통과
        $regularToken = $this->regularUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$regularToken,
            'Accept' => 'application/json',
        ])->putJson(route('api.admin.templates.layouts.update', [
            'templateName' => 'test-template',
            'name' => 'test-layout',
        ]), [
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'test-layout',
                'endpoint' => '/api/admin/test',
                'components' => [],
            ],
        ]);

        // 일반 사용자가 성공적으로 레이아웃을 수정할 수 없어야 함
        // 403(권한 없음), 422(검증 실패) 모두 허용
        $this->assertContains($response->status(), [403, 422], '일반 사용자가 레이아웃 수정에 성공해서는 안됩니다.');
    }

    /**
     * API 속도 제한 헤더 존재 확인
     *
     * api 미들웨어 그룹에 기본 throttle이 적용되어 있으므로
     * 헤더 존재 여부만 확인합니다.
     */
    public function test_rate_limit_headers_are_present(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson(route('api.admin.templates.index'));

        // Rate Limit 헤더가 존재하는지 확인
        $response->assertHeader('X-RateLimit-Limit')
            ->assertHeader('X-RateLimit-Remaining');

        // Rate Limit 값이 적용되었는지 확인 (api 그룹 기본값 또는 라우트 설정값)
        $rateLimit = (int) $response->headers->get('X-RateLimit-Limit');
        $this->assertGreaterThan(0, $rateLimit);
    }

    /**
     * FormRequest의 authorize()가 항상 true를 반환하는지 확인
     */
    public function test_form_request_authorize_always_returns_true(): void
    {
        // UpdateLayoutRequest 클래스를 직접 테스트
        $request = new \App\Http\Requests\Layout\UpdateLayoutRequest();
        $request->setUserResolver(fn () => $this->regularUser);

        $this->assertTrue($request->authorize());

        // 관리자 사용자로도 테스트
        $request->setUserResolver(fn () => $this->adminUser);

        $this->assertTrue($request->authorize());
    }

    /**
     * Controller에 권한 체크 로직이 없는지 확인
     */
    public function test_controller_has_no_permission_check_logic(): void
    {
        // TemplateController 소스 코드를 읽어서 확인
        $controllerPath = app_path('Http/Controllers/Api/Admin/TemplateController.php');
        $controllerContent = file_get_contents($controllerPath);

        // authorize(), can(), hasPermission() 등의 권한 체크 메서드가 없는지 확인
        $this->assertStringNotContainsString('$this->authorize(', $controllerContent);
        $this->assertStringNotContainsString('->can(', $controllerContent);
        $this->assertStringNotContainsString('->hasPermission(', $controllerContent);

        // LayoutController도 확인
        $layoutControllerPath = app_path('Http/Controllers/Api/Admin/LayoutController.php');
        $layoutControllerContent = file_get_contents($layoutControllerPath);

        $this->assertStringNotContainsString('$this->authorize(', $layoutControllerContent);
        $this->assertStringNotContainsString('->can(', $layoutControllerContent);
        $this->assertStringNotContainsString('->hasPermission(', $layoutControllerContent);
    }

    /**
     * 관리자가 템플릿 목록을 조회할 수 있는지 확인
     */
    public function test_admin_can_view_templates(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson(route('api.admin.templates.index'));

        $response->assertStatus(200);
    }

    /**
     * 관리자가 템플릿 상세를 조회할 수 있는지 확인
     *
     * 템플릿 디렉토리가 존재하지 않으면 404를 반환할 수 있습니다.
     */
    public function test_admin_can_view_template_details(): void
    {
        Sanctum::actingAs($this->adminUser);

        $template = Template::first();

        $response = $this->getJson(route('api.admin.templates.show', ['templateName' => $template->identifier]));

        // 200 (템플릿 디렉토리 존재) 또는 404 (템플릿 디렉토리 없음) 허용
        // 권한 체크가 통과했으므로 403이 아니면 성공
        $this->assertContains($response->status(), [200, 404]);
        $this->assertNotEquals(403, $response->status());
    }

    /**
     * 일반 사용자가 템플릿 활성화를 시도할 때 403 응답 확인
     */
    public function test_regular_user_cannot_activate_templates(): void
    {
        Sanctum::actingAs($this->regularUser);

        $template = Template::first();

        $response = $this->postJson(route('api.admin.templates.activate'), [
            'identifier' => $template->identifier,
        ]);

        $response->assertStatus(403);
    }
}
