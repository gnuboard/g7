<?php

namespace Plugins\Zztest\Seedprov\Providers;

use App\Extension\BasePluginServiceProvider;
use Plugins\Zztest\Seedprov\Contracts\ProbeRepositoryInterface;
use Plugins\Zztest\Seedprov\Repositories\ProbeRepository;

/**
 * 시험 플러그인 프로바이더 — 저장소 바인딩을 `$repositories` 로만 선언한다.
 */
class SeedprovServiceProvider extends BasePluginServiceProvider
{
    protected string $pluginIdentifier = 'zztest-seedprov';

    protected array $repositories = [
        ProbeRepositoryInterface::class => ProbeRepository::class,
    ];
}
