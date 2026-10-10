<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\IpAddress;
use App\Contracts\Analytics\DimensionRegistryInterface;
use App\Contracts\Repositories\Analytics\ActionRepositoryInterface;
use App\Contracts\Repositories\Analytics\ArchiveRunRepositoryInterface;
use App\Contracts\Repositories\Analytics\BotHitRepositoryInterface;
use App\Contracts\Repositories\Analytics\CollectFailureRepositoryInterface;
use App\Contracts\Repositories\Analytics\ConversionRepositoryInterface;
use App\Contracts\Repositories\Analytics\SaltRepositoryInterface;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\ArchiveRunType;
use App\Extension\HookManager;
use App\Models\Analytics\AnalyticsArchiveRun;
use App\Models\Analytics\AnalyticsVisit;
use App\Services\Analytics\Concerns\BroadcastsRunProgress;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 쌓인 기록의 소급 익명화
 *
 * 개인정보 수준을 더 엄격한 쪽으로 바꾼 운영자가, 이미 쌓인 기록에도 같은 수준을
 * 적용하고 싶을 때 씁니다. 되돌릴 수 없습니다 — 마스킹으로 지운 자리는 복구되지
 * 않습니다.
 *
 * 집계 표는 건드리지 않습니다. 집계에는 개인을 가리키는 값이 없기 때문입니다.
 *
 * 주소가 남는 표는 방문 원장만이 아닙니다 — 봇 기록과 적재 실패 로그도 수집할 때 같은
 * 마스킹 규칙을 따르므로, 소급 적용도 세 표에 함께 겁니다.
 */
class AnonymizeService
{
    use BroadcastsRunProgress;

    /**
     * 한 번에 처리할 방문 수
     */
    private const CHUNK_SIZE = 1000;

    /**
     * 익명화기를 생성합니다.
     *
     * @param  VisitRepositoryInterface  $visits  방문 원장 저장소
     * @param  ArchiveRunRepositoryInterface  $runs  실행 이력 저장소
     * @param  SaltRepositoryInterface  $salts  솔트 저장소
     * @param  BotHitRepositoryInterface  $botHits  봇 기록 저장소
     * @param  CollectFailureRepositoryInterface  $failures  적재 실패 로그 저장소
     * @param  ActionRepositoryInterface  $actions  액션 원장 저장소
     * @param  ConversionRepositoryInterface  $conversions  전환 원장 저장소
     * @param  DimensionRegistryInterface  $dimensions  확장 차원 등록부 — 개인정보 차원을 찾는다
     */
    public function __construct(
        private readonly VisitRepositoryInterface $visits,
        private readonly ArchiveRunRepositoryInterface $runs,
        private readonly SaltRepositoryInterface $salts,
        private readonly BotHitRepositoryInterface $botHits,
        private readonly CollectFailureRepositoryInterface $failures,
        private readonly ActionRepositoryInterface $actions,
        private readonly ConversionRepositoryInterface $conversions,
        private readonly DimensionRegistryInterface $dimensions,
    ) {}

