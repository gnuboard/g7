<?php

namespace Tests\Unit\Scheduling;

use App\Rules\ValidIpAllowList;
use App\Scheduling\WebCronPolicy;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * 웹 크론 호출 정책의 해석 단위 계약.
 *
 * 접근 판정(허용 IP)과 빈도 판정(최소 간격)이 여기 한 곳에서만 나온다 — 엔드포인트·
 * 방문 폴백·관리자 화면이 각자 해석하면 같은 사이트가 통로마다 다르게 동작한다.
 *
 * @scenario caller=external, caller=visit
 */
class WebCronPolicyTest extends TestCase
{
    /** 정책 해석기 */
    private WebCronPolicy $policy;

    /**
     * 테스트 준비.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new WebCronPolicy;
    }

    /**
     * 저장값이 없으면 자동 판단이고, 주소 호출이 열려 있다.
     *
     * @effects default_mode_allows_web_calls
     */
    public function test_default_mode_allows_web_calls(): void
    {
        Config::set('g7_settings.core.system.cron_mode', null);

        $this->assertSame(WebCronPolicy::MODE_AUTO, $this->policy->mode());
        $this->assertTrue($this->policy->webCallAllowed());
    }

    /**
     * 뜻 모를 값이 저장돼 있어도 자동 판단으로 떨어진다 (파손 없음).
     *
     * @effects unknown_mode_falls_back_to_auto
     */
    public function test_unknown_mode_falls_back_to_auto(): void
    {
        Config::set('g7_settings.core.system.cron_mode', 'nonsense');

        $this->assertSame(WebCronPolicy::MODE_AUTO, $this->policy->mode());
    }

    /**
     * "서버 크론만" 일 때만 주소 호출이 닫힌다.
     *
     * @effects only_cron_mode_closes_web_calls
     */
    public function test_only_cron_mode_closes_web_calls(): void
    {
        foreach ([WebCronPolicy::MODE_AUTO, WebCronPolicy::MODE_WEB, WebCronPolicy::MODE_BOTH] as $mode) {
            Config::set('g7_settings.core.system.cron_mode', $mode);
            $this->assertTrue($this->policy->webCallAllowed(), "{$mode} 는 주소 호출을 허용해야 합니다.");
        }

        Config::set('g7_settings.core.system.cron_mode', WebCronPolicy::MODE_CRON);
        $this->assertFalse($this->policy->webCallAllowed());
    }

    /**
     * 허용 IP 는 줄바꿈과 쉼표 어느 쪽으로 적어도 같게 읽힌다.
     *
     * @effects ip_allow_list_accepts_both_separators
     */
    public function test_ip_allow_list_accepts_both_separators(): void
    {
        Config::set('g7_settings.core.system.cron_allowed_ips', "203.0.113.7\n198.51.100.9");
        $this->assertSame(['203.0.113.7', '198.51.100.9'], $this->policy->allowedIps());

        Config::set('g7_settings.core.system.cron_allowed_ips', '203.0.113.7, 198.51.100.9');
        $this->assertSame(['203.0.113.7', '198.51.100.9'], $this->policy->allowedIps());
    }

    /**
     * CIDR 대역이 접두사 문자열 비교가 아니라 대역 판정으로 처리된다.
     *
     * 문자열 비교로 대신하면 `10.1.1.1` 규칙이 `10.1.1.10` 까지 통과시킨다.
     *
     * @effects ip_allow_list_matches_by_range
     */
    public function test_ip_allow_list_matches_by_range_not_prefix(): void
    {
        Config::set('g7_settings.core.system.cron_allowed_ips', '10.1.1.0/24');

        $this->assertTrue($this->policy->ipAllowed('10.1.1.55'));
        $this->assertFalse($this->policy->ipAllowed('10.1.2.55'));

        Config::set('g7_settings.core.system.cron_allowed_ips', '10.1.1.1');

        $this->assertTrue($this->policy->ipAllowed('10.1.1.1'));
        $this->assertFalse($this->policy->ipAllowed('10.1.1.10'));
    }

    /**
     * 목록이 비어 있으면 제한하지 않고, 목록이 있는데 IP 를 알 수 없으면 막는다.
     *
     * @effects unknown_caller_ip_is_denied_when_a_list_exists
     */
    public function test_unknown_ip_is_denied_only_when_a_list_exists(): void
    {
        Config::set('g7_settings.core.system.cron_allowed_ips', '');
        $this->assertTrue($this->policy->ipAllowed(null));

        Config::set('g7_settings.core.system.cron_allowed_ips', '203.0.113.7');
        $this->assertFalse($this->policy->ipAllowed(null));
    }

