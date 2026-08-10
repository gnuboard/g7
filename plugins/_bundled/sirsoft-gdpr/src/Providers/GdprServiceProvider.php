<?php

namespace Plugins\Sirsoft\Gdpr\Providers;

use App\Extension\BasePluginServiceProvider;
use Plugins\Sirsoft\Gdpr\Console\Commands\PlaywrightSeedGdprGuestLogin;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprPolicyVersionRepositoryInterface;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprUserConsentHistoryRepositoryInterface;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprUserConsentRepositoryInterface;
use Plugins\Sirsoft\Gdpr\Repositories\GdprPolicyVersionRepository;
use Plugins\Sirsoft\Gdpr\Repositories\GdprUserConsentHistoryRepository;
use Plugins\Sirsoft\Gdpr\Repositories\GdprUserConsentRepository;

/**
 * GDPR 플러그인 서비스 프로바이더.
 *
 * Repository 바인딩은 BasePluginServiceProvider 표준에 위임합니다.
 * functional cookie 게이팅 미들웨어(CookieConsentMiddleware)는 코어 self-gate 방식으로
 * 이관되어 Plugin::getMiddleware() 가 선언하고 코어 ExtensionMiddlewareGate 가 실행합니다.
 * (SP Kernel 직접 조작 제거 — EDPB §16 광역 타게팅은 targets=['everything']/before_core 로 유지)
 */
class GdprServiceProvider extends BasePluginServiceProvider
{
    protected string $pluginIdentifier = 'sirsoft-gdpr';

    protected array $repositories = [
        GdprUserConsentRepositoryInterface::class => GdprUserConsentRepository::class,
        GdprUserConsentHistoryRepositoryInterface::class => GdprUserConsentHistoryRepository::class,
        GdprPolicyVersionRepositoryInterface::class => GdprPolicyVersionRepository::class,
    ];

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                PlaywrightSeedGdprGuestLogin::class,
            ]);
        }
    }
}
