<?php

namespace Tests\Feature\Menu;

use App\Models\Menu;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuI18nIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        // 관리자 역할 및 권한 생성 (is_active=true 필수)
        $adminRole = \App\Models\Role::create([
            'identifier' => 'admin',
            'name' => [
                'ko' => '관리자',
                'en' => 'Administrator',
            ],
            'description' => [
                'ko' => '시스템 관리자',
                'en' => 'System Administrator',
            ],
            'is_active' => true,
        ]);

        // 메뉴 관련 권한들 생성 (core. 접두사 필요)
        $permissions = [
            [
                'identifier' => 'core.menus.read',
                'name' => ['ko' => '메뉴 조회', 'en' => 'Read Menus'],
                'description' => ['ko' => '메뉴를 조회할 수 있습니다.', 'en' => 'Can read menus.'],
            ],
            [
                'identifier' => 'core.menus.create',
                'name' => ['ko' => '메뉴 생성', 'en' => 'Create Menus'],
                'description' => ['ko' => '메뉴를 생성할 수 있습니다.', 'en' => 'Can create menus.'],
            ],
            [
                'identifier' => 'core.menus.update',
                'name' => ['ko' => '메뉴 수정', 'en' => 'Update Menus'],
                'description' => ['ko' => '메뉴를 수정할 수 있습니다.', 'en' => 'Can update menus.'],
            ],
            [
                'identifier' => 'core.menus.delete',
                'name' => ['ko' => '메뉴 삭제', 'en' => 'Delete Menus'],
                'description' => ['ko' => '메뉴를 삭제할 수 있습니다.', 'en' => 'Can delete menus.'],
            ],
        ];

        foreach ($permissions as $permData) {
            // extension_type / extension_identifier 를 명시해야 현재 권한 시스템에서 확인됨
            $perm = \App\Models\Permission::create(array_merge($permData, [
                'extension_type' => 'core',
                'extension_identifier' => 'core',
                'type' => 'admin',
            ]));
            $adminRole->permissions()->attach($perm->id);
        }

        // 관리자 사용자 생성 (is_super=true 로 admin 미들웨어 + 모든 permission 통과)
        $this->adminUser = User::factory()->create(['is_super' => true]);
        $this->adminUser->roles()->attach($adminRole->id);
    }

    /**
     * 다국어 메뉴 생성 테스트
     */
    public function test_can_create_menu_with_i18n_name(): void
    {
        $menuData = [
            'name' => [
                'ko' => '테스트 메뉴',
                'en' => 'Test Menu',
            ],
            'slug' => 'test-menu-integration',
            'url' => '/admin/test',
            'icon' => 'fa-test',
            'order' => 1,
        ];

        $response = $this->actingAs($this->adminUser, "sanctum")
            ->postJson('/api/admin/menus', $menuData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['id', 'name', 'slug', 'url', 'icon', 'order'],
            ]);

        $this->assertDatabaseHas('menus', [
            'slug' => 'test-menu-integration',
        ]);

        // JSON 컬럼 확인
        $menu = Menu::where('slug', 'test-menu-integration')->first();
        $this->assertEquals('테스트 메뉴', $menu->getLocalizedName('ko'));
        $this->assertEquals('Test Menu', $menu->getLocalizedName('en'));
    }

    /**
     * 다국어 메뉴 조회 테스트 (한국어)
     *
     * API는 다국어 객체를 그대로 반환하며, 프론트엔드에서 $localized()를 사용합니다.
     */
    public function test_can_retrieve_menu_in_korean(): void
    {
        $menu = Menu::factory()->create([
            'name' => [
                'ko' => '한국어 메뉴',
                'en' => 'Korean Menu',
            ],
        ]);

        $response = $this->actingAs($this->adminUser, "sanctum")
            ->withHeader('Accept-Language', 'ko')
            ->getJson("/api/admin/menus/{$menu->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $menu->id,
                    'name' => [
                        'ko' => '한국어 메뉴',
                        'en' => 'Korean Menu',
                    ],
                ],
            ]);
    }

    /**
     * 다국어 메뉴 조회 테스트 (영어)
     *
     * API는 다국어 객체를 그대로 반환하며, 프론트엔드에서 $localized()를 사용합니다.
     */
    public function test_can_retrieve_menu_in_english(): void
    {
        $menu = Menu::factory()->create([
            'name' => [
                'ko' => '한국어 메뉴',
                'en' => 'English Menu',
            ],
        ]);

        $response = $this->actingAs($this->adminUser, "sanctum")
            ->withHeader('Accept-Language', 'en')
            ->getJson("/api/admin/menus/{$menu->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $menu->id,
                    'name' => [
                        'ko' => '한국어 메뉴',
                        'en' => 'English Menu',
                    ],
                ],
            ]);
    }

    /**
     * 다국어 메뉴 업데이트 테스트
     *
     * 메뉴 업데이트 시 필수 필드도 함께 전송해야 합니다.
     */
    public function test_can_update_menu_with_i18n_name(): void
    {
        $menu = Menu::factory()->create([
            'name' => [
                'ko' => '원본 메뉴',
                'en' => 'Original Menu',
            ],
            'slug' => 'original-menu',
            'url' => '/admin/original',
        ]);

        $updateData = [
            'name' => [
                'ko' => '수정된 메뉴',
                'en' => 'Updated Menu',
            ],
            'slug' => $menu->slug,
            'url' => $menu->url,
        ];

        $response = $this->actingAs($this->adminUser, "sanctum")
            ->putJson("/api/admin/menus/{$menu->id}", $updateData);

        $response->assertStatus(200);

        $menu->refresh();
        $this->assertEquals('수정된 메뉴', $menu->getLocalizedName('ko'));
        $this->assertEquals('Updated Menu', $menu->getLocalizedName('en'));
    }

    /**
     * 메뉴 트리 구조 테스트
     *
     * API는 다국어 객체를 그대로 반환합니다.
     */
    public function test_can_retrieve_menu_hierarchy_with_i18n(): void
    {
        $parent = Menu::factory()->create([
            'name' => [
                'ko' => '부모 메뉴',
                'en' => 'Parent Menu',
            ],
            'order' => 1,
        ]);

        Menu::factory()->create([
            'name' => [
                'ko' => '자식 메뉴',
                'en' => 'Child Menu',
            ],
            'parent_id' => $parent->id,
            'order' => 1,
        ]);

        $response = $this->actingAs($this->adminUser, "sanctum")
            ->withHeader('Accept-Language', 'ko')
            ->getJson('/api/admin/menus/hierarchy');

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => [
                    'ko' => '부모 메뉴',
                    'en' => 'Parent Menu',
                ],
            ])
            ->assertJsonFragment([
                'name' => [
                    'ko' => '자식 메뉴',
                    'en' => 'Child Menu',
                ],
            ]);
    }

    /**
     * 로케일 전환 테스트
     */
    public function test_locale_switching_works_correctly(): void
    {
        $menu = Menu::factory()->create([
            'name' => [
                'ko' => '로케일 테스트',
                'en' => 'Locale Test',
            ],
        ]);

        // 한국어로 조회
        app()->setLocale('ko');
        $menu->refresh();
        $this->assertEquals('로케일 테스트', $menu->localized_name);

        // 영어로 조회
        app()->setLocale('en');
        $menu->refresh();
        $this->assertEquals('Locale Test', $menu->localized_name);
    }
}
