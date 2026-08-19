<?php

namespace Tests\Unit\Services;

use App\Services\DriverRegistryService;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Predis\Client;
use Tests\TestCase;

/**
 * DriverRegistryService 드라이버 가용성 판정 테스트 (공개 #99 / 내부 #563, A7)
 *
 * isDriverUsable()/usabilityFailureReason() 의 런타임 능력 판정과
 * a5(CONFIG_KEYS 로그 키 정정)·a6(websocket 설정 키 제외) 회귀를 검증합니다.
 */
class DriverRegistryServiceUsabilityTest extends TestCase
{
    private DriverRegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DriverRegistryService;
    }

    /**
     * s3 드라이버 가용성이 Flysystem 어댑터 존재와 일치하는지 테스트합니다.
     *
     * 어댑터는 composer require 로 코어에 포함되므로(#99 A1) 항상 true 여야 합니다.
     *
     * @scenario region_shape=aws_region,endpoint=absent,driver_usability=adapter_present,attachment_disk=local_default
     *
     * @effects s3_disk_resolves
     */
    public function test_s3_usability_matches_adapter_presence(): void
    {
        $expected = class_exists(AwsS3V3Adapter::class);

        $this->assertSame($expected, $this->service->isDriverUsable('storage', 's3'));
        $this->assertTrue(
            $expected,
            'league/flysystem-aws-s3-v3 는 코어 필수 의존성 — 부재 시 S3 선택이 즉사한다 (#99 A1)'
        );
        $this->assertNull($this->service->usabilityFailureReason('storage', 's3'));
    }

    /**
     * redis 드라이버 가용성이 phpredis 확장 또는 predis 존재와 일치하는지 테스트합니다.
     *
     * predis 는 composer require 로 코어에 포함되므로(#99 A2) 항상 true 여야 합니다.
     */
    public function test_redis_usability_matches_client_presence(): void
    {
        $expected = extension_loaded('redis') || class_exists(Client::class);

        $this->assertSame($expected, $this->service->isDriverUsable('cache', 'redis'));
        $this->assertTrue(
            $expected,
            'predis/predis 는 코어 필수 의존성 — 확장 없는 서버에서 redis 선택 시 전면 다운 방어 (#99 A2)'
        );

        // predis 명시 설정 분기 — 판정은 설정 클라이언트 기준 (predis 는 코어 의존성이라 참)
        config(['database.redis.client' => 'predis']);
        $this->assertTrue($this->service->isDriverUsable('cache', 'redis'));
    }

    /**
     * memcached 드라이버 가용성이 PHP 확장 존재와 일치하는지 테스트합니다.
     *
     * @scenario region_shape=aws_region,endpoint=absent,driver_usability=adapter_absent,attachment_disk=local_default
     *
     * @effects unusable_driver_rejected
     */
    public function test_memcached_usability_matches_extension(): void
    {
        $expected = extension_loaded('memcached');

        $this->assertSame($expected, $this->service->isDriverUsable('cache', 'memcached'));

        if (! $expected) {
            $this->assertNotNull(
                $this->service->usabilityFailureReason('cache', 'memcached'),
                '사용 불능 드라이버는 사유 문자열을 제공해야 함 (422 메시지 파라미터)'
            );
        }
    }

    /**
     * 능력 요구가 없는 드라이버(local/file 등)와 미지정 드라이버는 항상 사용 가능해야 합니다.
     */
    public function test_unknown_and_capability_free_drivers_are_usable(): void
    {
        $this->assertTrue($this->service->isDriverUsable('storage', 'local'));
        $this->assertTrue($this->service->isDriverUsable('cache', 'file'));
        $this->assertTrue($this->service->isDriverUsable('queue', 'database'));
        // 플러그인 등록 드라이버 — 가용성은 해당 플러그인 SP 가 보증하므로 코어는 통과
        $this->assertTrue($this->service->isDriverUsable('storage', 'plugin-custom-driver'));
    }

    /**
     * a5 회귀 — log 카테고리 Config 키가 실제 적용 키(stack.channels)와 일치해야 합니다.
     */
    public function test_log_config_key_matches_actual_applied_key(): void
    {
        $this->assertSame(
            'logging.channels.stack.channels',
            $this->service->getConfigKey('log'),
            'logging.default 는 어디에서도 읽지 않는 죽은 키 — applyLogConfig 실제 기록 키와 일치해야 폴백이 실효한다 (a5)'
        );
    }

    /**
     * log 카테고리의 Config 기록 값은 채널 배열 형태여야 합니다.
     */
    public function test_log_config_value_is_channel_array(): void
    {
        $this->assertSame(['daily'], $this->service->getConfigValueForDriver('log', 'daily'));
        $this->assertSame('file', $this->service->getConfigValueForDriver('cache', 'file'));
    }

    /**
     * a6 회귀 — websocket 은 드라이버 ID 선택 설정이 없으므로 설정 키 매핑에서 제외되어야 합니다.
     */
    public function test_websocket_settings_key_excluded(): void
    {
        $this->assertNull(
            $this->service->getSettingsKey('websocket'),
            "websocket 은 불리언 websocket_enabled 뿐 — 종전 'websocket_driver' 는 어떤 저장 경로에도 없는 유령 키였다 (a6)"
        );
    }
}
