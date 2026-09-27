<?php

namespace App\Console\Commands\Analytics;

use App\Analytics\AnalyticsConfig;
use App\Services\Analytics\ReportMailService;
use Illuminate\Console\Command;

/**
 * 방문자 통계 정기 리포트 커맨드
 *
 * 지난 주·달의 요약을 만들어 알림 훅으로 밀어 올립니다. 스케줄이 걸려 있어도
 * 설정이 꺼져 있으면 아무것도 보내지 않습니다 — 설정을 끈 운영자에게 메일이 계속
 * 가면 그 알림 전체를 신뢰하지 않게 됩니다.
 *
 * @example php artisan analytics:report --period=weekly
 * @example php artisan analytics:report --period=monthly
 */
class ReportCommand extends Command
{
    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'analytics:report {--period=weekly : 기간 (weekly, monthly)}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '방문자 통계 정기 리포트를 만들어 알림으로 보냅니다.';

    /**
     * 커맨드 실행
     *
     * @param  ReportMailService  $service  리포트 서비스
     * @return int 종료 코드
     */
    public function handle(ReportMailService $service): int
    {
        $period = (string) $this->option('period');

        if (! in_array($period, ['weekly', 'monthly'], true)) {
            $this->error('기간은 weekly 또는 monthly 여야 합니다.');

            return self::FAILURE;
        }

        $report = $service->send($period, AnalyticsConfig::fromSettings());

        if ($report === null) {
            $this->info('리포트 발송이 꺼져 있어 보내지 않았습니다.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s 리포트를 보냈습니다 — 방문 %d건, 방문자 %d명, 페이지뷰 %d건',
            $report['period_label'],
            $report['visits'],
            $report['visitors'],
            $report['pageviews'],
        ));

        return self::SUCCESS;
    }
}
