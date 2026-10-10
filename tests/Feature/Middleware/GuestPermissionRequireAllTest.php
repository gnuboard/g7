<?php

namespace Tests\Feature\Middleware;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Http\Middleware\PermissionMiddleware;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 비회원 다중 권한 판정 (core-backend #17).
 *
 * 회원은 다중 권한 라우트에서 requireAll(기본 AND)을 따르는데, 비회원만 항상 OR 로 판정해
 * 같은 라우트가 회원에게는 닫히고 비회원에게는 열리는 역전이 있었다.
 */
class GuestPermissionRequireAllTest extends TestCase
{
    use RefreshDatabase;

    /**
     * guest 역할 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        PermissionMiddleware::clearGuestRoleCache();
    }

    /**
     * 비회원의 다중 권한도 회원과 같이 requireAll(기본 AND)을 따른다 — 하나만 가지면 거부, OR 지정이면 허용 (core-backend #17)
     *
     * @scenario subject=guest,permissions=multiple,require_all=default|false
     *
     * @effects guest_multiple_permissions_follow_require_all
     *
     * @return void 반환값 없음
     */
    public function test_guest_multiple_permissions_follow_require_all(): void
    {
        $guestRole = Role::create([
            'identifier' => 'guest',
            'name' => ['ko' => '비회원', 'en' => 'Guest'],
            'description' => ['ko' => '비회원 역할', 'en' => 'Guest role'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);
        $one = Permission::create([
            'identifier' => 'test.guest.one',
            'name' => ['ko' => '하나', 'en' => 'One'],
            'description' => ['ko' => '하나', 'en' => 'One'],
            'type' => PermissionType::User,
        ]);
        Permission::create([
            'identifier' => 'test.guest.two',
            'name' => ['ko' => '둘', 'en' => 'Two'],
            'description' => ['ko' => '둘', 'en' => 'Two'],
            'type' => PermissionType::User,
        ]);
        $guestRole->permissions()->attach($one->id);

        Route::middleware(['api', 'permission:user,test.guest.one|test.guest.two'])->get('/api/test-guest-and', fn () => response()->json(['ok' => true]));
        Route::middleware(['api', 'permission:user,test.guest.one|test.guest.two,false'])->get('/api/test-guest-or', fn () => response()->json(['ok' => true]));

        $this->getJson('/api/test-guest-and')->assertStatus(401);
        $this->getJson('/api/test-guest-or')->assertStatus(200);
    }
}
