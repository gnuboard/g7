<?php

namespace Modules\G7Testing\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\G7Testing\Console\FakeExtensionScheduleCommand;

/**
 * 스케줄 Artisan 허용목록의 "확장 소유 명령" 계층을 HTTP 문맥에서 검증하기 위한
 * 테스트 전용 서비스 프로바이더.
 *
 * 실제 확장 프로바이더는 `$this->app->runningInConsole()` 일 때만 커맨드를 Artisan 에
 * 등록하므로, HTTP 요청(관리자 화면의 스케줄 저장 검증)에서는 `Artisan::all()` 에
 * 확장 커맨드가 존재하지 않는다. 검증기는 이 경우 프로바이더가 선언한 `$commands`
 * 기본값을 폴백으로 해석해야 한다 — 이 픽스처는 그 상황(커맨드 미등록 + 프로바이더의
 * `$commands` 선언만 존재)을 재현한다.
 *
 * `Tests\` PSR-4 네임스페이스가 아니므로 오토로드되지 않는다 — 사용처에서 require 한다.
 */
class FakeExtensionScheduleServiceProvider extends ServiceProvider
{
    /** @var array<int, class-string> 실제 확장 프로바이더와 동일한 선언 형태 */
    protected array $commands = [
        FakeExtensionScheduleCommand::class,
    ];
}