    /**
     * 기간 안의 방문에 마스킹·해시를 소급 적용합니다.
     *
     * @param  array<string, mixed>  $options  적용 옵션 (mask_octets, hash, from, to, triggered_by, trigger)
     * @return AnalyticsArchiveRun 실행 이력
     */
    public function anonymize(array $options = []): AnalyticsArchiveRun
    {
        $maskOctets = max(0, min(3, (int) ($options['mask_octets'] ?? 0)));
        $hash = (bool) ($options['hash'] ?? false);
        $from = $options['from'] ?? null;
        $to = $options['to'] ?? null;

        $run = $this->runs->create([
            'run_type' => ArchiveRunType::Anonymize->value,
            'period_start' => $from !== null ? $from.' 00:00:00' : null,
            'period_end' => $to !== null ? $to.' 23:59:59' : null,
            'trigger' => $options['trigger'] ?? 'manual',
            'status' => ArchiveRunStatus::Running->value,
            'progress_percent' => 0,
            'started_at' => AnalyticsClock::now(),
            'triggered_by' => $options['triggered_by'] ?? null,
        ]);

        // 진단 화면은 이 표의 행 하나를 진행률의 출처로 삼는다 — 집계가 아닌 실행도 같은 자리에
        // 뜨므로 같은 채널로 알린다(#682 S3 라운드 2).
        $this->broadcastRunProgress($run);

        try {
            $total = $this->visits->countInDateRange($from, $to);
            $processed = 0;
            // 해시 모드는 「익명 수준으로 내린다」는 뜻이다 — 수집 때 익명 모드가 싣지 않는 개인정보
            // 차원도 같은 기준으로 걷어 낸다.
            $piiKeys = $hash ? $this->piiDimensionKeys() : [];
            $context = ['mask_octets' => $maskOctets, 'hash' => $hash];

            $this->visits->chunkInDateRange($from, $to, self::CHUNK_SIZE, function ($visits) use (
                $maskOctets,
                $hash,
                $piiKeys,
                $context,
                &$processed,
                $total,
                $run,
            ): void {
                foreach ($visits as $visit) {
                    $attributes = $this->anonymizedAttributes($visit, $maskOctets, $hash, $piiKeys);

                    // 확장은 이 방문을 받아 덮어쓸 값을 보태거나, 자기 표의 같은 방문 기록을 함께
                    // 익명화한다. 코어가 정한 값(주소·해시·회원 번호)은 필터 결과로 되돌릴 수 없다.
                    $filtered = HookManager::applyFilters('core.analytics.anonymize_attributes', $attributes, $visit, $context);
                    $attributes = array_merge(is_array($filtered) ? $filtered : [], $attributes);

                    if ($attributes !== []) {
                        $this->visits->update($visit, $attributes);
                    }

                    $processed++;
                }

                if ($piiKeys !== []) {
                    $visitIds = $visits->pluck('id')->all();
                    $this->actions->forgetDimensionsForVisits($visitIds, $piiKeys);
                    $this->conversions->forgetDimensionsForVisits($visitIds, $piiKeys);
                }

                $this->runs->update($run, [
                    'processed_visits' => $processed,
                    'progress_percent' => $total > 0 ? (int) min(99, floor($processed / $total * 100)) : 99,
                ]);

                $this->broadcastRunProgress($run->refresh());
            });

            // 봇 기록·실패 로그에는 해시를 담을 칸이 없다. 해시 모드에서는 주소를 지우고,
            // 마스킹 모드에서는 방문 원장과 같은 옥텟 수로 가린다.
            $this->anonymizeSideLedgers($from, $to, $maskOctets, $hash);

            $this->runs->update($run, [
                'status' => ArchiveRunStatus::Success->value,
                'processed_visits' => $processed,
                'progress_percent' => 100,
                'finished_at' => AnalyticsClock::now(),
            ]);

            $this->broadcastRunProgress($run->refresh());

            HookManager::doAction('core.analytics.anonymized', [
                'mask_octets' => $maskOctets,
                'hash' => $hash,
                'from' => $from,
                'to' => $to,
                'processed' => $processed,
            ]);
        } catch (Throwable $e) {
            $this->runs->update($run, [
                'status' => ArchiveRunStatus::Failed->value,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => AnalyticsClock::now(),
            ]);

            $this->broadcastRunProgress($run->refresh());

            Log::error('방문자 통계 소급 익명화 실패', ['error' => $e->getMessage()]);
        }

        return $run->refresh();
    }

