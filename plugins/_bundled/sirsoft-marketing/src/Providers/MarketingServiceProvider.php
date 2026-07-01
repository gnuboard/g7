<?php

namespace Plugins\Sirsoft\Marketing\Providers;

use App\Extension\BasePluginServiceProvider;
use Plugins\Sirsoft\Marketing\Repositories\Contracts\MarketingConsentRepositoryInterface;
use Plugins\Sirsoft\Marketing\Repositories\MarketingConsentRepository;

/**
 * 마케팅 동의 플러그인 서비스 프로바이더.
 *
 * Repository 바인딩을 BasePluginServiceProvider 표준에 위임합니다.
 */
class MarketingServiceProvider extends BasePluginServiceProvider
{
    protected string $pluginIdentifier = 'sirsoft-marketing';

    protected array $repositories = [
        MarketingConsentRepositoryInterface::class => MarketingConsentRepository::class,
    ];
}
