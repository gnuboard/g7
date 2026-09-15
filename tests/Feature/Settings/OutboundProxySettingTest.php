<?php

namespace Tests\Feature\Settings;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Http\Requests\Settings\SaveSettingsRequest;
use App\Providers\AppServiceProvider;
use App\Services\OutboundProxyTester;
use App\Services\SettingsService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Validator;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * 아웃바운드 프록시 설정의 저장·검증·적용을 고정합니다.
 *
 * 이 설정은 화면 한 곳에서 입력되지만 네 지점을 지나야 실제로 동작합니다 — 검증(FormRequest),
 * 분류 저장(고급 탭 → debug 카테고리), 판정(OutboundProxy), 적용(Http 전역 옵션). 어느 한
 * 지점이 빠지면 "저장은 되는데 적용되지 않는" 상태가 되고, 저장 응답은 200 이라 화면에도
 * API 에도 실패가 드러나지 않습니다.
 *
 * 축 조합은 각 테스트 메서드의 @scenario 주석이 담당한다.
 *
 * @effects proxy_setting_persists_to_debug_category
 * @effects proxy_setting_rejects_invalid_url
 * @effects proxy_applied_to_global_http_options
 * @effects proxy_absent_leaves_global_http_options_untouched
 * @effects proxy_connection_test_reports_egress_ip
 */
class OutboundProxySettingTest extends TestCase
{
    /**
     * 고급 탭에서 저장한 프록시 값이 debug 카테고리에 기록되는지 확인합니다.
     *
     * @scenario debug_mode=on, proxy_value=valid, bypass_list=normalizable
     *
     * @effects proxy_setting_persists_to_debug_category
     */
    public function test_proxy_settings_saved_from_advanced_tab_are_persisted(): void
    {
        $saved = app(SettingsService::class)->saveSettings([
            '_tab' => 'advanced',
            'advanced' => [
                'outbound_proxy' => 'socks5h://127.0.0.1:1080',
                'outbound_proxy_bypass' => ['g7.dev', 'localhost'],
            ],
        ]);

        $this->assertTrue($saved, '고급 탭 저장이 실패했습니다.');

        $debug = app(ConfigRepositoryInterface::class)->getCategory('debug');

        $this->assertSame(
            'socks5h://127.0.0.1:1080',
            $debug['outbound_proxy'] ?? null,
            'debug.outbound_proxy 가 저장되지 않았습니다 — 고급 탭 분류표에서 누락되면 값이 조용히 버려집니다.'
        );
        $this->assertSame(['g7.dev', 'localhost'], $debug['outbound_proxy_bypass'] ?? null);
    }

    /**
     * 기존 설치본에도 새 키가 기본값으로 노출되는지 확인합니다.
     *
     * 설정 JSON 은 defaults 와 병합되어 읽히므로 별도 마이그레이션 없이 키가 생겨야 합니다.
     *
     * @scenario debug_mode=on, proxy_value=empty, bypass_list=empty
     *
     * @effects proxy_setting_persists_to_debug_category
     */
    public function test_new_keys_are_present_through_defaults_merge(): void
    {
        $debug = app(ConfigRepositoryInterface::class)->getCategory('debug');

        $this->assertArrayHasKey('outbound_proxy', $debug);
        $this->assertArrayHasKey('outbound_proxy_bypass', $debug);
        $this->assertSame('', $debug['outbound_proxy']);
        $this->assertSame([], $debug['outbound_proxy_bypass']);
    }

    /**
     * 적용할 수 없는 형태의 주소는 저장 검증에서 막습니다.
     *
     * @scenario debug_mode=on, proxy_value=invalid, bypass_list=empty
     *
     * @effects proxy_setting_rejects_invalid_url
     */
    public function test_invalid_proxy_url_fails_validation(): void
    {
        $validator = $this->validatorForAdvanced([
            'outbound_proxy' => 'ftp://proxy.internal:21',
        ]);

        $this->assertTrue(
            $validator->fails(),
            '허용 목록에 없는 스킴이 검증을 통과했습니다 — 저장은 되고 적용은 안 되는 상태가 됩니다.'
        );
        $this->assertArrayHasKey('advanced.outbound_proxy', $validator->errors()->messages());
    }

