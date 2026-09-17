<?php

namespace App\Jobs\Analytics;

use App\Services\Analytics\AnonymizeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 소급 익명화 잡
 *
 * 개인정보 수준을 엄격하게 바꾼 운영자가 「기존 기록에도 적용」을 고르면 부릅니다.
 * 쌓인 방문이 많으면 오래 걸리므로 워커에게 넘기고 진행률만 화면에 보입니다.
 */
class AnonymizeLedgerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 최대 실행 시간 (초)
     */
    public int $timeout = 3600;

    /**
     * 재시도 횟수
     *
     * 익명화는 되돌릴 수 없는 변경이라 자동 재시도로 같은 구간을 두 번 훑지 않는다.
     */
    public int $tries = 1;

    /**
     * 잡을 만듭니다.
     *
     * @param  int  $maskOctets  가릴 주소 옥텟 수 (0~3)
     * @param  bool  $hash  주소를 지우고 해시만 남길지 여부
     * @param  string|null  $from  시작일 (Y-m-d)
     * @param  string|null  $to  종료일 (Y-m-d)
     * @param  int|null  $triggeredBy  실행한 관리자 ID
     */
    public function __construct(
        private readonly int $maskOctets,
        private readonly bool $hash,
        private readonly ?string $from = null,
        private readonly ?string $to = null,
        private readonly ?int $triggeredBy = null,
    ) {}

    /**
     * 잡을 실행합니다.
     *
     * @param  AnonymizeService  $service  익명화 서비스
     * @return void 없음
     */
    public function handle(AnonymizeService $service): void
    {
        $service->anonymize([
            'mask_octets' => $this->maskOctets,
            'hash' => $this->hash,
            'from' => $this->from,
            'to' => $this->to,
            'trigger' => 'manual',
            'triggered_by' => $this->triggeredBy,
        ]);
    }
}
