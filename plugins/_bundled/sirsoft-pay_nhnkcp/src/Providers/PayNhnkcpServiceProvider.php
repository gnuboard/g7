<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Providers;

use App\Extension\BasePluginServiceProvider;
use Illuminate\Cookie\Middleware\EncryptCookies;

class PayNhnkcpServiceProvider extends BasePluginServiceProvider
{
    protected string $pluginIdentifier = 'sirsoft-pay_nhnkcp';

    /**
     * NHN KCP 플러그인을 부팅합니다 — 영수증 쿠키를 쿠키 암호화 대상에서 뺍니다(활성일 때만).
     *
     * @return void 반환값 없음
     */
    public function boot(): void
    {
        parent::boot();

        // PG callback 이 발급하는 영수증 쿠키는 HMAC 으로 자체 무결성 보장하므로
        // EncryptCookies 대상에서 제외한다. 암호화 대상이면 callback 응답에서
        // 직접 발급한 평문 쿠키가 다음 API 요청에서 복호화 실패로 폐기될 수 있다.
        // 값 동기화: IssuesReceiptCookie::RECEIPT_COOKIE_NAME 과 동일해야 함.
        // 비활성이면 걸지 않는다 (#730 게이트, #860)
        if (! method_exists($this, 'isExtensionActive') || $this->isExtensionActive()) {
            EncryptCookies::except(['nhnkcp_receipt_token']);
        }
    }
}
