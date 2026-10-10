<?php

namespace Modules\Sirsoft\Ecommerce\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;

/**
 * 선교환 회수 지연 관리자 알림 커맨드 (D2)
 *
 * 새 물건을 먼저 보낸 선교환에서 헌 물건이 반송 기한까지 오지 않으면 운영자에게 **한 번** 알린다.
 * 돈 청구는 쇼핑몰 밖에서 한다 — 이 배치는 알리기만 한다. 판정·기록은
 * `OrderExchangeService::notifyOverdueCollections()` 가 소유한다.
 */
class NotifyOverdueCollectionsCommand extends Command
{
    /**
     * 커맨드 시그니처
     *
     * @var string
     */
    protected $signature = 'sirsoft-ecommerce:claims:notify-overdue-collections
                            {--dry-run : 실제 알림 없이 대상 건수만 확인}
                            {--limit=200 : 한 번에 처리할 최대 건수}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '반송 기한을 넘긴 선교환의 회수 지연을 관리자에게 한 번 알립니다.';

    /**
     * @param  OrderExchangeService  $exchangeService  교환 서비스
     */
    public function __construct(protected OrderExchangeService $exchangeService)
    {
        parent::__construct();
    }

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $isDryRun = (bool) $this->option('dry-run');

        $result = $this->exchangeService->notifyOverdueCollections($limit, $isDryRun);

        if ($isDryRun) {
            $this->info("[dry-run] 알림 대상 {$result['notified']}건 / 회수 대기 없음 {$result['skipped']}건 (검사 {$result['scanned']}건)");

            return Command::SUCCESS;
        }

        $this->info("선교환 회수 지연 알림: 검사 {$result['scanned']}건 / 알림 {$result['notified']}건 / 회수 대기 없음 {$result['skipped']}건");

        Log::info('[claim.exchange] 선교환 회수 지연 알림 배치', [
            'limit' => $limit,
            'scanned' => $result['scanned'],
            'notified' => $result['notified'],
            'skipped' => $result['skipped'],
        ]);

        return Command::SUCCESS;
    }
}
