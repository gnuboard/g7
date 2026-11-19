<?php

namespace Modules\G7TestingWeb\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * 웹 요청에서 확장 명령을 찾는 경로를 재현하기 위한 테스트 전용 서비스 프로바이더.
 *
 * 번들 확장 대부분은 `boot()` 안에서 `runningInConsole()` 일 때만 `$this->commands([...])`
 * 로 명령을 등록하고 `$commands` 프로퍼티는 두지 않는다. 그래서 관리자 화면의 「즉시 실행」
 * (HTTP 요청)에서는 `Artisan::all()` 에도, 프로바이더 선언 폴백에도 그 명령이 없다.
 * 이 프로바이더는 아무 명령도 등록·선언하지 않아 그 상태를 그대로 만든다 — 명령 클래스는
 * 실제 확장과 같은 위치(`src/Console/Commands/`)에만 있다.
 *
 * `Tests\` PSR-4 네임스페이스가 아니므로 오토로드되지 않는다 — 사용처에서 require 한다.
 */
class FakeWebCommandServiceProvider extends ServiceProvider
{
    /**
     * 명령을 등록하지 않습니다(웹 요청 재현).
     *
     * @return void 반환값 없음
     */
    public function boot(): void
    {
        // 실제 확장은 여기서 `if ($this->app->runningInConsole()) { $this->commands([...]); }` 를 한다.
    }
}
