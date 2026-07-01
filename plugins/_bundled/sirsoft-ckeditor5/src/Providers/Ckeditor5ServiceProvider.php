<?php

namespace Plugins\Sirsoft\Ckeditor5\Providers;

use App\Extension\BasePluginServiceProvider;
use Plugins\Sirsoft\Ckeditor5\Repositories\Contracts\ImageUploadRepositoryInterface;
use Plugins\Sirsoft\Ckeditor5\Repositories\ImageUploadRepository;
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
    ];

    protected array $storageServices = [
        ImageUploadService::class,
        ImageServeService::class,
    ];
}
