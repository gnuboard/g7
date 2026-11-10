<?php

namespace Tests\Feature\Broadcasting;

use App\Enums\ExtensionStatus;
use App\Extension\AbstractModule;
use App\Extension\ModuleManager;
use App\Models\Module;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 브로드캐스트 채널 인가 콜백의 지연 등록 (#860 W12)
 *
 * 채널 인가 콜백은 `/api/broadcasting/auth` 처리 때만 쓰인다. 부팅마다 `Broadcast::channel()` 로 드라이버
 * (reverb 면 Pusher 클라이언트)를 만들던 것을, 인가 라우트가 `Broadcast::auth()` 직전에 한 번 등록하도록 바꾼다.
 */
class BroadcastChannelRegistrarTest extends TestCase
{
    use DatabaseTransactions;

    private const MODULE = 'w12-chan-test';

    /**
     * 부팅만으로는 브로드캐스트 드라이버가 만들어지지 않습니다.
     *
     * @effects broadcaster_not_created_during_boot
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 부팅만으로는_드라이버가_생기지_않는다(): void
    {
        $this->assertSame([], $this->createdDrivers());
    }

    /**
     * 웹소켓을 끄면 인가 요청이 403 으로 끝나고 드라이버·채널 등록도 일어나지 않습니다.
     *
     * @effects broadcaster_not_created_during_boot
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 웹소켓_끔에서는_등록_없이_403(): void
    {
        Config::set('broadcasting.default', 'null');
        Sanctum::actingAs(User::factory()->create(), ['*'], 'sanctum');

        $this->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-core.admin.dashboard',
            'socket_id' => '123.456',
        ])->assertStatus(403);

        $this->assertSame([], $this->createdDrivers());
    }

    /**
     * 인가 요청이 코어 채널(routes/channels.php)과 활성 확장의 채널을 등록하고, 확장 채널 콜백은 권한으로 판정합니다.
     *
     * @effects broadcast_auth_registers_core_and_extension_channels
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 인가_요청이_코어와_확장_채널을_등록한다(): void
    {
        Config::set('broadcasting.default', 'log');
        $this->addActiveModuleWithChannel();
        $this->assertArrayNotHasKey('module.'.self::MODULE.'.{id}', $this->registeredChannels());

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*'], 'sanctum');

        $response = $this->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-module.'.self::MODULE.'.1',
            'socket_id' => '123.456',
        ]);
        $this->assertNotSame(500, $response->getStatusCode());

        $channels = $this->registeredChannels();
        $this->assertArrayHasKey('core.admin.seo.sitemap', $channels);
        $this->assertArrayHasKey('App.Models.User.{id}', $channels);
        $this->assertArrayHasKey('module.'.self::MODULE.'.{id}', $channels);
        $this->assertFalse((bool) $channels['module.'.self::MODULE.'.{id}']($user, '1'), '권한 없는 사용자는 거부');

        // 같은 브로드캐스터에 두 번 등록하지 않는다
        $count = count($channels);
        $this->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-core.admin.dashboard',
            'socket_id' => '123.456',
        ]);
        $this->assertCount($count, $this->registeredChannels());
    }

    /**
     * 활성 상태의 시험 모듈을 모듈 매니저에 넣습니다 (채널 1개 선언).
     *
     * @return void 반환값 없음
     */
    private function addActiveModuleWithChannel(): void
    {
        Module::query()->where('identifier', self::MODULE)->delete();
        Module::factory()->create(['identifier' => self::MODULE, 'status' => ExtensionStatus::Active->value]);
        ModuleManager::invalidateModuleStatusCache();

        $module = new class('/virtual/'.self::MODULE) extends AbstractModule
        {
            /**
             * 가상 모듈 경로를 받습니다 (basename 이 식별자).
             *
             * @param  string  $fakeModulePath  모듈 경로
             */
            public function __construct(private string $fakeModulePath) {}

            /**
             * 파일 시스템 대신 빈 매니페스트를 씁니다.
             *
             * @return array<string, mixed> 매니페스트
             */
            protected function loadManifest(): array
            {
                return [];
            }

            /**
             * 가상 모듈 경로를 반환합니다.
             *
             * @return string 모듈 경로
             */
            protected function getModulePath(): string
            {
                return $this->fakeModulePath;
            }

            /**
             * 시험 채널 1개를 선언합니다.
             *
             * @return array<string, array<string, string>> 채널 정의
             */
            public function getChannels(): array
            {
                return ['module.w12-chan-test.{id}' => ['permission' => 'w12-chan-test.read']];
            }
        };

        $manager = app(ModuleManager::class);
        $property = new \ReflectionProperty($manager, 'modules');
        $modules = $property->getValue($manager);
        $modules[self::MODULE] = $module;
        $property->setValue($manager, $modules);
    }

    /**
     * 브로드캐스트 매니저가 만든 드라이버 이름 목록을 반환합니다 (매니저 미해석이면 빈 배열).
     *
     * @return array<int, string> 드라이버 이름
     */
    private function createdDrivers(): array
    {
        if (! $this->app->resolved(BroadcastManager::class) && ! $this->app->resolved(Factory::class)) {
            return [];
        }

        $property = new \ReflectionProperty(BroadcastManager::class, 'drivers');

        return array_keys($property->getValue(app(BroadcastManager::class)));
    }

    /**
     * 기본 브로드캐스터에 등록된 채널 인가 콜백을 반환합니다.
     *
     * @return array<string, mixed> 채널명 → 콜백
     */
    private function registeredChannels(): array
    {
        $broadcaster = app(Factory::class)->connection();
        $ref = new \ReflectionClass($broadcaster);
        while ($ref !== false && ! $ref->hasProperty('channels')) {
            $ref = $ref->getParentClass();
        }
        $this->assertNotFalse($ref, 'broadcaster 에 channels 프로퍼티가 없음');

        return $ref->getProperty('channels')->getValue($broadcaster);
    }
}
