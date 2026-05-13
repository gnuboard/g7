<?php

namespace Plugins\Sirsoft\Marketing\Providers;

use Illuminate\Support\ServiceProvider;
use Plugins\Sirsoft\Marketing\Repositories\Contracts\MarketingConsentRepositoryInterface;
use Plugins\Sirsoft\Marketing\Repositories\MarketingConsentRepository;

/**
 * 마케팅 동의 플러그인 서비스 프로바이더
 *
 * Repository 인터페이스와 구현체를 바인딩합니다.
 */
class MarketingServiceProvider extends ServiceProvider
{
    /**
     * 서비스 컨테이너 바인딩 등록
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->bind(
            MarketingConsentRepositoryInterface::class,
            MarketingConsentRepository::class
        );
    }
}
