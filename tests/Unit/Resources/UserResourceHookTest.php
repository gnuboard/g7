<?php

namespace Tests\Unit\Resources;

use App\Extension\HookManager;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UserResource 훅 테스트
 *
 * 사용자 리소스 반환 시 filter 훅이 올바르게 실행되는지 테스트합니다.
 */
class UserResourceHookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        HookManager::resetAll();
    }

    protected function tearDown(): void
    {
        HookManager::resetAll();
        parent::tearDown();
    }

    /**
     * withAdminInfo 호출 시 filter_resource_data 훅이 호출되는지 테스트합니다.
     */
    public function test_filter_resource_data_hook_is_called(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $hookCalled = false;

        HookManager::addFilter('core.user.filter_resource_data', function ($data, $u) use (&$hookCalled, $user) {
            $hookCalled = true;
            $this->assertEquals($user->id, $u->id);

            return $data;
        });

        $resource = new UserResource($user);
        $resource->withAdminInfo();

        $this->assertTrue($hookCalled);
    }

    /**
     * filter_resource_data 훅이 데이터를 병합하는지 테스트합니다.
     */
    public function test_filter_resource_data_hook_merges_data(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        HookManager::addFilter('core.user.filter_resource_data', function ($data, $u) {
            $data['custom_setting'] = true;
            $data['module_data'] = [
                'key' => 'value',
            ];

            return $data;
        });

        $resource = new UserResource($user);
        $result = $resource->withAdminInfo();

        $this->assertArrayHasKey('custom_setting', $result);
        $this->assertTrue($result['custom_setting']);
        $this->assertArrayHasKey('module_data', $result);
        $this->assertEquals('value', $result['module_data']['key']);
    }

    /**
     * 여러 filter 훅이 체이닝되어 동작하는지 테스트합니다.
     */
    public function test_multiple_filters_are_chained(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
        ]);

        // 첫 번째 필터
        HookManager::addFilter('core.user.filter_resource_data', function ($data, $u) {
            $data['module_a'] = 'data_a';

            return $data;
        }, 10);

        // 두 번째 필터
        HookManager::addFilter('core.user.filter_resource_data', function ($data, $u) {
            $data['module_b'] = 'data_b';

            return $data;
        }, 20);

        $resource = new UserResource($user);
        $result = $resource->withAdminInfo();

        $this->assertArrayHasKey('module_a', $result);
        $this->assertArrayHasKey('module_b', $result);
        $this->assertEquals('data_a', $result['module_a']);
        $this->assertEquals('data_b', $result['module_b']);
    }
}
