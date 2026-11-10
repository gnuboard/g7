<?php

namespace Plugins\Sirsoft\CdnCloudfront\Console\Commands;

use Illuminate\Console\Command;
use Plugins\Sirsoft\CdnCloudfront\Services\PurgeService;

/**
 * 실패한 CloudFront 캐시 무효화를 다시 보낸다 (정기 작업, 5분마다).
 */
class RetryPurgesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sirsoft-cdn_cloudfront:retry-purges';

    /**
     * @var string
     */
    protected $description = '실패한 CloudFront 캐시 무효화를 다시 보냅니다';

    /**
     * 재시도 시각이 된 대기 건을 다시 보냅니다.
     *
     * 다시 보낸 건이 실패해도 명령은 성공으로 끝낸다 — 실패는 대기열에 기록되고 다음 주기에 다시 시도하므로,
     * 명령 실패로 남기면 스케줄 실패 알림이 5분마다 쌓인다. 재시도 상한에 닿아 멈춘 건만 경고로 출력한다.
     *
     * @param  PurgeService  $purges  무효화 서비스
     * @return int 종료 코드
     */
    public function handle(PurgeService $purges): int
    {
        $result = $purges->retryDue();

        $this->info(__('sirsoft-cdn_cloudfront::messages.retry.summary', [
            'retried' => $result['retried'],
            'succeeded' => $result['succeeded'],
            // 한도로 미룬 건(deferred)도 이번 차례에는 보내지 못했으므로 실패 건수에 함께 센다(시도 횟수는 쓰지 않았다).
            'failed' => $result['failed'] + $result['deferred'],
        ]));

        if ($result['abandoned'] > 0) {
            $this->warn(__('sirsoft-cdn_cloudfront::messages.retry.abandoned', ['count' => $result['abandoned']]));
        }

        return self::SUCCESS;
    }
}
