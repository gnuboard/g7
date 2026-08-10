<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Providers;

use App\Extension\BasePluginServiceProvider;
use Plugins\Sirsoft\VerificationNhnkcp\Identity\KcpIdentityProvider;
use Plugins\Sirsoft\VerificationNhnkcp\Listeners\CompleteKcpRecordAfterRegister;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpCertTransactionRepository;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpCertTransactionRepositoryInterface;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpIdentityLogQueryRepository;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpIdentityLogQueryRepositoryInterface;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpIdentityRecordRepository;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationNhnkcp\Services\KcpCallbackResolver;
use Plugins\Sirsoft\VerificationNhnkcp\Services\KcpCertClient;
use Plugins\Sirsoft\VerificationNhnkcp\Services\KcpCertClientInterface;
use Plugins\Sirsoft\VerificationNhnkcp\Services\KcpDuplicateIdentityChecker;
use Plugins\Sirsoft\VerificationNhnkcp\Services\KcpIdentityCardService;

/**
 * NHN KCP 휴대폰 본인확인 플러그인 ServiceProvider.
 *
 * BasePluginServiceProvider 표준 자동 바인딩으로 Repository 와 캐시 소비 서비스의 contextual
 * binding 을 일괄 등록한다. 본 플러그인 도메인의 캐시(`g7:plugin.sirsoft-verification_nhnkcp:*`)
 * 는 Provider 와 가입 backfill listener 에만 주입되며, 글로벌 코어 CacheInterface 바인딩을
 * 덮어쓰지 않는다.
 *
 * @since 1.0.0
 */
class KcpVerificationServiceProvider extends BasePluginServiceProvider
{
    protected string $pluginIdentifier = 'sirsoft-verification_nhnkcp';

    /**
     * 자동 바인딩할 Repository 인터페이스 → 구현체 맵.
     *
     * @var array<class-string, class-string>
     */
    protected array $repositories = [
        KcpIdentityRecordRepositoryInterface::class => KcpIdentityRecordRepository::class,
        KcpCertTransactionRepositoryInterface::class => KcpCertTransactionRepository::class,
        KcpIdentityLogQueryRepositoryInterface::class => KcpIdentityLogQueryRepository::class,
    ];

    /**
     * 플러그인 캐시 도메인이 필요한 서비스.
     *
     * 글로벌 `CacheInterface::class` 바인딩을 덮어쓰지 않고 contextual binding 으로만
     * 격리된 캐시를 주입한다.
     *
     * @var array<int, class-string>
     */
    protected array $cacheServices = [
        KcpIdentityProvider::class,
        CompleteKcpRecordAfterRegister::class,
    ];

    /**
     * 컨테이너 바인딩 등록.
     */
    public function register(): void
    {
        parent::register();

        $this->app->singleton(KcpCertClientInterface::class, KcpCertClient::class);
        $this->app->bind(KcpDuplicateIdentityChecker::class);
        $this->app->bind(KcpCallbackResolver::class);
        $this->app->bind(KcpIdentityCardService::class);
    }

    /**
     * 플러그인 부팅.
     */
    public function boot(): void
    {
        if (method_exists(get_parent_class($this), 'boot')) {
            parent::boot();
        }
    }
}
