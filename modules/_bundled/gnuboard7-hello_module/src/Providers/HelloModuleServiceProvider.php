<?php

namespace Modules\Gnuboard7\HelloModule\Providers;

use App\Extension\BaseModuleServiceProvider;
use Modules\Gnuboard7\HelloModule\Repositories\Contracts\MemoRepositoryInterface;
use Modules\Gnuboard7\HelloModule\Repositories\MemoRepository;

/**
 * Hello 모듈 서비스 프로바이더
 *
 * Repository 인터페이스와 구현체 바인딩을 담당합니다.
 */
class HelloModuleServiceProvider extends BaseModuleServiceProvider
{
    /**
     * 모듈 식별자
     *
     * @var string
     */
    protected string $moduleIdentifier = 'gnuboard7-hello_module';

    /**
     * Repository 인터페이스와 구현체 매핑
     *
     * @var array<class-string, class-string>
     */
    protected array $repositories = [
        MemoRepositoryInterface::class => MemoRepository::class,
    ];
}
