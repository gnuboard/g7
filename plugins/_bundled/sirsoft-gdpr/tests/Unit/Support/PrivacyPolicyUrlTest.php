<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Support;

use Plugins\Sirsoft\Gdpr\Support\PrivacyPolicyUrl;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 개인정보처리방침 주소 해석기 검증
 *
 * 저장 검증·공개 응답·메일 링크·스냅샷 비교가 모두 이 판정을 쓴다. 판정이 느슨하면 배너 링크가
 * 스크립트 실행(`javascript:`)이나 외부 호스트(`//host`, `/\host`)로 새고, 빡빡하면 운영자가 정상
 * 주소를 저장하지 못한다 — 어느 쪽도 오류를 남기지 않는다.
 */
class PrivacyPolicyUrlTest extends PluginTestCase
{
    /**
     * 사이트 내부 경로와 https 절대 주소는 받습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin_update
     *
     * @effects privacy_policy_url_accepts_site_path_and_https_url
     */
    public function test_accepts_site_path_and_https_url(): void
    {
        foreach ([
            '/privacy',
            '/page/privacy',
            '/policies/privacy?lang=ko#section-2',
            'https://example.com/privacy',
            'HTTPS://Example.com/privacy',
            'https://example.com:8443/p/privacy',
        ] as $url) {
            $this->assertTrue(PrivacyPolicyUrl::isSafe($url), "허용해야 합니다: {$url}");
        }
    }

    /**
     * 다른 스킴·http·프로토콜 상대 주소·백슬래시·공백·제어문자·userinfo 위장은 거부합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin_update
     *
     * @effects privacy_policy_url_rejects_unsafe_addresses
     */
    public function test_rejects_unsafe_addresses(): void
    {
        foreach ([
            '',
            'privacy',
            'javascript:alert(1)',
            'JavaScript:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            'vbscript:msgbox(1)',
            'mailto:dpo@example.com',
            'http://example.com/privacy',
            '//evil.com/privacy',
            '///evil.com/privacy',
            '/\\evil.com/privacy',
            '\\\\evil.com\\privacy',
            "/\t/evil.com",
            "/\n/evil.com",
            "java\nscript:alert(1)",
            ' /privacy',
            '/pri vacy',
            "/privacy\u{00A0}",
            "/privacy\u{200B}",
            "/privacy\u{202E}",
            "/privacy\x00",
            'https://user@evil.com/privacy',
            'https://example.com@evil.com/privacy',
            'https:evil.com/privacy',
            'https:///evil.com/privacy',
            'https://',
            '/'.str_repeat('a', PrivacyPolicyUrl::MAX_LENGTH),
        ] as $url) {
            $this->assertFalse(PrivacyPolicyUrl::isSafe($url), '거부해야 합니다: '.json_encode($url));
        }
    }

    /**
     * 메일용 절대 주소 — 사이트 경로는 앱 주소를 붙이고 https 주소는 그대로, 허용 형식이 아니면 비웁니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=annual_notice
     *
     * @effects annual_notice_links_operator_privacy_policy_url
     */
    public function test_to_absolute_prefixes_site_paths_and_drops_unsafe_values(): void
    {
        $this->assertSame(url('/privacy'), PrivacyPolicyUrl::toAbsolute('/privacy'));
        $this->assertStringStartsWith('http', PrivacyPolicyUrl::toAbsolute('/privacy'));
        $this->assertSame('https://example.com/privacy', PrivacyPolicyUrl::toAbsolute('https://example.com/privacy'));
        $this->assertSame('', PrivacyPolicyUrl::toAbsolute('javascript:alert(1)'));
        $this->assertSame('', PrivacyPolicyUrl::toAbsolute('//evil.com'));
        $this->assertSame('', PrivacyPolicyUrl::toAbsolute(''));
        $this->assertSame('', PrivacyPolicyUrl::toAbsolute(null));
    }

    /**
     * 스냅샷 읽기 — 새 스냅샷은 주소를, 옛 스냅샷은 슬러그를 그 시절 링크 경로로 풀어 옛 슬러그와 함께 돌려줍니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=policy_snapshot
     *
     * @effects policy_diff_reads_legacy_slug_snapshot_as_same_address
     */
    public function test_from_snapshot_reads_new_and_legacy_formats(): void
    {
        $this->assertSame(
            ['url' => 'https://example.com/privacy', 'legacy_slug' => null],
            PrivacyPolicyUrl::fromSnapshot(['privacy_policy_url' => 'https://example.com/privacy']),
        );
        $this->assertSame(
            ['url' => '/page/privacy-policy', 'legacy_slug' => 'privacy-policy'],
            PrivacyPolicyUrl::fromSnapshot(['privacy_policy_slug' => 'privacy-policy']),
        );
        // 주소 키가 있으면 옛 슬러그보다 우선한다 (1.1.0 이후 발행분)
        $this->assertSame(
            ['url' => '', 'legacy_slug' => null],
            PrivacyPolicyUrl::fromSnapshot(['privacy_policy_url' => '', 'privacy_policy_slug' => 'privacy']),
        );
        // 허용 형식이 아닌 기록은 링크로 내보내지 않는다
        $this->assertSame(
            ['url' => '', 'legacy_slug' => null],
            PrivacyPolicyUrl::fromSnapshot(['privacy_policy_url' => 'javascript:alert(1)']),
        );
        $this->assertSame(['url' => '', 'legacy_slug' => null], PrivacyPolicyUrl::fromSnapshot([]));
        $this->assertSame(['url' => '', 'legacy_slug' => null], PrivacyPolicyUrl::fromSnapshot(['privacy_policy_slug' => '']));
    }

    /**
     * 연간 통지 메일의 처리방침 링크 줄 — 주소가 있으면 한 줄을 만들고, 없으면 줄 자체를 빼기 위해 빈 값을 낸다.
     *
     * @scenario surface=annual_notice
     *
     * @effects annual_notice_omits_policy_link_line_when_address_empty
     *
     * @return void 반환값 없음
     */
    public function test_mail_link_line_is_empty_when_address_is_empty(): void
    {
        $this->assertSame('', PrivacyPolicyUrl::mailLinkLine('', '개인정보처리방침'));
        $this->assertSame(
            '<p><a href="https://example.com/privacy?a=1&amp;b=2">Privacy &lt;policy&gt;</a></p>',
            PrivacyPolicyUrl::mailLinkLine('https://example.com/privacy?a=1&b=2', 'Privacy <policy>'),
        );
    }
}
