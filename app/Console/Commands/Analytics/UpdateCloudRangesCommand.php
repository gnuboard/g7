<?php

namespace App\Console\Commands\Analytics;

use App\Services\Analytics\CloudIpRangeService;
use Illuminate\Console\Command;

/**
 * 클라우드 IP 대역 목록 갱신 커맨드
 *
 * 데이터센터에서 오는 요청을 거르려면 그 대역 목록이 최신이어야 합니다. 사업자가
 * 공개하는 목록을 내려받아 파일로 둡니다.
 *
 * 목록을 못 받아 와도 기존 파일은 남깁니다 — 새 목록이 없을 때 옛 목록이 사라지면
 * 그 순간부터 모든 데이터센터 요청이 사람으로 집계됩니다.
 *
 * @example php artisan analytics:update-cloud-ranges
 */
class UpdateCloudRangesCommand extends Command
{
    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'analytics:update-cloud-ranges';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '클라우드 사업자의 IP 대역 목록을 내려받아 갱신합니다.';

    /**
     * 커맨드 실행
     *
     * @param  CloudIpRangeService  $service  대역 목록 서비스
     * @return int 종료 코드
     */
    public function handle(CloudIpRangeService $service): int
    {
        $result = $service->refresh();

        if ($result['failed'] !== []) {
            $this->warn('받지 못한 출처: '.implode(', ', $result['failed']));
        }

        if ($result['count'] === 0) {
            $this->error('대역을 하나도 받지 못해 기존 목록을 그대로 둡니다.');

            return self::FAILURE;
        }

        $this->info(sprintf('클라우드 IP 대역 %d건을 갱신했습니다.', $result['count']));

        return self::SUCCESS;
    }
}
