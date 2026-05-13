<?php

namespace Plugins\Sirsoft\Ckeditor5\Providers;

use App\Contracts\Extension\StorageInterface;
use App\Extension\Storage\PluginStorageDriver;
use Illuminate\Support\ServiceProvider;
use Plugins\Sirsoft\Ckeditor5\Repositories\Contracts\ImageUploadRepositoryInterface;
use Plugins\Sirsoft\Ckeditor5\Repositories\ImageUploadRepository;
use Plugins\Sirsoft\Ckeditor5\Services\ImageServeService;
use Plugins\Sirsoft\Ckeditor5\Services\ImageUploadService;

/**
 * CKEditor5 플러그인 서비스 프로바이더
 *
 * Repository 인터페이스와 구현체 바인딩,
 * StorageInterface 주입을 담당합니다.
 */
class Ckeditor5ServiceProvider extends ServiceProvider
{
    /**
     * 플러그인 식별자
     */
    private const PLUGIN_ID = 'sirsoft-ckeditor5';

    /**
     * 서비스 컨테이너 바인딩 등록
     *
     * @return void
     */
    public function register(): void
    {
        // Repository 바인딩
        $this->app->bind(
            ImageUploadRepositoryInterface::class,
            ImageUploadRepository::class
        );

        // StorageInterface 바인딩 — plugins 디스크 사용
        $this->app->when([ImageUploadService::class, ImageServeService::class])
            ->needs(StorageInterface::class)
            ->give(function () {
                return new PluginStorageDriver(self::PLUGIN_ID, 'plugins');
            });
    }
}
