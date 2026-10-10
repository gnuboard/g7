<?php

namespace App\Listeners\Analytics;

use App\Analytics\ExclusionCounter;
use App\Contracts\Extension\HookListenerInterface;
use App\Extension\Consent\ConsentManager;

/**
 * 동의 철회 반영 리스너
 *
 * 코어가 소유한 범용 동의 변경 액션 `core.consent.changed` 를 구독합니다. 동의 제공자는
 * `ConsentManager::reportChange()` 로 바뀐 카테고리를 알리고 — 코어는 어느 확장이 알리는지 모릅니다 —
 * 이 리스너는 그중 분석 동의가 꺼진(true → false) 경우만 셉니다.
 *
 * 방문자가 분석 쿠키 동의를 철회하면 그 뒤로는 수집하지 않습니다. 다만 **이미 쌓인
 * 기록을 지우지는 않습니다** — 철회는 「앞으로 모으지 말라」는 뜻이고, 지난 기록을
 * 지우는 것은 삭제권 행사라 정보주체 요청 화면이 따로 처리합니다. 둘을 한데 묶으면
 * 운영자가 의도하지 않은 데이터 소실이 조용히 일어납니다.
 *
 * 수집 중단 자체는 동의 확인이 매 요청에서 이뤄지므로 별도 조치가 필요 없습니다.
 * 이 리스너는 그 사실이 진단 화면에 보이도록 카운터만 남깁니다.
 */
class ConsentRevokedListener implements HookListenerInterface
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
            ConsentManager::CHANGED_ACTION => ['method' => 'handle', 'priority' => 20],
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
        // 인자: 제공자 id, ConsentSubject, 바뀐 카테고리 [key => 새 동의 여부]. 바뀐 것만 실리므로
        // analytics 가 false 로 실렸다는 것은 true → false 철회다.
        $changes = $args[2] ?? null;
        if (! is_array($changes) || ($changes['analytics'] ?? null) !== false) {
            return;
        }

        // 「동의가 없어 버림」과 「동의를 철회함」은 다른 칸에 센다 — 같은 칸에 합치면
        // 진단 화면에서 철회 때문에 몇 건이 빠지는지 가를 수 없다.
        $this->counter->incrementKey(ExclusionCounter::CONSENT_REVOKED);
    }
}
