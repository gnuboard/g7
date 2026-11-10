<?php

namespace Tests\Unit\Services;

use App\Services\DriverConnectionTester;
use Tests\TestCase;

/**
 * 드라이버 연결 테스트의 Redis 인증 자격 결정
 *
 * 사이트별 Redis ACL 사용자(`REDIS_USERNAME`)를 쓰는 서버에서 관리자 「환경설정 → 드라이버」 연결 테스트가
 * 비밀번호만으로 AUTH 해 항상 `WRONGPASS` 로 실패했다(사이트 실행은 config 의 사용자 이름을 써서 정상).
 * 비밀번호 칸을 비우면 비밀번호 없이 시험하던 것도 같은 결함군이다.
 *
 * 규칙: 시험 대상 host·port 가 지금 설정된 연결과 같을 때만 설정의 사용자 이름을 쓰고 빈 비밀번호를 설정값으로 채운다.
 * 다른 서버를 시험하면 입력값만 쓴다.
 *
 * @scenario-manifest tests/scenarios/redis-connection-test-acl.yaml
 */
class DriverConnectionTesterRedisAuthTest extends TestCase
{
    private DriverConnectionTester $tester;

    /**
     * 설정된 Redis 연결(127.0.0.1:6379, 사용자 site1)을 깐다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tester = new DriverConnectionTester;
        config([
            'database.redis.default.host' => '127.0.0.1',
            'database.redis.default.port' => 6379,
            'database.redis.default.username' => 'site1',
            'database.redis.default.password' => 'stored-secret',
        ]);
    }

    /**
     * 설정된 연결을 비밀번호 칸을 비운 채 시험하면 설정의 사용자 이름·비밀번호로 인증한다.
     *
     * @scenario endpoint=same_as_config, submitted_password=blank, configured_username=present
     *
     * @effects same_endpoint_blank_password_uses_configured_username_and_password
     *
     * @return void 반환값 없음
     */
    public function test_same_endpoint_with_blank_password_uses_configured_username_and_password(): void
    {
        $auth = $this->tester->resolveRedisAuth(['redis_host' => '127.0.0.1', 'redis_port' => 6379, 'redis_password' => '']);

        $this->assertSame(['site1', 'stored-secret'], $auth);
    }

    /**
     * 설정된 연결에 비밀번호를 입력하면 입력값을 쓰되 사용자 이름은 설정에서 붙인다.
     *
     * @scenario endpoint=same_as_config, submitted_password=typed, configured_username=present
     *
     * @effects same_endpoint_typed_password_keeps_typed_and_adds_username
     *
     * @return void 반환값 없음
     */
    public function test_same_endpoint_with_typed_password_keeps_typed_password_and_adds_username(): void
    {
        $auth = $this->tester->resolveRedisAuth(['redis_host' => '127.0.0.1', 'redis_port' => '6379', 'redis_password' => 'typed']);

        $this->assertSame(['site1', 'typed'], $auth);
    }

    /**
     * 다른 서버를 비밀번호 칸을 비운 채 시험하면 설정 자격을 보내지 않고 인증 없이 시험한다.
     *
     * @scenario endpoint=other, submitted_password=blank, configured_username=present
     *
     * @effects other_endpoint_uses_only_submitted_values
     *
     * @return void 반환값 없음
     */
    public function test_other_endpoint_with_blank_password_sends_no_credentials(): void
    {
        $this->assertNull($this->tester->resolveRedisAuth(['redis_host' => '10.0.0.5', 'redis_port' => 6379, 'redis_password' => '']));
    }

    /**
     * 다른 서버에 비밀번호를 입력하면 사용자 이름 없이 입력한 비밀번호만 쓴다.
     *
     * @scenario endpoint=other, submitted_password=typed, configured_username=present
     *
     * @effects other_endpoint_uses_only_submitted_values
     *
     * @return void 반환값 없음
     */
    public function test_other_endpoint_with_typed_password_uses_only_typed_password(): void
    {
        $this->assertSame('typed', $this->tester->resolveRedisAuth(['redis_host' => '10.0.0.5', 'redis_port' => 6379, 'redis_password' => 'typed']));
    }

    /**
     * 설정에 사용자 이름이 없으면 비밀번호만으로 인증한다(종전 동작).
     *
     * @scenario endpoint=same_as_config, submitted_password=blank, configured_username=absent
     *
     * @effects no_username_falls_back_to_password_only
     *
     * @return void 반환값 없음
     */
    public function test_without_configured_username_falls_back_to_password_only(): void
    {
        config(['database.redis.default.username' => null]);

        $this->assertSame('stored-secret', $this->tester->resolveRedisAuth(['redis_host' => '127.0.0.1', 'redis_port' => 6379]));
    }

    /**
     * 입력·설정 어디에도 비밀번호가 없으면 인증 없이 시험한다.
     *
     * @scenario endpoint=same_as_config, submitted_password=blank, configured_username=absent
     *
     * @effects no_credentials_means_no_auth
     *
     * @return void 반환값 없음
     */
    public function test_no_credentials_anywhere_means_no_auth(): void
    {
        config(['database.redis.default.username' => null, 'database.redis.default.password' => null]);

        $this->assertNull($this->tester->resolveRedisAuth(['redis_host' => '127.0.0.1', 'redis_port' => 6379, 'redis_password' => '']));
    }
}
