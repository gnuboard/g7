<?php

namespace Plugins\Sirsoft\HeroSection\Services;

use App\Contracts\Extension\CacheInterface;
use App\Extension\HookManager;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\HeroSection\Enums\BannerStatus;
use Plugins\Sirsoft\HeroSection\Enums\HeroPosition;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerRepositoryInterface;
use Plugins\Sirsoft\HeroSection\Support\PublicBannerPresenter;

/**
 * 사용자 화면 공개 노출 서비스 — 위치·언어별 노출 배너와 슬라이더 설정을 돌려준다.
 *
 * 캐시는 노출 기간 경계와 충돌하지 않게 두 겹으로 막는다.
 *  1. 만료 = min(지금 + 설정 캐시 시간, 그 위치의 다음 상태 전환 시각).
 *  2. 캐시에는 노출 후보(사용 중 + 아직 안 끝난 배너, 기간 포함)를 담고, 꺼낼 때 지금 시각으로 상태를 다시 판정한다.
 * 비우기는 위치별 버전 키를 올리는 방식이라 언어 목록과 무관하게 그 위치의 모든 언어 캐시가 함께 무효가 된다.
 */
class PublicHeroService
{
    /**
     * 플러그인 식별자
     */
    private const PLUGIN = 'sirsoft-hero_section';

    /**
     * 위치 캐시 버전 표지의 수명(초) — 배너 캐시 최대 수명(60분)보다 길게 둔다. 그래도 먼저 사라지면 새 표지를 만든다.
     */
    private const VERSION_TTL = 86400;

    /**
     * PublicHeroService 생성자
     *
     * @param  BannerRepositoryInterface  $banners  배너 Repository
     * @param  PositionSettingService  $positions  위치 설정 서비스
     * @param  CacheInterface  $cache  플러그인 캐시 드라이버
     */
    public function __construct(
        private BannerRepositoryInterface $banners,
        private PositionSettingService $positions,
        private CacheInterface $cache,
    ) {}

    /**
     * 위치의 공개 노출 정보를 반환합니다.
     *
     * @param  HeroPosition  $position  위치
     * @param  string  $locale  요청 언어
     * @return array{position: string, settings: array<string, mixed>, banners: array<int, array<string, mixed>>} 공개 응답 본문
     */
    public function forPosition(HeroPosition $position, string $locale): array
    {
        $now = now();
        $candidates = $this->candidates($position, $locale, $now);

        // 2겹: 꺼낸 뒤 지금 시각으로 다시 판정 — 시작 전·종료 후 배너는 캐시에 있어도 나가지 않는다
        $exposed = array_values(array_filter($candidates['banners'], fn (array $row) => $this->isExposedAt($row['_period'], $now)));
        $banners = array_map(function (array $row) {
            unset($row['_period']);

            return $row;
        }, $exposed);

        $banners = HookManager::applyFilters('sirsoft-hero_section.hero.filter_public_banners', $banners, $position->value, $locale);

        return [
            'position' => $position->value,
            'settings' => $candidates['settings'],
            'banners' => array_values($banners),
        ];
    }

    /**
     * 위치 하나(또는 전체)의 공개 캐시를 비웁니다 — 위치별 버전을 올립니다.
     *
     * @param  string|null  $position  위치 키 (null 이면 모든 위치)
     * @return void 반환값 없음
     */
    public function invalidate(?string $position = null): void
    {
        $targets = $position === null ? HeroPosition::values() : [$position];

        foreach ($targets as $key) {
            $this->cache->put($this->versionKey($key), $this->newVersion(), self::VERSION_TTL);
        }
    }

    /**
     * 캐시 시간(분) 설정을 반환합니다(0~60).
     *
     * @return int 캐시 시간(분)
     */
    public function cacheMinutes(): int
    {
        return max(0, min(60, (int) (plugin_setting(self::PLUGIN, 'public_cache_minutes', 10) ?? 10)));
    }

