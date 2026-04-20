<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Traits;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Enums\PermissionType;
use App\Http\Middleware\PermissionMiddleware;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;
use PHPUnit\Framework\Attributes\Test;

/**
 * ChecksBoardPermission Trait 테스트
 *
 * 게시판 권한 체크 로직을 테스트합니다.
 * - 회원 권한 체크 (Gate)
 * - 비회원 권한 체크 (guest role)
 */
class ChecksBoardPermissionTest extends ModuleTestCase
{

    /**
     * Trait을 사용하는 테스트용 클래스
     */
    private object $traitObject;

    /**
     * 테스트 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        // PermissionMiddleware 정적 캐시 초기화
        $this->resetPermissionMiddlewareCache();

        // ChecksBoardPermission 요청 내 캐시 초기화
        ChecksBoardPermission::clearPermissionCache();

        // Trait을 사용하는 익명 클래스 생성
        $this->traitObject = new class
        {
            use ChecksBoardPermission;

            public function testCheckPermissionByIdentifier(string $identifier): bool
            {
                return $this->checkPermissionByIdentifier($identifier);
            }

            public function testCheckBoardPermission(string $slug, string $action, PermissionType $type = PermissionType::User): bool
            {
                return $this->checkBoardPermission($slug, $action, $type);
            }
        };
    }

    /**
     * PermissionMiddleware 정적 캐시 초기화
     */
    private function resetPermissionMiddlewareCache(): void
    {
        try {
            $reflection = new \ReflectionClass(PermissionMiddleware::class);
            if ($reflection->hasProperty('guestRoleCache')) {
                $prop = $reflection->getProperty('guestRoleCache');
                $prop->setAccessible(true);
                $prop->setValue(null, null);
            }
        } catch (\ReflectionException $e) {
            // 무시
        }
    }

    /**
     * 테스트 후 정리
     */
    protected function tearDown(): void
    {
        Auth::logout();
        parent::tearDown();
    }

    /**
     * 회원이 권한을 보유한 경우 true 반환
     */
    #[Test]
    public function 회원_권한_보유_시_true_반환(): void
    {
        // Given: 권한을 가진 회원 생성
        $user = User::factory()->create();
        $role = Role::firstOrCreate(
            ['identifier' => 'user'],
            ['name' => ['ko' => '회원', 'en' => 'User'], 'is_active' => true]
        );
        $permission = Permission::factory()->create([
            'identifier' => 'sirsoft-board.notice.posts.read',
            'type' => 'user',
        ]);

        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        Auth::login($user);
        // Unit 테스트에서 request()->user()가 Auth::login()을 반영하도록 설정
        request()->setUserResolver(fn () => $user);

        // When: 권한 체크
        $result = $this->traitObject->testCheckPermissionByIdentifier('sirsoft-board.notice.posts.read');

        // Then: true 반환
        $this->assertTrue($result);
    }

    /**
     * 회원이 권한을 보유하지 않은 경우 false 반환
     */
    #[Test]
    public function 회원_권한_미보유_시_false_반환(): void
    {
        // Given: 권한이 없는 회원 생성
        $user = User::factory()->create();
        $role = Role::firstOrCreate(
            ['identifier' => 'user'],
            ['name' => ['ko' => '회원', 'en' => 'User'], 'is_active' => true]
        );
        $permission = Permission::factory()->create([
            'identifier' => 'sirsoft-board.notice.posts.write',
            'type' => 'user',
        ]);

        $role->permissions()->syncWithoutDetaching([$permission->id]);
        // 사용자에게 역할 할당하지 않음

        Auth::login($user);

        // When: 권한 체크
        $result = $this->traitObject->testCheckPermissionByIdentifier('sirsoft-board.notice.posts.write');

        // Then: false 반환
        $this->assertFalse($result);
    }

    /**
     * 비회원이 guest role 권한을 보유한 경우 true 반환
     */
    #[Test]
    public function 비회원_guest_role_권한_보유_시_true_반환(): void
    {
        // Given: guest role과 권한 생성
        $guestRole = Role::firstOrCreate(
            ['identifier' => 'guest'],
            ['name' => ['ko' => '비회원', 'en' => 'Guest'], 'is_active' => true]
        );
        $permission = Permission::factory()->create([
            'identifier' => 'sirsoft-board.notice.posts.read',
            'type' => 'user',
        ]);

        $guestRole->permissions()->syncWithoutDetaching([$permission->id]);

        // Auth::logout() 또는 Auth 없음 (비회원 상태)
        Auth::logout();

        // When: 권한 체크
        $result = $this->traitObject->testCheckPermissionByIdentifier('sirsoft-board.notice.posts.read');

        // Then: true 반환
        $this->assertTrue($result);
    }

    /**
     * 비회원이 guest role 권한을 보유하지 않은 경우 false 반환
     */
    #[Test]
    public function 비회원_guest_role_권한_미보유_시_false_반환(): void
    {
        // Given: guest role은 있지만 해당 권한은 없음
        $guestRole = Role::firstOrCreate(
            ['identifier' => 'guest'],
            ['name' => ['ko' => '비회원', 'en' => 'Guest'], 'is_active' => true]
        );
        $permission = Permission::factory()->create([
            'identifier' => 'sirsoft-board.notice.posts.write',
            'type' => 'user',
        ]);

        $guestRole->permissions()->syncWithoutDetaching([$permission->id]);

        Auth::logout();

        // When: 다른 권한 체크 (guest role에 없는 권한)
        $result = $this->traitObject->testCheckPermissionByIdentifier('sirsoft-board.notice.posts.delete');

        // Then: false 반환
        $this->assertFalse($result);
    }

