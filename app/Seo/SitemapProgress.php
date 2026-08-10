<?php

namespace App\Seo;

use App\Contracts\Extension\CacheInterface;
use App\Extension\HookManager;

/**
 * Sitemap 재생성 진행상황 스토어.
 *
 * 재생성 잡/커맨드가 단계(phase)를 기록하면 캐시(seo.sitemap.progress)에 저장하고,
 * Reverb 가 활성일 때는 관리자 채널로 방송해 SEO 탭이 실시간으로 갱신되게 합니다.
 * Reverb OFF 시 방송은 HookManager 가 자동으로 건너뛰며(분기 불필요), 캐시는 항상
 * 기록되므로 프론트가 폴링으로 폴백할 수 있습니다.
 *
 * 상태 전이: queued → running(기여자별) → writing → completed | failed
 * (진행바는 퍼센트가 아닌 단계 기반 — 사전 count 쿼리가 1.4M 에서 부담이므로.)
 */
final class SitemapProgress
{
    /**
     * 진행상황 캐시 키
     */
    public const KEY = 'seo.sitemap.progress';

    /**
     * 방송 채널명 (관리자 전용)
     */
    public const CHANNEL = 'core.admin.seo.sitemap';

    /**
     * 방송 이벤트명 (클라이언트는 `.` 접두로 수신)
     */
    public const EVENT = 'sitemap.progress.updated';

    /**
     * 진행상황 캐시 TTL (초) — 잡이 하드 kill 로 죽어도 이 시간 후 자동 만료(무한 running 방지)
     */
    private const TTL = 3600;

    /**
     * URL 누적 방송 간격 — 같은 단계에서 urls 만 증가할 때 이 간격마다만 방송(방송 폭주 방지)
     */
    private const BROADCAST_URL_INTERVAL = 5000;

    /**
     * 마지막으로 방송한 시점의 누적 URL 수
     */
    private int $lastBroadcastUrls = 0;

    public function __construct(private CacheInterface $cache) {}

    /**
     * 재생성 예약을 기록합니다(큐 대기 중에도 UI 가 'queued' 를 표시).
     *
     * @param  string  $mode  재생성 모드(full|auto|incremental)
     */
    public function start(string $mode): void
    {
        $this->lastBroadcastUrls = 0;

        $this->persist([
            'status' => 'queued',
            'mode' => $mode,
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
            'phase' => null,
            'urls' => 0,
            'url_count' => null,
            'child_count' => null,
            'message' => null,
        ], broadcast: true);
    }

    /**
     * 현재 처리 중인 기여자 단계를 기록합니다.
     *
     * 단계가 바뀌면 즉시 방송하고, 같은 단계에서 urls 만 증가하면 간격 스로틀을 적용합니다.
     * 캐시는 폴링 정확도를 위해 항상 기록합니다.
     *
     * @param  string  $phase  단계 식별자(기여자 identifier)
     * @param  int|null  $urls  누적 URL 수(선택)
     */
    public function phase(string $phase, ?int $urls = null): void
    {
        $state = $this->current();
        $phaseChanged = ($state['phase'] ?? null) !== $phase;

        $state['status'] = 'running';
        $state['phase'] = $phase;
        if ($urls !== null) {
            $state['urls'] = $urls;
        }

        $broadcast = $phaseChanged
            || ($urls !== null && ($urls - $this->lastBroadcastUrls) >= self::BROADCAST_URL_INTERVAL);

        $this->persist($state, broadcast: $broadcast);

        if ($broadcast) {
            $this->lastBroadcastUrls = (int) ($state['urls'] ?? 0);
        }
    }

    /**
     * 파일 작성 단계를 기록합니다.
     *
     * @param  int|null  $urls  작성 대상 누적 URL 수(선택)
     */
    public function writing(?int $urls = null): void
    {
        $state = $this->current();
        $state['status'] = 'writing';
        $state['phase'] = null;
        if ($urls !== null) {
            $state['urls'] = $urls;
        }

        $this->persist($state, broadcast: true);
    }

