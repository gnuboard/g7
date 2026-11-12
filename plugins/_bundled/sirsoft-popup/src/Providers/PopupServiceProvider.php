<?php

namespace Plugins\Sirsoft\Popup\Providers;

use App\Extension\BasePluginServiceProvider;
use Plugins\Sirsoft\Popup\Console\Commands\PruneTempImagesCommand;
use Plugins\Sirsoft\Popup\Repositories\Contracts\PopupImageRepositoryInterface;
use Plugins\Sirsoft\Popup\Repositories\Contracts\PopupRepositoryInterface;
use Plugins\Sirsoft\Popup\Repositories\PopupImageRepository;
use Plugins\Sirsoft\Popup\Repositories\PopupRepository;
use Plugins\Sirsoft\Popup\Services\PopupImageService;
use Plugins\Sirsoft\Popup\Services\PublicPopupService;

/**
 * 팝업 플러그인 서비스 프로바이더
 *
 * BasePluginServiceProvider 의 표준 자동 바인딩을 쓴다 — Repository 인터페이스, 플러그인 캐시(공개 노출 서비스),
 * 플러그인 스토리지(이미지 서비스). 전역 Cache/Storage 바인딩은 건드리지 않는다.
 */
class PopupServiceProvider extends BasePluginServiceProvider
{
    /**
     * 플러그인 식별자 (manifest 와 일치)
     */
    protected string $pluginIdentifier = 'sirsoft-popup';

    /**
     * Repository 인터페이스 ↔ 구현체
     *
     * @var array<class-string, class-string>
     */
    protected array $repositories = [
        PopupRepositoryInterface::class => PopupRepository::class,
        PopupImageRepositoryInterface::class => PopupImageRepository::class,
    ];

    /**
     * 플러그인 캐시를 주입받는 서비스
     *
     * @var array<int, class-string>
     */
    protected array $cacheServices = [
        PublicPopupService::class,
    ];

    /**
     * 카테고리별 스토리지를 주입받는 서비스 (클래스 ⇒ 카테고리) — #878
     *
     * 이미지 서비스는 `getStorageDiskFor('images')` 가 정한 디스크(공개 자산 디스크 설정 반영)를 주입받는다. 주입 스토리지는
     * 「지금 업로드가 갈 디스크」이고, 이미 올린 파일의 읽기·서빙·삭제는 서비스가 행 disk 로 다시 고른다.
     *
     * @var array<class-string, string>
     */
    protected array $storageCategoryServices = [
        PopupImageService::class => 'images',
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
