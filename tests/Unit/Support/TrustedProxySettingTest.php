<?php

namespace Tests\Unit\Support;

use App\Support\TrustedProxySetting;
use Tests\TestCase;

/**
 * 신뢰 프록시 저장값 ↔ 설정값 변환 계약.
 *
 * 이 변환이 어긋나도 예외는 나지 않는다 — 화면은 그리고 저장도 되며, 방문자 IP 만 조용히
 * 잘못 기록된다. 그래서 변환의 각 갈래를 값으로 못박는다.
 *
 * 특히 **null 과 빈 배열의 구분**이 이 파일의 핵심이다. 둘을 같게 다루면 `.env` 로 프록시를
 * 설정해 둔 기존 설치가, 고급 탭의 무관한 항목을 저장하는 순간 신뢰 설정을 잃는다.
 */
class TrustedProxySettingTest extends TestCase
{
    /**
     * 콤마 문자열이 태그 목록으로 정규화됩니다.
     *
     * @scenario proxies_setting=cidr
     *
     * @effects saved_list_is_normalized_to_a_config_value
     *
     * @return void 반환값 없음
     */
    public function test_콤마_문자열이_태그_목록이_된다(): void
    {
        $this->assertSame(
            ['10.0.0.1', '10.0.0.0/24'],
            TrustedProxySetting::toList('10.0.0.1, 10.0.0.0/24')
        );
    }

    /**
     * 빈 토큰과 중복은 걷어냅니다.
     *
     * 붙여넣기로 들어오는 목록에는 꼬리 콤마와 중복이 흔하다. 그대로 저장하면 빈 토큰이
     * 설정값에 섞여 `explode()` 결과에 빈 항목이 생긴다.
     *
     * @scenario proxies_setting=single_ip_match
     *
     * @effects saved_list_is_normalized_to_a_config_value
     *
     * @return void 반환값 없음
     */
    public function test_빈_토큰과_중복을_걷어낸다(): void
    {
        $this->assertSame(
            ['10.0.0.1'],
            TrustedProxySetting::toList('10.0.0.1, , 10.0.0.1,')
        );
    }

    /**
     * 호환 표기 `**` 는 `*` 로 정규화됩니다.
     *
     * 두 표기를 그대로 두면 같은 설정이 화면에서 서로 다른 모드로 보인다.
     *
     * @scenario proxies_setting=double_star
     *
     * @effects double_star_behaves_like_star
     *
     * @return void 반환값 없음
     */
    public function test_이중_별표가_단일_별표로_정규화된다(): void
    {
        $this->assertSame(['*'], TrustedProxySetting::toList('**'));
        $this->assertSame(TrustedProxySetting::MODE_NEAREST, TrustedProxySetting::modeOf(['**']));
    }

    /**
     * 빈 목록은 설정값 null 이 됩니다 (아무도 신뢰하지 않음).
     *
     * @scenario proxies_setting=unset
     *
     * @effects saved_list_is_normalized_to_a_config_value
     *
     * @return void 반환값 없음
     */
    public function test_빈_목록은_설정값_null_이_된다(): void
    {
        $this->assertNull(TrustedProxySetting::toConfigValue([]));
        $this->assertSame(TrustedProxySetting::MODE_NONE, TrustedProxySetting::modeOf([]));
    }

    /**
     * 와일드카드가 섞이면 그것이 목록 전체를 대신합니다.
     *
     * 미들웨어는 `*` 를 만나면 직전 호출 IP 만 신뢰하고 나머지 항목을 보지 않는다. 저장값을
     * 그대로 흘려 보내면 화면은 「지정 목록」인데 실제로는 와일드카드로 도는 상태가 된다.
     *
     * @scenario proxies_setting=star
     *
     * @effects saved_list_is_normalized_to_a_config_value
     *
     * @return void 반환값 없음
     */
    public function test_와일드카드가_섞이면_목록_전체를_대신한다(): void
    {
        $this->assertSame('*', TrustedProxySetting::toConfigValue(['10.0.0.1', '*']));
    }

