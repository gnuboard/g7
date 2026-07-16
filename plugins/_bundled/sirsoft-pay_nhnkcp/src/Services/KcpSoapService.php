<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Services;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\PayNhnkcp\Exceptions\NhnKcpApiException;
use SoapClient;
use SoapFault;

/**
 * KCP SmartPhone Pay SOAP 서비스
 *
 * 모바일 결제창 호출 전 서버에서 approval_key 와 pay_url 을 획득합니다.
 * PC Standard Pay(payplus_web.jsp iframe)와 달리 모바일은 2-step 방식:
 *   1) SOAP → approval_key + pay_url
 *   2) 브라우저 form submit → pay_url (페이지 전환)
 */
class KcpSoapService
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';

    private const LIVE_SITE_CD_PREFIX = 'SR';

    private const WSDL_TEST = 'KCPPaymentService.wsdl';

    private const WSDL_LIVE = 'real_KCPPaymentService.wsdl';

    private bool $isTest;

    private string $siteCd;

    private string $escrowSiteCd;

    private string $easyPaySiteCd;

    private string $binDir;

    public function __construct(PluginSettingsService $pluginSettingsService)
    {
        $settings = $pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $this->isTest = $settings['is_test_mode'] ?? true;

        $liveSuffix = $settings['live_site_cd'] ?? '';
        $liveSiteCd = str_starts_with($liveSuffix, self::LIVE_SITE_CD_PREFIX)
            ? $liveSuffix
            : self::LIVE_SITE_CD_PREFIX . $liveSuffix;

        $this->siteCd = $this->isTest
            ? ($settings['test_site_cd'] ?? 'T0000')
            : $liveSiteCd;

        if ($this->isTest) {
            $escrowTestSiteCd = trim((string) ($settings['escrow_test_site_cd'] ?? ''));
            $this->escrowSiteCd = $escrowTestSiteCd !== '' ? $escrowTestSiteCd : $this->siteCd;
        } else {
            $this->escrowSiteCd = $liveSiteCd;
        }

        $easyPayTestSiteCd = $settings['easy_pay_test_site_cd'] ?? 'S6729';
        $this->easyPaySiteCd = $this->isTest ? $easyPayTestSiteCd : $liveSiteCd;

        $this->binDir = dirname(__DIR__, 2) . '/bin';
    }

    /**
     * KCP 모바일 결제 승인키 획득 (SOAP)
     *
     * @param  string  $orderNumber    주문번호
     * @param  string  $goodName       상품명
     * @param  int     $amount         결제 금액
     * @param  string  $payMethod      결제수단 코드 (CARD/BANK/MOBX/VCNT)
     * @param  string  $retUrl         결제 결과 콜백 URL
     * @param  string  $payMethodKey   원본 결제수단 키 (nhnkcp_payco 등, site_cd 분기용)
     * @param  bool    $escrow         에스크로 여부
     * @return array{approval_key: string, pay_url: string}
     *
     * @throws NhnKcpApiException
     */
    public function getApprovalKey(
        string $orderNumber,
        string $goodName,
        int $amount,
        string $payMethod,
        string $retUrl,
        string $payMethodKey = '',
        bool $escrow = false,
    ): array {
        $wsdlFile = $this->binDir . DIRECTORY_SEPARATOR . ($this->isTest ? self::WSDL_TEST : self::WSDL_LIVE);

        if (! file_exists($wsdlFile)) {
            throw new NhnKcpApiException(__('sirsoft-pay_nhnkcp::messages.errors.wsdl_missing', ['file' => $wsdlFile]));
        }

        $siteCd = $escrow ? $this->escrowSiteCd : $this->resolveSiteCd($payMethodKey);

        try {
            $soapClient = new SoapClient($wsdlFile, [
                'trace' => false,
                'exceptions' => true,
                'connection_timeout' => 15,
                'encoding' => 'UTF-8',
            ]);

            $req = new \stdClass();
            $req->accessCredentialType = (object) [
                'accessLicense' => '',
                'signature' => '',
                'timestamp' => '',
            ];
            $req->baseRequestType = (object) [
                'detailLevel' => '0',
                'requestApp' => 'WEB',
                'requestID' => $orderNumber,
                'userAgent' => request()->userAgent() ?? '',
                'version' => '0.1',
            ];
            $req->escrow = $escrow;
            $req->orderID = $orderNumber;
            $req->paymentAmount = (string) $amount;
            $req->paymentMethod = $payMethod;
            $req->productName = $goodName;
            $req->returnUrl = $retUrl;
            $req->siteCode = $siteCd;

            $param = new \stdClass();
            $param->req = $req;

            $response = $soapClient->__soapCall('approve', [$param], [
                'uri' => 'http://webservice.act.webpay.service.kcp.kr',
                'soapaction' => '',
            ]);

            $resCode = $response->return->baseResponseType->error->code ?? '9999';
            $resMsg  = (string) ($response->return->baseResponseType->error->message ?? '연동 오류');

            if ($resCode !== '0000') {
                Log::warning('[nhnkcp] mobile SOAP approval error', [
                    'order' => $orderNumber,
                    'res_cd' => $resCode,
                    'res_msg' => $resMsg,
                    'site_cd' => $siteCd,
                ]);
                throw new NhnKcpApiException(__('sirsoft-pay_nhnkcp::messages.errors.approval_key_error', ['code' => $resCode, 'message' => $resMsg]));
            }

            return [
                'approval_key' => (string) ($response->return->approvalKey ?? ''),
                'pay_url' => (string) ($response->return->payUrl ?? ''),
            ];

        } catch (SoapFault $e) {
            Log::error('[nhnkcp] SOAP fault', [
                'order' => $orderNumber,
                'error' => $e->getMessage(),
            ]);
            throw new NhnKcpApiException(__('sirsoft-pay_nhnkcp::messages.errors.soap_error', ['message' => $e->getMessage()]), 0, $e);
        }
    }

    /**
     * 결제수단 키에 따른 site_cd 반환
     *
     * 레거시 settle_kcp.inc.php 규칙: PAYCO만 간편결제 전용 site_cd(테스트: S6729) 사용,
     * NaverPay/KakaoPay/ApplePay 는 일반 site_cd(테스트: T0000) 사용.
     *
     * @param  string  $payMethodKey  결제수단 키 (nhnkcp_payco / nhnkcp_naverpay 등)
     * @return string KCP site_cd
     */
    public function getSiteCd(string $payMethodKey = ''): string
    {
        return $this->resolveSiteCd($payMethodKey);
    }

    /**
     * 에스크로 전용 site_cd 반환
     *
     * @return string 에스크로 전용 KCP site_cd
     */
    public function getEscrowSiteCd(): string
    {
        return $this->escrowSiteCd;
    }

    private function resolveSiteCd(string $payMethodKey): string
    {
        // PAYCO만 전용 간편결제 site_cd 사용 (레거시 settle_kcp.inc.php 동일)
        if ($payMethodKey === 'nhnkcp_payco') {
            return $this->easyPaySiteCd;
        }

        return $this->siteCd;
    }
}