    /**
     * 비문자열 값 거부 시에도 오류 문구의 :schemes 치환 자리가 채워집니다.
     *
     * 비문자열 분기가 치환 파라미터 없이 번역을 호출하면 번역기가 자리표시자를 그대로 둔
     * 문장을 돌려줘 관리자 화면에 `:schemes` 리터럴이 노출됩니다 — 실패했을 때만 드러나는
     * 결함이라 정상 흐름 테스트로는 잡히지 않습니다.
     *
     * @scenario debug_mode=on, proxy_value=invalid, bypass_list=empty
     *
     * @effects proxy_setting_rejects_invalid_url
     */
    public function test_non_string_proxy_value_error_message_has_schemes_substituted(): void
    {
        $validator = $this->validatorForAdvanced([
            'outbound_proxy' => ['not', 'a', 'string'],
        ]);

        $this->assertTrue($validator->fails(), '비문자열 프록시 값이 검증을 통과했습니다.');

        $messages = $validator->errors()->get('advanced.outbound_proxy');
        $this->assertNotEmpty($messages);

        foreach ($messages as $message) {
            $this->assertStringNotContainsString(
                ':schemes',
                $message,
                '치환 자리가 채워지지 않아 :schemes 리터럴이 관리자 화면에 노출됩니다.'
            );
        }
    }

    /**
     * 유효한 주소와 빈 값은 검증을 통과합니다.
     *
     * @scenario debug_mode=on, proxy_value=valid, bypass_list=empty
     *
     * @effects proxy_setting_rejects_invalid_url
     */
    public function test_valid_and_empty_proxy_url_pass_validation(): void
    {
        foreach (['socks5h://127.0.0.1:1080', 'http://proxy.internal:3128', ''] as $url) {
            $validator = $this->validatorForAdvanced(['outbound_proxy' => $url]);

            $this->assertFalse(
                $validator->errors()->has('advanced.outbound_proxy'),
                "유효한 주소 '{$url}' 가 거부됐습니다."
            );
        }
    }

