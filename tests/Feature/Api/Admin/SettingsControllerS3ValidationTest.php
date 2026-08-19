<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\DriverRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 드라이버 탭 S3 검증 테스트 (공개 #99 / 내부 #563, A4·A7)
 *
 * s3_region 형식 검증 완화(리전 하드코딩 제거), s3_endpoint/s3_use_path_style
 * 신규 키 검증, 드라이버 가용성 서버 게이트를 검증합니다.
 */
class SettingsControllerS3ValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * 설정 권한을 가진 관리자 사용자를 생성합니다.
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
                    'description' => json_encode(['ko' => $permIdentifier.' 권한', 'en' => $permIdentifier.' Permission']),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            );
            $permissionIds[] = $permission->id;
        }

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'is_active' => true,
            ]
        );
        $adminRole->permissions()->syncWithoutDetaching($permissionIds);
        $user->roles()->attach($adminRole->id);

        return $user;
    }

    /**
     * 인증 헤더를 부착한 요청 헬퍼입니다.
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * drivers 탭 저장의 기본 payload 를 반환합니다.
     *
     * @param  array  $overrides  drivers 오버라이드 값
     * @return array 요청 payload
     */
    private function driversPayload(array $overrides = []): array
    {
        return [
            '_tab' => 'drivers',
            'drivers' => array_merge([
                'storage_driver' => 'local',
                'cache_driver' => 'file',
                'session_driver' => 'file',
                'queue_driver' => 'database',
                'log_driver' => 'daily',
                'log_level' => 'error',
                'websocket_enabled' => false,
            ], $overrides),
        ];
    }

    /**
     * 리전 형식 검증 — 유효한 값(구 화이트리스트·신규 AWS 리전·R2 auto·MinIO 커스텀)은
     * 저장이 허용되어야 합니다.
     *
     * @scenario region_shape=aws_region,endpoint=absent,driver_usability=adapter_present,attachment_disk=local_default
     *
     * @effects s3_disk_resolves
     */
    #[DataProvider('validRegionProvider')]
    public function test_store_accepts_valid_region_shapes(string $region): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings', $this->driversPayload([
            'storage_driver' => 's3',
            's3_bucket' => 'my-bucket',
            's3_region' => $region,
            's3_access_key' => 'key',
            's3_secret_key' => 'secret',
        ]));

        $response->assertStatus(200)->assertJson(['success' => true]);
    }

    /**
     * 유효한 리전 형식 목록 (구 하드코딩 5종 밖 값 포함 — A4 회귀 가드)
     *
     * @return array<string, array{string}>
     */
    public static function validRegionProvider(): array
    {
        return [
            '구 화이트리스트 값' => ['ap-northeast-2'],
            '구 목록 밖 AWS 리전' => ['eu-central-1'],
            'Cloudflare R2 표준값' => ['auto'],
            'MinIO 관례값' => ['us-east-1'],
        ];
    }

    /**
     * 리전 형식 검증 — 불량 형식은 422 로 거부되어야 합니다.
     *
     * @scenario region_shape=invalid_format,endpoint=absent,driver_usability=adapter_present,attachment_disk=local_default
     *
     * @effects invalid_region_rejected
     */
    #[DataProvider('invalidRegionProvider')]
    public function test_store_rejects_invalid_region_shapes(string $region): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings', $this->driversPayload([
            'storage_driver' => 's3',
            's3_bucket' => 'my-bucket',
            's3_region' => $region,
            's3_access_key' => 'key',
            's3_secret_key' => 'secret',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['drivers.s3_region']);
    }

    /**
     * 불량 리전 형식 목록
     *
     * @return array<string, array{string}>
     */
    public static function invalidRegionProvider(): array
    {
        return [
            '특수문자' => ['S3!bad'],
            '대문자' => ['AP-NORTHEAST-2'],
            '공백 포함' => ['ap northeast 2'],
        ];
    }

    /**
     * s3_endpoint 는 URL 형식이어야 합니다 (불량 형식 422).
     *
     * @scenario region_shape=aws_region,endpoint=custom_url,driver_usability=adapter_present,attachment_disk=local_default
     *
     * @effects endpoint_injected
     */
    public function test_store_validates_s3_endpoint_url_format(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings', $this->driversPayload([
            'storage_driver' => 's3',
            's3_bucket' => 'my-bucket',
            's3_region' => 'auto',
            's3_access_key' => 'key',
            's3_secret_key' => 'secret',
            's3_endpoint' => 'not-a-url',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['drivers.s3_endpoint']);
    }

    /**
     * s3_endpoint/s3_use_path_style 정상 값은 저장 왕복이 성립해야 합니다.
     *
     * @scenario region_shape=custom_minio,endpoint=path_style,driver_usability=adapter_present,attachment_disk=follow_s3
     *
     * @effects endpoint_injected, attachment_upload_follows_driver
     */
    public function test_store_saves_s3_endpoint_and_path_style_roundtrip(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings', $this->driversPayload([
            'storage_driver' => 's3',
            's3_bucket' => 'my-bucket',
            's3_region' => 'us-east-1',
            's3_access_key' => 'key',
            's3_secret_key' => 'secret',
            's3_endpoint' => 'http://127.0.0.1:9000',
            's3_use_path_style' => true,
        ]));

        $response->assertStatus(200)->assertJson(['success' => true]);

        $show = $this->authRequest()->getJson('/api/admin/settings');
        $show->assertStatus(200);

        $drivers = $show->json('data.drivers');
        $this->assertSame('http://127.0.0.1:9000', $drivers['s3_endpoint'] ?? null);
        $this->assertTrue((bool) ($drivers['s3_use_path_style'] ?? false));
    }

    /**
     * s3_use_path_style 은 boolean 이어야 합니다.
     *
     * @scenario region_shape=aws_region,endpoint=path_style,driver_usability=adapter_present,attachment_disk=local_default
     *
     * @effects endpoint_injected
     */
    public function test_store_validates_s3_use_path_style_boolean(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings', $this->driversPayload([
            'storage_driver' => 's3',
            's3_bucket' => 'my-bucket',
            's3_region' => 'auto',
            's3_access_key' => 'key',
            's3_secret_key' => 'secret',
            's3_use_path_style' => 'not-bool',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['drivers.s3_use_path_style']);
    }

    /**
     * 드라이버 가용성 서버 게이트 — 사용 불능 드라이버 저장은 422 로 차단되어야 합니다 (A7).
     *
     * memcached PHP 확장 존재 여부는 환경 의존이므로, 확장이 있는 환경에서는
     * isDriverUsable 단위 검증(DriverRegistryServiceUsabilityTest)이 판정 로직을 담당하고
     * 이 테스트는 통과로 간주합니다.
     *
     * @scenario region_shape=aws_region,endpoint=absent,driver_usability=adapter_absent,attachment_disk=local_default
     *
     * @effects unusable_driver_rejected
     */
    public function test_store_rejects_unusable_driver(): void
    {
        if (extension_loaded('memcached')) {
            $this->markTestSkipped('memcached 확장이 설치된 환경 — 사용 불능 상태를 만들 수 없어 단위 테스트가 판정을 담당');
        }

        $response = $this->authRequest()->postJson('/api/admin/settings', $this->driversPayload([
            'cache_driver' => 'memcached',
            'memcached_host' => '127.0.0.1',
            'memcached_port' => 11211,
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['drivers.cache_driver']);
    }

    /**
     * 드라이버 가용성 게이트의 컨트롤러↔서비스 결합을 환경 무관하게 검증합니다 (A7).
     *
     * 위 테스트는 확장 미설치 환경에서만 실 422 를 밟으므로, 레지스트리를 컨테이너
     * partialMock 으로 교체해 "사용 불능" 판정 시 저장 요청이 422 + 사유 문구로
     * 차단되는 결합점을 어느 환경에서든 단언한다.
     *
     * @scenario region_shape=aws_region,endpoint=absent,driver_usability=adapter_absent,attachment_disk=local_default
     *
     * @effects unusable_driver_rejected
     */
    public function test_store_rejects_unusable_driver_via_registry_gate(): void
    {
        $this->partialMock(DriverRegistryService::class, function ($mock) {
            $mock->shouldReceive('isDriverUsable')
                ->andReturnUsing(fn (string $category, string $driverId) => ! ($category === 'cache' && $driverId === 'redis'));
            $mock->shouldReceive('usabilityFailureReason')
                ->andReturn('mocked: redis client missing');
        });

        $response = $this->authRequest()->postJson('/api/admin/settings', $this->driversPayload([
            'cache_driver' => 'redis',
            'redis_host' => '127.0.0.1',
            'redis_port' => 6379,
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['drivers.cache_driver']);

        // 사유가 422 응답 본문까지 도달해야 운영자가 원인을 알 수 있다
        $messages = $response->json('errors')['drivers.cache_driver'] ?? [];
        $this->assertStringContainsString('mocked: redis client missing', implode(' ', $messages));
    }

    /**
     * 연결 테스트 요청도 동일한 리전 완화·신규 키 규칙을 적용해야 합니다 (게이트 대칭).
     *
     * @scenario region_shape=auto,endpoint=custom_url,driver_usability=adapter_present,attachment_disk=local_default
     *
     * @effects endpoint_injected
     */
    public function test_test_driver_request_accepts_relaxed_region_and_new_keys(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings/test-driver', [
            'storage_driver' => 's3',
            's3_bucket' => 'my-bucket',
            's3_region' => 'auto',
            's3_access_key' => 'key',
            's3_secret_key' => 'secret',
            's3_endpoint' => 'http://127.0.0.1:9',
            's3_use_path_style' => true,
        ]);

        // 검증 통과(422 아님) — 연결 자체는 도달 불가 endpoint 라 실패 결과가 반환된다
        $response->assertStatus(200);
        $this->assertFalse($response->json('data.results.s3.success'));
    }

    /**
     * 연결 테스트 요청이 websocket server 3필드를 수용해야 합니다 (A6 요청부).
     *
     * @scenario region_shape=aws_region,endpoint=absent,driver_usability=adapter_present,attachment_disk=local_default
     *
     * @effects test_covers_server_websocket
     */
    public function test_test_driver_request_accepts_websocket_server_fields(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings/test-driver', [
            'websocket_enabled' => true,
            'websocket_host' => 'localhost',
            'websocket_port' => 65500,
            'websocket_scheme' => 'http',
            'websocket_server_host' => '127.0.0.1',
            'websocket_server_port' => 65501,
            'websocket_server_scheme' => 'http',
            'websocket_app_key' => 'k',
        ]);

        // 검증 통과 — 서버 미기동이므로 연결 실패 결과가 반환될 뿐 422 는 아니어야 한다
        $response->assertStatus(200);
        $this->assertFalse($response->json('data.results.websocket.success'));
    }

    /**
     * 비인증 요청은 401 로 차단되어야 합니다.
     *
     * @scenario region_shape=aws_region,endpoint=absent,driver_usability=adapter_present,attachment_disk=local_default
     *
     * @effects unauthenticated_returns_401
     */
    public function test_store_and_test_driver_return_401_without_authentication(): void
    {
        $this->postJson('/api/admin/settings', $this->driversPayload())->assertStatus(401);
        $this->postJson('/api/admin/settings/test-driver', ['storage_driver' => 's3'])->assertStatus(401);
    }
}