    /**
     * guest role이 없는 경우 false 반환
     */
    #[Test]
    public function guest_role_없으면_false_반환(): void
    {
        // Given: guest role이 아예 없음
        Auth::logout();

        // When: 권한 체크
        $result = $this->traitObject->testCheckPermissionByIdentifier('sirsoft-board.notice.posts.read');

        // Then: false 반환 (전체 허용도 없음)
        $this->assertFalse($result);
    }

    /**
     * 역할 할당이 없는 권한은 접근 거부 (false 반환)
     */
    #[Test]
    public function 역할_할당_없는_권한은_접근_거부(): void
    {
        // Given: 권한은 존재하지만 역할 할당이 없음
        Permission::factory()->create([
            'identifier' => 'sirsoft-board.notice.posts.read',
            'type' => 'user',
        ]);
        // role_permissions 테이블에 레코드 없음

        Auth::logout();

        // When: 권한 체크
        $result = $this->traitObject->testCheckPermissionByIdentifier('sirsoft-board.notice.posts.read');

        // Then: false 반환 (역할 할당 필수)
        $this->assertFalse($result);
    }

    /**
     * checkBoardPermission 메서드가 올바른 권한 식별자를 생성하는지 테스트
     */
    #[Test]
    public function checkBoardPermission_메서드_올바른_식별자_생성(): void
    {
        // Given: 권한 생성 + guest role에 할당
        $permission = Permission::factory()->create([
            'identifier' => 'sirsoft-board.notice.posts.read',
            'type' => 'user',
        ]);
        $guestRole = Role::firstOrCreate(
            ['identifier' => 'guest'],
            ['name' => ['ko' => '비회원', 'en' => 'Guest'], 'is_active' => true]
        );
        $guestRole->permissions()->syncWithoutDetaching([$permission->id]);

        Auth::logout();

        // When: checkBoardPermission 호출
        $result = $this->traitObject->testCheckBoardPermission('notice', 'posts.read');

        // Then: 올바른 식별자로 권한 체크됨 (guest role 권한 보유)
        $this->assertTrue($result);
    }

    /**
     * 권한이 존재하지 않고 역할 할당도 없으면 false 반환
     */
    #[Test]
    public function 권한_미존재_시_false_반환(): void
    {
        // Given: 권한 자체가 존재하지 않음
        Auth::logout();

        // When: 존재하지 않는 권한 체크
        $result = $this->traitObject->testCheckPermissionByIdentifier('sirsoft-board.nonexistent.permission');

        // Then: false 반환
        $this->assertFalse($result);
    }

    /**
     * 회원 권한 체크 우선순위 테스트 (회원이 로그인하면 Gate 먼저 체크)
     */
    #[Test]
    public function 회원_로그인_시_Gate_우선_체크(): void
    {
        // Given: guest role에도 권한 부여, 회원에게도 권한 부여
        $guestRole = Role::firstOrCreate(
            ['identifier' => 'guest'],
            ['name' => ['ko' => '비회원', 'en' => 'Guest'], 'is_active' => true]
        );
        $userRole = Role::firstOrCreate(
            ['identifier' => 'user'],
            ['name' => ['ko' => '회원', 'en' => 'User'], 'is_active' => true]
        );
        $permission = Permission::factory()->create([
            'identifier' => 'sirsoft-board.notice.posts.read',
            'type' => 'user',
        ]);

        $guestRole->permissions()->syncWithoutDetaching([$permission->id]);
        $userRole->permissions()->syncWithoutDetaching([$permission->id]);

        $user = User::factory()->create();
        $user->roles()->syncWithoutDetaching([$userRole->id]);

        Auth::login($user);
        // Unit 테스트에서 request()->user()가 Auth::login()을 반영하도록 설정
        request()->setUserResolver(fn () => $user);

        // When: 권한 체크
        $result = $this->traitObject->testCheckPermissionByIdentifier('sirsoft-board.notice.posts.read');

        // Then: true 반환 (회원 권한으로 체크됨)
        $this->assertTrue($result);
    }

    /**
     * 비회원은 guest role에 할당된 권한만 접근 가능
     */
    #[Test]
    public function 비회원_guest_role_미할당_권한은_접근_거부(): void
    {
        // Given: 권한 생성하지만 guest role에 할당하지 않음
        $permission = Permission::factory()->create([
            'identifier' => 'sirsoft-board.notice.posts.read',
            'type' => 'user',
        ]);

        // guest role 생성하지만 해당 권한 부여하지 않음
        Role::firstOrCreate(
            ['identifier' => 'guest'],
            ['name' => ['ko' => '비회원', 'en' => 'Guest'], 'is_active' => true]
        );

        Auth::logout();

        // When: 권한 체크
        $result = $this->traitObject->testCheckPermissionByIdentifier('sirsoft-board.notice.posts.read');

        // Then: false 반환 (guest role에 권한 미할당)
        $this->assertFalse($result);
    }
}