    /**
     * 재생성 완료를 기록합니다.
     *
     * @param  array<string, mixed>  $meta  생성 메타(url_count, child_count 등)
     */
    public function complete(array $meta): void
    {
        $state = $this->current();
        $state['status'] = 'completed';
        $state['phase'] = null;
        $state['finished_at'] = now()->toIso8601String();
        $state['url_count'] = $meta['url_count'] ?? null;
        $state['child_count'] = $meta['child_count'] ?? null;
        $state['urls'] = $meta['url_count'] ?? ($state['urls'] ?? 0);
        $state['message'] = null;

        // 완료 시점엔 방금 커밋된 새 생성 시각을 실어 "마지막 생성"이 실시간 갱신되게 한다.
        $this->persist($state, broadcast: true, lastUpdatedAt: $meta['generated_at'] ?? null);
    }

    /**
     * 재생성 실패를 기록합니다(무한 running 방지).
     *
     * @param  string  $message  실패 메시지
     */
    public function fail(string $message): void
    {
        $state = $this->current();
        $state['status'] = 'failed';
        $state['phase'] = null;
        $state['finished_at'] = now()->toIso8601String();
        $state['message'] = $message;

        $this->persist($state, broadcast: true);
    }

    /**
     * 현재 진행상황을 반환합니다(폴링/초기 로드용).
     *
     * @return array<string, mixed>|null 진행상황 또는 아직 실행 이력이 없으면 null
     */
    public function get(): ?array
    {
        $state = $this->cache->get(self::KEY);

        return is_array($state) ? $state : null;
    }

    /**
     * 현재 진행상황을 반환하되, 없으면 기본 골격을 돌려줍니다(부분 갱신 병합용).
     *
     * @return array<string, mixed> 진행상황 골격
     */
    private function current(): array
    {
        return $this->get() ?? [
            'status' => 'idle',
            'mode' => null,
            'started_at' => null,
            'finished_at' => null,
            'phase' => null,
            'urls' => 0,
            'url_count' => null,
            'child_count' => null,
            'message' => null,
        ];
    }

    /**
     * 진행상황을 캐시에 기록하고, 필요 시 관리자 채널로 방송합니다.
     *
     * 방송 payload 는 상태 API 응답 봉투와 동형입니다 — 최상위 `data` 봉투 안에
     * `progress`/`last_updated_at`/`realtime_enabled` 를 담습니다(대시보드 방송과 동일 규약).
     * websocket 데이터소스가 `target_source` 로 대상 소스의 저장값을 통째 교체하므로, 봉투
     * 형태여야 교체 후에도 템플릿의 `sitemap_status.data.*` 바인딩이 유지됩니다(D27).
     *
     * @param  array<string, mixed>  $state  진행상황 상태
     * @param  bool  $broadcast  방송 여부(스로틀 판정 결과)
     * @param  string|null  $lastUpdatedAt  이 방송에 실을 마지막 생성 시각(완료 시 새 시각, 그 외 직전 값)
     */
    private function persist(array $state, bool $broadcast, ?string $lastUpdatedAt = null): void
    {
        $this->cache->put(self::KEY, $state, self::TTL);

        if (! $broadcast) {
            return;
        }

        // 미지정(진행 중 단계)이면 직전 완료 시각(settings)을 싣는다. target_source 가 대상 소스
        // data 를 통째 교체하므로 매 방송마다 형제 필드를 온전히 실어야 "마지막 생성"이 유지된다.
        if ($lastUpdatedAt === null) {
            $stored = (string) g7_core_settings('seo.sitemap_last_updated_at', '');
            $lastUpdatedAt = $stored !== '' ? $stored : null;
        }

        // 방송 payload 는 상태 API 응답 봉투와 동형이어야 한다(대시보드 방송과 동일 규약).
        // 최상위 data 봉투 안에 progress/last_updated_at/realtime_enabled 를 담아야
        // target_source 교체 후에도 템플릿의 `sitemap_status.data.*` 바인딩이 유지된다.
        HookManager::broadcast(self::CHANNEL, self::EVENT, [
            'data' => [
                'last_updated_at' => $lastUpdatedAt,
                'progress' => $state,
                'realtime_enabled' => true,
            ],
        ]);
    }
}
