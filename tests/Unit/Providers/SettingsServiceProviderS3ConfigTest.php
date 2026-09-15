<?php

namespace Tests\Unit\Providers;

use App\Providers\SettingsServiceProvider;
use App\Repositories\JsonConfigRepository;
use Illuminate\Support\Facades\Config;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * SettingsServiceProvider S3 설정 주입 테스트 (공개 #99 / 내부 #563)
 *
 * applyS3Config()의 endpoint/path-style 주입, s3_url→url 매핑,
 * applyDriverConfig()의 첨부 디스크 전환(A8), 죽은 주입 제거(a2·a3) 회귀를 검증합니다.
 */
class SettingsServiceProviderS3ConfigTest extends TestCase
{
    /**
     * applyS3Config를 리플렉션으로 호출합니다.
     *
     * @param  array  $driverSettings  drivers 카테고리 설정 데이터
     */
    private function callApplyS3Config(array $driverSettings): void
    {
        $provider = new SettingsServiceProvider($this->app);
        $method = new ReflectionMethod($provider, 'applyS3Config');
        $method->invoke($provider, $driverSettings);
    }

    /**
     * applyDriverConfig를 리플렉션으로 호출합니다 (drivers 카테고리 mock 주입).
     *
     * @param  array  $driverSettings  drivers 카테고리 설정 데이터
     */
    private function callApplyDriverConfig(array $driverSettings): void
    {
        $repository = Mockery::mock(JsonConfigRepository::class);
        $repository->shouldReceive('getCategory')->with('drivers')->andReturn($driverSettings);

        $provider = new SettingsServiceProvider($this->app);
        $method = new ReflectionMethod($provider, 'applyDriverConfig');
        $method->invoke($provider, $repository);
    }

    /**
     * s3_endpoint 가 filesystems.disks.s3.endpoint 로 주입되는지 테스트합니다.
     *
     * @scenario region_shape=aws_region,endpoint=custom_url,driver_usability=adapter_present,attachment_disk=local_default
     *
     * @effects endpoint_injected
     */
    public function test_s3_endpoint_injected_into_disk_config(): void
    {
        Config::set('filesystems.disks.s3.endpoint', null);

        $this->callApplyS3Config([
            's3_endpoint' => 'https://account.r2.cloudflarestorage.com',
        ]);

        $this->assertSame(
            'https://account.r2.cloudflarestorage.com',
            config('filesystems.disks.s3.endpoint'),
            's3_endpoint 는 SDK 요청 대상(endpoint)에 주입되어야 함 — 미주입 시 S3 호환 스토리지 연결 불가 (#99)'
        );
    }

    /**
     * s3_use_path_style 이 use_path_style_endpoint 로 주입되는지 테스트합니다.
     *
     * @scenario region_shape=aws_region,endpoint=path_style,driver_usability=adapter_present,attachment_disk=local_default
     *
     * @effects endpoint_injected
     */
    public function test_s3_use_path_style_injected_into_disk_config(): void
    {
        Config::set('filesystems.disks.s3.use_path_style_endpoint', false);

        $this->callApplyS3Config(['s3_use_path_style' => true]);

        $this->assertTrue((bool) config('filesystems.disks.s3.use_path_style_endpoint'));
    }

    /**
     * 빈 endpoint/path-style 은 주입되지 않는지 테스트합니다 (AWS 기본 경로 보존).
     */
    public function test_empty_s3_endpoint_and_path_style_not_injected(): void
    {
        Config::set('filesystems.disks.s3.endpoint', null);
        Config::set('filesystems.disks.s3.use_path_style_endpoint', false);

        $this->callApplyS3Config([
            's3_endpoint' => '',
            's3_use_path_style' => false,
            's3_bucket' => 'my-bucket',
        ]);

        $this->assertNull(config('filesystems.disks.s3.endpoint'));
        $this->assertFalse((bool) config('filesystems.disks.s3.use_path_style_endpoint'));
    }

    /**
     * s3_url 은 public URL(url)에만 매핑되고 endpoint 에는 매핑되지 않는지 테스트합니다.
     */
    public function test_s3_url_maps_to_public_url_not_endpoint(): void
    {
        Config::set('filesystems.disks.s3.url', null);
        Config::set('filesystems.disks.s3.endpoint', null);

        $this->callApplyS3Config(['s3_url' => 'https://cdn.example.com']);

        $this->assertSame('https://cdn.example.com', config('filesystems.disks.s3.url'));
        $this->assertNull(
            config('filesystems.disks.s3.endpoint'),
            's3_url 은 공개 URL base — SDK 요청 대상(endpoint)과 별개 축이어야 함'
        );
    }

    /**
     * storage_driver=s3 + ATTACHMENT_DISK 미명시 시 첨부 디스크가 s3 로 전환되는지 테스트합니다.
     *
     * @scenario region_shape=aws_region,endpoint=absent,driver_usability=adapter_present,attachment_disk=follow_s3
     *
     * @effects attachment_upload_follows_driver
     */
    public function test_attachment_disk_follows_s3_when_env_not_explicit(): void
    {
        Config::set('attachment.disk', 'attachments');
        Config::set('attachment.disk_explicit', null);

        $this->callApplyDriverConfig(['storage_driver' => 's3']);

        $this->assertSame('s3', config('attachment.disk'));
    }

    /**
     * ATTACHMENT_DISK 명시 시 env 가 항상 우선하는지 테스트합니다.
     *
     * @scenario region_shape=aws_region,endpoint=absent,driver_usability=adapter_present,attachment_disk=env_override
     *
     * @effects attachment_upload_follows_driver
     */
    public function test_attachment_disk_env_override_wins_over_s3(): void
    {
        Config::set('attachment.disk', 'custom-disk');
        Config::set('attachment.disk_explicit', 'custom-disk');

        $this->callApplyDriverConfig(['storage_driver' => 's3']);

        $this->assertSame('custom-disk', config('attachment.disk'));
    }

    /**
     * ATTACHMENT_DISK= 빈 값(키 존재·값 없음)은 "미명시" 로 간주되어야 합니다.
     *
     * `cp .env.example .env` 설치 절차에서 `ATTACHMENT_DISK=` 가 그대로 복사되면
     * env() 는 기본값 대신 빈 문자열을 돌려준다 — `=== null` 판별이면 s3 전환이
     * 영구 미발동하고, `attachment.disk` 도 '' 가 되어 첨부 업로드가 전면 실패한다.
     *
     * @scenario region_shape=aws_region,endpoint=absent,driver_usability=adapter_present,attachment_disk=follow_s3
     *
     * @effects attachment_upload_follows_driver
     */
    public function test_attachment_disk_follows_s3_when_env_is_blank_string(): void
    {
        Config::set('attachment.disk', 'attachments');
        Config::set('attachment.disk_explicit', '');

        $this->callApplyDriverConfig(['storage_driver' => 's3']);

        $this->assertSame(
            's3',
            config('attachment.disk'),
            'ATTACHMENT_DISK= 빈 값은 미명시와 동일해야 함 — 빈 문자열을 명시로 취급하면 신규 설치에서 전환이 절대 발동하지 않는다'
        );
    }

    /**
     * storage_driver=local 이면 첨부 디스크가 기본값을 유지하는지 테스트합니다.
     */
    public function test_attachment_disk_unchanged_when_storage_driver_local(): void
    {
        Config::set('attachment.disk', 'attachments');
        Config::set('attachment.disk_explicit', null);

        $this->callApplyDriverConfig(['storage_driver' => 'local']);

        $this->assertSame('attachments', config('attachment.disk'));
    }

    /**
     * A2 실효성 — REDIS_CLIENT=phpredis 활성 배포(.env.example 표준 설치) + 확장 부재
     * 조합에서 predis 폴백이 발동해야 합니다.
     *
     * 종전 게이트(env('REDIS_CLIENT') === null)는 표준 설치에서 env 값이 'phpredis' 라
     * 영구 미발동 — A2 가 막으려던 `Class "Redis" not found` 전면 다운이 그대로 남는다.
     * 확장 설치 머신에서는 부재 상태를 재현할 수 없으므로(A5 선례: mock 불가 시 구성
     * 검증으로 대체), 판정 술어를 확장 로드 여부 인자로 분리해 검증한다.
     */
    public function test_predis_fallback_fires_for_phpredis_client_without_extension(): void
    {
        $provider = new SettingsServiceProvider($this->app);
        $method = new ReflectionMethod($provider, 'shouldFallBackToPredis');

        // 표준 설치 상태: config 클라이언트 phpredis + 확장 부재 → 폴백해야 함
        Config::set('database.redis.client', 'phpredis');
        $this->assertTrue(
            $method->invoke($provider, false),
            'REDIS_CLIENT=phpredis 활성 배포 + 확장 부재 = 표준 설치 시나리오 — 폴백 미발동이면 redis 저장 시 전면 다운 (#99 A2)'
        );

        // 확장이 있으면 phpredis 경로 유지
        $this->assertFalse($method->invoke($provider, true));

        // predis 명시 설정이면 이미 동작 경로 — 개입하지 않음
        Config::set('database.redis.client', 'predis');
        $this->assertFalse($method->invoke($provider, false));
    }

    /**
     * applyRedisConfig 통합 — 폴백 판정이 env() 가 아닌 config 값을 기준으로 해야 합니다.
     *
     * env() 직접 호출은 config:cache 환경에서 null 로 고정된다 (A8 disk_explicit 와 동형).
     * 이 머신은 확장 설치 여부에 따라 결과가 갈리므로 분기 단언한다.
     */
    public function test_apply_redis_config_fallback_uses_config_not_env(): void
    {
        Config::set('database.redis.client', 'phpredis');

        $provider = new SettingsServiceProvider($this->app);
        $method = new ReflectionMethod($provider, 'applyRedisConfig');
        $method->invoke($provider, []);

        if (extension_loaded('redis')) {
            $this->assertSame('phpredis', config('database.redis.client'), '확장이 있으면 phpredis 유지');
        } else {
            $this->assertSame('predis', config('database.redis.client'), '확장 부재 시 predis 폴백 (표준 설치 REDIS_CLIENT=phpredis 포함)');
        }
    }

    /**
     * upload.disk 죽은 주입 제거 회귀 — upload 카테고리의 disk 값이
     * filesystems.default 를 더 이상 덮어쓰지 않아야 합니다 (a2).
     */
    public function test_upload_disk_no_longer_overrides_filesystems_default(): void
    {
        Config::set('filesystems.default', 'local');

        $repository = Mockery::mock(JsonConfigRepository::class);
        $repository->shouldReceive('getCategory')->with('upload')->andReturn([
            'disk' => 'rogue-disk',
            'max_file_size' => 10,
        ]);

        $provider = new SettingsServiceProvider($this->app);
        $method = new ReflectionMethod($provider, 'applyUploadConfig');
        $method->invoke($provider, $repository);

        $this->assertSame(
            'local',
            config('filesystems.default'),
            'upload.disk 는 저장 rule 없는 유령 키 — filesystems.default 를 storage_driver 와 경쟁 기록하면 안 됨'
        );
    }

    /**
     * cache.driver/prefix 죽은 주입 제거 회귀 — applyCacheConfig 메서드 자체가 제거되어야 합니다 (a3).
     */
    public function test_apply_cache_config_dead_injection_removed(): void
    {
        $this->assertFalse(
            method_exists(SettingsServiceProvider::class, 'applyCacheConfig'),
            'cache.driver/prefix 는 저장 경로 없는 죽은 주입 — drivers.cache_driver(cache.default SSoT)와 경쟁하므로 제거 유지'
        );
    }
}
