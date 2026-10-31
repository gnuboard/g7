<?php

namespace Plugins\Sirsoft\Sso\Providers;

use App\Extension\BasePluginServiceProvider;
use App\Models\User;
use Plugins\Sirsoft\Sso\Bootstrap\Commands;
use Plugins\Sirsoft\Sso\Bootstrap\Container;
use Plugins\Sirsoft\Sso\Models\SsoAccount;

/**
 * SSO 연동 플러그인 서비스 프로바이더.
 *
 * 바인딩(저장소, 플러그인 캐시 주입 서비스, 요청 단위 싱글턴)과 artisan 명령은 `Bootstrap\Container`, `Bootstrap\Commands` 가 영역별
 * 파일에서 모은다 — 영역을 더할 때 이 파일을 고치지 않는다. 회원 모델에 `ssoAccounts` 동적 관계를 붙인다.
 *
 * @since 1.0.0
 */
class SsoServiceProvider extends BasePluginServiceProvider
{
    /** 본 플러그인 식별자 */
    protected string $pluginIdentifier = 'sirsoft-sso';

    /**
     * 서비스 컨테이너 바인딩을 등록합니다.
     *
     * @return void 반환값 없음
     */
    public function register(): void
    {
        $this->repositories = Container::repositories();
        $this->cacheServices = Container::cacheServices();

        parent::register();

        foreach (Container::scoped() as $service) {
            $this->app->scoped($service);
        }

        if ($this->app->runningInConsole()) {
            $this->commands(Commands::all());
        }
    }

    /**
     * 부팅 — 회원 모델에 `ssoAccounts` 관계를 붙입니다.
     *
     * @return void 반환값 없음
     */
    public function boot(): void
    {
        parent::boot();

        User::resolveRelationUsing('ssoAccounts', fn (User $user) => $user->hasMany(SsoAccount::class, 'user_id'));
    }
}
