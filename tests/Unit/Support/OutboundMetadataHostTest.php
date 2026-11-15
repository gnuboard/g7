<?php

namespace Tests\Unit\Support;

use App\Support\OutboundUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 링크로컬·클라우드 메타데이터 주소 판정.
 *
 * 관리자가 정하는 서버 연결 대상(웹소켓·저장소·캐시·메일·프록시)은 localhost·사내 IP 가 정상 구성이라 사설
 * 대역을 막지 않는다. 다만 어떤 정상 구성에도 나오지 않는 링크로컬·클라우드 메타데이터 주소만은 막는다.
 * 판정은 숫자 대역과 호스트명 목록 대조만 한다(이름 해석을 하지 않는다).
 *
 * @scenario-manifest tests/scenarios/outbound-connection-target.yaml
 */
class OutboundMetadataHostTest extends TestCase
{
    /**
     * 메타데이터·링크로컬 주소는 표기 변종까지 막는다.
     *
     * @param  string  $host  검사할 호스트
     * @return void 반환값 없음
     *
     * @scenario case=metadata_address
     *
     * @effects metadata_and_link_local_hosts_detected
     */
    #[DataProvider('metadataHosts')]
    public function test_metadata_hosts_are_detected(string $host): void
    {
        $this->assertTrue(OutboundUrlValidator::isMetadataHost($host), $host);
    }

    /**
     * 정상 구성에 나오는 주소(localhost·사설 IP·공개 도메인)는 막지 않는다.
     *
     * @param  string  $host  검사할 호스트
     * @return void 반환값 없음
     *
     * @scenario case=private_or_public_address
     *
     * @effects private_and_public_hosts_stay_allowed
     */
    #[DataProvider('ordinaryHosts')]
    public function test_ordinary_hosts_are_not_treated_as_metadata(string $host): void
    {
        $this->assertFalse(OutboundUrlValidator::isMetadataHost($host), $host);
    }

    /**
     * URL 형태도 호스트 부분으로 같은 판정을 한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=metadata_address
     *
     * @effects metadata_and_link_local_hosts_detected
     */
    public function test_url_form_uses_the_host_part(): void
    {
        $this->assertTrue(OutboundUrlValidator::isMetadataUrl('http://169.254.169.254/latest/meta-data/'));
        $this->assertTrue(OutboundUrlValidator::isMetadataUrl('http://[fd00:ec2::254]:80/'));
        $this->assertTrue(OutboundUrlValidator::isMetadataUrl('https://metadata.google.internal/computeMetadata/v1/'));
        $this->assertTrue(OutboundUrlValidator::isMetadataUrl('socks5://user:pass@169.254.169.254:1080'));

        $this->assertFalse(OutboundUrlValidator::isMetadataUrl('http://127.0.0.1:9000'));
        $this->assertFalse(OutboundUrlValidator::isMetadataUrl('https://s3.ap-northeast-2.amazonaws.com'));
        $this->assertFalse(OutboundUrlValidator::isMetadataUrl('not a url'));
    }

    /**
     * 이름 해석은 하지 않는다 — 메타데이터 주소로 풀리는 공개 이름은 이 판정의 대상이 아니다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=name_resolving_to_metadata
     *
     * @effects no_dns_resolution_in_metadata_check
     */
    public function test_names_that_resolve_to_metadata_are_out_of_scope(): void
    {
        $this->assertFalse(OutboundUrlValidator::isMetadataHost('169.254.169.254.nip.io'));
    }

    /**
     * 메타데이터·링크로컬 호스트 목록.
     *
     * @return array<string, array{string}> 호스트 목록
     */
    public static function metadataHosts(): array
    {
        return [
            'AWS·GCP·Azure 메타데이터' => ['169.254.169.254'],
            '링크로컬 대역의 다른 주소' => ['169.254.0.1'],
            '알리바바 메타데이터' => ['100.100.100.200'],
            'IPv6 링크로컬' => ['fe80::1'],
            'IPv6 링크로컬(대괄호)' => ['[fe80::1]'],
            'AWS IPv6 메타데이터' => ['fd00:ec2::254'],
            'IPv4-mapped IPv6' => ['[::ffff:169.254.169.254]'],
            'IPv4-mapped IPv6(16진 표기)' => ['::ffff:a9fe:a9fe'],
            'GCP 메타데이터 이름' => ['metadata.google.internal'],
            'GCP 메타데이터 이름(후행 점)' => ['metadata.google.internal.'],
            'GCP 메타데이터 이름(대문자)' => ['Metadata.Google.Internal'],
            'GCP 메타데이터 이름(전각 점)' => ["metadata\u{FF0E}google\u{FF0E}internal"],
            'GCP 짧은 이름' => ['metadata.goog'],
            'EC2 이름' => ['instance-data'],
            'EC2 이름(정식)' => ['instance-data.ec2.internal'],
            '전각 점으로 쓴 메타데이터 IP' => ["169\u{FF0E}254\u{FF0E}169\u{FF0E}254"],
            '앞뒤 공백' => [' 169.254.169.254 '],
        ];
    }

    /**
     * 정상 구성에 나오는 호스트 목록.
     *
     * @return array<string, array{string}> 호스트 목록
     */
    public static function ordinaryHosts(): array
    {
        return [
            '사내 사설 IP' => ['192.168.0.10'],
            '루프백 IP' => ['127.0.0.1'],
            'localhost' => ['localhost'],
            '공개 도메인' => ['ws.example.com'],
            '10 대역' => ['10.0.0.1'],
            'CGNAT 대역의 다른 주소' => ['100.100.100.201'],
            'IPv6 루프백' => ['::1'],
            'IPv6 사설(ULA)' => ['fd12:3456:789a::1'],
            '빈 값' => [''],
        ];
    }
}
