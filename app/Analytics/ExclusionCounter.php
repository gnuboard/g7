<?php

namespace App\Analytics;

use App\Contracts\Extension\CacheInterface;
use App\Enums\Analytics\ExclusionReason;
use App\Extension\HookManager;

/**
 * 제외 사유 카운터
 *
 * 방문을 세지 않기로 한 이유를 사유별로 셉니다. 제외는 조용히 일어나므로 이 숫자가
 * 없으면 운영자는 「방문이 왜 이렇게 적은가」에 답할 방법이 없습니다.
 *
 * 표가 아니라 캐시에 둡니다 — 제외는 방문보다 훨씬 자주 일어나는데 그때마다 행을
 * 남기면 봇 트래픽에 원장이 잠식됩니다. 대신 캐시가 비워지면 숫자도 사라지므로
 * 진단 화면이 그 사실을 함께 알립니다.
 *
 * 증가가 원자적이지 않아 동시 제외가 몰리면 몇 건이 덜 세어질 수 있습니다. 이 숫자는
 * 「무엇 때문에 얼마나 빠지는가」를 가늠하는 용도라 그 오차를 감수합니다 — 정확한
 * 계수가 필요한 값은 원장이 갖습니다.
 */
class ExclusionCounter
{
    /** 카운터 보관 기간 (초) — 진단 화면이 오늘·어제를 읽는다 */
    private const TTL_SECONDS = 172800;

    /** 제외 사유가 아닌 진단 계수 키 — 동의 철회 건수 */
    public const CONSENT_REVOKED = 'consent_revoked';

    /** 제외 사유 열거형 밖의 진단 계수 키 목록 */
    public const EVENT_KEYS = [self::CONSENT_REVOKED];

    /**
     * 제외 사유 카운터를 생성합니다.
     *
     * @param  CacheInterface  $cache  캐시 저장소
     */
    public function __construct(private readonly CacheInterface $cache) {}

    /**
     * 제외 사유를 하나 셉니다.
     *
     * 카운터 실패가 수집 경로를 막지 않도록 예외를 삼킵니다 — 세지 못한 것보다
     * 요청이 깨지는 쪽이 나쁩니다.
     *
     * @param  ExclusionReason  $reason  제외 사유
     * @param  string|null  $date  사이트 표준시 기준 일자 (기본: 오늘)
     * @return void
     */
    public function increment(ExclusionReason $reason, ?string $date = null): void
    {
        $this->incrementKey($reason->value, $date);
    }

    /**
     * 제외 사유가 아닌 진단 계수를 하나 셉니다.
     *
     * 동의 철회처럼 「방문을 버린 이유」가 아니라 「앞으로 안 세게 된 사건」은 제외 사유
     * 열거형에 넣지 않습니다 — 그 열거형은 파이프라인이 돌려주는 값의 목록이라, 사건을
     * 섞으면 「제외 12사유」가 더 이상 파이프라인과 일치하지 않습니다.
     *
     * @param  string  $key  진단 계수 키 (`self::EVENT_KEYS` 또는 제외 사유 값)
     * @param  string|null  $date  사이트 표준시 기준 일자 (기본: 오늘)
     * @return void
     */
    public function incrementKey(string $key, ?string $date = null): void
    {
        $key = self::key($key, $date ?? AnalyticsClock::today());

        try {
            $current = (int) $this->cache->get($key, 0);
            $this->cache->put($key, $current + 1, self::TTL_SECONDS);
        } catch (\Throwable $e) {
            // 캐시 저장소가 죽어도 수집은 계속된다.
        }
    }

    /**
     * 하루치 사유별 건수를 읽습니다.
     *
     * @param  string|null  $date  사이트 표준시 기준 일자 (기본: 오늘)
     * @return array<string, int> 사유별 건수 (0 인 사유도 포함)
     */
    public function forDate(?string $date = null): array
    {
        $date ??= AnalyticsClock::today();
        $result = [];

        $keys = array_merge(
            array_column(ExclusionReason::cases(), 'value'),
            self::EVENT_KEYS,
            array_keys(self::extensionReasons()),
        );

        foreach ($keys as $key) {
            try {
                $result[$key] = (int) $this->cache->get(self::key($key, $date), 0);
            } catch (\Throwable $e) {
                $result[$key] = 0;
            }
        }

        return $result;
    }

    /**
     * 확장이 등록한 제외 사유와 표시 이름을 반환합니다.
     *
     * 필터 `core.analytics.registered_exclusion_reasons` — 인자: 빈 배열. 확장은
     * `'{식별자}:{사유}' => 이름(문자열 또는 로케일 맵)` 을 더합니다. 카운터 키는
     * `ext:{식별자}:{사유}` 로 정규화됩니다 — 코어 사유와 이름이 겹치지 않게 합니다.
     *
     * 등록하지 않은 사유로는 제외할 수 없습니다(`VisitExclusionPipeline`). 진단 화면이 셀 수
     * 없는 제외는 운영자에게 보이지 않는 구멍이 되기 때문입니다.
     *
     * @return array<string, string> 카운터 키 → 현재 로케일 표시 이름
     */
    public static function extensionReasons(): array
    {
        $declared = HookManager::applyFilters('core.analytics.registered_exclusion_reasons', []);
        $reasons = [];

        foreach (is_array($declared) ? $declared : [] as $name => $label) {
            $key = self::extensionKey((string) $name);

            if ($key === null) {
                continue;
            }

            $reasons[$key] = LocalizedText::pick(LocalizedText::normalize($label), substr($key, 4));
        }

        return $reasons;
    }

    /**
     * 확장 제외 사유 이름을 카운터 키로 바꿉니다.
     *
     * @param  string  $name  `{식별자}:{사유}` 또는 `ext:{식별자}:{사유}`
     * @return string|null `ext:{식별자}:{사유}` (형식이 아니면 null)
     */
    public static function extensionKey(string $name): ?string
    {
        $name = str_starts_with($name, 'ext:') ? substr($name, 4) : $name;

        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}:[a-z0-9_]{1,50}$/', $name) !== 1) {
            return null;
        }

        return 'ext:'.$name;
    }

    /**
     * 카운터 캐시 키를 만듭니다.
     *
     * @param  string  $reason  제외 사유 값
     * @param  string  $date  일자 (Y-m-d)
     * @return string 캐시 키
     */
    private static function key(string $reason, string $date): string
    {
        return 'analytics:excluded:'.str_replace('-', '', $date).':'.$reason;
    }
}