    /**
     * 최소 간격은 저장값 → 코드 기본값 순서로 해석되고 상한으로 잘린다.
     *
     * @effects min_interval_resolution_order_and_clamp
     */
    public function test_min_interval_resolution(): void
    {
        Config::set('core.cron.min_interval_minutes', 5);
        Config::set('core.settings_limits.system_cron_min_interval_max', 1440);

        Config::set('g7_settings.core.system.cron_min_interval', null);
        $this->assertSame(5, $this->policy->minIntervalMinutes());

        Config::set('g7_settings.core.system.cron_min_interval', 15);
        $this->assertSame(15, $this->policy->minIntervalMinutes());

        Config::set('g7_settings.core.system.cron_min_interval', 0);
        $this->assertSame(0, $this->policy->minIntervalMinutes());

        Config::set('g7_settings.core.system.cron_min_interval', 999999);
        $this->assertSame(1440, $this->policy->minIntervalMinutes());

        Config::set('g7_settings.core.system.cron_min_interval', -5);
        $this->assertSame(0, $this->policy->minIntervalMinutes());
    }

    /**
     * 방문 트리거 폴백의 기본값은 꺼짐이다 (D5).
     *
     * @effects visit_fallback_defaults_to_off
     */
    public function test_visit_fallback_defaults_to_off(): void
    {
        Config::set('g7_settings.core.system.cron_visit_fallback', null);
        $this->assertFalse($this->policy->visitFallbackEnabled());

        Config::set('g7_settings.core.system.cron_visit_fallback', true);
        $this->assertTrue($this->policy->visitFallbackEnabled());
    }

    /**
     * IP 허용 목록 검증 규칙이 형태를 판정한다.
     *
     * @effects ip_allow_list_rule_rejects_malformed_entries
     */
    public function test_ip_allow_list_rule(): void
    {
        $valid = ["203.0.113.7\n2001:db8::/32, 10.0.0.0/8", '', '  '];

        foreach ($valid as $value) {
            $this->assertFalse(
                $this->validateAllowList($value)->fails(),
                "유효한 목록이 거부되었습니다: {$value}",
            );
        }

        $invalid = ['not-an-ip', '203.0.113.7/99', '203.0.113.7/abc', '203.0.113.999'];

        foreach ($invalid as $value) {
            $this->assertTrue(
                $this->validateAllowList($value)->fails(),
                "잘못된 목록이 통과했습니다: {$value}",
            );
        }
    }

    /**
     * 방문 트리거 폴백은 **배포되는 기본값**이 꺼짐이어야 한다 (D5).
     *
     * 기존 가드는 설정을 테스트가 직접 덮은 뒤 판정해서, 배포되는 기본값
     * (`config/settings/defaults.json`)을 켜짐으로 바꿔도 전부 초록이었다. 그 값이
     * 켜지면 모든 사이트가 방문자 요청 끝에서 예약을 돌리게 되는데, 운영자가 고른
     * 적 없는 정책 변경이고 트래픽이 있는 사이트일수록 조용히 부하가 된다.
     *
     * 그래서 덮어쓴 값이 아니라 **배포되는 파일 자체**를 잰다.
     *
     * @effects visit_fallback_ships_disabled_by_default
     */
    public function test_visit_fallback_ships_disabled_in_the_defaults_file(): void
    {
        $path = base_path('config/settings/defaults.json');
        $defaults = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($defaults, "설정 기본값 파일을 읽지 못했습니다: {$path}");

        $shipped = data_get($defaults, 'defaults.system.cron_visit_fallback');

        $this->assertFalse(
            $shipped,
            '배포되는 기본값에서 방문 트리거 폴백이 켜져 있다 — 운영자가 고르지 않은 '
            .'정책이 모든 설치본에 적용된다.',
        );
    }

    /**
     * 설정이 없을 때의 해석도 꺼짐이다 (미설치 키·구설치본 대비).
     *
     * @effects visit_fallback_defaults_to_disabled_when_unset
     */
    public function test_visit_fallback_is_disabled_when_the_setting_is_absent(): void
    {
        Config::set('g7_settings.core.system.cron_visit_fallback', null);

        $this->assertFalse((new WebCronPolicy)->visitFallbackEnabled());
    }

    /**
     * 허용 목록 규칙으로 값을 검증합니다.
     *
     * @param  string  $value  검증할 값
     * @return \Illuminate\Contracts\Validation\Validator 검증기
     */
    private function validateAllowList(string $value)
    {
        return Validator::make(['ips' => $value], ['ips' => [new ValidIpAllowList]]);
    }
}
