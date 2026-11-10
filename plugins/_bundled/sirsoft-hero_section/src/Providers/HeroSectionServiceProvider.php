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
use Plugins\Sirsoft\HeroSection\Services\PositionRegistry;
use Plugins\Sirsoft\HeroSection\Services\PreviewFrameResolver;
use Plugins\Sirsoft\HeroSection\Services\PublicHeroService;

/**
 * 히어로섹션 플러그인 서비스 프로바이더
 *
 * BasePluginServiceProvider 의 표준 자동 바인딩을 쓴다 — Repository 인터페이스, 플러그인 캐시(공개 노출 서비스),
 * 카테고리 스토리지(이미지 서비스, 공개 자산 디스크). 전역 Cache/Storage 바인딩은 건드리지 않는다.
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
     * 카테고리별 스토리지를 주입받는 서비스 (클래스 ⇒ 카테고리) — #878
     *
     * 이미지 서비스는 `getStorageDiskFor('images')` 가 정한 디스크(공개 자산 디스크 설정 반영)를 주입받는다.
     * 주입 스토리지는 「지금 업로드가 갈 디스크」이고 `put()`·`getDisk()` 행 기록이 그 디스크를 따른다. 이미 올린
     * 파일의 읽기·서빙·삭제는 서비스가 행 disk 로 다시 고른다(`BannerImageService::storageFor()`).
     *
     * @var array<class-string, string>
     */
    protected array $storageCategoryServices = [
        BannerImageService::class => 'images',
    ];

    /**
     * 서비스를 등록합니다 — 위치 레지스트리는 요청마다 한 번만 모은다(검증·저장소·응답이 같은 목록을 본다).
     *
     * @return void 반환값 없음
     */
    public function register(): void
    {
        parent::register();

        $this->app->scoped(PositionRegistry::class);
    }

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
