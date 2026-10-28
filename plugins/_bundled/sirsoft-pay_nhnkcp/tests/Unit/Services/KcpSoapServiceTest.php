<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Unit\Services;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\PayNhnkcp\Exceptions\NhnKcpApiException;
use Plugins\Sirsoft\PayNhnkcp\Services\KcpSoapService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * 모바일 승인키 발급 SOAP 서비스가 PHP soap 확장 부재를 안내 예외로 바꾸는지 검증한다 (#151)
 *
 * soap 확장이 없으면 `new SoapClient` 가 치명 오류(Class not found)가 되어 컨트롤러의
 * `NhnKcpApiException` 분기를 지나치고 500 이 났다. 고객에게는 일반 문구만, 원인은 로그에 남긴다.
 *
 * 시나리오 매니페스트: `tests/scenarios/mobile-approval-without-soap.yaml`
 */
class KcpSoapServiceTest extends PluginTestCase
{
    /**
     * soap 사용 가능 여부를 지정한 부분 mock 서비스를 만듭니다.
     *
     * @param  bool  $soapAvailable  soap 확장 사용 가능 여부
     * @return KcpSoapService 서비스(부분 mock)
     */
    private function makeService(bool $soapAvailable): KcpSoapService
    {
        $settings = $this->createMock(PluginSettingsService::class);
        $settings->method('get')->willReturn(['is_test_mode' => true, 'test_site_cd' => 'T0000']);

        $service = $this->getMockBuilder(KcpSoapService::class)
            ->setConstructorArgs([$settings])
            ->onlyMethods(['soapAvailable'])
            ->getMock();
        $service->method('soapAvailable')->willReturn($soapAvailable);

        return $service;
    }

    /**
     * soap 확장이 없으면 일반 안내 문구 예외를 던지고 원인을 오류 로그에 남깁니다.
     *
     * @return void 반환값 없음
     *
     * @scenario soap=absent
     *
     * @effects missing_soap_throws_generic_customer_message, missing_soap_cause_is_logged
     */
    public function test_missing_soap_extension_throws_generic_message_and_logs_cause(): void
    {
        Log::spy();
        $service = $this->makeService(false);

        try {
            $service->getApprovalKey('ORD-151', '상품', 10000, 'CARD', 'https://example.test/callback');
            $this->fail('NhnKcpApiException 이 발생해야 합니다.');
        } catch (NhnKcpApiException $e) {
            $this->assertSame(__('sirsoft-pay_nhnkcp::messages.errors.mobile_payment_unavailable'), $e->getMessage());
            $this->assertStringNotContainsString('soap', strtolower($e->getMessage()), '고객 문구에 서버 구성 정보를 노출하지 않는다');
        }

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, 'soap'))
            ->once();
    }

    /**
     * 고객 안내 문구는 ko·en 모두 번역돼 있어 키가 그대로 노출되지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario soap=absent
     *
     * @effects missing_soap_throws_generic_customer_message
     */
    public function test_generic_message_is_translated_in_ko_and_en(): void
    {
        foreach (['ko', 'en'] as $locale) {
            $message = __('sirsoft-pay_nhnkcp::messages.errors.mobile_payment_unavailable', [], $locale);
            $this->assertNotSame('sirsoft-pay_nhnkcp::messages.errors.mobile_payment_unavailable', $message, "{$locale} 번역 누락");
        }
    }

    /**
     * 플러그인 src 의 `new SoapClient` 는 모두 `soapAvailable()` 확인이 있는 메서드 안에 있습니다. (정적 잠금)
     *
     * soap 은 composer.json suggest(권장)라 설치를 막지 않는다 — 가드 없는 생성이 다시 들어오면 soap 없는 서버에서 500 이 된다.
     *
     * @return void 반환값 없음
     *
     * @scenario soap=absent
     *
     * @effects soap_client_is_created_only_behind_guard
     */
    public function test_soap_client_is_created_only_behind_soap_available_guard(): void
    {
        $src = dirname(__DIR__, 3).'/src';
        $sites = 0;
        $unguarded = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = (string) file_get_contents($file->getPathname());
            // 메서드 단위로 자른다 — 생성이 든 메서드 본문에 soapAvailable() 확인이 있어야 한다
            foreach (preg_split('/\n    (?:public|protected|private)(?: static)? function /', $content) as $method) {
                if (preg_match('/new\s+\\\\?SoapClient\b/', $method)) {
                    $sites++;
                    if (! str_contains($method, 'soapAvailable()')) {
                        $unguarded[] = $file->getFilename();
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $sites, 'SoapClient 생성 지점을 찾지 못했습니다 — 판정식 점검 필요');
        $this->assertSame([], $unguarded);
    }

    /**
     * soap 확장이 있으면 가드를 지나 기존 흐름(WSDL 확인)으로 진행합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario soap=present
     *
     * @effects soap_present_keeps_existing_flow
     */
    public function test_soap_available_proceeds_to_existing_flow(): void
    {
        $this->assertTrue(class_exists(\SoapClient::class), '이 테스트는 soap 확장이 있는 PHP 에서 기존 흐름을 확인한다');

        $service = $this->makeService(true);
        $reflection = new \ReflectionMethod(KcpSoapService::class, 'soapAvailable');

        $this->assertTrue($reflection->isProtected(), '테스트가 덮을 수 있도록 protected 로 둔다');
        $this->assertTrue($reflection->invoke(new KcpSoapService($this->createMock(PluginSettingsService::class))));
        $this->assertInstanceOf(KcpSoapService::class, $service);
    }
}
