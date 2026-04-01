<?php

namespace Tests\Unit\Services;

use App\Extension\HookManager;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UserService 훅 테스트
 *
 * 사용자 생성/수정 시 훅이 올바르게 실행되는지 테스트합니다.
 */
class UserServiceHookTest extends TestCase
{
    use RefreshDatabase;

    private UserService $userService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userService = app(UserService::class);
        HookManager::resetAll();
    }

    protected function tearDown(): void
    {
        HookManager::resetAll();
        parent::tearDown();
    }

    /**
     * 사용자 생성 시 before_create 훅이 호출되는지 테스트합니다.
     */
    public function test_before_create_hook_is_called(): void
    {
        $hookCalled = false;

        HookManager::addAction('core.user.before_create', function ($data) use (&$hookCalled) {
            $hookCalled = true;
            $this->assertArrayHasKey('name', $data);
            $this->assertArrayHasKey('email', $data);
        });

        $this->userService->createUser([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $this->assertTrue($hookCalled);
    }

    /**
     * 사용자 생성 시 filter_create_data 훅이 데이터를 변형하는지 테스트합니다.
     */
    public function test_filter_create_data_hook_modifies_data(): void
    {
        HookManager::addFilter('core.user.filter_create_data', function ($data) {
            // 테스트용 필드 제거
            unset($data['custom_field']);

            return $data;
        });

        $user = $this->userService->createUser([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'custom_field' => 'should be removed',
        ]);

        // custom_field가 제거되어 DB에 저장되지 않음
        $this->assertNotNull($user->id);
    }

    /**
     * 사용자 생성 시 after_create 훅이 호출되는지 테스트합니다.
     */
    public function test_after_create_hook_is_called(): void
    {
        $hookCalled = false;
        $receivedUser = null;
        $receivedData = null;

        HookManager::addAction('core.user.after_create', function ($user, $data) use (&$hookCalled, &$receivedUser, &$receivedData) {
            $hookCalled = true;
            $receivedUser = $user;
            $receivedData = $data;
        });

        $user = $this->userService->createUser([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $this->assertTrue($hookCalled);
        $this->assertInstanceOf(User::class, $receivedUser);
        $this->assertEquals('Test User', $receivedData['name']);
    }

    /**
     * 사용자 수정 시 before_update 훅이 호출되는지 테스트합니다.
     */
    public function test_before_update_hook_is_called(): void
    {
        $user = User::factory()->create();
        $hookCalled = false;

        HookManager::addAction('core.user.before_update', function ($u, $data) use (&$hookCalled, $user) {
            $hookCalled = true;
            $this->assertEquals($user->id, $u->id);
            $this->assertArrayHasKey('name', $data);
        });

        $this->userService->updateUser($user, [
            'name' => 'Updated Name',
        ]);

        $this->assertTrue($hookCalled);
    }

    /**
     * 사용자 수정 시 filter_update_data 훅이 데이터를 변형하는지 테스트합니다.
     */
    public function test_filter_update_data_hook_modifies_data(): void
    {
        $user = User::factory()->create();

        HookManager::addFilter('core.user.filter_update_data', function ($data, $u) {
            // 테스트용 필드 제거
            unset($data['custom_field']);

            return $data;
        });

        $updatedUser = $this->userService->updateUser($user, [
            'name' => 'Updated Name',
            'custom_field' => 'should be removed',
        ]);

        $this->assertEquals('Updated Name', $updatedUser->name);
    }

    /**
     * 사용자 수정 시 after_update 훅이 호출되는지 테스트합니다.
     */
    public function test_after_update_hook_is_called(): void
    {
        $user = User::factory()->create();
        $hookCalled = false;

        HookManager::addAction('core.user.after_update', function ($u, $data) use (&$hookCalled) {
            $hookCalled = true;
        });

        $this->userService->updateUser($user, [
            'name' => 'Updated Name',
        ]);

        $this->assertTrue($hookCalled);
    }

    /**
     * 사용자 삭제 시 before_delete 훅이 호출되는지 테스트합니다.
     */
    public function test_before_delete_hook_is_called(): void
    {
        $user = User::factory()->create(['is_super' => false]);
        $hookCalled = false;

        HookManager::addAction('core.user.before_delete', function ($u) use (&$hookCalled, $user) {
            $hookCalled = true;
            $this->assertEquals($user->id, $u->id);
        });

        $this->userService->deleteUser($user);

        $this->assertTrue($hookCalled);
    }

    /**
     * 사용자 삭제 시 after_delete 훅이 호출되는지 테스트합니다.
     */
    public function test_after_delete_hook_is_called(): void
    {
        $user = User::factory()->create(['is_super' => false]);
        $hookCalled = false;
        $receivedData = null;

        HookManager::addAction('core.user.after_delete', function ($userData) use (&$hookCalled, &$receivedData) {
            $hookCalled = true;
            $receivedData = $userData;
        });

        $this->userService->deleteUser($user);

        $this->assertTrue($hookCalled);
        $this->assertArrayHasKey('id', $receivedData);
        $this->assertArrayHasKey('name', $receivedData);
        $this->assertArrayHasKey('email', $receivedData);
    }
}
