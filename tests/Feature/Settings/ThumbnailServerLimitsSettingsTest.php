<?php

namespace Tests\Feature\Settings;

use App\Enums\ExtensionOwnerType;
use App\Exceptions\SettingsConsoleException;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Providers\SettingsServiceProvider;
use App\Repositories\JsonConfigRepository;
use App\Services\SettingsConsoleService;
use App\Support\EnvPriority;
use App\Thumbnails\ThumbnailConfig;
use App\Thumbnails\ThumbnailGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 썸네일 서버 보호 값 여섯 — `.env`(`G7_THUMBNAIL_*`)와 업로드 탭 「고급」 칸
 *
 * 값의 우선순위는 다른 `.env` 대응 설정과 같다(`EnvPriority`).
 *  - `.env` 우선 모드(`G7_ENV_PRIORITY=true`)에서 `.env` 에 값이 있으면 그 값이 이기고, 화면은 잠김으로 내려오며 저장이 그 키를 버린다.
 *  - 그렇지 않으면 저장값이 이긴다. 저장값이 비어 있으면 `config/core.php` 가 읽은 값(`.env` 가 있으면 그 값, 없으면 기본값)을 쓴다.
 * 설정 반영은 기본값이 아닌 값으로 재고, 설정 디스크는 TestCase 가 페이크로 바꾼다.
 */
class ThumbnailServerLimitsSettingsTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<string, string> 설정 키 → env 변수 */
    private const ENV = [
        'thumbnail_generate_per_minute' => 'G7_THUMBNAIL_GENERATE_PER_MINUTE',
        'thumbnail_lock_wait_seconds' => 'G7_THUMBNAIL_LOCK_WAIT_SECONDS',
        'thumbnail_max_source_pixels' => 'G7_THUMBNAIL_MAX_SOURCE_PIXELS',
        'thumbnail_pregenerate_batch' => 'G7_THUMBNAIL_PREGENERATE_BATCH',
        'thumbnail_pregenerate_time_budget' => 'G7_THUMBNAIL_PREGENERATE_TIME_BUDGET',
        'thumbnail_purge_time_budget' => 'G7_THUMBNAIL_PURGE_TIME_BUDGET',
    ];

    /** @var array<string, int> 기본값이 아닌 저장값 */
    private const SAVED = [
        'thumbnail_generate_per_minute' => 300,
        'thumbnail_lock_wait_seconds' => 20,
        'thumbnail_max_source_pixels' => 25000000,
        'thumbnail_pregenerate_batch' => 40,
        'thumbnail_pregenerate_time_budget' => 25,
        'thumbnail_purge_time_budget' => 35,
    ];

    private string $token;

    /**
     * 관리자 토큰을 만들고 여섯 값을 기본값으로 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['generate_per_minute' => 120, 'lock_wait_seconds' => 10, 'max_source_pixels' => 16000000, 'pregenerate_batch' => 20, 'pregenerate_time_budget' => 15, 'purge_time_budget' => 10] as $key => $value) {
            Config::set("core.thumbnail.{$key}", $value);
            Config::set("core.thumbnail_server_defaults.{$key}", $value);
        }

        config(['env-priority.enabled' => false, 'env-priority.explicit' => []]);

        $this->token = $this->createAdminUser()->createToken('test-token')->plainTextToken;
    }

    /**
     * 설정 읽기·수정 권한을 가진 관리자를 만듭니다.
     *
     * @return User 관리자
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create();

        $permissionIds = [];
        foreach (['core.settings.read', 'core.settings.update'] as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }

        $role = Role::create([
            'identifier' => 'thumb_limits_admin_'.uniqid(),
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Admin']),
            'description' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);

        $adminBase = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $user->roles()->attach($adminBase->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * `.env` 우선 모드를 켜고 지정한 env 변수만 명시 상태로 만듭니다.
     *
     * @param  array<int, string>  $explicitVars  명시 상태로 만들 env 변수
     * @return void 반환값 없음
     */
    private function enableEnvPriorityWith(array $explicitVars): void
    {
        config([
            'env-priority.enabled' => true,
            'env-priority.explicit' => array_fill_keys($explicitVars, true),
        ]);
    }

    /**
     * 업로드 탭 저장 요청을 보냅니다.
     *
     * @param  array<string, mixed>  $upload  업로드 탭 값 (기본 필수값 위에 덮는다)
     * @return TestResponse 응답
     */
    private function saveUpload(array $upload): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/admin/settings', [
            '_tab' => 'upload',
            'upload' => array_merge([
                'max_file_size' => 20,
                'allowed_extensions' => 'jpg,png,gif,webp',
            ], $upload),
        ]);
    }

    /**
     * 다음 부팅의 주입(`SettingsServiceProvider::applyUploadConfig`)을 지금 저장본으로 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function bootUploadInjection(): void
    {
        $provider = new SettingsServiceProvider($this->app);
        (new ReflectionMethod($provider, 'applyUploadConfig'))->invoke($provider, new JsonConfigRepository);
    }

    /**
     * 업로드 저장본에 값을 직접 넣습니다.
     *
     * @param  array<string, mixed>  $values  덮어쓸 업로드 값
     * @return void 반환값 없음
     */
    private function storeUpload(array $values): void
    {
        $repository = new JsonConfigRepository;
        $repository->saveCategory('upload', array_merge($repository->getCategory('upload'), $values));
    }

    /**
     * `config/core.php` 가 여섯 값을 `.env` 에서 읽고, 빈 값·숫자가 아닌 값은 기본값으로 둡니다.
     * 화면 칸을 비웠을 때 쓸 값(`core.thumbnail_server_defaults`)도 같은 값입니다.
     *
     * @scenario surface=server_limits
     *
     * @effects thumbnail_server_limits_read_from_env
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function config_file_reads_thumbnail_env_variables(): void
    {
        $values = [
            'G7_THUMBNAIL_GENERATE_PER_MINUTE' => '60',
            'G7_THUMBNAIL_LOCK_WAIT_SECONDS' => '5',
            'G7_THUMBNAIL_MAX_SOURCE_PIXELS' => '9000000',
            'G7_THUMBNAIL_PREGENERATE_BATCH' => '',
            'G7_THUMBNAIL_PREGENERATE_TIME_BUDGET' => 'abc',
            'G7_THUMBNAIL_PURGE_TIME_BUDGET' => '7',
        ];

        foreach ($values as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
            putenv("{$name}={$value}");
        }

        try {
            $config = require config_path('core.php');
        } finally {
            foreach (array_keys($values) as $name) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);
            }
        }

        $expected = [
            'generate_per_minute' => 60,
            'lock_wait_seconds' => 5,
            'max_source_pixels' => 9000000,
            'pregenerate_batch' => 20,
            'pregenerate_time_budget' => 15,
            'purge_time_budget' => 7,
        ];

        foreach ($expected as $key => $value) {
            $this->assertSame($value, $config['thumbnail'][$key], $key);
            $this->assertSame($value, $config['thumbnail_server_defaults'][$key], 'server_defaults.'.$key);
        }
    }

    /**
     * 여섯 키가 `.env` 대응 표(`EnvPriority::MAP`)에 `G7_THUMBNAIL_*` 로 올라 있고, 기본값 파일은 비운 값(null)입니다.
     *
     * 기본값을 숫자로 시드하면 저장값이 늘 있어 우선 모드가 꺼진 설치에서 `.env` 가 사문화된다.
     *
     * @scenario surface=server_limits
     *
     * @effects thumbnail_server_limits_registered_for_env_priority
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function server_limits_are_mapped_to_env_and_seeded_blank(): void
    {
        $defaults = json_decode((string) file_get_contents(config_path('settings/defaults.json')), true)['defaults']['upload'];

        foreach (self::ENV as $settingKey => $env) {
            $entry = EnvPriority::MAP['upload.'.$settingKey] ?? null;
            $this->assertNotNull($entry, $settingKey);
            $this->assertSame([$env], $entry['env']);
            $this->assertSame(['core.thumbnail.'.substr($settingKey, strlen('thumbnail_'))], $entry['config']);
            $this->assertArrayHasKey($settingKey, $defaults);
            $this->assertNull($defaults[$settingKey], $settingKey);
        }
    }

    /**
     * `.env` 우선 모드에서 `.env` 에 값이 있으면 저장값이 있어도 `.env` 값(config 가 읽은 값)이 남습니다.
     *
     * @scenario surface=server_limits
     *
     * @effects thumbnail_server_limits_env_wins_when_locked
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function env_value_wins_over_saved_value_when_locked(): void
    {
        $this->storeUpload(self::SAVED);
        $this->enableEnvPriorityWith(array_values(self::ENV));
        // config/core.php 가 .env 에서 읽은 값이라고 친다
        Config::set('core.thumbnail.lock_wait_seconds', 7);
        Config::set('core.thumbnail.generate_per_minute', 90);

        $this->bootUploadInjection();

        $this->assertSame(7, config('core.thumbnail.lock_wait_seconds'));
        $this->assertSame(90, config('core.thumbnail.generate_per_minute'));
        $this->assertSame(7, ThumbnailGenerator::lockWaitSeconds());
    }

    /**
     * `.env` 가 잠그지 않으면 저장값이 런타임 값이 됩니다(정수로).
     *
     * @scenario surface=server_limits
     *
     * @effects thumbnail_server_limits_saved_value_applies
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function saved_value_applies_when_not_locked(): void
    {
        $this->storeUpload(array_map('strval', self::SAVED));
        // 우선 모드가 켜져 있어도 이 여섯 env 가 명시되지 않았으면 잠기지 않는다
        $this->enableEnvPriorityWith(['APP_NAME']);

        $this->bootUploadInjection();

        foreach (self::SAVED as $settingKey => $value) {
            $this->assertSame($value, config('core.thumbnail.'.substr($settingKey, strlen('thumbnail_'))), $settingKey);
        }
        $this->assertSame(20, ThumbnailGenerator::lockWaitSeconds());
    }

    /**
     * 저장값이 비어 있으면 config 파일이 읽은 값(`.env` 가 있으면 그 값, 없으면 기본값)을 씁니다.
     *
     * @scenario surface=server_limits
     *
     * @effects thumbnail_server_limits_blank_falls_back_to_server_default
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function blank_saved_value_falls_back_to_server_default(): void
    {
        $this->storeUpload(array_fill_keys(array_keys(self::SAVED), null));
        Config::set('core.thumbnail_server_defaults.lock_wait_seconds', 12);

        $this->bootUploadInjection();

        $this->assertSame(12, config('core.thumbnail.lock_wait_seconds'));
        $this->assertSame(120, config('core.thumbnail.generate_per_minute'));

        // 저장 경로도 같다 — 값을 지우고 저장하면 같은 요청 안에서 서버 기본값으로 돌아간다(낡은 저장값이 남지 않는다)
        $this->saveUpload(['thumbnail_lock_wait_seconds' => 25])->assertStatus(200);
        $this->assertSame(25, config('core.thumbnail.lock_wait_seconds'));

        $this->saveUpload(['thumbnail_lock_wait_seconds' => null])->assertStatus(200);
        $this->assertSame(12, config('core.thumbnail.lock_wait_seconds'));
        $this->assertNull((new JsonConfigRepository)->getCategory('upload')['thumbnail_lock_wait_seconds']);
    }

    /**
     * 화면에서 저장한 값은 정수로 저장되고, 조회 응답이 그 값과 칸 한계·서버 기본값 메타를 싣습니다.
     *
     * @scenario surface=server_limits
     *
     * @effects thumbnail_server_limits_saved_and_returned
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function saved_values_are_stored_as_integers_and_returned_with_meta(): void
    {
        $this->saveUpload(array_map('strval', self::SAVED))->assertStatus(200);

        $stored = (new JsonConfigRepository)->getCategory('upload');
        foreach (self::SAVED as $settingKey => $value) {
            $this->assertSame($value, $stored[$settingKey], $settingKey);
        }
        $this->assertSame(20, config('core.thumbnail.lock_wait_seconds'));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/settings')->assertStatus(200);

        foreach (self::SAVED as $settingKey => $value) {
            $this->assertSame($value, $response->json('data.upload.'.$settingKey), $settingKey);
        }
        $this->assertSame(10, $response->json('data._meta.thumbnail_server_defaults.lock_wait_seconds'));
        $this->assertSame(16000000, $response->json('data._meta.thumbnail_server_defaults.max_source_pixels'));
        $this->assertSame(1, $response->json('data._meta.limits.upload_thumbnail_lock_wait_seconds_min'));
        $this->assertSame(30, $response->json('data._meta.limits.upload_thumbnail_lock_wait_seconds_max'));
    }

    /**
     * `.env` 로 잠긴 키는 탭 저장이 버리고(200), 조회는 잠김 표시와 적용 중인 값을 내리며, 단건 저장과 명령은 거부합니다.
     *
     * @scenario surface=server_limits
     *
     * @effects thumbnail_server_limits_locked_key_not_saved
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function locked_key_is_dropped_from_save_and_shown_locked(): void
    {
        $this->enableEnvPriorityWith(['G7_THUMBNAIL_LOCK_WAIT_SECONDS']);
        Config::set('core.thumbnail.lock_wait_seconds', 7);
        Config::set('core.thumbnail_server_defaults.lock_wait_seconds', 7);

        $this->saveUpload([
            'thumbnail_lock_wait_seconds' => 25,
            'thumbnail_generate_per_minute' => 300,
        ])->assertStatus(200);

        $stored = (new JsonConfigRepository)->getCategory('upload');
        $this->assertNull($stored['thumbnail_lock_wait_seconds'] ?? null, '잠긴 키는 저장되지 않는다');
        $this->assertSame(300, $stored['thumbnail_generate_per_minute']);
        $this->assertSame(7, config('core.thumbnail.lock_wait_seconds'), '저장 직후 반영도 잠긴 키를 건드리지 않는다');
        $this->assertSame(300, config('core.thumbnail.generate_per_minute'));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/settings')->assertStatus(200);

        $this->assertTrue($response->json('data._meta.env_locked')['upload.thumbnail_lock_wait_seconds'] ?? false);
        $this->assertArrayNotHasKey('upload.thumbnail_generate_per_minute', $response->json('data._meta.env_locked'));
        $this->assertSame(7, $response->json('data.upload.thumbnail_lock_wait_seconds'));

        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->putJson('/api/admin/settings/upload.thumbnail_lock_wait_seconds', ['value' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['value']);

        $this->expectException(SettingsConsoleException::class);
        app(SettingsConsoleService::class)->set('upload.thumbnail_lock_wait_seconds', 5);
    }

    /**
     * 범위 밖·숫자가 아닌 값
     *
     * @return array<string, array{0: string, 1: mixed}> 설정 키와 값
     */
    public static function invalidValues(): array
    {
        return [
            'generate_per_minute 0' => ['thumbnail_generate_per_minute', 0],
            'generate_per_minute 10001' => ['thumbnail_generate_per_minute', 10001],
            'lock_wait_seconds 0' => ['thumbnail_lock_wait_seconds', 0],
            'lock_wait_seconds 31' => ['thumbnail_lock_wait_seconds', 31],
            'max_source_pixels 999999' => ['thumbnail_max_source_pixels', 999999],
            'max_source_pixels 100000001' => ['thumbnail_max_source_pixels', 100000001],
            'pregenerate_batch 0' => ['thumbnail_pregenerate_batch', 0],
            'pregenerate_batch 501' => ['thumbnail_pregenerate_batch', 501],
            'pregenerate_time_budget 0' => ['thumbnail_pregenerate_time_budget', 0],
            'pregenerate_time_budget 61' => ['thumbnail_pregenerate_time_budget', 61],
            'purge_time_budget 0' => ['thumbnail_purge_time_budget', 0],
            'purge_time_budget 61' => ['thumbnail_purge_time_budget', 61],
            'lock_wait_seconds 문자' => ['thumbnail_lock_wait_seconds', 'abc'],
            'lock_wait_seconds 소수' => ['thumbnail_lock_wait_seconds', 1.5],
        ];
    }

    /**
     * 범위 밖 값은 탭 저장·단건 저장 모두 422 이고 저장본이 바뀌지 않습니다.
     *
     * @scenario surface=server_limits
     *
     * @effects thumbnail_server_limits_out_of_range_rejected
     *
     * @param  string  $settingKey  설정 키
     * @param  mixed  $value  값
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('invalidValues')]
    public function out_of_range_value_is_rejected(string $settingKey, mixed $value): void
    {
        $before = (new JsonConfigRepository)->getCategory('upload')[$settingKey] ?? null;

        $this->saveUpload([$settingKey => $value])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['upload.'.$settingKey]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->putJson('/api/admin/settings/upload.'.$settingKey, ['value' => $value])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['value']);

        $this->assertSame($before, (new JsonConfigRepository)->getCategory('upload')[$settingKey] ?? null);
    }

    /**
     * 경계값(하한·상한)은 저장됩니다.
     *
     * @scenario surface=server_limits
     *
     * @effects thumbnail_server_limits_boundaries_accepted
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function boundary_values_are_accepted(): void
    {
        $min = ['thumbnail_generate_per_minute' => 1, 'thumbnail_lock_wait_seconds' => 1, 'thumbnail_max_source_pixels' => 1000000, 'thumbnail_pregenerate_batch' => 1, 'thumbnail_pregenerate_time_budget' => 1, 'thumbnail_purge_time_budget' => 1];
        $max = ['thumbnail_generate_per_minute' => 10000, 'thumbnail_lock_wait_seconds' => 30, 'thumbnail_max_source_pixels' => 100000000, 'thumbnail_pregenerate_batch' => 500, 'thumbnail_pregenerate_time_budget' => 60, 'thumbnail_purge_time_budget' => 60];

        foreach ([$min, $max] as $values) {
            $this->saveUpload($values)->assertStatus(200);
            $stored = (new JsonConfigRepository)->getCategory('upload');

            foreach ($values as $key => $value) {
                $this->assertSame($value, $stored[$key], $key);
            }
        }

        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->putJson('/api/admin/settings/upload.thumbnail_lock_wait_seconds', ['value' => 30])->assertStatus(200);

        $this->assertSame(30, ThumbnailConfig::fromUploadSettings(['thumbnail_lock_wait_seconds' => '30'])['core.thumbnail.lock_wait_seconds']);
    }
}
