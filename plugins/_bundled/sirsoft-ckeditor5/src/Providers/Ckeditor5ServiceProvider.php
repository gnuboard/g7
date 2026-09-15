<?php

namespace Plugins\Sirsoft\Ckeditor5\Providers;

use App\Extension\BasePluginServiceProvider;
use Plugins\Sirsoft\Ckeditor5\Console\Commands\PruneUnusedImagesCommand;
use Plugins\Sirsoft\Ckeditor5\Repositories\Contracts\ImageReferenceSourceRepositoryInterface;
use Plugins\Sirsoft\Ckeditor5\Repositories\Contracts\ImageUploadRepositoryInterface;
use Plugins\Sirsoft\Ckeditor5\Repositories\ImageReferenceSourceRepository;
use Plugins\Sirsoft\Ckeditor5\Repositories\ImageUploadRepository;
use Plugins\Sirsoft\Ckeditor5\Services\ImageCleanupService;
use Plugins\Sirsoft\Ckeditor5\Services\ImageServeService;
use Plugins\Sirsoft\Ckeditor5\Services\ImageUploadService;

/**
 * CKEditor5 플러그인 서비스 프로바이더.
 *
 * Repository 인터페이스/구현체 바인딩과 ImageUpload/ImageServe 서비스의
 * StorageInterface 자동 주입을 BasePluginServiceProvider 표준에 위임합니다.
 */
class Ckeditor5ServiceProvider extends BasePluginServiceProvider
{
    protected string $pluginIdentifier = 'sirsoft-ckeditor5';

    protected array $repositories = [
        ImageUploadRepositoryInterface::class => ImageUploadRepository::class,
        ImageReferenceSourceRepositoryInterface::class => ImageReferenceSourceRepository::class,
    ];

    protected array $storageServices = [
        ImageServeService::class,
        ImageCleanupService::class,
    ];

    /**
     * 카테고리별 StorageInterface가 필요한 서비스 매핑 (클래스 ⇒ 카테고리)
     *
     * 업로드 서비스는 getStorageDiskFor('images') 가 결정한 디스크(공개 자산 디스크
     * 설정 반영)를 주입받아, put/getDisk() 행 기록이 자동으로 카테고리 디스크를 따릅니다.
     * 서빙 서비스는 행 storage_disk 기준 withDisk() 를 쓰므로 기본 주입을 유지합니다.
     *
     * @var array<class-string, string>
     */
    protected array $storageCategoryServices = [
        ImageUploadService::class => 'images',
    ];

    /**
     * 플러그인 부팅 — 콘솔 실행 시 정리 커맨드를 등록합니다.
     */
    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneUnusedImagesCommand::class,
            ]);
        }
    }
}
