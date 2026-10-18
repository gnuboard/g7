<?php

namespace Tests\Feature\Extension;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Exceptions\ExtensionOperationInProgressException;
use App\Extension\Helpers\ExtensionOperationLock;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ModuleService;
use App\Services\PluginService;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 확장 업데이트 동시 실행 거절과 「업데이트할 것 없음」 응답 문구 (#138 실측 ②·④)
 *
 * ② 업데이트 확인 버튼을 두 번 누르면 두 요청이 상태 가드(status=updating)를 세우기 전 구간을 함께 지나
 *    업데이트가 두 번 수행됐다. 같은 확장의 업데이트는 원자적 잠금으로 하나만 수행하고, 두 번째 요청은 409 로
 *    거절한다(관리자 화면은 409 를 「이미 처리 중」으로 보고 무시한다).
 * ④ 업데이트할 것이 없어도 응답 문구가 「… 버전으로 업데이트되었습니다」였다 — 「업데이트할 내용이 없습니다」로 답한다.
 */
class ExtensionUpdateConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 종류별 Manager 클래스·업데이트 메서드·직전 훅·API 경로 접두어·Service·Service 메서드·결과 정보 키·문구 파일.
     *
     * @return array<string, array{0: class-string, 1: string, 2: string, 3: string, 4: class-string, 5: string, 6: string, 7: string}> 종류별 데이터 세트
     */
    public static function extensionTypes(): array
    {
        return [
            'module' => [ModuleManager::class, 'updateModule', 'core.modules.before_update', 'modules', ModuleService::class, 'updateModule', 'module_info', 'modules'],
            'plugin' => [PluginManager::class, 'updatePlugin', 'core.plugins.before_update', 'plugins', PluginService::class, 'updatePlugin', 'plugin_info', 'plugins'],
            'template' => [TemplateManager::class, 'updateTemplate', 'core.templates.before_version_update', 'templates', TemplateService::class, 'performVersionUpdate', 'template_info', 'templates'],
        ];
    }

    /**
     * 같은 확장의 업데이트가 진행 중이면 Manager 가 곧바로 거절하고 직전 훅도 발화하지 않는다 (CLI·관리자 공통 진입점).
     *
     * @scenario case=lock_held_manager
     *
     * @effects concurrent_update_rejected_before_any_step
     *
     * @param  class-string  $managerClass  Manager 클래스
     * @param  string  $method  업데이트 메서드
     * @param  string  $beforeHook  직전 훅 이름
     * @return void 반환값 없음
     */
    #[DataProvider('extensionTypes')]
    public function test_manager_rejects_update_while_same_extension_update_is_running(string $managerClass, string $method, string $beforeHook): void
    {
        $type = $this->typeOf($managerClass);
        $held = Cache::lock(ExtensionOperationLock::key($type, 'acme-busy'), 60);
        $this->assertTrue($held->get());

        $fired = 0;
        HookManager::addAction($beforeHook, function () use (&$fired) {
            $fired++;
        });

        try {
            app($managerClass)->{$method}('acme-busy');
            $this->fail('진행 중인 업데이트가 있는데 두 번째 업데이트가 거절되지 않았다');
        } catch (ExtensionOperationInProgressException $e) {
            $this->assertSame('acme-busy', $e->identifier);
            $this->assertSame(__('extensions.errors.update_already_running', ['name' => 'acme-busy']), $e->getMessage());
        } finally {
            HookManager::clearAction($beforeHook);
            $held->release();
        }

        $this->assertSame(0, $fired, '거절된 요청이 직전 훅을 발화했다');
    }

    /**
     * 업데이트가 실패로 끝나도 잠금은 풀려 다음 업데이트를 막지 않는다.
     *
     * @scenario case=lock_released_after_failure
     *
     * @effects update_lock_released_after_operation
     *
     * @param  class-string  $managerClass  Manager 클래스
     * @param  string  $method  업데이트 메서드
     * @return void 반환값 없음
     */
    #[DataProvider('extensionTypes')]
    public function test_lock_is_released_after_update_fails(string $managerClass, string $method): void
    {
        $type = $this->typeOf($managerClass);

        try {
            app($managerClass)->{$method}('acme-missing');
            $this->fail('없는 확장의 업데이트가 성공했다');
        } catch (ExtensionOperationInProgressException $e) {
            $this->fail('잠금이 잡혀 있지 않은데 거절됐다');
        } catch (\RuntimeException) {
            // 없는 확장 — 기대한 실패
        }

        $lock = Cache::lock(ExtensionOperationLock::key($type, 'acme-missing'), 60);
        $this->assertTrue($lock->get(), '실패한 업데이트가 잠금을 풀지 않았다');
        $lock->release();
    }

    /**
     * 관리자 화면의 업데이트 요청이 진행 중인 업데이트와 겹치면 409 와 「이미 진행 중」 문구로 답한다.
     *
     * Service 가 모든 예외를 422 「업데이트 실패」로 감싸면 화면이 두 번째 요청을 실패로 보여 준다 — 이 예외만은
     * 그대로 올려 보낸다.
     *
     * @scenario case=lock_held_api
     *
     * @effects concurrent_update_api_responds_409
     *
     * @param  class-string  $managerClass  Manager 클래스
     * @param  string  $method  업데이트 메서드
     * @param  string  $beforeHook  직전 훅 이름
     * @param  string  $route  API 경로 접두어
     * @return void 반환값 없음
     */
    #[DataProvider('extensionTypes')]
    public function test_api_responds_409_while_same_extension_update_is_running(string $managerClass, string $method, string $beforeHook, string $route): void
    {
        $type = $this->typeOf($managerClass);
        $held = Cache::lock(ExtensionOperationLock::key($type, 'acme-busy'), 60);
        $this->assertTrue($held->get());

        try {
            $response = $this->actingAs($this->admin(), 'sanctum')
                ->postJson("/api/admin/{$route}/acme-busy/update");
        } finally {
            $held->release();
        }

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('extensions.errors.update_already_running', ['name' => 'acme-busy']));
    }

    /**
     * 업데이트할 것이 없으면 「업데이트되었습니다」가 아니라 「업데이트할 내용이 없습니다」로 답한다.
     *
     * @scenario case=no_update_api
     *
     * @effects no_update_response_message_says_nothing_to_update
     *
     * @param  class-string  $managerClass  Manager 클래스
     * @param  string  $method  업데이트 메서드
     * @param  string  $beforeHook  직전 훅 이름
     * @param  string  $route  API 경로 접두어
     * @param  class-string  $serviceClass  Service 클래스
     * @param  string  $serviceMethod  Service 업데이트 메서드
     * @param  string  $infoKey  결과의 확장 정보 키
     * @param  string  $langFile  문구 파일
     * @return void 반환값 없음
     */
    #[DataProvider('extensionTypes')]
    public function test_api_says_nothing_to_update_when_already_latest(
        string $managerClass,
        string $method,
        string $beforeHook,
        string $route,
        string $serviceClass,
        string $serviceMethod,
        string $infoKey,
        string $langFile,
    ): void {
        $service = Mockery::mock($serviceClass);
        $service->shouldReceive($serviceMethod)->once()->andReturn([
            'success' => false,
            'from_version' => '1.0.0',
            'to_version' => '1.0.0',
            'message' => __("{$langFile}.no_update_available"),
            $infoKey => null,
        ]);
        $this->app->instance($serviceClass, $service);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/admin/{$route}/acme-latest/update")
            ->assertStatus(200)
            ->assertJsonPath('message', __("{$langFile}.no_update_available"));
    }

    /**
     * Manager 클래스에서 잠금 종류 이름을 얻습니다.
     *
     * @param  class-string  $managerClass  Manager 클래스
     * @return string 확장 종류 (module|plugin|template)
     */
    private function typeOf(string $managerClass): string
    {
        return match ($managerClass) {
            ModuleManager::class => 'module',
            PluginManager::class => 'plugin',
            TemplateManager::class => 'template',
        };
    }

    /**
     * 세 종류의 업데이트 권한을 가진 관리자를 만듭니다.
     *
     * @return User 관리자
     */
    private function admin(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => ['ko' => '관리자', 'en' => 'Administrator'],
                'description' => ['ko' => '관리자', 'en' => 'Administrator'],
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $ids = [];
        foreach (['core.modules.install', 'core.plugins.install', 'core.templates.install'] as $identifier) {
            $ids[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => ['ko' => $identifier, 'en' => $identifier],
                    'description' => ['ko' => $identifier, 'en' => $identifier],
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => PermissionType::Admin,
                ]
            )->id;
        }
        $role->permissions()->syncWithoutDetaching($ids);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }
}
