<?php

namespace Modules\Zztest\Seedprov\Providers;

use App\Extension\BaseModuleServiceProvider;
use Modules\Zztest\Seedprov\Contracts\ProbeRepositoryInterface;
use Modules\Zztest\Seedprov\Repositories\ProbeRepository;

/**
 * 시험 모듈 프로바이더 — 저장소 바인딩을 `$repositories` 로만 선언한다.
 */
class SeedprovServiceProvider extends BaseModuleServiceProvider
{
    protected string $moduleIdentifier = 'zztest-seedprov';

    protected array $repositories = [
        ProbeRepositoryInterface::class => ProbeRepository::class,
    ];
}
