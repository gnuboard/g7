<?php

namespace Plugins\Gnuboard7\HelloPlugin\Providers;

use Illuminate\Support\ServiceProvider;
use Plugins\Gnuboard7\HelloPlugin\Services\HelloLogService;

/**
 * Hello 플러그인 서비스 프로바이더
 *
 * HelloLogService 를 싱글톤으로 바인딩합니다. 플러그인에서 DI 가 필요할 때의
 * 구조를 시연하기 위한 최소 프로바이더입니다.
 */
class HelloPluginServiceProvider extends ServiceProvider
{
    /**
     * 서비스 컨테이너 바인딩을 등록합니다.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(HelloLogService::class, function () {
            return new HelloLogService();
        });
    }

    /**
     * 부트 로직을 실행합니다.
     *
     * @return void
     */
    public function boot(): void
    {
        // 학습용 샘플은 부트 단계 로직이 없습니다.
    }
}
