<?php

namespace Tests\Unit\Rules;

use App\Rules\ConnectionHost;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 서버 연결 대상의 호스트 칸 형식 — 호스트명 또는 IP 만 받는다.
 *
 * 호스트 칸에 경로·쿼리·계정 정보가 섞이면 서버가 그 값을 붙여 만든 주소가 운영자가 본 것과 다른 곳을
 * 가리킨다(예: `169.254.169.254/latest/meta-data/?` 뒤에 포트가 붙어 쿼리로 밀려난다). 정상 구성에서
 * 나오는 값(localhost·사내 IP·도메인, 캐시 서버의 소켓 경로·TLS 접두)은 그대로 받는다.
 *
 * @scenario-manifest tests/scenarios/outbound-connection-target.yaml
 */
class ConnectionHostTest extends TestCase
{
    /**
     * 규칙을 돌려 실패 문구를 모읍니다.
     *
     * @param  mixed  $value  검사할 값
     * @param  bool  $allowSocketAndTls  소켓 경로·TLS 접두 허용 여부
     * @return array<int, string> 실패 문구 (통과면 빈 배열)
     */
    private function failures(mixed $value, bool $allowSocketAndTls = false): array
    {
        $messages = [];
        (new ConnectionHost($allowSocketAndTls))->validate('host', $value, function (string $message) use (&$messages) {
            $messages[] = $message;
        });

        return $messages;
    }

    /**
     * 정상 구성의 호스트는 통과한다.
     *
     * @param  string  $host  호스트
     * @return void 반환값 없음
     *
     * @scenario case=private_or_public_address
     *
     * @effects private_and_public_hosts_stay_allowed
     */
    #[DataProvider('legitimateHosts')]
    public function test_legitimate_hosts_pass(string $host): void
    {
        $this->assertSame([], $this->failures($host), $host);
        $this->assertSame([], $this->failures($host, true), $host);
    }

    /**
     * 경로·쿼리·계정 정보·공백이 섞인 값과 숫자로 감춘 IP 는 형식 오류다.
     *
     * @param  string  $host  호스트
     * @return void 반환값 없음
     *
     * @scenario case=path_injection
     *
     * @effects host_field_rejects_path_query_userinfo
     */
    #[DataProvider('malformedHosts')]
    public function test_malformed_hosts_fail_with_the_format_message(string $host): void
    {
        $this->assertSame([__('validation.settings.host_format_invalid')], $this->failures($host), $host);
        $this->assertSame([__('validation.settings.host_format_invalid')], $this->failures($host, true), $host);
    }

    /**
     * 메타데이터·링크로컬 주소는 「허용되지 않는 주소」 로 막는다.
     *
     * @param  string  $host  호스트
     * @return void 반환값 없음
     *
     * @scenario case=metadata_address
     *
     * @effects metadata_and_link_local_hosts_detected
     */
    #[DataProvider('blockedHosts')]
    public function test_metadata_hosts_fail_with_the_blocked_message(string $host): void
    {
        $this->assertSame([__('validation.settings.host_blocked')], $this->failures($host), $host);
        $this->assertSame([__('validation.settings.host_blocked')], $this->failures($host, true), $host);
    }

    /**
     * 소켓 절대 경로와 TLS 접두는 캐시 서버 칸에서만 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=socket_or_tls_prefix
     *
     * @effects socket_path_and_tls_prefix_allowed_only_for_cache_hosts
     */
    public function test_socket_path_and_tls_prefix_are_allowed_only_in_that_mode(): void
    {
        foreach (['/var/run/redis/redis.sock', '/tmp/memcached.sock', 'tls://redis.local', 'rediss://cache.example.com', 'TLS://10.0.0.5'] as $value) {
            $this->assertSame([], $this->failures($value, true), $value);
            $this->assertSame([__('validation.settings.host_format_invalid')], $this->failures($value), $value);
        }

        // 그 모드에서도 다른 경로·쿼리·다른 접두는 받지 않는다.
        foreach (['redis.local/path', 'tls://redis.local/0', 'tls://redis.local?x=1', '/var/run/../etc/passwd', '/var/run/a b.sock', 'http://redis.local', 'tls://'] as $value) {
            $this->assertSame([__('validation.settings.host_format_invalid')], $this->failures($value, true), $value);
        }

        // TLS 접두 뒤의 메타데이터 주소도 막는다.
        $this->assertSame([__('validation.settings.host_blocked')], $this->failures('tls://169.254.169.254', true));

        // 점으로 시작하는 폴더(숨김 폴더)가 낀 소켓 경로도 정상 경로다. 상위·현재 폴더 표기는 받지 않는다.
        foreach (['/home/app/.redis/redis.sock', '/run/.sockets/memcached.sock'] as $value) {
            $this->assertSame([], $this->failures($value, true), $value);
        }
        foreach (['/var/run/./redis.sock', '/var/run/.../redis.sock', '/var/run/redis/..', '/var/run/redis/.'] as $value) {
            $this->assertSame([__('validation.settings.host_format_invalid')], $this->failures($value, true), $value);
        }
    }

