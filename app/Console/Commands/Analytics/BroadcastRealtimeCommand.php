<?php

namespace App\Console\Commands\Analytics;

use App\Analytics\AnalyticsConfig;
use App\Extension\HookManager;
use App\Services\Analytics\RealtimeService;
use Illuminate\Console\Command;

/**
 * 현재 접속자 현황 브로드캐스트 커맨드
 *
 * 실시간 화면이 30초마다 서버에 묻는 대신, 서버가 밀어 주는 길입니다. 브로드캐스트가
 * 설정되지 않은 사이트에서는 스케줄이 등록되지 않고 화면이 폴링으로 돕니다.
 *
 * 주소는 담지 않습니다 — 브로드캐스트는 채널을 듣는 모든 연결에 같은 내용이 가므로,
 * 개인정보 권한에 따라 다르게 보낼 수 없습니다.
 *
 * @example php artisan analytics:broadcast-realtime
 */
class BroadcastRealtimeCommand extends Command
{
    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'analytics:broadcast-realtime';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '현재 접속자 현황을 관리자 채널로 브로드캐스트합니다.';

    /**
     * 커맨드 실행
     *
     * @param  RealtimeService  $service  현재 접속자 서비스
     * @return int 종료 코드
     */
    public function handle(RealtimeService $service): int
    {
        $snapshot = $service->snapshot(AnalyticsConfig::fromSettings(), ['include_ip' => false]);

        HookManager::broadcast('core.admin.analytics', 'analytics.realtime.updated', [
            'type' => 'realtime',
            'data' => $snapshot,
        ]);

        $this->info(sprintf('현재 접속자 %d명을 브로드캐스트했습니다.', $snapshot['count']));

        return self::SUCCESS;
    }
}
