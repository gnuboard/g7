<?php

namespace Tests\Unit\Resources;

use App\Http\Resources\BaseApiResource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * BaseApiResource의 resourceMeta() 메서드 단위 테스트
 *
 * ownerField(), abilityMap(), resolveIsOwner(), resolveAbilities(),
 * checkAbility(), resourceMeta()를 테스트합니다.
 */
class BaseApiResourceMetaTest extends TestCase
{
    use RefreshDatabase;

    private Role $adminRole;

    private Role $userRole;

    private User $adminUser;

    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        // Role 생성
        $this->adminRole = Role::where('identifier', 'admin')->first()
            ?? Role::create([
                'identifier' => 'admin',
                'name' => ['ko' => '관리자', 'en' => 'Administrator'],
            ]);

        $this->userRole = Role::where('identifier', 'user')->first()
            ?? Role::create([
                'identifier' => 'user',
                'name' => ['ko' => '일반 사용자', 'en' => 'Regular User'],
            ]);

        // 테스트용 Permission 생성
        $permission = Permission::create([
            'identifier' => 'test-module.products.update',
            'name' => ['ko' => '상품 수정', 'en' => 'Update Products'],
            'type' => 'admin',
        ]);
        $this->adminRole->permissions()->syncWithoutDetaching([$permission->id]);

        $permission2 = Permission::create([
            'identifier' => 'test-module.products.delete',
            'name' => ['ko' => '상품 삭제', 'en' => 'Delete Products'],
            'type' => 'admin',
        ]);
        $this->adminRole->permissions()->syncWithoutDetaching([$permission2->id]);

        // User 생성
        $this->adminUser = User::factory()->create(['email' => 'admin-meta@example.com']);
        $this->adminUser->roles()->syncWithoutDetaching([$this->adminRole->id]);

