<?php

namespace Tests\Unit\Support;

use App\Support\IpAccessMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * IP 접근 제한 판정 규약을 고정합니다.
 *
 * 차단 미들웨어·설정 저장의 자기 잠금 검사·구제 커맨드가 모두 이 판정 하나를 쓴다. 판정이
 * 틀리면 두 방향으로 조용히 무너진다 — 너무 넓으면 허용 목록 밖에서 관리자 영역이 열리고,
 * 너무 좁으면 운영자가 자기 IP 를 넣고도 잠긴다. 어느 쪽도 예외나 로그를 남기지 않는다.
 *
 * 축 조합은 각 테스트 메서드의 @scenario 주석이 담당한다.
 */
class IpAccessMatcherTest extends TestCase
{
    /**
     * 허용 목록 항목의 형식 사례를 제공합니다.
     *
     * @return array<string, array{0: mixed, 1: bool}> [항목, 유효 여부]
     */
    public static function entryProvider(): array
    {
        return [
            'IPv4' => ['203.0.113.24', true],
            'IPv6' => ['2001:db8::1', true],
            'IPv4 CIDR' => ['10.0.0.0/8', true],
            'IPv6 CIDR' => ['2001:db8::/32', true],
            '앞뒤 공백' => ['  198.51.100.7  ', true],
            '전체 IPv4' => ['0.0.0.0/0', true],
            '호스트명' => ['admin.example.com', false],
            '범위 표기' => ['10.0.0.1-10.0.0.9', false],
            '와일드카드' => ['192.168.*', false],
            'IPv4 접두 초과' => ['10.0.0.0/33', false],
            'IPv6 접두 초과' => ['2001:db8::/129', false],
            '음수 접두' => ['10.0.0.0/-1', false],
            '문자 접두' => ['10.0.0.0/a', false],
            '빈 접두' => ['10.0.0.0/', false],
            '빈 문자열' => ['', false],
            '정수' => [12345, false],
            'null' => [null, false],
        ];
    }

    /**
     * IP·CIDR 만 유효 항목으로 받습니다.
     *
     * 호스트명·범위·와일드카드는 매칭 규칙이 없어, 저장만 되고 아무도 통과시키지 못한다.
     *
     * @param  mixed  $entry  검사할 항목
     * @param  bool  $expected  기대 유효 여부
     * @return void 반환값 없음
     *
     * @effects entry_format_is_ip_or_cidr_only
     */
    #[DataProvider('entryProvider')]
    public function test_only_ip_or_cidr_entries_are_valid(mixed $entry, bool $expected): void
    {
        $this->assertSame($expected, IpAccessMatcher::isValidEntry($entry));
    }

    /**
     * IPv4·IPv6 모두 단일 주소와 CIDR 범위로 매칭합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario ip_source=direct
     *
     * @effects cidr_and_ipv6_are_matched
     */
    public function test_ipv4_and_ipv6_match_by_address_and_cidr(): void
    {
        $this->assertTrue(IpAccessMatcher::ipMatches('203.0.113.24', ['203.0.113.24']));
        $this->assertTrue(IpAccessMatcher::ipMatches('203.0.113.77', ['203.0.113.0/24']));
        // 대역 밖 주소도 문서용 예약 대역(RFC 5737·3849) 안에서 고른다 — 실재 공인 주소를 테스트에 적지 않는다.
        $this->assertFalse(IpAccessMatcher::ipMatches('198.51.100.1', ['203.0.113.0/24']));
        $this->assertTrue(IpAccessMatcher::ipMatches('2001:db8::abcd', ['2001:db8::/32']));
        $this->assertFalse(IpAccessMatcher::ipMatches('2001:db8:2::1', ['2001:db8:1::/48']));
        $this->assertFalse(IpAccessMatcher::ipMatches(null, ['0.0.0.0/0']), 'IP 를 알 수 없으면 어떤 목록에도 해당하지 않아야 합니다.');
        $this->assertFalse(IpAccessMatcher::ipMatches('203.0.113.24', []), '빈 목록은 아무도 통과시키지 않습니다 — 적용 여부는 호출자가 목록 유무로 정합니다.');
    }

