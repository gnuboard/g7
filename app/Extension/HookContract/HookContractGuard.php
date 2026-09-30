<?php

namespace App\Extension\HookContract;

use App\Contracts\Extension\CacheInterface;
use Illuminate\Support\Facades\Log;

/**
 * 훅 구독 등록 시점 가드 — 사용 중단된 훅이나 발화처가 없는 훅을 구독하면 경고를 남긴다.
 *
 * 서드파티 확장 개발자가 코어 업데이트 뒤 자기 리스너가 조용히 멈춘 것을 스스로 알 수 있게 하는 장치다
 * (설계 결정 2026-09-29). 등록은 요청마다 수백 건 일어나므로 판정은 배열 조회로만 하고, 문제가 있을 때만
 * 캐시로 하루 한 번(리스너 × 훅 단위)으로 묶어 기록한다 — 매 요청 같은 경고가 쌓이지 않게.
 */
class HookContractGuard
{
    /**
     * 이 프로세스에서 이미 판정한 리스너·훅 조합.
     *
     * @var array<string, true>
     */
    private static array $checked = [];

    /**
     * 구독 하나를 판정하고, 문제가 있으면 경고를 남깁니다.
     *
     * @param  string  $listenerClass  구독한 리스너 FQCN
     * @param  string  $hook  구독한 훅 이름
     * @return void
     */
    public static function checkSubscription(string $listenerClass, string $hook): void
    {
        $key = $listenerClass.'|'.$hook;
        if (isset(self::$checked[$key])) {
            return;
        }
        self::$checked[$key] = true;

        $problem = HookContract::subscriptionProblem($hook);
        if ($problem === null) {
            return;
        }

        try {
            $cache = app(CacheInterface::class);
            $cacheKey = 'hook_contract_warned:'.md5($key);
            if ($cache->has($cacheKey)) {
                return;
            }
            $cache->put($cacheKey, 1, 86400);
        } catch (\Throwable) {
            // 캐시를 못 쓰는 부팅 단계면 프로세스 단위 중복 제거만으로 기록한다
        }

        $message = $problem['kind'] === 'deprecated'
            ? '사용 중단된 훅을 구독하고 있습니다 — 대체 훅으로 옮겨 주세요'
            : '발화처가 없는 훅을 구독하고 있습니다 — 이 리스너는 실행되지 않습니다';

        Log::warning($message, [
            'listener' => $listenerClass,
            'hook' => $hook,
            'replacement' => $problem['replacement'],
            'note' => $problem['note'],
        ]);
    }

    /**
     * 프로세스 단위 판정 기록을 비웁니다 (테스트용).
     *
     * @return void
     */
    public static function flush(): void
    {
        self::$checked = [];
    }
}
