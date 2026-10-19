<?php

namespace Plugins\Sirsoft\SocialLogin\Providers;

use App\Extension\BasePluginServiceProvider;
use App\Models\User;
use Plugins\Sirsoft\SocialLogin\Console\Commands\MockCommand;
use Plugins\Sirsoft\SocialLogin\Drivers\OidcDriver;
use Plugins\Sirsoft\SocialLogin\Mock\MockController;
use Plugins\Sirsoft\SocialLogin\Mock\MockGate;
use Plugins\Sirsoft\SocialLogin\Mock\MockKeyPair;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Repositories\AttemptRepository;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\AttemptRepositoryInterface;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\ProviderRepositoryInterface;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\SocialAccountRepositoryInterface;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\WebhookEventRepositoryInterface;
use Plugins\Sirsoft\SocialLogin\Repositories\ProviderRepository;
use Plugins\Sirsoft\SocialLogin\Repositories\SocialAccountRepository;
use Plugins\Sirsoft\SocialLogin\Repositories\WebhookEventRepository;
use Plugins\Sirsoft\SocialLogin\Services\IdTokenVerifier;
use Plugins\Sirsoft\SocialLogin\Services\OAuthFlowService;
use Plugins\Sirsoft\SocialLogin\Services\ProviderRegistry;
use Plugins\Sirsoft\SocialLogin\Services\StateStore;

/**
 * 소셜 로그인 플러그인 서비스 프로바이더.
 *
 * 저장소 바인딩, 플러그인 캐시가 필요한 서비스의 contextual 바인딩, 요청당 레지스트리, artisan 명령, 회원 모델의
 * `socialAccounts` 동적 관계를 등록한다.
 *
 * @since 1.0.0
 */
class SocialLoginServiceProvider extends BasePluginServiceProvider
{
    /** 본 플러그인 식별자 */
    protected string $pluginIdentifier = 'sirsoft-social_login';

    /**
     * Repository 인터페이스 ↔ 구현체 바인딩.
     *
     * @var array<class-string, class-string>
     */
    protected array $repositories = [
        ProviderRepositoryInterface::class => ProviderRepository::class,
        SocialAccountRepositoryInterface::class => SocialAccountRepository::class,
        AttemptRepositoryInterface::class => AttemptRepository::class,
        WebhookEventRepositoryInterface::class => WebhookEventRepository::class,
    ];

    /**
     * 플러그인 캐시(CacheInterface)가 필요한 서비스 — contextual binding.
     *
     * @var array<int, class-string>
     */
    protected array $cacheServices = [
        StateStore::class,
        IdTokenVerifier::class,
        OidcDriver::class,
        OAuthFlowService::class,
        MockGate::class,
        MockKeyPair::class,
        MockController::class,
    ];

    /**
     * 서비스 컨테이너 바인딩을 등록합니다.
     *
     * 레지스트리는 요청 단위 싱글턴이다 — 드라이버 목록과 확장 필터를 한 요청에서 한 번만 만든다.
     *
     * @return void
     */
    public function register(): void
    {
        parent::register();

        $this->app->scoped(ProviderRegistry::class);

        if ($this->app->runningInConsole()) {
            $this->commands([MockCommand::class]);
        }
    }

    /**
     * 부팅 — 회원 모델에 `socialAccounts` 관계를 붙입니다.
     *
     * @return void
     */
    public function boot(): void
    {
        parent::boot();

        User::resolveRelationUsing('socialAccounts', fn (User $user) => $user->hasMany(SocialAccount::class, 'user_id'));
    }
}