    /**
     * 이중 스택 소켓이 보고하는 IPv4-mapped 주소도 IPv4 항목에 매칭합니다.
     *
     * `IpUtils::checkIp()` 는 콜론 수로 v4/v6 비교를 가르므로, 정규화가 없으면 `::ffff:a.b.c.d`
     * 는 IPv4 허용 항목과 절대 매칭되지 않아 운영자가 자기 IP 를 넣고도 잠긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario ip_source=ipv4_mapped
     *
     * @effects ipv4_mapped_address_matches_ipv4_entry
     */
    public function test_ipv4_mapped_ipv6_address_matches_ipv4_entry(): void
    {
        $this->assertSame('203.0.113.5', IpAccessMatcher::normalizeIp('::ffff:203.0.113.5'));
        $this->assertSame('203.0.113.5', IpAccessMatcher::normalizeIp('0:0:0:0:0:ffff:cb00:7105'));
        $this->assertSame('2001:db8::1', IpAccessMatcher::normalizeIp('2001:db8::1'));

        $this->assertTrue(IpAccessMatcher::ipMatches('::ffff:203.0.113.5', ['203.0.113.0/24']));
        $this->assertTrue(IpAccessMatcher::ipMatches('::ffff:203.0.113.5', ['203.0.113.5']));
    }

    /**
     * 접두 길이의 앞자리 0 표기도 같은 범위로 판정합니다.
     *
     * `IpUtils` 는 `/08` 을 `/8` 과 다르게 다룬다 — 정규형으로 다시 쓰지 않으면 같은 뜻의 항목이
     * 매칭되지 않는다.
     *
     * @return void 반환값 없음
     *
     * @effects prefix_with_leading_zero_is_canonicalized
     */
    public function test_prefix_with_leading_zero_is_canonicalized(): void
    {
        $this->assertSame('10.0.0.0/8', IpAccessMatcher::canonicalEntry('10.0.0.0/08'));
        $this->assertTrue(IpAccessMatcher::ipMatches('10.20.30.40', ['10.0.0.0/08']));
        $this->assertSame(['10.0.0.0/8'], IpAccessMatcher::canonicalEntries(['10.0.0.0/8', ' 10.0.0.0/08 ', 'bad']));
    }

    /**
     * 관리자 영역 판정 사례를 제공합니다.
     *
     * @return array<string, array{0: string, 1: bool}> [경로, 관리자 영역 여부]
     */
    public static function adminAreaProvider(): array
    {
        return [
            '관리자 셸 루트' => ['admin', true],
            '관리자 셸' => ['/admin/settings', true],
            '코어 관리자 API' => ['api/admin/users', true],
            '관리자 로그인 API' => ['api/auth/admin/login', true],
            '관리자 2단계 인증 API' => ['api/auth/admin/login/two-factor', true],
            '모듈 관리자 API' => ['api/modules/sirsoft-ecommerce/admin/orders', true],
            '플러그인 관리자 API' => ['api/plugins/sirsoft-gdpr/admin/settings', true],
            '인코딩 우회' => ['%61dmin/settings', true],
            '사용자 셸' => ['/', false],
            '사용자 로그인 API' => ['api/auth/login', false],
            '비슷한 접두' => ['administrator', false],
            '비슷한 API 접두' => ['api/adminx/users', false],
            '확장 사용자 API' => ['api/modules/sirsoft-board/boards/notice/posts', false],
            '식별자 두 세그먼트' => ['api/modules/sirsoft-board/boards/admin', false],
            '플러그인 웹 라우트' => ['plugins/sirsoft-pay_kginicis/payment/callback', false],
        ];
    }

    /**
     * 관리자 영역은 코어·확장 관리자 API 와 관리자 로그인 API 까지 덮고, 사용자 경로는 삼키지 않습니다.
     *
     * 확장 관리자 API 는 `api/admin/*` 아래가 아니다 — 그 둘을 빼면 확장 관리 화면만 IP 보호 밖에
     * 남는다. 반대로 확장 식별자 자리를 한 세그먼트로 묶지 않으면 사용자 경로 깊숙한 `admin`
     * 세그먼트까지 관리자 영역으로 오판한다.
     *
     * @param  string  $path  요청 경로
     * @param  bool  $expected  기대 판정
     * @return void 반환값 없음
     *
     * @effects admin_area_covers_core_extension_and_login_surfaces, encoded_path_cannot_bypass_admin_area
     */
    #[DataProvider('adminAreaProvider')]
    public function test_admin_area_covers_every_admin_surface_only(string $path, bool $expected): void
    {
        $this->assertSame($expected, IpAccessMatcher::isAdminArea($path));
    }

