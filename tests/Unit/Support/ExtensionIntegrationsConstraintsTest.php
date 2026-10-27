<?php

namespace Tests\Unit\Support;

use App\Support\ExtensionIntegrations;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 선택 연동 선언(`integrations`)의 버전 제약 정규화 (#822 P8·D5)
 *
 * 7.1.4 부터 `integrations` 는 `dependencies` 와 같은 수준의 스펙이다 — 객체형 `{"modules": {"sirsoft-board": ">=1.2.1"}}`
 * 으로 버전 제약을 선언한다. 목록형은 「버전 무관(*)」으로 계속 받고, 값이 버전식이 아닌 옛 설명형 문자열도
 * 「버전 무관」으로 받되 경고를 남긴다. 버전 미달은 막지 않고 안내만 한다.
 */
class ExtensionIntegrationsConstraintsTest extends TestCase
{
    /**
     * 목록형은 버전 무관(*), 객체형은 제약 문자열을 그대로 싣고 세 유형을 모두 받는다.
     *
     * @scenario case=integration_list_and_version_object
     *
     * @effects integration_constraints_are_normalized
     *
     * @return void 반환값 없음
     */
    public function test_list_and_version_object_shapes(): void
    {
        $this->assertSame(
            [
                'modules' => ['sirsoft-board' => '>=1.2.1', 'sirsoft-ecommerce' => '^1.3'],
                'plugins' => ['sirsoft-daum_postcode' => '*'],
                'templates' => ['sirsoft-basic' => '*'],
            ],
            ExtensionIntegrations::constraints([
                'modules' => ['sirsoft-board' => '>=1.2.1', 'sirsoft-ecommerce' => ' ^1.3 '],
                'plugins' => ['sirsoft-daum_postcode', 'sirsoft-daum_postcode'],
                'templates' => ['sirsoft-basic' => ''],
            ]),
        );
    }

    /**
     * 버전식이 아닌 설명형 값은 버전 무관(*)으로 받고 경고를 한 번 남긴다 — 같은 값은 다시 경고하지 않는다.
     *
     * @scenario case=integration_descriptive_value
     *
     * @effects descriptive_integration_value_is_any_version_with_warning
     *
     * @return void 반환값 없음
     */
    public function test_descriptive_value_becomes_any_version_with_warning(): void
    {
        Log::spy();

        $raw = ['modules' => ['acme-board' => '기본 템플릿 화면에 탭을 끼운다'], 'plugins' => ['acme-sms' => ['nested']]];

        $this->assertSame(
            ['modules' => ['acme-board' => '*'], 'plugins' => ['acme-sms' => '*'], 'templates' => []],
            ExtensionIntegrations::constraints($raw),
        );
        ExtensionIntegrations::constraints($raw);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => ($context['identifier'] ?? null) === 'acme-board'
                && ($context['value'] ?? null) === '기본 템플릿 화면에 탭을 끼운다')
            ->once();
    }

    /**
     * 모르는 유형·형식이 틀린 식별자·잘못된 모양은 버린다 — 식별자 목록 정규화(normalize)와 같은 규칙.
     *
     * @scenario case=integration_malformed
     *
     * @effects malformed_integration_constraints_are_ignored
     *
     * @return void 반환값 없음
     */
    public function test_malformed_input_is_ignored(): void
    {
        $empty = ['modules' => [], 'plugins' => [], 'templates' => []];

        $this->assertSame($empty, ExtensionIntegrations::constraints(null));
        $this->assertSame($empty, ExtensionIntegrations::constraints('sirsoft-board'));
        $this->assertSame(
            ['modules' => [], 'plugins' => ['sirsoft-gdpr' => '>=1.0'], 'templates' => []],
            ExtensionIntegrations::constraints([
                'widgets' => ['sirsoft-x' => '>=1.0'],
                'modules' => 'sirsoft-board',
                'plugins' => ['sirsoft-gdpr' => '>=1.0', 'NoVendor' => '>=1.0', '../evil' => '*'],
            ]),
        );
        $this->assertSame(
            ['modules' => [], 'plugins' => [], 'templates' => []],
            ExtensionIntegrations::constraints(['modules' => [42, null, 'NoVendor']]),
        );
    }

    /**
     * 정규화 결과를 다시 넣어도 같은 결과다 — 화면 보강·설치 창이 정규화 결과를 다시 받는다.
     * 식별자 목록 정규화(normalize)도 객체형 선언에서 식별자만 뽑아 하위 호환을 지킨다.
     *
     * @scenario case=integration_idempotent
     *
     * @effects integration_constraints_are_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_normalized_constraints_are_idempotent_and_normalize_stays_compatible(): void
    {
        $once = ExtensionIntegrations::constraints(['modules' => ['sirsoft-board' => '>=1.2.1'], 'templates' => ['sirsoft-basic']]);

        $this->assertSame($once, ExtensionIntegrations::constraints($once));
        $this->assertSame(
            ['modules' => ['sirsoft-board'], 'plugins' => [], 'templates' => ['sirsoft-basic']],
            ExtensionIntegrations::normalize($once),
        );
        $this->assertSame('modules', ExtensionIntegrations::pluralType('module'));
        $this->assertSame('templates', ExtensionIntegrations::pluralType('templates'));
        $this->assertNull(ExtensionIntegrations::pluralType('widget'));
    }
}
