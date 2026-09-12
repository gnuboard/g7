<?php

namespace Tests\Feature\Settings;

use App\Http\Requests\Settings\SaveSettingsRequest;
use App\Providers\SettingsServiceProvider;
use App\Repositories\JsonConfigRepository;
use App\Rules\ValidTrustedProxyAddress;
use App\Services\SettingsService;
use App\Services\TrustedProxyProbe;
use App\Support\EnvPriority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 신뢰 프록시 화면 편집 경로의 계약 (#609).
 *
 * 이 경로의 모든 실패는 **조용하다** — 저장도 되고 화면도 그려지며, 방문자 IP 만 잘못
 * 기록된다. 그래서 각 갈래를 값으로 못박는다.
 *
 * 가장 중요한 갈래는 「저장한 적 없음(null)」이다. 그것을 「신뢰하지 않음([])」과 같게
 * 다루면, `.env` 로 프록시를 설정해 둔 기존 설치가 고급 탭의 **무관한** 항목을 저장하는
 * 순간 신뢰 설정을 잃는다. 그 회귀를 두 방향(주입 · 화면 표시)에서 잠근다.
 *
 * DB 를 쓰지 않으므로 `RefreshDatabase` 를 붙이지 않는다. 신뢰 상태는 `Request` 의 **정적**
 * 상태라 사전 검사 케이스가 끝난 뒤 원복 여부까지 확인한다.
 */
class TrustedProxySettingsTest extends TestCase
{
    /** 프록시로 위장할 직전 호출 IP */
    private const REMOTE_ADDR = '10.0.0.5';

    /** 프록시 너머의 진짜 방문자 IP */
    private const CLIENT_IP = '203.0.113.9';

    /**
     * Provider 의 `applyDebugConfig` 를 지정한 저장값으로 돌립니다.
     *
     * `applyDebugConfig` 는 testing 환경에서 통째로 조기 return 하므로(테스트 격리 가드)
     * 환경을 바꿔야 본문 분기가 돈다. 앱 인스턴스는 테스트마다 새로 만들어지므로 이
     * 변경은 그 테스트 안에만 머문다.
     *
     * @param  array<string, mixed>  $debugSettings  debug 카테고리 저장값
     */
    private function applyDebugSettings(array $debugSettings): void
    {
        $this->app->instance('env', 'production');

        $configRepository = $this->createMock(JsonConfigRepository::class);
        $configRepository->method('getCategory')
            ->willReturnCallback(fn (string $c) => $c === 'debug' ? $debugSettings : []);

        $provider = new SettingsServiceProvider($this->app);
        (new ReflectionMethod($provider, 'applyDebugConfig'))->invoke($provider, $configRepository);
    }

    /**
     * 프록시 뒤에 있는 것처럼 보이는 요청을 만듭니다.
     */
    private function forwardedRequest(): Request
    {
        return Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => self::REMOTE_ADDR,
            'HTTP_X_FORWARDED_FOR' => self::CLIENT_IP,
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);
    }

    // ------------------------------------------------------------- 주입 경로

    /**
     * 저장값이 null 이면 주입하지 않아 `.env` 유래 값이 살아남습니다.
     *
     * 이것이 기존 설치가 업그레이드로 깨지지 않는 유일한 조건이다.
     *
     * @scenario proxies_setting=unset
     *
     * @effects unset_setting_leaves_env_authority_intact
     */
    public function test_저장한_적_없으면_env_값이_살아남는다(): void
    {
        Config::set('trustedproxy.proxies', '10.0.0.1');

        $this->applyDebugSettings(['mode' => false, 'trusted_proxies' => null]);

        $this->assertSame('10.0.0.1', config('trustedproxy.proxies'));
    }

    /**
     * 키가 아예 없어도 주입하지 않습니다.
     *
     * 업그레이드 직후 저장 파일에 키가 없는 상태 — null 과 같게 다뤄야 한다.
     *
     * @scenario proxies_setting=unset
     *
     * @effects unset_setting_leaves_env_authority_intact
     */
    public function test_키가_없으면_env_값이_살아남는다(): void
    {
        Config::set('trustedproxy.proxies', '10.0.0.1');

        $this->applyDebugSettings(['mode' => false]);

        $this->assertSame('10.0.0.1', config('trustedproxy.proxies'));
    }

    /**
     * 빈 배열은 명시적 「신뢰하지 않음」이라 주입되어 `.env` 를 덮습니다.
     *
     * null 과 갈라지는 지점이다 — 여기서 둘이 같아지면 운영자가 화면에서 끈 설정이
     * 적용되지 않는다.
     *
     * @scenario proxies_setting=unset
     *
     * @effects saved_setting_overrides_env_value
     */
    public function test_빈_배열은_env_를_덮어_아무도_신뢰하지_않는다(): void
    {
        Config::set('trustedproxy.proxies', '10.0.0.1');

        $this->applyDebugSettings(['mode' => false, 'trusted_proxies' => []]);

        $this->assertNull(config('trustedproxy.proxies'));
    }

    /**
     * 저장한 목록이 `.env` 를 덮습니다.
     *
     * @scenario proxies_setting=cidr
     *
     * @effects saved_setting_overrides_env_value
     */
    public function test_저장한_목록이_env_를_덮는다(): void
    {
        Config::set('trustedproxy.proxies', '10.0.0.1');

        $this->applyDebugSettings([
            'mode' => false,
            'trusted_proxies' => ['192.168.0.0/16', '172.16.0.1'],
        ]);

        $this->assertSame('192.168.0.0/16,172.16.0.1', config('trustedproxy.proxies'));
    }

    /**
     * `.env` 우선 모드에서 잠긴 키는 주입되지 않습니다.
     *
     * @scenario proxies_setting=star
     *
     * @effects env_priority_locks_the_trusted_proxy_field
     */
    public function test_env_우선_모드에서는_저장값이_주입되지_않는다(): void
    {
        Config::set('env-priority.enabled', true);
        Config::set('env-priority.explicit', ['TRUSTED_PROXIES' => true]);
        Config::set('trustedproxy.proxies', '10.0.0.1');

        $this->applyDebugSettings(['mode' => false, 'trusted_proxies' => ['*']]);

        $this->assertSame('10.0.0.1', config('trustedproxy.proxies'), '잠긴 키는 주입 스킵');
    }

    // -------------------------------------------------------- `.env` 잠금 표시

    /**
     * 잠긴 설치의 표시값은 `.env` 유효값을 태그 배열로 바꾼 것입니다.
     *
     * 화면 계약은 배열 하나다 — 잠긴 설치만 문자열을 받으면 태그 입력이 그리지 못한다.
     *
     * @scenario proxies_setting=cidr
     *
     * @effects env_priority_locks_the_trusted_proxy_field
     */
    public function test_잠긴_설치의_표시값이_태그_배열이_된다(): void
    {
        Config::set('trustedproxy.proxies', '10.0.0.1, 10.0.0.0/24');

        $this->assertSame(
            ['10.0.0.1', '10.0.0.0/24'],
            EnvPriority::effectiveValue('debug.trusted_proxies')
        );
    }

    /**
     * 신뢰 프록시 키가 `.env` 우선 맵에 등재되어 있습니다.
     *
     * 등재를 잊으면 잠금이 조용히 미발동하고, 그 상태는 "그 키가 `.env` 에 없다" 와
     * 화면상 구분되지 않는다.
     *
     * @scenario proxies_setting=star
     *
     * @effects env_priority_locks_the_trusted_proxy_field
     */
    public function test_신뢰_프록시_키가_env_우선_맵에_등재되어_있다(): void
    {
        $this->assertArrayHasKey('debug.trusted_proxies', EnvPriority::MAP);
        $this->assertSame(['TRUSTED_PROXIES'], EnvPriority::MAP['debug.trusted_proxies']['env']);
        $this->assertContains('trustedproxy.proxies', EnvPriority::MAP['debug.trusted_proxies']['config']);
    }

    // -------------------------------------------------------------- 화면 표시

    /**
     * 저장값이 비어 있으면 화면에는 현재 적용 중인 값이 실립니다.
     *
     * 이것이 없으면 화면이 「신뢰하지 않음」을 보여주고, 운영자가 무관한 항목을 저장하는
     * 순간 그 빈 값이 함께 제출되어 `.env` 설정이 사라진다.
     *
     * @scenario proxies_setting=star
     *
     * @effects unset_setting_leaves_env_authority_intact
     */
    public function test_저장값이_없으면_화면에_적용중인_값이_실린다(): void
    {
        Config::set('trustedproxy.proxies', '*');

        $seeded = $this->seedDisplayValues(['advanced' => ['trusted_proxies' => null], 'debug' => ['trusted_proxies' => null]]);

        $this->assertSame(['*'], $seeded['advanced']['trusted_proxies']);
        $this->assertSame(['*'], $seeded['debug']['trusted_proxies'], '개별 접근용 카테고리도 같은 값이어야 한다');
    }

    /**
     * 저장값이 있으면 그 값을 태그 배열로 정규화해 싣습니다.
     *
     * @scenario proxies_setting=single_ip_match
     *
     * @effects saved_list_is_normalized_to_a_config_value
     */
    public function test_저장값이_있으면_그_값이_실린다(): void
    {
        Config::set('trustedproxy.proxies', '*');

        $seeded = $this->seedDisplayValues(['advanced' => ['trusted_proxies' => ['10.0.0.1']]]);

        $this->assertSame(['10.0.0.1'], $seeded['advanced']['trusted_proxies']);
    }

    /**
     * 설정 응답의 신뢰 프록시 채우기를 돌립니다.
     *
     * @param  array<string, mixed>  $settings  프론트엔드 키 변환이 끝난 설정 배열
     * @return array<string, mixed> 채워진 설정 배열
     */
    private function seedDisplayValues(array $settings): array
    {
        $service = app(SettingsService::class);

        return (new ReflectionMethod($service, 'seedTrustedProxiesFromRuntime'))->invoke($service, $settings);
    }

    // -------------------------------------------------------------- 저장 검증

    /**
     * 저장 규칙이 신뢰 프록시 목록과 항목 검증을 걸고 있습니다.
     *
     * 룰 클래스만 있고 배선이 빠지면 아무 값이나 저장되는데, 그 상태는 오류를 내지 않는다.
     *
     * @scenario proxies_setting=single_ip_mismatch
     *
     * @effects invalid_proxy_address_is_rejected_on_save
     */
    public function test_저장_규칙에_주소_검증이_걸려_있다(): void
    {
        $rules = (new SaveSettingsRequest)->rules();

        $this->assertArrayHasKey('advanced.trusted_proxies', $rules);
        $this->assertContains('array', $rules['advanced.trusted_proxies']);

        $itemRules = $rules['advanced.trusted_proxies.*'];
        $hasAddressRule = false;

        foreach ($itemRules as $rule) {
            if ($rule instanceof ValidTrustedProxyAddress) {
                $hasAddressRule = true;
            }
        }

        $this->assertTrue($hasAddressRule, '항목 검증 룰이 배선되지 않았다');
    }

    /**
     * 검증 룰이 잘못된 주소를 거부하고 올바른 주소를 통과시킵니다.
     *
     * @scenario proxies_setting=single_ip_mismatch
     *
     * @effects invalid_proxy_address_is_rejected_on_save
     */
    public function test_검증_룰이_잘못된_주소를_거부한다(): void
    {
        $rule = new ValidTrustedProxyAddress;

        $failed = null;
        $rule->validate('advanced.trusted_proxies.0', 'proxy.example.com', function ($message) use (&$failed) {
            $failed = $message;
        });
        $this->assertNotNull($failed, '호스트명은 거부되어야 한다');

        $passed = true;
        $rule->validate('advanced.trusted_proxies.0', '10.0.0.0/24', function () use (&$passed) {
            $passed = false;
        });
        $this->assertTrue($passed, 'CIDR 은 통과해야 한다');
    }

    /**
     * 고급 탭의 신뢰 프록시 필드가 debug 카테고리로 갈라집니다.
     *
     * 고급 탭은 여러 카테고리가 한 폼에 섞여 오고, 분류표에 없는 키는 **아무 카테고리에도
     * 담기지 않아 조용히 버려집니다** — 저장은 성공으로 끝나고 값만 사라집니다.
     *
     * @scenario proxies_setting=cidr
     *
     * @effects saved_setting_overrides_env_value
     */
    public function test_신뢰_프록시_필드가_debug_카테고리로_갈라진다(): void
    {
        $service = app(SettingsService::class);
        $map = (new ReflectionMethod($service, 'buildAdvancedCategoryFieldMap'))->invoke($service);

        $owning = [];

        foreach ($map as $category => $fields) {
            if (in_array('trusted_proxies', $fields, true)) {
                $owning[] = $category;
            }
        }

        $this->assertSame(['debug'], $owning, '저장 분류표가 이 키를 debug 로 보내지 않는다');
    }

    // ---------------------------------------------------------- 저장 전 검사

    /**
     * 사전 검사가 후보값의 해석 결과를 돌려줍니다.
     *
     * @scenario proxies_setting=star, forwarded_proto=https, forwarded_for=single
     *
     * @effects probe_reports_resolution_without_saving
     */
    public function test_사전_검사가_후보값의_해석_결과를_돌려준다(): void
    {
        Config::set('trustedproxy.proxies', null);

        $result = (new TrustedProxyProbe)->probe($this->forwardedRequest(), ['*']);

        $this->assertSame(self::REMOTE_ADDR, $result['current']['client_ip'], '현재는 프록시가 방문자로 보인다');
        $this->assertFalse($result['current']['is_secure']);

        $this->assertSame(self::CLIENT_IP, $result['result']['client_ip'], '후보값이면 진짜 방문자가 해석된다');
        $this->assertTrue($result['result']['is_secure']);
        $this->assertTrue($result['changed']);
        $this->assertSame(TrustedProxyProbe::VERDICT_RESOLVES, $result['verdict']);
    }

    /**
     * 목록이 앞단을 포함하지 않으면 바뀌지 않았다고 판정합니다.
     *
     * "검사는 돌았는데 아무 것도 안 바뀐다" 를 성공으로 보이면 운영자가 틀린 목록을
     * 그대로 저장한다.
     *
     * @scenario proxies_setting=single_ip_mismatch, forwarded_for=single
     *
     * @effects probe_reports_resolution_without_saving
     */
    public function test_목록이_앞단을_포함하지_않으면_변화없음으로_판정한다(): void
    {
        Config::set('trustedproxy.proxies', null);

        $result = (new TrustedProxyProbe)->probe($this->forwardedRequest(), ['192.0.2.77']);

        $this->assertSame(self::REMOTE_ADDR, $result['result']['client_ip']);
        $this->assertFalse($result['changed']);
        $this->assertSame(TrustedProxyProbe::VERDICT_UNCHANGED, $result['verdict']);
    }

    /**
     * 프록시 헤더가 없으면 판정할 것이 없다고 답합니다.
     *
     * 직접 노출 구성에서 「변화 없음」으로 답하면 있지도 않은 문제를 쫓게 된다.
     *
     * @scenario proxies_setting=star, forwarded_proto=absent, forwarded_for=absent
     *
     * @effects probe_reports_resolution_without_saving
     */
    public function test_프록시_헤더가_없으면_판정할_것이_없다고_답한다(): void
    {
        Config::set('trustedproxy.proxies', null);

        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => self::REMOTE_ADDR]);
        $result = (new TrustedProxyProbe)->probe($request, ['*']);

        $this->assertSame(TrustedProxyProbe::VERDICT_NO_HEADERS, $result['verdict']);
        $this->assertSame([], $result['forwarded_headers']);
    }

    /**
     * 검사는 전역 신뢰 상태와 설정값을 원래대로 돌려놓습니다.
     *
     * `Request::setTrustedProxies()` 는 정적이라 복원하지 않으면 같은 프로세스의 이후
     * 요청·테스트가 검사용 값으로 돌아간다 — 그 유출은 오류를 내지 않는다.
     *
     * @scenario proxies_setting=star
     *
     * @effects probe_restores_global_trust_state
     */
    public function test_검사가_전역_신뢰_상태를_원복한다(): void
    {
        Request::setTrustedProxies(['198.51.100.1'], Request::HEADER_X_FORWARDED_FOR);
        Config::set('trustedproxy.proxies', '198.51.100.1');

        (new TrustedProxyProbe)->probe($this->forwardedRequest(), ['*']);

        $this->assertSame(['198.51.100.1'], Request::getTrustedProxies());
        $this->assertSame(Request::HEADER_X_FORWARDED_FOR, Request::getTrustedHeaderSet());
        $this->assertSame('198.51.100.1', config('trustedproxy.proxies'));

        // 다음 테스트로 새지 않도록 이 케이스가 만든 상태를 스스로 지운다.
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }
}
