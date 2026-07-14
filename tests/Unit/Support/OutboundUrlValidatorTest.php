<?php

namespace Tests\Unit\Support;

use App\Support\OutboundUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * outbound URL 검증 유틸 테스트.
 *
 * 접두사 매칭 우회(userinfo `@`, 접미사 확장)와 내부망 타격(사설/루프백/링크로컬 IP,
 * 내부 도메인)을 전수 매트릭스로 차단 검증한다.
 */
class OutboundUrlValidatorTest extends TestCase
{
    /** 본인인증 게이트웨이 화이트리스트 (실사용 값) */
    private const HOSTS = ['kssa.inicis.com', 'fcsa.inicis.com'];

    /**
     * 화이트리스트 host 와 완전 일치하는 URL 은 통과한다.
     *
     * @param  string  $url  검증 대상 URL
     */
    #[Test]
    #[DataProvider('allowedHostUrlProvider')]
    public function it_allows_urls_whose_host_exactly_matches_the_whitelist(string $url): void
    {
        $this->assertTrue(OutboundUrlValidator::isHostAllowed($url, self::HOSTS));
    }

    /**
     * 화이트리스트를 우회하려는 URL 은 모두 차단한다.
     *
     * @param  string  $url  검증 대상 URL
     * @param  string  $reason  차단 사유 (실패 메시지용)
     */
    #[Test]
    #[DataProvider('blockedHostUrlProvider')]
    public function it_blocks_urls_that_do_not_exactly_match_the_whitelist(string $url, string $reason): void
    {
        $this->assertFalse(
            OutboundUrlValidator::isHostAllowed($url, self::HOSTS),
            "차단되어야 하는 URL 이 통과함 ({$reason}): {$url}"
        );
    }

    /**
     * 공개 인터넷 URL 은 통과한다.
     *
     * @param  string  $url  검증 대상 URL
     */
    #[Test]
    #[DataProvider('publicUrlProvider')]
    public function it_allows_public_internet_urls(string $url): void
    {
        $this->assertTrue(
            OutboundUrlValidator::isPublicHttpUrl($url, ['schemes' => ['http', 'https']]),
            "공개 URL 이 차단됨: {$url}"
        );
    }

    /**
     * 내부망을 가리키는 URL 은 모두 차단한다.
     *
     * @param  string  $url  검증 대상 URL
     * @param  string  $reason  차단 사유 (실패 메시지용)
     */
    #[Test]
    #[DataProvider('internalUrlProvider')]
    public function it_blocks_urls_pointing_at_internal_addresses(string $url, string $reason): void
    {
        $this->assertFalse(
            OutboundUrlValidator::isPublicHttpUrl($url, ['schemes' => ['http', 'https']]),
            "차단되어야 하는 내부 URL 이 통과함 ({$reason}): {$url}"
        );
    }

    /**
     * scheme 기본값은 https 전용 — http 는 옵트인해야 통과한다.
     */
    #[Test]
    public function it_rejects_http_unless_explicitly_opted_in(): void
    {
        $this->assertFalse(OutboundUrlValidator::isPublicHttpUrl('http://example.com/api'));
        $this->assertTrue(OutboundUrlValidator::isPublicHttpUrl('http://example.com/api', ['schemes' => ['http', 'https']]));
    }

    /**
     * 화이트리스트 검증은 기본적으로 명시 포트를 거부한다.
     */
    #[Test]
    public function it_rejects_explicit_ports_for_whitelisted_hosts_by_default(): void
    {
        $this->assertFalse(OutboundUrlValidator::isHostAllowed('https://kssa.inicis.com:8080/auth', self::HOSTS));
        $this->assertTrue(OutboundUrlValidator::isHostAllowed('https://kssa.inicis.com:8080/auth', self::HOSTS, ['allowPort' => true]));
    }

    /**
     * host 단독 판정도 동일한 내부망 차단 규칙을 따른다.
     */
    #[Test]
    public function it_judges_bare_hosts_with_the_same_internal_rules(): void
    {
        $this->assertTrue(OutboundUrlValidator::isPublicHost('example.com'));
        $this->assertFalse(OutboundUrlValidator::isPublicHost('localhost'));
        $this->assertFalse(OutboundUrlValidator::isPublicHost('127.0.0.1'));
        $this->assertFalse(OutboundUrlValidator::isPublicHost('169.254.169.254'));
        $this->assertFalse(OutboundUrlValidator::isPublicHost(''));
    }

    /**
     * 화이트리스트 통과 URL 목록.
     *
     * @return array<string, array{string}>
     */
    public static function allowedHostUrlProvider(): array
    {
        return [
            '표준 인증 URL' => ['https://kssa.inicis.com/auth/result'],
            '두 번째 화이트리스트 host' => ['https://fcsa.inicis.com/auth/result'],
            '대소문자 혼용 host' => ['https://KSSA.Inicis.COM/auth/result'],
            '경로 없음' => ['https://kssa.inicis.com'],
            '쿼리스트링 포함' => ['https://kssa.inicis.com/auth?txId=abc'],
        ];
    }