    /**
     * 대괄호 없는 IPv6 주소는 캐시 서버 칸에서만 받는다 — 그 칸의 연결 클라이언트가 쓰는 표기다.
     *
     * 다른 칸(웹소켓·메일)에서는 포트 구분과 섞이므로 형식 오류다. 캐시 서버 칸에서도 링크로컬·메타데이터
     * 대역은 막는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=socket_or_tls_prefix
     *
     * @effects socket_path_and_tls_prefix_allowed_only_for_cache_hosts
     */
    public function test_bare_ipv6_is_allowed_only_for_cache_hosts(): void
    {
        foreach (['::1', 'fd12:3456:789a::1', '2001:db8::10', 'tls://fd12::5'] as $value) {
            $this->assertSame([], $this->failures($value, true), $value);
        }

        foreach (['::1', 'fd12:3456:789a::1', 'fe80::1'] as $value) {
            $this->assertSame([__('validation.settings.host_format_invalid')], $this->failures($value), $value);
        }

        foreach (['fe80::1', 'fd00:ec2::254', '::ffff:169.254.169.254', '::ffff:a9fe:a9fe'] as $value) {
            $this->assertSame([__('validation.settings.host_blocked')], $this->failures($value, true), $value);
        }

        // 포트가 붙은 값·영역 표기·IPv6 가 아닌 값은 그 칸에서도 형식 오류다.
        foreach (['redis.local:6379', '::1:6379x', 'fe80::1%eth0', '1::2::3'] as $value) {
            $this->assertSame([__('validation.settings.host_format_invalid')], $this->failures($value, true), $value);
        }
    }

    /**
     * 빈 값은 이 규칙이 판단하지 않고(필수 여부는 다른 규칙), 문자열이 아니면 형식 오류다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=path_injection
     *
     * @effects host_field_rejects_path_query_userinfo
     */
    public function test_empty_passes_and_non_string_fails(): void
    {
        $this->assertSame([], $this->failures(null));
        $this->assertSame([], $this->failures(''));
        $this->assertSame([__('validation.settings.host_format_invalid')], $this->failures(['a']));
        $this->assertSame([__('validation.settings.host_format_invalid')], $this->failures(123));
    }

    /**
     * 정상 호스트 목록.
     *
     * @return array<string, array{string}> 호스트 목록
     */
    public static function legitimateHosts(): array
    {
        return [
            '기본값 localhost' => ['localhost'],
            '루프백 IP' => ['127.0.0.1'],
            '사내 사설 IP' => ['192.168.0.10'],
            '공개 도메인' => ['ws.example.com'],
            '한 단어 서비스 이름' => ['redis'],
            '밑줄이 든 서비스 이름' => ['redis_cache'],
            '대괄호 IPv6' => ['[::1]'],
            '대괄호 IPv6 사설' => ['[fd12:3456:789a::1]'],
            '후행 점 도메인' => ['ws.example.com.'],
            '대문자 도메인' => ['WS.Example.COM'],
            '메일 서버' => ['smtp.gmail.com'],
            'Mailgun 기본값' => ['api.mailgun.net'],
            '0x 로 시작하는 도메인' => ['0xcafe.example.com'],
        ];
    }

    /**
     * 형식 오류 목록.
     *
     * @return array<string, array{string}> 호스트 목록
     */
    public static function malformedHosts(): array
    {
        return [
            '경로·쿼리 주입' => ['169.254.169.254/latest/meta-data/iam/?'],
            '경로' => ['smtp.example.com/x'],
            '쿼리' => ['example.com?x=1'],
            '프래그먼트' => ['example.com#x'],
            '계정 정보' => ['user@host'],
            '계정 정보 위장' => ['example.com@169.254.169.254'],
            '역슬래시' => ['example.com\\evil'],
            '가운데 공백' => ['exam ple.com'],
            '제어문자' => ["example.com\r\nX-Injected: 1"],
            '포트가 붙은 값' => ['example.com:8080'],
            '스킴이 붙은 값' => ['http://example.com'],
            '10진수로 감춘 IP' => ['2852039166'],
            '16진수로 감춘 IP' => ['0xA9FEA9FE'],
            '점으로 나눈 16진수 IP' => ['0xa9.0xfe.0xa9.0xfe'],
            '후행 점으로 감춘 메타데이터 IP' => ['169.254.169.254.'],
            '대괄호 안이 IPv6 가 아님' => ['[example.com]'],
            '퍼센트 인코딩' => ['example.com%2Fx'],
        ];
    }

    /**
     * 차단 주소 목록.
     *
     * @return array<string, array{string}> 호스트 목록
     */
    public static function blockedHosts(): array
    {
        return [
            '메타데이터 IP' => ['169.254.169.254'],
            '알리바바 메타데이터' => ['100.100.100.200'],
            'IPv6 링크로컬' => ['[fe80::1]'],
            'IPv4-mapped IPv6' => ['[::ffff:169.254.169.254]'],
            'GCP 메타데이터 이름' => ['metadata.google.internal'],
            'GCP 메타데이터 이름(후행 점)' => ['metadata.google.internal.'],
        ];
    }
}