    /**
     * 규칙 패턴은 앞뒤 `/` 를 무시하는 URI glob 이며 대소문자를 구분합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=public_path, rule=blocks_client
     *
     * @effects rule_pattern_is_uri_glob
     */
    public function test_rule_pattern_is_uri_glob(): void
    {
        $this->assertTrue(IpAccessMatcher::pathMatches('/api/admin/users*', 'api/admin/users/7/roles'));
        $this->assertTrue(IpAccessMatcher::pathMatches('api/admin/users*', '/api/admin/users'));
        $this->assertTrue(IpAccessMatcher::pathMatches('/api/*/export', 'api/modules/sirsoft-ecommerce/admin/orders/export'), '* 는 / 를 포함해 맞아야 합니다.');
        $this->assertFalse(IpAccessMatcher::pathMatches('/api/admin/users*', 'api/admin/roles'));
        $this->assertFalse(IpAccessMatcher::pathMatches('/API/admin/*', 'api/admin/users'), '라우터와 같은 기준으로 대소문자를 구분해야 합니다.');
        $this->assertTrue(IpAccessMatcher::pathMatches('/', '/'), '/ 하나는 루트만 맞습니다.');
        $this->assertFalse(IpAccessMatcher::pathMatches('/', 'shop'));
    }

    /**
     * 사용 중이고 유효 IP 가 있는 첫 매칭 규칙 하나만 적용합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=public_path, rule=disabled
     *
     * @effects first_enabled_matching_rule_applies
     */
    public function test_first_enabled_matching_rule_wins(): void
    {
        $rules = [
            ['pattern' => '/api/__probe/*', 'ips' => ['192.0.2.1'], 'enabled' => false],
            ['pattern' => '/api/__probe/*', 'ips' => ['bad-entry']],
            ['pattern' => '/api/__probe/x*', 'ips' => ['192.0.2.2']],
            ['pattern' => '/api/__probe/*', 'ips' => ['192.0.2.3']],
        ];

        $this->assertSame(['192.0.2.2'], IpAccessMatcher::findRule('api/__probe/xyz', $rules)['ips']);
        $this->assertSame(['192.0.2.3'], IpAccessMatcher::findRule('api/__probe/abc', $rules)['ips']);
        $this->assertNull(IpAccessMatcher::findRule('api/other', $rules));
        $this->assertTrue(IpAccessMatcher::isRuleEnabled(['pattern' => '/x']), 'enabled 미지정은 사용 중입니다.');
        $this->assertFalse(IpAccessMatcher::isRuleEnabled(['enabled' => 'false']));
    }

    /**
     * 코어 고정 제외 경로가 결제·웹훅·본인인증 콜백 관례를 덮습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=inbound_callback, rule=blocks_client
     *
     * @effects fixed_exempt_paths_cover_inbound_callbacks
     */
    public function test_fixed_exempt_paths_cover_inbound_callback_conventions(): void
    {
        foreach ([
            'plugins/sirsoft-pay_kginicis/payment/vbank-notify',
            'plugins/sirsoft-pay_kginicis/payment/mobile/callback',
            'plugins/sirsoft-pay_nhnkcp/payment/escrow-common-notify',
            'plugins/sirsoft-tosspayments/webhook/deposit',
            'api/plugins/sirsoft-message_bizppurio/webhook',
            'plugins/sirsoft-verification_kginicis/plugin/inicis/callback',
        ] as $path) {
            $this->assertTrue(IpAccessMatcher::isExempt($path), "인바운드 콜백 {$path} 가 제외되지 않았습니다.");
        }

        $this->assertFalse(IpAccessMatcher::isExempt('api/plugins/sirsoft-pay_kginicis/user/orders/A1/receipt'));
        $this->assertTrue(IpAccessMatcher::isExempt('plugins/acme-pay/custom-return', ['plugins/*/custom-return']), '확장이 훅으로 더한 패턴도 제외되어야 합니다.');
    }

    /**
     * 관리자 영역은 어떤 제외 패턴으로도 빠져나갈 수 없습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=core_admin_api, admin_list=excludes_client
     *
     * @effects exemption_never_opens_admin_area
     */
    public function test_exemption_never_opens_admin_area(): void
    {
        $this->assertFalse(IpAccessMatcher::isExempt('api/admin/settings', ['api/admin/*', '*']));
        $this->assertFalse(IpAccessMatcher::isExempt('api/plugins/sirsoft-pay_kginicis/admin/orders/1/escrow-delivery', ['*']));

        $decision = IpAccessMatcher::decide('198.51.100.7', 'api/admin/settings', [
            'ip_restriction_enabled' => true,
            'admin_allowed_ips' => ['203.0.113.0/24'],
            'ip_restriction_rules' => [],
        ], ['*']);

        $this->assertSame(IpAccessMatcher::AXIS_ADMIN, $decision['axis'] ?? null);
    }

