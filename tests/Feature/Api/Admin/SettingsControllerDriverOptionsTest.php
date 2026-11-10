<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Providers\SettingsServiceProvider;
use App\Repositories\JsonConfigRepository;
use App\Support\PhpExtensionProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Settings API에서 available_drivers 필드 포함 검증
 *
 * DriverRegistryService를 통해 코어 + 플러그인 드라이버 목록이
 * settings API 응답에 포함되는지 검증합니다.
 */
class SettingsControllerDriverOptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * 관리자 사용자 생성
     *
     * @return User 설정 권한 역할을 단 관리자
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $permissionIds = [];
        foreach (['core.settings.read', 'core.settings.update'] as $permIdentifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $permIdentifier],
                [
                    'name' => json_encode(['ko' => $permIdentifier, 'en' => $permIdentifier]),
                    'description' => json_encode(['ko' => $permIdentifier, 'en' => $permIdentifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            );
            $permissionIds[] = $permission->id;
        }

        $roleIdentifier = 'admin_test_'.uniqid();
        $adminRole = Role::create([
            'identifier' => $roleIdentifier,
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Admin']),
            'description' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'is_active' => true,
        ]);

        $adminBaseRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $adminRole->permissions()->sync($permissionIds);

        $user->roles()->attach($adminBaseRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);
        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        return $user->fresh();
    }

    /**
     * 인증된 요청 헬퍼 메서드
     *
     * @return static Bearer 토큰 헤더를 실은 테스트 인스턴스
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * settings index 응답에 available_drivers가 포함되는지 검증합니다.
     *
     * @scenario surface=admin_response
     *
     * @effects all_driver_categories_returned_at_once
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function index_response_includes_available_drivers(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/settings');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'available_drivers' => [
                        'storage',
                        'public_asset',
                        'cache',
                        'session',
                        'queue',
                        'log',
                        'websocket',
                        'mail',
                    ],
                ],
            ]);
    }

    /**
     * available_drivers의 각 드라이버에 id와 label 필드가 있는지 검증합니다.
     *
     * @scenario engine_source=core
     *
     * @effects admin_driver_options_include_search_category
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function available_drivers_have_correct_structure(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/settings');

        $response->assertStatus(200);

        $data = $response->json('data.available_drivers');

        // 10개 카테고리 존재 (search 는 폴백 가드 편입으로 추가 — A5b, image 는 이미지 처리 엔진 — #869)
        $this->assertCount(10, $data);

        // 각 카테고리에 최소 1개 이상의 드라이버 존재
        foreach ($data as $category => $drivers) {
            $this->assertNotEmpty($drivers, "{$category} 카테고리에 드라이버가 없습니다.");

            // 첫 번째 드라이버 구조 검증
            $this->assertArrayHasKey('id', $drivers[0]);
            $this->assertArrayHasKey('label', $drivers[0]);
            $this->assertArrayHasKey('ko', $drivers[0]['label']);
            $this->assertArrayHasKey('en', $drivers[0]['label']);
        }
    }

    /**
     * storage 카테고리에 코어 드라이버가 포함되는지 검증합니다.
     *
     * @scenario surface=admin_response
     *
     * @effects core_drivers_listed_per_category
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function storage_drivers_include_core_options(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/settings');

        $storageDrivers = $response->json('data.available_drivers.storage');
        $ids = array_column($storageDrivers, 'id');

        $this->assertContains('local', $ids);
        $this->assertContains('s3', $ids);
    }

    /**
     * mail 카테고리에 코어 드라이버가 포함되는지 검증합니다.
     *
     * @scenario surface=admin_response
     *
     * @effects core_drivers_listed_per_category
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function mail_drivers_include_core_options(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/settings');

        $mailDrivers = $response->json('data.available_drivers.mail');
        $ids = array_column($mailDrivers, 'id');

        $this->assertContains('smtp', $ids);
        $this->assertContains('mailgun', $ids);
        $this->assertContains('ses', $ids);
    }

    /**
     * search 카테고리에 코어 검색엔진이 포함되는지 검증합니다.
     *
     * 카테고리 개수만 세면 어떤 카테고리가 늘었는지 알 수 없다 — search 가 빠진 채
     * 다른 카테고리가 하나 늘어도 개수 단언은 통과한다.
     *
     * @scenario engine_source=core
     *
     * @effects admin_driver_options_include_search_category
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function search_drivers_include_core_engine(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/settings');

        $response->assertStatus(200);

        $drivers = $response->json('data.available_drivers.search');

        $this->assertIsArray($drivers, 'search 카테고리가 응답에 없습니다.');
        $this->assertContains('mysql-fulltext', array_column($drivers, 'id'));
    }

    /**
     * 플러그인이 Scout 엔진 등록 훅으로 추가한 검색엔진이 카탈로그에 나타납니다.
     *
     * 관리자 화면 셀렉트가 이 카탈로그를 그대로 바인딩하므로, 여기 없으면 플러그인이
     * 등록한 검색엔진을 운영자가 고를 수 없다.
     *
     * @scenario engine_source=plugin
     *
     * @effects admin_driver_options_include_search_category
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function search_drivers_include_plugin_registered_engine(): void
    {
        HookManager::addFilter(
            'core.search.engine_drivers',
            fn (array $drivers) => array_merge($drivers, ['meilisearch' => \stdClass::class])
        );

        $response = $this->authRequest()->getJson('/api/admin/settings');

        $ids = array_column($response->json('data.available_drivers.search'), 'id');

        $this->assertContains('meilisearch', $ids, '플러그인이 등록한 검색엔진이 카탈로그에 없습니다.');
        $this->assertContains('mysql-fulltext', $ids, '코어 검색엔진이 사라졌습니다.');
    }

    /**
     * public_asset 카테고리에 코어 3종(none/public/s3)이 포함되는지 검증합니다.
     *
     * @effects settings_catalog_includes_plugin_registered_disks
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function public_asset_drivers_include_core_options(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/settings');

        $drivers = $response->json('data.available_drivers.public_asset');
        $ids = array_column($drivers, 'id');

        $this->assertSame(['none', 'public', 's3'], $ids);
    }

    /**
     * 카탈로그에 있는 공개 자산 디스크 저장이 성공하는지 검증합니다.
     *
     * @effects catalog_public_asset_disk_saved_to_drivers_category
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function saving_valid_public_asset_disk_succeeds(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'drivers',
            'drivers' => $this->driversPayload(['public_asset_disk' => 'public']),
        ]);

        $response->assertStatus(200);

        $saved = (new JsonConfigRepository)->getCategory('drivers');
        $this->assertSame('public', $saved['public_asset_disk'] ?? null);
    }

    /**
     * 저장 → drivers.json → core.storage.public_asset_disk 주입 사슬을
     * 한 테스트 안에서 종단으로 검증합니다.
     *
     * 주입 자동 실행은 testing 격리 가드(applyPublicAssetDiskConfig)가 막고 있으므로
     * (dev 공유 drivers.json 유입 차단), 가드를 통과한 뒤의 주입부만 실제 저장소로
     * 호출해 사슬을 잇습니다. mock 저장소를 쓰는 단위 테스트와 달리 이 테스트는
     * 저장 산출물(drivers.json)이 주입 입력이 되는 것까지 고정합니다.
     *
     * @effects global_setting_injects_core_config_with_none_normalized
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function saved_public_asset_disk_reaches_core_config_through_injection(): void
    {
        config(['core.storage.public_asset_disk' => 'stale_value']);

        $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'drivers',
            'drivers' => $this->driversPayload(['public_asset_disk' => 'public']),
        ])->assertStatus(200);

        $this->injectFromStoredSettings();

        $this->assertSame('public', config('core.storage.public_asset_disk'));

        // 'none' 저장은 미설정('')으로 정규화되어 스트리밍으로 되돌아간다
        $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'drivers',
            'drivers' => $this->driversPayload(['public_asset_disk' => 'none']),
        ])->assertStatus(200);

        $this->injectFromStoredSettings();

        $this->assertSame('', config('core.storage.public_asset_disk'));
    }

    /**
     * 실제 저장 산출물(drivers.json)을 읽어 주입부를 호출합니다.
     *
     * @return void 반환값 없음
     */
    private function injectFromStoredSettings(): void
    {
        $provider = new SettingsServiceProvider($this->app);
        $method = new \ReflectionMethod($provider, 'injectPublicAssetDiskConfig');
        $method->invoke($provider, new JsonConfigRepository);
    }

    /**
     * 카탈로그에 없는 공개 자산 디스크 저장이 422 로 거부되는지 검증합니다.
     *
     * @effects invalid_disk_rejected_with_422
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function saving_unknown_public_asset_disk_fails_with_422(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'drivers',
            'drivers' => $this->driversPayload(['public_asset_disk' => 'nonexistent_disk']),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['drivers.public_asset_disk']);
    }

    /**
     * 이미지 엔진 선택지는 이 서버에서 쓸 수 있는 엔진만 보입니다 (#869).
     *
     * imagick 확장이 없는 서버(Windows 개발 서버)에서는 gd 만 보여야 한다 — 고를 수 없는 엔진을 보여 주면
     * 운영자가 골라 저장하다 422 를 만난다.
     *
     * @scenario surface=driver_catalog
     *
     * @effects unusable_image_engines_hidden_from_catalog
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function image_drivers_list_only_engines_usable_on_this_server(): void
    {
        $this->fakeExtensions(['gd' => true, 'imagick' => false]);

        $ids = array_column($this->authRequest()->getJson('/api/admin/settings')->json('data.available_drivers.image'), 'id');
        $this->assertSame(['gd'], $ids);

        $this->fakeExtensions(['gd' => true, 'imagick' => true]);

        $ids = array_column($this->authRequest()->getJson('/api/admin/settings')->json('data.available_drivers.image'), 'id');
        $this->assertSame(['gd', 'imagick'], $ids);
    }

    /**
     * 쓸 수 없는 이미지 엔진을 직접 보내면 422 와 사유가 납니다 — 목록에서 뺀 것만으로는 직접 호출을 막지 못한다.
     *
     * @scenario surface=save_validation
     *
     * @effects unusable_image_engine_rejected_with_reason
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function saving_unusable_image_engine_fails_with_422_and_reason(): void
    {
        $this->fakeExtensions(['gd' => true, 'imagick' => false]);

        $response = $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'drivers',
            'drivers' => $this->driversPayload(['image_driver' => 'imagick']),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['drivers.image_driver']);
        $this->assertStringContainsString(
            __('settings.driver_unusable_imagick_extension'),
            implode(' ', (array) ($response->json('errors')['drivers.image_driver'] ?? []))
        );

        $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'drivers',
            'drivers' => $this->driversPayload(['image_driver' => 'no-such-engine']),
        ])->assertStatus(422)->assertJsonValidationErrors(['drivers.image_driver' => __('validation.settings.image_driver_invalid')]);
    }

    /**
     * 쓸 수 있는 이미지 엔진은 저장되고 같은 요청 안의 config 에 반영됩니다 — 기본값(gd)이 아닌 imagick 으로 잰다.
     *
     * @scenario surface=save_storage
     *
     * @effects image_engine_saved_reaches_config
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function saving_usable_image_engine_reaches_config(): void
    {
        $this->fakeExtensions(['gd' => true, 'imagick' => true]);
        config(['core.image.driver' => 'gd']);

        $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'drivers',
            'drivers' => $this->driversPayload(['image_driver' => 'imagick']),
        ])->assertStatus(200);

        $this->assertSame('imagick', (new JsonConfigRepository)->getCategory('drivers')['image_driver'] ?? null);
        $this->assertSame('imagick', config('core.image.driver'));
    }

    /**
     * 쓸 수 있는 엔진이 하나도 없으면 메타가 그것을 알립니다 (업로드 탭 「엔진 없음」 안내의 근거).
     *
     * @scenario surface=admin_response
     *
     * @effects engine_availability_meta_reported
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function thumbnail_engine_meta_follows_engine_availability(): void
    {
        $this->fakeExtensions(['gd' => true, 'imagick' => false]);
        $this->assertTrue($this->authRequest()->getJson('/api/admin/settings')->json('data._meta.thumbnail_engine_available'));

        $this->fakeExtensions(['gd' => false, 'imagick' => false]);
        $this->assertFalse($this->authRequest()->getJson('/api/admin/settings')->json('data._meta.thumbnail_engine_available'));
    }

    /**
     * PHP 확장 조회기를 바꿔 이미지 엔진 확장의 유무를 흉내 냅니다.
     *
     * @param  array<string, bool>  $loaded  확장 이름 → 로드 여부 (없는 이름은 실제 값)
     * @return void 반환값 없음
     */
    private function fakeExtensions(array $loaded): void
    {
        $this->app->instance(PhpExtensionProbe::class, new class($loaded) extends PhpExtensionProbe
        {
            /**
             * 확장 로드 여부 표를 받습니다.
             *
             * @param  array<string, bool>  $loaded  확장 이름 → 로드 여부
             */
            public function __construct(private array $loaded) {}

            /**
             * 주입한 표로 확장 로드 여부를 답합니다.
             *
             * @param  string  $extension  확장 이름
             * @return bool 로드돼 있으면 true
             */
            public function loaded(string $extension): bool
            {
                return $this->loaded[$extension] ?? parent::loaded($extension);
            }
        });
    }

    /**
     * drivers 탭 저장에 필요한 최소 필수 필드 payload 를 반환합니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 필드
     * @return array<string, mixed> drivers 탭 저장 요청 본문
     */
    private function driversPayload(array $overrides = []): array
    {
        return array_merge([
            'storage_driver' => 'local',
            'cache_driver' => 'file',
            'session_driver' => 'file',
            'queue_driver' => 'sync',
            'log_driver' => 'daily',
            'log_level' => 'error',
        ], $overrides);
    }
}