    /**
     * 봇 기록·적재 실패 로그의 주소에도 같은 규칙을 적용합니다.
     *
     * 두 표에는 `ip_hash` 칸이 없습니다. 해시 모드는 되돌릴 수 없게 주소를 지우는 것이
     * 목적이므로, 담을 곳이 없으면 지우는 쪽이 그 목적에 맞습니다.
     *
     * @param  string|null  $from  시작 일자 (Y-m-d, null 이면 처음부터)
     * @param  string|null  $to  끝 일자 (Y-m-d, null 이면 끝까지)
     * @param  int  $maskOctets  가릴 옥텟 수
     * @param  bool  $hash  주소를 지울지 여부
     * @return void 없음
     */
    private function anonymizeSideLedgers(?string $from, ?string $to, int $maskOctets, bool $hash): void
    {
        if (! $hash && $maskOctets <= 0) {
            return;
        }

        $newIp = fn (string $ip): ?string => $hash ? null : IpAddress::mask($ip, $maskOctets);

        $this->botHits->chunkInDateRange($from, $to, self::CHUNK_SIZE, function ($hits) use ($newIp): void {
            foreach ($hits as $hit) {
                if ($hit->ip !== null) {
                    $this->botHits->updateIp($hit, $newIp((string) $hit->ip));
                }
            }
        });

        $this->failures->chunkInDateRange($from, $to, self::CHUNK_SIZE, function ($failures) use ($newIp): void {
            foreach ($failures as $failure) {
                if ($failure->ip !== null) {
                    $this->failures->updateIp($failure, $newIp((string) $failure->ip));
                }
            }
        });
    }

    /**
     * 방문 하나에 적용할 익명화 값을 만듭니다.
     *
     * @param  AnalyticsVisit  $visit  방문
     * @param  int  $maskOctets  가릴 옥텟 수
     * @param  bool  $hash  주소를 지우고 해시만 남길지 여부
     * @param  array<int, string>  $piiKeys  걷어 낼 개인정보 차원 이름
     * @return array<string, mixed> 덮어쓸 속성
     */
    private function anonymizedAttributes(AnalyticsVisit $visit, int $maskOctets, bool $hash, array $piiKeys = []): array
    {
        $ip = $visit->ip;
        $attributes = [];

        if ($hash) {
            // 해시는 그 방문이 일어난 날의 솔트로 만든다. 그 날 솔트가 이미 지워졌으면
            // 날짜로 만든 고정 솔트를 쓴다 — 다른 날 기록과는 이어 붙지 않지만, 같은
            // 날 안에서 같은 사람을 찾는 일은 된다.
            $date = $visit->visit_date?->toDateString() ?? AnalyticsClock::today();
            $salt = $this->salts->findForDate($date) ?? 'anonymized-'.$date;

            $attributes['ip_hash'] = $ip !== null ? hash_hmac('sha256', (string) $ip, $salt) : null;
            $attributes['ip'] = null;
        } elseif ($ip !== null && $maskOctets > 0) {
            $attributes['ip'] = IpAddress::mask((string) $ip, $maskOctets);
        }

        if ($visit->referrer_url !== null && $visit->referrer_url !== '') {
            $attributes['referrer_url'] = $this->domainOnly((string) $visit->referrer_url);
        }

        if ($visit->user_id !== null) {
            $attributes['user_hash'] = hash_hmac('sha256', (string) $visit->user_id, (string) config('app.key'));
            $attributes['user_id'] = null;
        }

        if ($piiKeys !== [] && is_array($visit->custom_dimensions)) {
            $remaining = array_diff_key($visit->custom_dimensions, array_flip($piiKeys));

            if (count($remaining) !== count($visit->custom_dimensions)) {
                $attributes['custom_dimensions'] = $remaining === [] ? null : $remaining;
            }
        }

        return $attributes;
    }

    /**
     * 개인정보로 선언된 확장 차원의 원장 이름을 모읍니다.
     *
     * 등록부에 없는 차원(확장이 꺼진 뒤)은 알 수 없으므로 걷어 내지 못합니다. 확장을 지울 때
     * 데이터까지 지우면 전환·집계가 정리되고, 원장 값은 보존 기간이 지나면 함께 사라집니다.
     *
     * @return array<int, string> `{식별자}:{키}` 목록
     */
    private function piiDimensionKeys(): array
    {
        return array_values(array_map(
            fn ($definition): string => $definition->storageKey(),
            array_filter($this->dimensions->all(), fn ($definition): bool => $definition->pii),
        ));
    }

    /**
     * 유입 주소에서 도메인만 남깁니다.
     *
     * @param  string  $url  유입 주소
     * @return string 도메인까지만 남긴 주소
     */
    private function domainOnly(string $url): string
    {
        $parts = parse_url($url);

        if (! isset($parts['host'])) {
            return $url;
        }

        return ($parts['scheme'] ?? 'https').'://'.$parts['host'];
    }
}