    /**
     * 노출 후보를 캐시에서 꺼내거나 만들어 담습니다.
     *
     * @param  HeroPosition  $position  위치
     * @param  string  $locale  요청 언어
     * @param  CarbonInterface  $now  기준 시각
     * @return array{settings: array<string, mixed>, banners: array<int, array<string, mixed>>} 노출 후보
     */
    private function candidates(HeroPosition $position, string $locale, CarbonInterface $now): array
    {
        $minutes = $this->cacheMinutes();
        if ($minutes === 0) {
            return $this->build($position, $locale, $now);
        }

        $key = 'hero:'.$position->value.':v'.$this->version($position->value).':'.$locale;
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $payload = $this->build($position, $locale, $now);
        $ttl = $this->ttlSeconds($position, $now, $minutes);
        if ($ttl > 0) {
            $this->cache->put($key, $payload, $ttl);
        }

        return $payload;
    }

    /**
     * 캐시 수명(초) — 설정 시간과 다음 상태 전환까지 남은 시간 중 짧은 쪽.
     *
     * @param  HeroPosition  $position  위치
     * @param  CarbonInterface  $now  기준 시각
     * @param  int  $minutes  설정 캐시 시간(분)
     * @return int 캐시 수명(초, 0 이면 담지 않음)
     */
    public function ttlSeconds(HeroPosition $position, CarbonInterface $now, int $minutes): int
    {
        $ttl = $minutes * 60;
        $next = $this->banners->nextTransitionAt($position->value, $now);
        if ($next !== null) {
            $ttl = min($ttl, (int) floor($now->diffInSeconds($next, false)));
        }

        return max(0, $ttl);
    }

    /**
     * DB 에서 노출 후보와 설정을 만들어 언어를 골라 둡니다.
     *
     * @param  HeroPosition  $position  위치
     * @param  string  $locale  요청 언어
     * @param  CarbonInterface  $now  기준 시각
     * @return array{settings: array<string, mixed>, banners: array<int, array<string, mixed>>} 노출 후보
     */
    private function build(HeroPosition $position, string $locale, CarbonInterface $now): array
    {
        /** @var Collection<int, Banner> $rows */
        $rows = $this->banners->exposureCandidatesForPosition($position->value, $now);

        return [
            'settings' => $this->positions->settingsFor($position),
            'banners' => $rows->map(fn (Banner $banner) => PublicBannerPresenter::present($banner, $locale))->values()->all(),
        ];
    }

    /**
     * 캐시 후보 한 건이 그 시각에 노출 중인지 판정합니다 — `BannerStatus::resolve()`(모델과 같은 규칙).
     *
     * @param  array{is_active: bool, display_period: string, starts_at: string|null, ends_at: string|null}  $period  기간 정보
     * @param  CarbonInterface  $now  기준 시각
     * @return bool 노출 중이면 true
     */
    private function isExposedAt(array $period, CarbonInterface $now): bool
    {
        return BannerStatus::resolve(
            (bool) $period['is_active'],
            (string) $period['display_period'],
            $period['starts_at'] !== null ? Carbon::parse($period['starts_at']) : null,
            $period['ends_at'] !== null ? Carbon::parse($period['ends_at']) : null,
            $now,
        ) === BannerStatus::Active;
    }

    /**
     * 위치 캐시 버전 표지를 반환합니다.
     *
     * 표지는 다시 쓰지 않는 임의 값이다 — 순번(없으면 0)이면 표지가 배너 캐시보다 먼저 만료될 때(사이트 공통 캐시 시간이 더 짧을 때)
     * 처음 순번으로 돌아가, 아직 살아 있는 고치기 전 캐시와 같은 키를 만들어 옛 화면을 다시 낸다. 사라졌으면 새 표지를 만든다.
     *
     * @param  string  $position  위치 키
     * @return string 버전 표지
     */
    private function version(string $position): string
    {
        $version = $this->cache->get($this->versionKey($position));
        if (is_string($version) && $version !== '') {
            return $version;
        }

        $version = $this->newVersion();
        $this->cache->put($this->versionKey($position), $version, self::VERSION_TTL);

        return $version;
    }

    /**
     * 새 위치 캐시 버전 표지를 만듭니다.
     *
     * @return string 버전 표지
     */
    private function newVersion(): string
    {
        return bin2hex(random_bytes(6));
    }

    /**
     * 위치 캐시 버전 키를 반환합니다.
     *
     * @param  string  $position  위치 키
     * @return string 버전 키
     */
    private function versionKey(string $position): string
    {
        return 'hero:version:'.$position;
    }
}
