<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Providers;

use App\Extension\BasePluginServiceProvider;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Plugins\Sirsoft\PayKginicis\Repositories\CbtReconciliationRepository;
use Plugins\Sirsoft\PayKginicis\Repositories\CbtReconciliationRepositoryInterface;
use Plugins\Sirsoft\PayKginicis\Repositories\CbtCvsOperationsRepository;
use Plugins\Sirsoft\PayKginicis\Repositories\CbtCvsOperationsRepositoryInterface;

class PayKginicisServiceProvider extends BasePluginServiceProvider
{
    protected string $pluginIdentifier = 'sirsoft-pay_kginicis';

    /**
     * Repository 인터페이스 ↔ 구현체 매핑.
     *
     * @var array<class-string, class-string>
     */
    protected array $repositories = [
        CbtReconciliationRepositoryInterface::class => CbtReconciliationRepository::class,
        CbtCvsOperationsRepositoryInterface::class => CbtCvsOperationsRepository::class,
    ];

    /**
     * KG 이니시스 플러그인 부팅 처리를 수행합니다.
     *
     * @return void
     */
    public function boot(): void
    {
        parent::boot();

        // PG callback 이 발급하는 영수증 쿠키는 HMAC 으로 자체 무결성 보장하므로
        // Laravel 의 EncryptCookies 미들웨어 대상에서 제외 (암호화 시 PG callback
        // 응답에서 평문으로 직접 발급한 값이 EncryptCookies 의 복호화 실패로 폐기되는 것 방지).
        // 값 동기화: IssuesReceiptCookie::RECEIPT_COOKIE_NAME 과 동일해야 함.
        EncryptCookies::except(['kginicis_receipt_token']);
    }

    /**
     * KG 이니시스 플러그인의 루트 lang 디렉토리를 로드합니다.
     *
     * @return void
     */
    protected function loadExtensionTranslations(): void
    {
        $langPath = dirname($this->getProviderPath(), 2).'/lang';

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->translationNamespace());
        }
    }
}
