<?php

namespace Tests\Feature\CoreUpdate;

use App\Services\CoreUpdateService;
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
}
