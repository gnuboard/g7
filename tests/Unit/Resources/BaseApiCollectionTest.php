<?php

namespace Tests\Unit\Resources;

use App\Helpers\PermissionHelper;
use App\Http\Resources\BaseApiCollection;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/**
 * BaseApiCollection 단위 테스트
 */
class BaseApiCollectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * abilityMap 빈 배열 시 abilities 빈 배열 반환
     */
    public function test_empty_ability_map_returns_empty_abilities(): void
    {
        $collection = new class(collect([])) extends BaseApiCollection
        {
            public function toArray(Request $request): array
            {
                return ['data' => []];
            }
        };

        $request = Request::create('/test');

        $this->assertEmpty($collection->resolveCollectionAbilities($request));
    }

    /**
     * abilityMap 정의 시 권한에 따라 boolean 반환
     */
    public function test_ability_map_resolves_permissions(): void
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => ['ko' => '테스트'],
            'identifier' => 'test_role',
            'is_admin' => true,
        ]);

        $perm = Permission::firstOrCreate(
            ['identifier' => 'core.test.delete'],
            ['name' => ['ko' => '삭제'], 'type' => 'admin', 'order' => 1]
        );
        $role->permissions()->attach($perm->id);
        $user->roles()->attach($role->id);

        $collection = new class(collect([])) extends BaseApiCollection
        {
            protected function abilityMap(): array
            {
                return [
                    'can_delete' => 'core.test.delete',
                    'can_create' => 'core.test.create',
                ];
            }

            public function toArray(Request $request): array
            {
                return ['data' => []];
            }
        };

        $request = Request::create('/test');
        $request->setUserResolver(fn () => $user->fresh());

        $abilities = $collection->resolveCollectionAbilities($request);

        $this->assertTrue($abilities['can_delete']);
        $this->assertFalse($abilities['can_create']);
    }

    /**
     * resolveCollectionAbilities는 public 접근 가능
     */
    public function test_resolve_collection_abilities_is_public(): void
    {
        $collection = new class(collect([])) extends BaseApiCollection
        {
            public function toArray(Request $request): array
            {
                return ['data' => []];
            }
        };

        $ref = new \ReflectionMethod($collection, 'resolveCollectionAbilities');
        $this->assertTrue($ref->isPublic());
    }
}