    /**
     * 마스터 토글이 꺼져 있으면 어떤 목록·규칙도 적용하지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=off
     *
     * @effects master_toggle_off_allows_everything
     */
    public function test_master_toggle_off_allows_everything(): void
    {
        $security = [
            'ip_restriction_enabled' => false,
            'admin_allowed_ips' => ['203.0.113.0/24'],
            'ip_restriction_rules' => [['pattern' => '*', 'ips' => ['203.0.113.1']]],
        ];

        $this->assertNull(IpAccessMatcher::decide('198.51.100.7', 'api/admin/settings', $security));
        $this->assertNull(IpAccessMatcher::decide('198.51.100.7', 'shop', $security));
        $this->assertTrue(IpAccessMatcher::isEnabled(['ip_restriction_enabled' => '1']), '저장값이 문자열이어도 켜짐으로 읽어야 합니다.');
        $this->assertFalse(IpAccessMatcher::isEnabled([]));
    }

    /**
     * 관리자 허용 목록이 비어 있으면 관리자 영역 축을 적용하지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=core_admin_api, admin_list=empty
     *
     * @effects empty_admin_list_does_not_apply
     */
    public function test_empty_admin_list_does_not_restrict_admin_area(): void
    {
        $security = ['ip_restriction_enabled' => true, 'admin_allowed_ips' => [], 'ip_restriction_rules' => []];

        $this->assertNull(IpAccessMatcher::decide('198.51.100.7', 'api/admin/settings', $security));

        // 형식이 잘못된 항목만 남은 목록도 "빈 목록" 과 같다 — 손상된 목록이 전원 차단으로 번지지 않게.
        $security['admin_allowed_ips'] = ['not-an-ip'];
        $this->assertNull(IpAccessMatcher::decide('198.51.100.7', 'api/admin/settings', $security));
    }

    /**
     * 관리자 영역 축과 규칙 축을 순서대로 판정해 거부 사유를 돌려줍니다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=core_admin_api, admin_list=contains_client, rule=blocks_client
     *
     * @effects admin_axis_then_rule_axis_decide
     */
    public function test_admin_axis_then_rule_axis_decide(): void
    {
        $security = [
            'ip_restriction_enabled' => true,
            'admin_allowed_ips' => ['203.0.113.0/24'],
            'ip_restriction_rules' => [
                ['pattern' => '/api/admin/users*', 'ips' => ['203.0.113.10'], 'memo' => '회원 관리는 본사만'],
                ['pattern' => '/shop/*', 'ips' => ['198.51.100.7']],
            ],
        ];

        $this->assertSame(['axis' => 'admin', 'pattern' => null], IpAccessMatcher::decide('198.51.100.7', 'api/admin/settings', $security));
        $this->assertNull(IpAccessMatcher::decide('203.0.113.9', 'api/admin/settings', $security));
        $this->assertSame(['axis' => 'rule', 'pattern' => '/api/admin/users*'], IpAccessMatcher::decide('203.0.113.9', 'api/admin/users/3', $security), '관리자 축을 통과해도 규칙 축이 더 좁힐 수 있어야 합니다.');
        $this->assertNull(IpAccessMatcher::decide('198.51.100.7', 'shop/items', $security));
        $this->assertSame('rule', IpAccessMatcher::decide('192.0.2.1', 'shop/items', $security)['axis'] ?? null);
        $this->assertNull(IpAccessMatcher::decide('192.0.2.1', 'plugins/sirsoft-pay_nhnkcp/payment/vbank-notify', [
            ...$security,
            'ip_restriction_rules' => [['pattern' => 'plugins/*', 'ips' => ['198.51.100.7']]],
        ]), '결제 콜백은 규칙에 걸려도 제외되어야 합니다.');
    }

    /**
     * 목록 자리에 배열이 아닌 값이 있으면 예외로 알려 호출자가 처리 방식을 정하게 합니다.
     *
     * @return void 반환값 없음
     *
     * @effects malformed_lists_raise_for_caller_to_fail_open
     */
    public function test_malformed_lists_raise_for_the_caller(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        IpAccessMatcher::decide('198.51.100.7', 'api/admin/settings', [
            'ip_restriction_enabled' => true,
            'admin_allowed_ips' => '203.0.113.0/24',
        ]);
    }
}
