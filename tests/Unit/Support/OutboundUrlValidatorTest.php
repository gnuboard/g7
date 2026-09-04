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
     * 점(.) 동등 유니코드 문자로 위장한 내부 주소를 차단한다.
     *
     * 검증기가 ASCII 점만 구분자로 인식하면 `localhost。` 는 단일 라벨로 보여 공개
     * 도메인으로 승인되지만, libcurl/libidn2 는 UTS#46 정규화로 `localhost` 에 연결한다.
     * 검증 시점과 연결 시점의 호스트가 달라지는 이 간극이 SSRF 통로다.
     *
     * @param  string  $url  검증 대상 URL
     * @param  string  $reason  차단 사유 (실패 메시지용)
     */
    #[Test]
    #[DataProvider('idnInternalUrlProvider')]
    public function it_blocks_unicode_dot_variants_that_resolve_to_internal_hosts(string $url, string $reason): void
    {
        $this->assertFalse(
            OutboundUrlValidator::isPublicHttpUrl($url, ['schemes' => ['http', 'https']]),
            "차단되어야 하는 URL 이 통과함 ({$reason}): {$url}"
        );
    }

    /**
     * 정규화가 정당한 국제화 도메인(IDN)까지 막지는 않는다.
     *
     * @param  string  $url  검증 대상 URL
     */
    #[Test]
    #[DataProvider('legitimateIdnUrlProvider')]
    public function it_still_allows_legitimate_internationalized_domains(string $url): void
    {
        $this->assertTrue(
            OutboundUrlValidator::isPublicHttpUrl($url, ['schemes' => ['http', 'https']]),
            "정당한 IDN URL 이 차단됨: {$url}"
        );
    }

    /**
     * 화이트리스트 비교도 정규화된 host 로 수행한다.
     *
     * 유니코드 점으로 화이트리스트 host 뒤에 라벨을 덧붙이는 우회(`kssa.inicis.com。evil.example`)
     * 는 차단하고, 정규화 후 완전 일치하는 표기는 허용한다.
     */
    #[Test]
    public function it_normalizes_hosts_before_whitelist_comparison(): void
    {
        $this->assertFalse(
            OutboundUrlValidator::isHostAllowed("https://kssa.inicis.com\u{3002}attacker.example/", self::HOSTS),
            '유니코드 점으로 라벨을 덧붙인 host 가 통과함'
        );

        $this->assertTrue(
            OutboundUrlValidator::isHostAllowed("https://kssa.inicis.com\u{3002}", self::HOSTS),
            '정규화 후 화이트리스트와 일치하는 host 가 차단됨'
        );
    }

    /**
     * `normalizeHost()` 는 정규화된 A-label host 를 돌려주고, 변환 불가 입력은 null 을 돌려준다.
     *
     * 이 메서드가 정규화의 SSoT 다 — 검증기 밖 소비자(PG 콜백 URL 접미사 대조 등)가
     * 같은 규칙을 재사용해야 판정이 갈리지 않는다.
     */
    #[Test]
    public function it_exposes_host_normalization_as_a_reusable_primitive(): void
    {
        $this->assertSame('localhost', OutboundUrlValidator::normalizeHost("localhost\u{3002}"));
        $this->assertSame('localhost', OutboundUrlValidator::normalizeHost("LOCALHOST\u{FF0E}"));
        $this->assertSame('127.0.0.1', OutboundUrlValidator::normalizeHost("127\u{3002}0\u{3002}0\u{3002}1"));
        $this->assertSame('example.com', OutboundUrlValidator::normalizeHost('example.com.'));
        $this->assertSame('xn--r8jz45g.jp', OutboundUrlValidator::normalizeHost("\u{4F8B}\u{3048}.jp"));
        $this->assertSame('api.nicepay.co.kr', OutboundUrlValidator::normalizeHost('API.NicePay.co.kr'));
        $this->assertNull(OutboundUrlValidator::normalizeHost(''));
        $this->assertNull(OutboundUrlValidator::normalizeHost('   '));

        // 정규화 결과가 host 로 성립하지 않으면(구분자 잔존) 판정 불가 → null.
        $this->assertNull(OutboundUrlValidator::normalizeHost("127.0.0.1\u{FF0F}.example.com"));
        $this->assertNull(OutboundUrlValidator::normalizeHost('evil.com/.example.com'));
        $this->assertNull(OutboundUrlValidator::normalizeHost('ex ample.com'));
        $this->assertNull(OutboundUrlValidator::normalizeHost('ex@mple.com'));
        $this->assertNull(OutboundUrlValidator::normalizeHost('a%2e.example.com'));
    }

    /**
     * 화이트리스트 접미사 대조도 정규화된 host 로만 성립한다.
     *
     * 결제 게이트웨이 URL 을 도메인 접미사로 대조하는 소비자가 원문 host 를 그대로 쓰면
     * `evil.example／.gateway.example` 이 접미사 검사를 통과하는데, 연결 계층은
     * `evil.example` 에 접속한다. 정규화가 이 간극을 없앤다.
     */
    #[Test]
    public function it_rejects_hosts_whose_normalized_form_hides_a_different_target(): void
    {
        $host = parse_url("https://evil.example\u{FF0F}.gateway.example/pay", PHP_URL_HOST);

        // 원문은 접미사 대조를 통과한다 — 이것이 정규화 없는 대조가 위험한 이유다.
        $this->assertTrue(str_ends_with((string) $host, '.gateway.example'));

        // 정규화하면 판정 불가로 거부되어 그 통로가 닫힌다.
        $this->assertNull(OutboundUrlValidator::normalizeHost((string) $host));
    }

    /**
     * 유니코드 점 변종으로 내부 주소를 가리키는 URL 목록.
     *
     * @return array<string, array{string, string}>
     */
    public static function idnInternalUrlProvider(): array
    {
        return [
            'U+3002 표의문자 마침표 localhost' => ["http://localhost\u{3002}/", 'IDNA 정규화 시 localhost'],
            'U+FF0E 전각 마침표 localhost' => ["http://localhost\u{FF0E}/", 'IDNA 정규화 시 localhost'],
            'U+FF61 반각 표의문자 마침표 localhost' => ["http://localhost\u{FF61}/", 'IDNA 정규화 시 localhost'],
            '대문자 혼용 U+3002' => ["http://LOCALHOST\u{3002}/", '대소문자 무관 localhost'],
            '포트 포함 U+3002' => ["http://localhost\u{3002}:9200/_cat/indices", 'IDNA 정규화 시 localhost'],
            'U+3002 로 표기한 루프백 IP' => ["http://127\u{3002}0\u{3002}0\u{3002}1/", 'IDNA 정규화 시 127.0.0.1'],
            'U+FF0E 로 표기한 메타데이터 IP' => ["http://169\u{FF0E}254\u{FF0E}169\u{FF0E}254/latest/meta-data/", '링크로컬 메타데이터'],
            'U+3002 로 표기한 사설 IP' => ["http://192\u{3002}168\u{3002}1\u{3002}1/router", '사설 IP'],
            'U+3002 내부 도메인 접미사' => ["http://vault\u{3002}internal/v1/secret", '.internal 내부 도메인'],
            'U+FF0E .local 접미사' => ["http://printer\u{FF0E}local/", '.local 내부 도메인'],
            '후행 점 localhost' => ['http://localhost./', '완전한 DNS 이름 표기의 localhost'],
            '후행 유니코드 점 localhost' => ["http://localhost\u{3002}\u{3002}/", '연속 점 표기의 localhost'],

            // U+FF0F FULLWIDTH SOLIDUS 는 UTS#46 에서 ASCII `/` 로 매핑된다.
            // parse_url 은 이를 경로 구분자로 보지 않아 host 에 통째로 남기지만,
            // 연결 계층은 정규화 후 첫 `/` 앞까지만 host 로 읽는다 — 접미사 대조를
            // 통과시키면서 실제로는 앞쪽 주소에 연결하는 우회 통로다.
            '전각 슬래시로 감춘 루프백' => ["http://127.0.0.1\u{FF0F}.example.com/", '정규화 시 host 는 127.0.0.1'],
            '전각 슬래시로 감춘 localhost' => ["http://localhost\u{FF0F}.example.com/", '정규화 시 host 는 localhost'],
            '전각 슬래시로 감춘 메타데이터' => ["http://169.254.169.254\u{FF0F}x.example.com/", '정규화 시 host 는 169.254.169.254'],
            '전각 슬래시로 감춘 사설 IP' => ["http://10.0.0.5\u{FF0F}api.example.com/", '정규화 시 host 는 10.0.0.5'],
            '전각 역슬래시로 감춘 루프백' => ["http://127.0.0.1\u{FF3C}.example.com/", '정규화 시 구분자가 앞선다'],
            '전각 물음표로 감춘 루프백' => ["http://127.0.0.1\u{FF1F}.example.com/", '정규화 시 쿼리 구분자가 앞선다'],
            '전각 콜론으로 감춘 루프백' => ["http://127.0.0.1\u{FF1A}80.example.com/", '정규화 시 포트 구분자가 앞선다'],
            '전각 골뱅이로 감춘 userinfo' => ["http://example.com\u{FF20}127.0.0.1/", '정규화 시 userinfo 구분자가 앞선다'],
        ];
    }

    /**
     * 정규화 후에도 통과해야 하는 정당한 국제화 도메인 목록.
     *
     * @return array<string, array{string}>
     */
    public static function legitimateIdnUrlProvider(): array
    {
        return [
            '한글 도메인' => ["https://\u{D55C}\u{AE00}.\u{D55C}\u{AD6D}/"],
            '일본어 도메인' => ["https://\u{4F8B}\u{3048}.jp/"],
            '이미 punycode 인 도메인' => ['https://xn--r8jz45g.jp/'],
            '유니코드 점을 쓴 정당한 공개 도메인' => ["https://api\u{3002}example\u{3002}com/v1/quote"],
            '후행 점 있는 공개 도메인' => ['https://api.example.com./v1/quote'],
            '독일어 움라우트 도메인' => ["https://b\u{FC}cher.example/"],
        ];
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
            // IP 리터럴은 IDNA 라벨 구조가 없다. 정규화의 호스트명 문자 집합 검사에 IPv6 의
            // ':' 가 걸리면 공개 IPv6 가 통째로 차단되고, 사설/예약 대역 판정 코드는 도달
            // 불가가 된다 — 루프백 차단 케이스만으로는 그 회귀가 드러나지 않는다.
            '공인 IPv6' => ['https://[2001:4860:4860::8888]/'],
            '공인 IPv6 + 포트' => ['https://[2606:4700:4700::1111]:443/v1'],
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
