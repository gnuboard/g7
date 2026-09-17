<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\IpAddress;
use App\Contracts\Repositories\Analytics\ArchiveRunRepositoryInterface;
use App\Contracts\Repositories\Analytics\SaltRepositoryInterface;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\ArchiveRunType;
use App\Extension\HookManager;
use App\Models\Analytics\AnalyticsArchiveRun;
use App\Models\Analytics\AnalyticsVisit;
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
 */
class AnonymizeService
{
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
     */
    public function __construct(
        private readonly VisitRepositoryInterface $visits,
        private readonly ArchiveRunRepositoryInterface $runs,
        private readonly SaltRepositoryInterface $salts,
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

        try {
            $total = $this->visits->countInDateRange($from, $to);
            $processed = 0;

            $this->visits->chunkInDateRange($from, $to, self::CHUNK_SIZE, function ($visits) use (
                $maskOctets,
                $hash,
                &$processed,
                $total,
                $run,
            ): void {
                foreach ($visits as $visit) {
                    $this->visits->update($visit, $this->anonymizedAttributes($visit, $maskOctets, $hash));
                    $processed++;
                }

                $this->runs->update($run, [
                    'processed_visits' => $processed,
                    'progress_percent' => $total > 0 ? (int) min(99, floor($processed / $total * 100)) : 99,
                ]);
            });

            $this->runs->update($run, [
                'status' => ArchiveRunStatus::Success->value,
                'processed_visits' => $processed,
                'progress_percent' => 100,
                'finished_at' => AnalyticsClock::now(),
            ]);

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

            Log::error('방문자 통계 소급 익명화 실패', ['error' => $e->getMessage()]);
        }

        return $run->refresh();
    }

    /**
     * 방문 하나에 적용할 익명화 값을 만듭니다.
     *
     * @param  AnalyticsVisit  $visit  방문
     * @param  int  $maskOctets  가릴 옥텟 수
     * @param  bool  $hash  주소를 지우고 해시만 남길지 여부
     * @return array<string, mixed> 덮어쓸 속성
     */
    private function anonymizedAttributes(AnalyticsVisit $visit, int $maskOctets, bool $hash): array
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

        return $attributes;
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
