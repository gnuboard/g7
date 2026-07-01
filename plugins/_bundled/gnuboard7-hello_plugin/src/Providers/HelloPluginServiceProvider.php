<?php

namespace Plugins\Gnuboard7\HelloPlugin\Providers;

use App\Extension\BasePluginServiceProvider;
use Plugins\Gnuboard7\HelloPlugin\Services\HelloLogService;

/**
 * Hello 플러그인 서비스 프로바이더.
 *
 * BasePluginServiceProvider 를 상속해 표준 자동 바인딩 표면을 제공하며,
 * 추가로 HelloLogService 를 싱글톤으로 바인딩합니다. 학습용 샘플 플러그인
 * 의 최소 프로바이더 구조를 보여줍니다.
 */
class HelloPluginServiceProvider extends BasePluginServiceProvider
{
    protected string $pluginIdentifier = 'gnuboard7-hello_plugin';

    public function register(): void
    {
        parent::register();

        $this->app->singleton(HelloLogService::class, fn () => new HelloLogService());
    }
}
