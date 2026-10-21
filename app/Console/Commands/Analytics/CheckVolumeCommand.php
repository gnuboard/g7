<?php

namespace App\Console\Commands\Analytics;

use App\Analytics\AnalyticsConfig;
use App\Services\Analytics\VolumeCheckService;
use Illuminate\Console\Command;

/**
 * 방문자 통계 원장 용량 점검 커맨드
 *
 * 원장이 운영자가 정한 한계를 넘었으면 알림을 밀어 올립니다. 통계 표는 조용히
 * 자라므로, 알려 주는 쪽이 없으면 디스크가 찰 때까지 아무도 모릅니다.
 *
 * @example php artisan analytics:check-volume
 */
class CheckVolumeCommand extends Command
{
    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'analytics:check-volume';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '방문자 통계 원장이 경고 한계를 넘었는지 확인하고 알립니다.';

    /**
     * 커맨드 실행
     *
     * @param  VolumeCheckService  $service  용량 점검 서비스
     * @return int 종료 코드
     */
    public function handle(VolumeCheckService $service): int
    {
        $result = $service->check(AnalyticsConfig::fromSettings());

        $this->info(__('analytics.console.check_volume.rows', ['visits' => $result['visits'], 'actions' => $result['actions']]));

        if (! $result['exceeded']) {
            $this->info(__('analytics.console.check_volume.within_limit'));

            return self::SUCCESS;
        }

        $this->warn($result['notified']
            ? __('analytics.console.check_volume.exceeded_notified')
            : __('analytics.console.check_volume.exceeded_already_notified'));

        return self::SUCCESS;
    }
}
