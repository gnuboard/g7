<?php

namespace Tests\Unit\Support;

use App\Support\OpcacheStatus;
use PHPUnit\Framework\TestCase;

/**
 * OPcache 상태 판정 SSoT 단위 테스트
 *
 * 인스톨러(요구사항 화면)와 코어(관리자 정보 탭)가 같은 답을 내야 하므로,
 * 이 클래스는 Laravel 부트 없이 동작해야 한다. 본 테스트가
 * `PHPUnit\Framework\TestCase` 를 직접 상속하는 것은 그 "의존성 0" 속성을
 * 검증하기 위함이다.
 *
 * 검증 축:
 *  - 확장 미로드 → enabled=false (확인 불가 아님)
 *  - 확장 로드 + opcache.enable=1 → enabled=true
 *  - 확장 로드 + opcache.enable=0 → enabled=false (로드만으로 판정하면 안 되는 케이스)
 *  - 확장 로드 + ini_get 차단(disable_functions) → enabled=null (확인 불가)
 */
class OpcacheStatusTest extends TestCase
{
    /**
     * @scenario opcache_state=not_loaded, surface=judgement_ssot
     *
     * @effects not_loaded_reports_disabled_not_unknown
     */
    public function test_extension_not_loaded_reports_disabled(): void
    {
        $result = OpcacheStatus::probe(
            extensionLoaded: fn () => false,
            iniGet: fn () => $this->fail('확장 미로드 시 ini_get 을 호출하면 안 됩니다.'),
        );

        $this->assertSame(['loaded' => false, 'enabled' => false], $result);
    }

    /**
     * @scenario opcache_state=enabled, surface=judgement_ssot
     *
     * @effects on_off_directive_values_parsed
     */
    public function test_loaded_and_enabled_reports_enabled(): void
    {
        $result = OpcacheStatus::probe(
            extensionLoaded: fn (string $name) => $name === OpcacheStatus::EXTENSION_NAME,
            iniGet: fn () => '1',
        );

        $this->assertSame(['loaded' => true, 'enabled' => true], $result);
    }

    /**
     * 확장이 로드돼 있어도 opcache.enable=0 이면 실제로는 동작하지 않는다.
     * 확장 로드 여부만으로 판정하는 회귀를 차단한다.
     *
     * @scenario opcache_state=loaded_but_disabled, surface=judgement_ssot
     *
     * @effects loaded_but_disabled_directive_reports_disabled
     */
    public function test_loaded_but_disabled_directive_reports_disabled(): void
    {
        $result = OpcacheStatus::probe(
            extensionLoaded: fn () => true,
            iniGet: fn () => '0',
        );

        $this->assertSame(['loaded' => true, 'enabled' => false], $result);
    }

    /**
     * ini_get 이 disable_functions 로 차단돼 Error 를 던지는 환경.
     * 경고도 차단도 아닌 "확인 불가"(null) 로 폴백해야 한다.
     *
     * @scenario opcache_state=ini_get_blocked, surface=judgement_ssot
     *
     * @effects ini_get_blocked_reports_unknown_null
     */
    public function test_ini_get_blocked_reports_unknown(): void
    {
        $result = OpcacheStatus::probe(
            extensionLoaded: fn () => true,
            iniGet: fn () => throw new \Error('Call to undefined function ini_get()'),
        );

        $this->assertSame(['loaded' => true, 'enabled' => null], $result);
    }

    /**
     * ini_get 은 지시자를 못 읽으면 false 를 반환한다. 값 '0'(비활성 확정) 과
     * 구분해 "확인 불가" 로 처리해야 한다.
     *
     * @effects ini_get_false_distinguished_from_value_zero
     */
    public function test_ini_get_returning_false_reports_unknown(): void
    {
        $result = OpcacheStatus::probe(
            extensionLoaded: fn () => true,
            iniGet: fn () => false,
        );

        $this->assertSame(['loaded' => true, 'enabled' => null], $result);
    }

    public function test_ini_get_returning_empty_string_reports_unknown(): void
    {
        $result = OpcacheStatus::probe(
            extensionLoaded: fn () => true,
            iniGet: fn () => '',
        );

        $this->assertSame(['loaded' => true, 'enabled' => null], $result);
    }

    /**
     * php.ini 는 On/Off 표기도 허용한다.
     */
    public function test_on_off_directive_values_are_parsed(): void
    {
        $this->assertTrue(
            OpcacheStatus::probe(fn () => true, fn () => 'On')['enabled']
        );
        $this->assertFalse(
            OpcacheStatus::probe(fn () => true, fn () => 'Off')['enabled']
        );
    }

    /**
     * 인수 없이 호출해도 예외 없이 계약된 구조를 반환해야 한다
     * (실행 환경의 실제 OPcache 상태와 무관하게 형태만 검증).
     */
    public function test_default_probe_returns_contracted_shape(): void
    {
        $result = OpcacheStatus::probe();

        $this->assertArrayHasKey('loaded', $result);
        $this->assertArrayHasKey('enabled', $result);
        $this->assertIsBool($result['loaded']);
        $this->assertTrue($result['enabled'] === null || is_bool($result['enabled']));
    }

    /**
     * 인스톨러가 Laravel 오토로드 없이 `require_once` 로 로드할 수 있어야 하므로,
     * 소스에 프레임워크 심볼 참조가 없어야 한다.
     */
    public function test_source_has_no_framework_dependency(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/app/Support/OpcacheStatus.php');

        $this->assertIsString($source);

        foreach (['use Illuminate', 'Illuminate\\', 'config(', 'app(', '__(', 'trans('] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                sprintf('인스톨러 공용 클래스에 프레임워크 의존("%s")이 포함됐습니다.', $forbidden)
            );
        }
    }
}
