<?php

namespace Plugins\Sirsoft\Totp\Providers;

use App\Extension\BasePluginServiceProvider;
use Plugins\Sirsoft\Totp\Console\Commands\PlaywrightSeedTotp;
use Plugins\Sirsoft\Totp\Console\Commands\TotpResetCommand;
use Plugins\Sirsoft\Totp\Repositories\Contracts\TotpCredentialRepositoryInterface;
use Plugins\Sirsoft\Totp\Repositories\TotpCredentialRepository;

/**
 * 인증 앱(TOTP) 플러그인 서비스 프로바이더.
 *
 * 자격증명 저장소 바인딩과 artisan 커맨드 등록을 담당한다. 코어 challenge 로그는
 * 코어 Repository 가 이미 소유하므로 이 플러그인은 자격증명 저장소 하나만 둔다.
 *
 * @since 1.0.0
 */
class TotpServiceProvider extends BasePluginServiceProvider
{
    /** 본 플러그인 식별자 */
    protected string $pluginIdentifier = 'sirsoft-totp';

    /**
     * Repository 인터페이스 ↔ 구현체 바인딩.
     *
     * @var array<class-string, class-string>
     */
    protected array $repositories = [
        TotpCredentialRepositoryInterface::class => TotpCredentialRepository::class,
    ];

    /**
     * 부팅 — 콘솔에서 artisan 커맨드를 등록합니다.
     *
     * @return void
     */
    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                TotpResetCommand::class,
                PlaywrightSeedTotp::class,
            ]);
        }
    }
}
