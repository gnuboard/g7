<?php

namespace Plugins\Sirsoft\HeroSection\Providers;

use App\Extension\BasePluginServiceProvider;
use Plugins\Sirsoft\HeroSection\Console\Commands\PruneTempImagesCommand;
use Plugins\Sirsoft\HeroSection\Repositories\BannerImageRepository;
use Plugins\Sirsoft\HeroSection\Repositories\BannerRepository;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerImageRepositoryInterface;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerRepositoryInterface;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\PositionSettingRepositoryInterface;
use Plugins\Sirsoft\HeroSection\Repositories\PositionSettingRepository;
use Plugins\Sirsoft\HeroSection\Services\BannerImageService;
use Plugins\Sirsoft\HeroSection\Services\PreviewFrameResolver;
use Plugins\Sirsoft\HeroSection\Services\PublicHeroService;

/**
 * 히어로섹션 플러그인 서비스 프로바이더
 *
 * BasePluginServiceProvider 의 표준 자동 바인딩을 쓴다 — Repository 인터페이스, 플러그인 캐시(공개 노출 서비스),
 * 플러그인 스토리지(이미지 서비스). 전역 Cache/Storage 바인딩은 건드리지 않는다.
 */
class HeroSectionServiceProvider extends BasePluginServiceProvider
{
    /**
     * 플러그인 식별자 (manifest 와 일치)
     */
    protected string $pluginIdentifier = 'sirsoft-hero_section';

    /**
     * Repository 인터페이스 ↔ 구현체
     *
     * @var array<class-string, class-string>
     */
    protected array $repositories = [
        BannerRepositoryInterface::class => BannerRepository::class,
        BannerImageRepositoryInterface::class => BannerImageRepository::class,
        PositionSettingRepositoryInterface::class => PositionSettingRepository::class,
    ];

    /**
     * 플러그인 캐시를 주입받는 서비스
     *
     * @var array<int, class-string>
     */
    protected array $cacheServices = [
        PublicHeroService::class,
        PreviewFrameResolver::class,
    ];

    /**
     * 플러그인 스토리지를 주입받는 서비스
     *
     * @var array<int, class-string>
     */
    protected array $storageServices = [
        BannerImageService::class,
    ];

    /**
     * 서비스를 시작합니다 — 콘솔 명령을 등록합니다.
     *
     * @return void 반환값 없음
     */
    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([PruneTempImagesCommand::class]);
        }
    }
}
