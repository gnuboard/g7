<?php

namespace Tests\Feature\CoreUpdate;

use App\Services\CoreUpdateService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 코어 업데이트 마무리의 상주 프로세스 재시작 신호 검증.
 *
 * 회귀 가드: 7.2.0 → 7.2.1 웹 업데이트 실측에서 큐 워커는 마무리 뒤 다시 떴지만 실시간 알림 서버(Reverb)는
 * 업데이트 전 코드로 계속 돌았다. 재시작 신호(`queue:restart`)만 보내고 `reverb:restart` 를 보내지 않았다.
 */
class CoreUpdateResidentProcessRestartTest extends TestCase
{
    /**
     * 재시작 신호가 큐 워커와 Reverb 모두에 간다.
     *
     * @scenario process=queue_and_reverb
     *
     * @effects core_update_restart_signal_reaches_queue_workers, core_update_restart_signal_reaches_reverb_server
     *
     * @return void 반환값 없음
     */
    public function test_restart_signal_reaches_queue_workers_and_reverb(): void
    {
        Cache::forget('illuminate:queue:restart');
        Cache::forget('laravel:reverb:restart');

        app(CoreUpdateService::class)->signalQueueRestart();

        $this->assertNotNull(Cache::get('illuminate:queue:restart'), '큐 워커 재시작 신호가 없다');
        $this->assertNotNull(Cache::get('laravel:reverb:restart'), 'Reverb 재시작 신호가 없다');
    }

    /**
     * 웹 요청에서도 Reverb 재시작 신호가 간다 — Reverb 는 콘솔 실행일 때만 `reverb:restart` 명령을 등록한다.
     *
     * 웹 코어 업데이트의 마무리는 HTTP 요청 안에서 돌아 명령 목록에 `reverb:restart` 가 없다. 명령 등록 여부에
     * 기대면 신호를 조용히 건너뛴다(7.2.1 서버 재시험에서 Reverb 가 업데이트 전 코드로 남음).
     *
     * @scenario process=reverb_web_request
     *
     * @effects core_update_restart_signal_reaches_reverb_server_from_web_request
     *
     * @return void 반환값 없음
     */
    public function test_reverb_restart_signal_is_sent_even_when_command_is_not_registered(): void
    {
        Cache::forget('laravel:reverb:restart');

        $kernel = \Mockery::mock(app(Kernel::class))->makePartial();
        $kernel->shouldReceive('all')->andReturn([]);
        $this->app->instance(Kernel::class, $kernel);

        app(CoreUpdateService::class)->signalQueueRestart();

        $this->assertNotNull(Cache::get('laravel:reverb:restart'), '명령이 등록되지 않은 웹 요청에서 Reverb 재시작 신호가 없다');
    }
}