    /**
     * 화이트리스트 차단 URL 목록 (우회 벡터 전수).
     *
     * @return array<string, array{string, string}>
     */
    public static function blockedHostUrlProvider(): array
    {
        return [
            'userinfo 로 내부 IP 위장' => ['https://kssa.inicis.com@127.0.0.1/', 'userinfo(@) 뒤가 실제 host'],
            'userinfo 로 메타데이터 위장' => ['https://kssa.inicis.com@169.254.169.254/latest/meta-data/', 'userinfo(@) 뒤가 실제 host'],
            'userinfo + 비밀번호' => ['https://kssa.inicis.com:pw@evil.example/', 'user:pass@host'],
            '접미사 확장 도메인' => ['https://kssa.inicis.com.attacker.com/', '화이트리스트가 접두사일 뿐 host 불일치'],
            '숫자 접미사 확장' => ['https://kssa.inicis.com.169.254.169.254.nip.io/', '접미사 확장'],
            '하이픈 접미사' => ['https://kssa.inicis.com-evil.example/', '접미사 확장'],
            '서브도메인 위장' => ['https://evil.example/kssa.inicis.com', '경로에만 포함'],
            'http scheme' => ['http://kssa.inicis.com/auth', 'https 아님'],
            'scheme 없음' => ['//kssa.inicis.com/auth', 'scheme 부재'],
            'host 없는 상대경로' => ['/auth/result', 'host 부재'],
            '빈 문자열' => ['', '입력 없음'],
            '공백' => ['   ', '입력 없음'],
            'file scheme' => ['file:///etc/passwd', '허용 scheme 아님'],
            'gopher scheme' => ['gopher://kssa.inicis.com/', '허용 scheme 아님'],
            'CRLF 주입' => ["https://kssa.inicis.com/auth\r\nX-Injected: 1", '제어문자 포함'],
            '완전 무관 host' => ['https://attacker.example/', '화이트리스트 불일치'],
        ];
    }

    /**
     * 공개 인터넷 URL 목록.
     *
     * @return array<string, array{string}>
     */
    public static function publicUrlProvider(): array
    {
        return [
            'https 공개 도메인' => ['https://example.com/api/shipping'],
            'http 공개 도메인' => ['http://api.example.co.kr/fee'],
            '비표준 포트' => ['https://api.example.com:8443/fee'],
            '공인 IP' => ['https://8.8.8.8/'],
            '서브도메인' => ['https://shipping.api.example.com/v1/quote'],
        ];
    }

    /**
     * 내부망 차단 URL 목록 (SSRF 표적 전수).
     *
     * @return array<string, array{string, string}>
     */
    public static function internalUrlProvider(): array
    {
        return [
            '클라우드 메타데이터' => ['http://169.254.169.254/latest/meta-data/', '링크로컬 메타데이터'],
            'GCP 메타데이터 호스트' => ['http://metadata.google.internal/computeMetadata/v1/', '.internal 내부 도메인'],
            '루프백 IPv4' => ['http://127.0.0.1:8080/admin', '루프백'],
            '루프백 변형' => ['http://127.1/', '루프백 대역'],
            '루프백 IPv6' => ['http://[::1]/', '루프백'],
            'localhost' => ['http://localhost:9200/_cat/indices', 'localhost'],
            '사설 10 대역' => ['http://10.0.0.5/internal', '사설 IP'],
            '사설 172.16 대역' => ['http://172.16.0.10/internal', '사설 IP'],
            '사설 192.168 대역' => ['http://192.168.1.1/router', '사설 IP'],
            '.local 내부 도메인' => ['http://printer.local/', '.local'],
            '.internal 내부 도메인' => ['http://vault.internal/v1/secret', '.internal'],
            '.lan 내부 도메인' => ['http://nas.lan/', '.lan'],
            '10진수 인코딩 IP' => ['http://2130706433/', '127.0.0.1 의 10진수 표기'],
            '16진수 인코딩 IP' => ['http://0x7f000001/', '127.0.0.1 의 16진수 표기'],
            'userinfo 위장' => ['https://example.com@127.0.0.1/', 'userinfo 뒤가 실제 host'],
            '0.0.0.0' => ['http://0.0.0.0:3000/', '예약 대역'],
            'CRLF 주입' => ["http://example.com/\r\nHost: internal", '제어문자 포함'],
            'file scheme' => ['file:///etc/passwd', '허용 scheme 아님'],
            'host 부재' => ['http:///etc/passwd', 'host 부재'],
        ];
    }
}
