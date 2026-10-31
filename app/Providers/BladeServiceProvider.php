<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Blade 확장 등록 지점.
 *
 * 등록된 디렉티브가 없다 — 종전 `@viteThemeAssets` 는 사용처가 없고 존재하지 않는 라우트로 요청 Host
 * 기준 절대 URL 을 만들던 죽은 코드라 제거했다. 새 디렉티브는 여기에 등록한다.
 */
class BladeServiceProvider extends ServiceProvider
{
    /**
     * 서비스를 등록합니다.
     *
     * @return void 반환값 없음
     */
    public function register(): void
    {
        //
    }

    /**
     * 서비스를 부팅합니다.
     *
     * @return void 반환값 없음
     */
    public function boot(): void
    {
        //
    }
}
