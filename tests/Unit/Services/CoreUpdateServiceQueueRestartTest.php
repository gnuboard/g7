<?php

namespace Tests\Unit\Services;

use App\Services\CoreUpdateService;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 코어 업데이트 후 큐 워커 재시작 신호 회귀 테스트 (dev-g7 #658).
 *
 * 코어 업데이트는 어느 지점에서도 `queue:restart` 를 보내지 않았다. 큐 워커는 부팅이 한 번뿐이라
 * 코어 코드·config·확장 목록을 기동 시점 상태로 물고 있고, 업데이트가 파일을 전부 교체해도
 * 옛 코드로 잡을 계속 처리한다 — 오류로 드러나지 않으므로 운영자가 손수 재시작할 때까지
 * 조용히 어긋난 채 돈다(번들 확장 일괄 업데이트는 Manager 직접 호출이라 기존
 * `ExtensionUpdateQueueListener` 도 걸리지 않는다).
 */
class CoreUpdateServiceQueueRestartTest extends TestCase
{
    /**
     * `queue:restart` 를 정확히 한 번 호출한다.
     *
     * @effects core_update_step11_signals_queue_restart
     */
    #[Test]
    public function signal_queue_restart_는_queue_restart_를_한_번_호출한다(): void
    {
        Artisan::shouldReceive('call')->once()->with('queue:restart')->andReturn(0);

        app(CoreUpdateService::class)->signalQueueRestart();

        $this->assertTrue(true, 'Artisan 기대치는 tearDown 에서 검증된다');
    }

    /**
     * 신호 전송 실패는 업데이트를 되돌리지 않는다 — 예외를 삼키고 계속한다.
     */
    #[Test]
    public function signal_queue_restart_는_실패해도_예외를_전파하지_않는다(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('queue:restart')
            ->andThrow(new \RuntimeException('큐 재시작 신호 전송 실패 시뮬레이션'));

        app(CoreUpdateService::class)->signalQueueRestart();

        $this->assertTrue(true, '예외가 전파되면 이 지점에 도달하지 못한다');
    }

    /**
     * 업데이트를 마치는 세 지점이 캐시 정리 **직후** 신호를 보낸다 (호출 지점 계약).
     *
     * 행위 테스트로는 이 축을 덮을 수 없다 — `CoreUpdateCommandHandoffTest` 의 docblock 이
     * 밝히듯 `handle()` 의 catch cleanup 시퀀스는 GitHub 연동·migration·vendor 설치까지
     * 끌고 들어와야 하는 통합 면적이라 그 테스트 범위 밖이다. 그래서 세 지점의 배치를
     * 소스로 잠근다: 새 마무리 지점이 신호 없이 추가되면 여기서 걸린다.
     *
     * 롤백 catch 는 의도적 제외다 — 백업으로 되돌린 **옛** 코드가 다시 도는 자리라 워커를
     * 재기동시킬 이유가 없다.
     *
     * @effects core_update_step11_signals_queue_restart
     */
    #[Test]
    public function 캐시_정리_뒤_큐_신호를_보내는_지점이_셋이고_롤백은_제외된다(): void
    {
        $sites = [];

        foreach ([
            'app/Console/Commands/Core/CoreUpdateCommand.php',
            'app/Console/Commands/Core/ExecuteUpgradeStepsCommand.php',
        ] as $relative) {
            $source = (string) file_get_contents(base_path($relative));

            $offset = 0;
            while (($pos = strpos($source, '$service->clearAllCaches();', $offset)) !== false) {
                // 정리 직후 구간(다음 400자)에 신호가 있는지 본다 — 사이에 낀 주석은 허용하되
                // 다른 마무리 단계를 한참 지나 붙는 것은 "직후" 가 아니다.
                $window = substr($source, $pos, 400);
                $sites[] = [
                    'file' => $relative,
                    'line' => substr_count(substr($source, 0, $pos), "\n") + 1,
                    'signals' => str_contains($window, '$service->signalQueueRestart();'),
                    // 롤백 지점은 백업 복원 직후라 바로 뒤에 "롤백 후 캐시 정리 완료" 로그가 온다.
                    'is_rollback' => str_contains($window, '롤백 후 캐시 정리'),
                ];
                $offset = $pos + 1;
            }
        }

        $this->assertNotEmpty($sites, '전제: clearAllCaches 호출 지점을 찾을 수 있어야 한다');

        $signalling = array_values(array_filter($sites, fn (array $s): bool => $s['signals']));
        $silent = array_values(array_filter($sites, fn (array $s): bool => ! $s['signals']));

        $this->assertCount(
            3,
            $signalling,
            'Step 11 · 핸드오프 cleanup · 단독 실행 사후 단계 셋이 신호를 보내야 한다. 실제: '
                .json_encode($sites, JSON_UNESCAPED_SLASHES)
        );

        $this->assertCount(
            1,
            $silent,
            '신호를 보내지 않는 지점은 롤백 catch 하나뿐이어야 한다. 실제: '
                .json_encode($silent, JSON_UNESCAPED_SLASHES)
        );

        $this->assertTrue(
            $silent[0]['is_rollback'],
            '제외된 지점은 롤백 catch 여야 한다 (옛 코드로 되돌린 자리라 워커 재기동 대상이 아니다). 실제: '
                .json_encode($silent[0], JSON_UNESCAPED_SLASHES)
        );
    }
}
