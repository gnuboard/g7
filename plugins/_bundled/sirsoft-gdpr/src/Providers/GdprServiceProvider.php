<?php

namespace Plugins\Sirsoft\Gdpr\Providers;

use App\Extension\BasePluginServiceProvider;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Plugins\Sirsoft\Gdpr\Http\Middleware\CookieConsentMiddleware;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprPolicyVersionRepositoryInterface;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprUserConsentHistoryRepositoryInterface;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprUserConsentRepositoryInterface;
use Plugins\Sirsoft\Gdpr\Repositories\GdprPolicyVersionRepository;
use Plugins\Sirsoft\Gdpr\Repositories\GdprUserConsentHistoryRepository;
use Plugins\Sirsoft\Gdpr\Repositories\GdprUserConsentRepository;

/**
 * GDPR 플러그인 서비스 프로바이더.
 *
 * Repository 바인딩은 BasePluginServiceProvider 표준에 위임하며, 부트 단계에서
 * functional cookie 게이팅 미들웨어를 'web' / 'api' 그룹 앞단에 등록합니다 (Phase 2).
 */
class GdprServiceProvider extends BasePluginServiceProvider
{
    protected string $pluginIdentifier = 'sirsoft-gdpr';

    protected array $repositories = [
        GdprUserConsentRepositoryInterface::class => GdprUserConsentRepository::class,
        GdprUserConsentHistoryRepositoryInterface::class => GdprUserConsentHistoryRepository::class,
        GdprPolicyVersionRepositoryInterface::class => GdprPolicyVersionRepository::class,
    ];

    /**
     * 부트스트랩 — functional cookie 게이팅 미들웨어를 'web' / 'api' 그룹 앞단에 등록.
     *
     * Phase 2: EDPB Guidelines 2/2023 §16 (사전 차단) 충족 — functional 미동의 시 응답에서
     * functional cookie 제거. Laravel 11+ HTTP Kernel::prependMiddlewareToGroup() 은
     * 내부적으로 중복 등록을 방지하므로 매 요청 호출되어도 1회만 등록됩니다.
     */
    public function boot(): void
    {
        parent::boot();

        $kernel = $this->app->make(HttpKernelContract::class);

        if (! $kernel instanceof HttpKernel) {
            return;
        }

        $kernel->prependMiddlewareToGroup('web', CookieConsentMiddleware::class);
        $kernel->prependMiddlewareToGroup('api', CookieConsentMiddleware::class);
    }
}
