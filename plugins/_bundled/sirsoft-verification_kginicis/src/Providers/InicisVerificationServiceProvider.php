<?php

namespace Plugins\Sirsoft\VerificationKginicis\Providers;

use App\Extension\BasePluginServiceProvider;
use Plugins\Sirsoft\VerificationKginicis\Identity\InicisIdentityProvider;
use Plugins\Sirsoft\VerificationKginicis\Listeners\CompleteInicisRecordAfterRegister;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisChallengeMappingRepository;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisChallengeMappingRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityLogQueryRepository;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityLogQueryRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityRecordRepository;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Services\InicisCallbackResolver;
use Plugins\Sirsoft\VerificationKginicis\Services\InicisGateway;
use Plugins\Sirsoft\VerificationKginicis\Services\InicisGatewayInterface;
use Plugins\Sirsoft\VerificationKginicis\Services\InicisIdentityCardService;

/**
 * KG이니시스 본인인증 플러그인 ServiceProvider.
 *
 * BasePluginServiceProvider 표준 자동 바인딩을 사용해 Repository 와 캐시 소비
 * 서비스의 contextual binding 을 일괄 등록합니다. 본 플러그인 도메인의 캐시
 * (`g7:plugin.sirsoft-verification_kginicis:*`) 는 InicisIdentityProvider 와
 * CompleteInicisRecordAfterRegister listener 에만 주입되며, 글로벌 코어
 * CacheInterface 바인딩을 덮어쓰지 않습니다.
 *
 * @since 1.0.0-beta.1
 */
class InicisVerificationServiceProvider extends BasePluginServiceProvider
{
    protected string $pluginIdentifier = 'sirsoft-verification_kginicis';

    protected array $repositories = [
        InicisIdentityRecordRepositoryInterface::class => InicisIdentityRecordRepository::class,
        InicisChallengeMappingRepositoryInterface::class => InicisChallengeMappingRepository::class,
        InicisIdentityLogQueryRepositoryInterface::class => InicisIdentityLogQueryRepository::class,
    ];

    /**
     * 플러그인 캐시 도메인 (`g7:plugin.sirsoft-verification_kginicis:*`) 이 필요한 서비스.
     *
     * 글로벌 `CacheInterface::class` 바인딩을 덮어쓰지 않고 contextual binding 으로만
     * 격리된 캐시를 주입합니다.
     */
    protected array $cacheServices = [
        InicisIdentityProvider::class,
        CompleteInicisRecordAfterRegister::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->singleton(InicisGatewayInterface::class, InicisGateway::class);
        $this->app->bind(InicisCallbackResolver::class);
        $this->app->bind(InicisIdentityCardService::class);
    }

    /**
     * 플러그인 부팅.
     *
     * @return void
     */
    public function boot(): void
    {
        if (method_exists(get_parent_class($this), 'boot')) {
            parent::boot();
        }
    }
}