        $this->regularUser = User::factory()->create(['email' => 'user-meta@example.com']);
        $this->regularUser->roles()->syncWithoutDetaching([$this->userRole->id]);
    }

    // =========================================================================
    // ownerField / resolveIsOwner 테스트
    // =========================================================================

    /**
     * ownerField가 null이면 is_owner가 resourceMeta에 포함되지 않는다.
     */
    public function test_resource_meta_excludes_is_owner_when_owner_field_is_null(): void
    {
        $resource = new class(['id' => 1]) extends BaseApiResource {
            // ownerField() 기본값 = null
        };

        $request = Request::create('/test');

        $meta = $this->invokeResourceMeta($resource, $request);

        $this->assertArrayNotHasKey('is_owner', $meta);
    }

    /**
     * ownerField가 정의되고 요청 사용자가 소유자이면 is_owner = true.
     */
    public function test_resource_meta_returns_is_owner_true_when_user_is_owner(): void
    {
        $resource = new class(['id' => 1, 'user_id' => null]) extends BaseApiResource {
            protected function ownerField(): ?string
            {
                return 'user_id';
            }
        };

        // user_id를 adminUser의 id로 설정
        $resource->resource['user_id'] = $this->adminUser->id;

        $request = Request::create('/test');
        $request->setUserResolver(fn () => $this->adminUser);

        $meta = $this->invokeResourceMeta($resource, $request);

        $this->assertArrayHasKey('is_owner', $meta);
        $this->assertTrue($meta['is_owner']);
    }

    /**
     * ownerField가 정의되고 요청 사용자가 소유자가 아니면 is_owner = false.
     */
    public function test_resource_meta_returns_is_owner_false_when_user_is_not_owner(): void
    {
        $resource = new class(['id' => 1, 'user_id' => 999]) extends BaseApiResource {
            protected function ownerField(): ?string
            {
                return 'user_id';
            }
        };

        $request = Request::create('/test');
        $request->setUserResolver(fn () => $this->regularUser);

        $meta = $this->invokeResourceMeta($resource, $request);

        $this->assertArrayHasKey('is_owner', $meta);
        $this->assertFalse($meta['is_owner']);
    }

    /**
     * ownerField가 정의되고 비로그인 사용자이면 is_owner = false.
     */
    public function test_resource_meta_returns_is_owner_false_for_guest(): void
    {
        $resource = new class(['id' => 1, 'user_id' => 1]) extends BaseApiResource {
            protected function ownerField(): ?string
            {
                return 'user_id';
            }
        };

        $request = Request::create('/test');
        $request->setUserResolver(fn () => null);

        $meta = $this->invokeResourceMeta($resource, $request);

        $this->assertArrayHasKey('is_owner', $meta);
        $this->assertFalse($meta['is_owner']);
    }

    // =========================================================================
    // abilityMap / resolveAbilities 테스트
    // =========================================================================

    /**
     * abilityMap이 비어있으면 abilities가 resourceMeta에 포함되지 않는다.
     */
    public function test_resource_meta_excludes_abilities_when_permission_map_is_empty(): void
    {
        $resource = new class(['id' => 1]) extends BaseApiResource {
            // abilityMap() 기본값 = []
        };

        $request = Request::create('/test');

        $meta = $this->invokeResourceMeta($resource, $request);

        $this->assertArrayNotHasKey('abilities', $meta);
    }

    /**
     * Admin 역할 사용자는 모든 권한이 true.
     */
    public function test_resource_meta_returns_all_true_for_admin_user(): void
    {
        $resource = $this->createResourceWithPermissionMap();

        $request = Request::create('/test');
        $request->setUserResolver(fn () => $this->adminUser);

        $meta = $this->invokeResourceMeta($resource, $request);

        $this->assertArrayHasKey('abilities', $meta);
        $this->assertTrue($meta['abilities']['can_update']);
        $this->assertTrue($meta['abilities']['can_delete']);
    }

    /**
     * 일반 사용자는 권한이 없으면 false.
     */
    public function test_resource_meta_returns_false_for_user_without_abilities(): void
    {
        $resource = $this->createResourceWithPermissionMap();

        $request = Request::create('/test');
        $request->setUserResolver(fn () => $this->regularUser);

        $meta = $this->invokeResourceMeta($resource, $request);

        $this->assertArrayHasKey('abilities', $meta);
        $this->assertFalse($meta['abilities']['can_update']);
        $this->assertFalse($meta['abilities']['can_delete']);
    }

    // =========================================================================
    // resourceMeta 통합 테스트
    // =========================================================================

    /**
     * ownerField + abilityMap이 모두 정의되면 is_owner + abilities 모두 반환.
     */
    public function test_resource_meta_returns_both_is_owner_and_abilities(): void
    {
        $userId = $this->adminUser->id;
        $resource = new class(['id' => 1, 'user_id' => $userId]) extends BaseApiResource {
            protected function ownerField(): ?string
            {
                return 'user_id';
            }

            protected function abilityMap(): array
            {
                return [
                    'can_update' => 'test-module.products.update',
                    'can_delete' => 'test-module.products.delete',
                ];
            }
        };
        // 명시적으로 user_id 재설정 (anonymous class에서 캡처 안 될 수 있으므로)
        $resource->resource['user_id'] = $this->adminUser->id;

        $request = Request::create('/test');
        $request->setUserResolver(fn () => $this->adminUser);

        $meta = $this->invokeResourceMeta($resource, $request);

        $this->assertArrayHasKey('is_owner', $meta);
        $this->assertTrue($meta['is_owner']);
        $this->assertArrayHasKey('abilities', $meta);
        $this->assertCount(2, $meta['abilities']);
    }

    /**
     * ownerField, abilityMap 모두 미정의면 빈 배열 반환.
     */
    public function test_resource_meta_returns_empty_array_when_nothing_defined(): void
    {
        $resource = new class(['id' => 1]) extends BaseApiResource {};

        $request = Request::create('/test');

        $meta = $this->invokeResourceMeta($resource, $request);

        $this->assertEmpty($meta);
    }

    // =========================================================================
    // 헬퍼 메서드
    // =========================================================================

    /**
     * abilityMap이 정의된 테스트용 리소스를 생성합니다.
     */
    private function createResourceWithPermissionMap(): BaseApiResource
    {
        return new class(['id' => 1]) extends BaseApiResource {
            protected function abilityMap(): array
            {
                return [
                    'can_update' => 'test-module.products.update',
                    'can_delete' => 'test-module.products.delete',
                ];
            }
        };
    }

    /**
     * resourceMeta를 호출합니다.
     *
     * @param  BaseApiResource  $resource  리소스 인스턴스
     * @param  Request  $request  HTTP 요청 객체
     * @return array 메타 데이터
     */
    private function invokeResourceMeta(BaseApiResource $resource, Request $request): array
    {
        $reflection = new \ReflectionMethod($resource, 'resourceMeta');
        $reflection->setAccessible(true);

        return $reflection->invoke($resource, $request);
    }
}