    /**
     * 지정 목록은 콤마 문자열로 합쳐집니다.
     *
     * @scenario proxies_setting=cidr
     *
     * @effects saved_list_is_normalized_to_a_config_value
     *
     * @return void 반환값 없음
     */
    public function test_지정_목록이_콤마_문자열로_합쳐진다(): void
    {
        $this->assertSame(
            '10.0.0.1,192.168.0.0/16',
            TrustedProxySetting::toConfigValue(['10.0.0.1', '192.168.0.0/16'])
        );
        $this->assertSame(
            TrustedProxySetting::MODE_LIST,
            TrustedProxySetting::modeOf(['10.0.0.1', '192.168.0.0/16'])
        );
    }

    /**
     * 쓸 수 있는 주소 형태를 받아들입니다.
     *
     * @scenario proxies_setting=cidr
     *
     * @effects invalid_proxy_address_is_rejected_on_save
     *
     * @return void 반환값 없음
     */
    public function test_유효한_주소를_받아들인다(): void
    {
        foreach (['*', '**', '10.0.0.1', '10.0.0.0/24', '::1', '2001:db8::/32', '0.0.0.0/0'] as $token) {
            $this->assertTrue(TrustedProxySetting::isValidToken($token), "거부되면 안 되는 값: {$token}");
        }
    }

    /**
     * 쓸 수 없는 주소 형태를 거부합니다.
     *
     * 호스트명은 미들웨어가 IP 로 비교하므로 영원히 일치하지 않고, IPv4 에 `/64` 는 아무 것도
     * 매칭하지 않는다. 둘 다 저장은 되고 오류는 나지 않는 형태라 여기서 막는다.
     *
     * @scenario proxies_setting=single_ip_mismatch
     *
     * @effects invalid_proxy_address_is_rejected_on_save
     *
     * @return void 반환값 없음
     */
    public function test_쓸_수_없는_주소를_거부한다(): void
    {
        foreach (['proxy.example.com', '10.0.0.1/64', '10.0.0.256', '10.0.0.1/', '/24', '', '   '] as $token) {
            $this->assertFalse(TrustedProxySetting::isValidToken($token), "통과하면 안 되는 값: {$token}");
        }
    }

    /**
     * IPv6 의 프리픽스 상한은 128 입니다.
     *
     * 계열별 상한을 한 값으로 뭉뚱그리면 IPv6 정상 표기가 거부되거나 IPv4 오표기가 통과한다.
     *
     * @scenario proxies_setting=cidr
     *
     * @effects invalid_proxy_address_is_rejected_on_save
     *
     * @return void 반환값 없음
     */
    public function test_주소_계열마다_프리픽스_상한이_다르다(): void
    {
        $this->assertTrue(TrustedProxySetting::isValidToken('2001:db8::/64'));
        $this->assertFalse(TrustedProxySetting::isValidToken('2001:db8::/129'));
        $this->assertTrue(TrustedProxySetting::isValidToken('10.0.0.0/32'));
        $this->assertFalse(TrustedProxySetting::isValidToken('10.0.0.0/33'));
    }

    /**
     * null 은 빈 목록으로 읽히되, 그 구분은 호출처가 갖습니다.
     *
     * 이 클래스는 순수 변환만 한다 — "주입하지 않는다" 는 판정은 Provider 의 몫이고,
     * 여기서 null 을 `[]` 로 바꾼다고 그 판정이 사라지지 않는다.
     *
     * @scenario proxies_setting=unset
     *
     * @effects unset_setting_leaves_env_authority_intact
     *
     * @return void 반환값 없음
     */
    public function test_null_은_빈_목록으로_읽힌다(): void
    {
        $this->assertSame([], TrustedProxySetting::toList(null));
    }
}
