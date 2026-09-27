<?php

namespace App\Listeners\Analytics;

use App\Analytics\ExclusionCounter;
use App\Contracts\Extension\HookListenerInterface;

/**
 * 동의 철회 반영 리스너
 *
 * 방문자가 분석 쿠키 동의를 철회하면 그 뒤로는 수집하지 않습니다. 다만 **이미 쌓인
 * 기록을 지우지는 않습니다** — 철회는 「앞으로 모으지 말라」는 뜻이고, 지난 기록을
 * 지우는 것은 삭제권 행사라 정보주체 요청 화면이 따로 처리합니다. 둘을 한데 묶으면
 * 운영자가 의도하지 않은 데이터 소실이 조용히 일어납니다.
 *
 * 수집 중단 자체는 동의 확인이 매 요청에서 이뤄지므로 별도 조치가 필요 없습니다.
 * 이 리스너는 그 사실이 진단 화면에 보이도록 카운터만 남깁니다.
 */
class GdprConsentRevokedListener implements HookListenerInterface
{
    /**
     * 리스너를 생성합니다.
     *
     * @param  ExclusionCounter  $counter  제외 사유 카운터
     */
    public function __construct(private readonly ExclusionCounter $counter) {}

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 구독 정의
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-gdpr.consent.revoked' => ['method' => 'handle', 'priority' => 20],
        ];
    }

    /**
     * 동의 철회를 진단 카운터에 남깁니다.
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void
    {
        // 「동의가 없어 버림」과 「동의를 철회함」은 다른 칸에 센다 — 같은 칸에 합치면
        // 진단 화면에서 철회 때문에 몇 건이 빠지는지 가를 수 없다.
        $this->counter->incrementKey(ExclusionCounter::CONSENT_REVOKED);
    }
}
