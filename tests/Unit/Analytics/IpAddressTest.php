<?php

namespace Tests\Unit\Analytics;

use App\Analytics\IpAddress;
use Tests\TestCase;

/**
 * IP 유틸 — 마스킹·대역 비교·이진 변환.
 *
 * 마스킹 옥텟 수는 운영자가 개인정보 수준을 정하는 손잡이이고, 대역 비교는 「이 방문을
 * 세지 않는다」를 결정한다. 둘 다 틀리면 조용히 틀린다 — 마스킹이 덜 되면 개인정보가
 * 더 남고, 대역이 안 맞으면 차단해 둔 주소가 계속 집계된다.
 */
class IpAddressTest extends TestCase
{
    /**
     * IPv4 는 옥텟 수만큼 뒤에서부터 지운다.
     *
     * @scenario mask_octets=0|1|2|3, ip_family=ipv4
     *
     * @effects ip_masking_clears_requested_octets_for_ipv4
     *
     * @return void 없음
     */
    public function test_masks_ipv4_by_octets(): void
    {
        $this->assertSame('198.51.100.42', IpAddress::mask('198.51.100.42', 0));
        $this->assertSame('198.51.100.0', IpAddress::mask('198.51.100.42', 1));
        $this->assertSame('198.51.0.0', IpAddress::mask('198.51.100.42', 2));
        $this->assertSame('198.0.0.0', IpAddress::mask('198.51.100.42', 3));
    }

    /**
     * IPv6 는 같은 수만큼 16비트 블록을 지운다.
     *
     * @scenario mask_octets=0|1|2|3, ip_family=ipv6
     *
     * @effects ip_masking_clears_requested_blocks_for_ipv6
     *
     * @return void 없음
     */
    public function test_masks_ipv6_by_blocks(): void
    {
        $ip = '2001:db8:85a3:1234:5678:8a2e:370:7334';

        $this->assertSame($ip, IpAddress::mask($ip, 0));
        $this->assertSame('2001:db8:85a3:1234:5678:8a2e:370:0', IpAddress::mask($ip, 1));
        $this->assertSame('2001:db8:85a3:1234:5678:8a2e::', IpAddress::mask($ip, 2));
        $this->assertSame('2001:db8:85a3:1234:5678::', IpAddress::mask($ip, 3));
    }

    /**
     * 주소 전체를 지우지는 않는다 — 주소가 아니게 되면 저장할 의미가 없다.
     *
     * @scenario mask_octets=over_max, ip_family=ipv4
     *
     * @effects ip_masking_never_clears_the_entire_address
     *
     * @return void 없음
     */
    public function test_never_masks_entire_ipv4(): void
    {
        $this->assertSame('198.0.0.0', IpAddress::mask('198.51.100.42', 9));
    }

    /**
     * CIDR 대역 포함 여부를 판정한다.
     *
     * @scenario cidr=v4_inside|v4_outside|v6_inside|v6_outside|exact|all
     *
     * @effects allowlisted_ip_skips_ip_ua_headless_cloud_country_rules
     *
     * @return void 없음
     */
    public function test_matches_cidr_ranges(): void
    {
        $this->assertTrue(IpAddress::inCidr('192.168.1.50', '192.168.0.0/16'));
        $this->assertFalse(IpAddress::inCidr('192.169.1.50', '192.168.0.0/16'));
        $this->assertTrue(IpAddress::inCidr('2001:db8::1', '2001:db8::/32'));
        $this->assertFalse(IpAddress::inCidr('2001:db9::1', '2001:db8::/32'));
        $this->assertTrue(IpAddress::inCidr('192.168.1.1', '192.168.1.1'));
        $this->assertFalse(IpAddress::inCidr('192.168.1.2', '192.168.1.1'));
        $this->assertTrue(IpAddress::inCidr('1.2.3.4', '0.0.0.0/0'));
    }

    /**
     * 바이트 경계가 아닌 프리픽스도 정확히 가른다.
     *
     * 8의 배수만 맞추는 구현은 /25 에서 절반을 틀리게 판정하고, 그 오류는 대역
     * 하나를 통째로 잘못 차단할 때까지 드러나지 않는다.
     *
     * @scenario cidr=non_byte_boundary
     *
     * @effects allowlisted_ip_skips_ip_ua_headless_cloud_country_rules
     *
     * @return void 없음
     */
    public function test_matches_non_byte_aligned_prefix(): void
    {
        $this->assertTrue(IpAddress::inCidr('192.168.1.130', '192.168.1.128/25'));
        $this->assertFalse(IpAddress::inCidr('192.168.1.126', '192.168.1.128/25'));
    }

    /**
     * IPv4 와 IPv6 는 서로의 대역에 들어가지 않는다.
     *
     * @scenario cidr=cross_family
     *
     * @effects allowlisted_ip_skips_ip_ua_headless_cloud_country_rules
     *
     * @return void 없음
     */
    public function test_does_not_match_across_families(): void
    {
        $this->assertFalse(IpAddress::inCidr('192.168.1.1', '2001:db8::/32'));
        $this->assertFalse(IpAddress::inCidr('2001:db8::1', '192.168.0.0/16'));
    }

    /**
     * 잘못된 입력에 예외를 던지지 않는다 — 수집 경로가 깨지면 안 된다.
     *
     * @scenario input=invalid
     *
     * @effects tracking_never_throws_to_the_caller
     *
     * @return void 없음
     */
    public function test_rejects_invalid_input_without_throwing(): void
    {
        $this->assertNull(IpAddress::mask('not-an-ip', 1));
        $this->assertFalse(IpAddress::inCidr('1.2.3.4', 'garbage/99'));
        $this->assertFalse(IpAddress::inCidr('1.2.3.4', ''));
        $this->assertFalse(IpAddress::inCidr('1.2.3.4', '1.2.3.0/33'));
        $this->assertNull(IpAddress::fromBinary('abc'));
    }

    /**
     * 목록 중 하나라도 맞으면 참이다.
     *
     * @scenario list=hit|miss
     *
     * @effects allowlisted_ip_skips_ip_ua_headless_cloud_country_rules
     *
     * @return void 없음
     */
    public function test_matches_any_in_list(): void
    {
        $this->assertTrue(IpAddress::matchesAny('10.1.2.3', ['192.168.0.0/16', '10.0.0.0/8']));
        $this->assertFalse(IpAddress::matchesAny('8.8.8.8', ['192.168.0.0/16']));
    }

    /**
     * 이진 변환이 원값을 그대로 되돌린다.
     *
     * @scenario roundtrip=ipv4|ipv6
     *
     * @effects ip_masking_clears_requested_blocks_for_ipv6
     *
     * @return void 없음
     */
    public function test_binary_roundtrip(): void
    {
        foreach (['203.0.113.9', '2001:db8::1', '::1'] as $ip) {
            $this->assertSame($ip, IpAddress::fromBinary(IpAddress::toBinary($ip)));
        }
    }
}