    /**
     * 예외 목록의 각 항목이 문자열·길이 검증을 받는지 확인합니다.
     *
     * @scenario debug_mode=on, proxy_value=valid, bypass_list=normalizable
     *
     * @effects proxy_setting_rejects_invalid_url
     */
    public function test_bypass_list_items_are_validated(): void
    {
        $validator = $this->validatorForAdvanced([
            'outbound_proxy_bypass' => [str_repeat('a', 256)],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('advanced.outbound_proxy_bypass.0', $validator->errors()->messages());
    }

    /**
     * 판정 결과가 있으면 Http 전역 옵션에 프록시가 실립니다.
     *
     * @scenario debug_mode=on, proxy_value=valid, bypass_list=normalizable
     *
     * @effects proxy_applied_to_global_http_options
     */
    public function test_resolved_proxy_is_applied_to_global_http_options(): void
    {
        $proxy = [
            'http' => 'socks5h://127.0.0.1:1080',
            'https' => 'socks5h://127.0.0.1:1080',
            'no' => ['g7.dev'],
        ];

        config(['g7.outbound_proxy' => $proxy]);
        $this->invokeOutboundProxyConfigurer();

        $this->assertSame(
            ['proxy' => $proxy],
            $this->globalHttpOptions(),
            'Http 전역 옵션에 프록시가 실리지 않았습니다 — 설정은 저장되어도 실제 요청은 그대로 나갑니다.'
        );
    }

    /**
     * 판정 결과가 없으면 Http 전역 옵션을 건드리지 않습니다.
     *
     * 디버그 모드가 꺼져 있으면 판정이 null 을 돌려주므로, 이 경로가 프록시 미적용을 보장합니다.
     *
     * @scenario debug_mode=off, proxy_value=valid, bypass_list=empty
     *
     * @effects proxy_absent_leaves_global_http_options_untouched
     */
    public function test_absent_proxy_leaves_global_http_options_untouched(): void
    {
        foreach ([null, [], ''] as $value) {
            config(['g7.outbound_proxy' => $value]);
            $this->invokeOutboundProxyConfigurer();

            $this->assertSame(
                [],
                $this->globalHttpOptions(),
                '프록시 미적용 상태인데 Http 전역 옵션이 설정됐습니다.'
            );
        }
    }

    /**
     * 고급 탭 검증기를 구성합니다.
     *
     * @param  array<string, mixed>  $advanced  advanced 탭 입력값
     */
    private function validatorForAdvanced(array $advanced): Validator
    {
        $request = new SaveSettingsRequest;
        $request->merge(['_tab' => 'advanced', 'advanced' => $advanced]);
        $request->setContainer(app());

        return validator(
            $request->all(),
            $request->rules(),
            $request->messages(),
            $request->attributes()
        );
    }

    /**
     * AppServiceProvider 의 프록시 적용 로직만 실행합니다.
     */
    private function invokeOutboundProxyConfigurer(): void
    {
        $this->resetGlobalHttpOptions();

        $provider = new AppServiceProvider($this->app);
        $method = new ReflectionMethod($provider, 'configureOutboundProxy');
        $method->setAccessible(true);
        $method->invoke($provider);
    }

    /**
     * Http 팩토리의 전역 옵션을 비웁니다.
     */
    private function resetGlobalHttpOptions(): void
    {
        Http::globalOptions([]);
    }

    /**
     * Http 팩토리에 설정된 전역 옵션을 읽습니다.
     *
     * @return array<string, mixed>
     */
    private function globalHttpOptions(): array
    {
        $factory = $this->app->make(Factory::class);
        $property = new ReflectionProperty($factory, 'globalOptions');
        $property->setAccessible(true);

        return (array) value($property->getValue($factory));
    }

    /**
     * 연결 테스트가 프록시를 거친 출발지 IP 를 보고합니다.
     *
     * 출발지 IP 는 운영자가 결제사에 등록해야 하는 값이라, 저장하기 전에 알 수 있어야 합니다.
     *
     * @scenario debug_mode=on, proxy_value=valid, bypass_list=empty
     *
     * @effects proxy_connection_test_reports_egress_ip
     */
    public function test_connection_test_reports_egress_ip(): void
    {
        Http::fake(['*' => Http::response('203.0.113.9', 200)]);

        $result = app(OutboundProxyTester::class)->test('socks5h://127.0.0.1:1080');

        $this->assertTrue($result['success']);
        $this->assertSame('203.0.113.9', $result['egress_ip']);
        $this->assertSame('settings.outbound_proxy_test_success', $result['message_key']);
    }

    /**
     * 연결 테스트는 제출된 값을 검사합니다 — 저장된 설정이나 전역 옵션을 보지 않습니다.
     *
     * @scenario debug_mode=on, proxy_value=valid, bypass_list=normalizable
     *
     * @effects proxy_connection_test_reports_egress_ip
     */
    public function test_connection_test_uses_submitted_proxy_not_stored_settings(): void
    {
        config(['g7.outbound_proxy' => null]);
        Http::globalOptions([]);

        Http::fake(['*' => Http::response('203.0.113.9', 200)]);

        $result = app(OutboundProxyTester::class)->test('socks5h://10.0.0.9:1080', ['g7.dev']);

        // 전역 프록시가 없는 상태에서도 제출값으로 요청이 나갔다.
        Http::assertSentCount(1);
        $this->assertTrue($result['success']);

        // 전역 옵션은 비어 있는 채여야 한다 — 테스트가 전역 상태를 바꾸면 이후 모든 요청이
        // 저장하지도 않은 프록시를 타게 된다.
        $this->assertSame([], $this->globalHttpOptions());
    }

    /**
     * 적용 불가 주소는 외부 호출 없이 즉시 거부합니다.
     *
     * @scenario debug_mode=on, proxy_value=invalid, bypass_list=empty
     *
     * @effects proxy_connection_test_reports_egress_ip
     */
    public function test_connection_test_rejects_invalid_url_without_calling_out(): void
    {
        Http::fake();

        $result = app(OutboundProxyTester::class)->test('ftp://proxy.internal:21');

        $this->assertFalse($result['success']);
        $this->assertSame('settings.outbound_proxy_test_invalid_url', $result['message_key']);
        Http::assertNothingSent();
    }

    /**
     * 조회 대상이 없으면 확인 불가로 보고합니다 — 실패와 구분되어야 합니다.
     *
     * @scenario debug_mode=on, proxy_value=valid, bypass_list=empty
     *
     * @effects proxy_connection_test_reports_egress_ip
     */
    public function test_connection_test_reports_when_no_lookup_target_is_configured(): void
    {
        config(['core.outbound_proxy.egress_lookup_urls' => []]);
        Http::fake();

        $result = app(OutboundProxyTester::class)->test('socks5h://127.0.0.1:1080');

        $this->assertFalse($result['success']);
        $this->assertSame('settings.outbound_proxy_test_no_lookup_url', $result['message_key']);
        Http::assertNothingSent();
    }
}
