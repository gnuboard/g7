<?php

namespace Plugins\Sirsoft\HeroSection\Services;

use App\Contracts\Extension\CacheInterface;
use App\Extension\HookManager;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\HeroSection\Enums\BannerStatus;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerRepositoryInterface;
use Plugins\Sirsoft\HeroSection\Support\Positions\Placement;
use Plugins\Sirsoft\HeroSection\Support\PublicBannerPresenter;

/**
 * 사용자 화면 공개 노출 서비스 — 자리(위치 + 대상)·언어별 노출 배너와 슬라이더 설정을 돌려준다.
 *
 * 캐시는 노출 기간 경계와 충돌하지 않게 두 겹으로 막는다.
 *  1. 만료 = min(지금 + 설정 캐시 시간, 그 자리의 다음 상태 전환 시각).
 *  2. 캐시에는 노출 후보(사용 중 + 아직 안 끝난 배너, 기간 포함)를 담고, 꺼낼 때 지금 시각으로 상태를 다시 판정한다.
 * 비우기는 버전 키를 올리는 방식이라 언어 목록과 무관하게 모든 언어 캐시가 함께 무효가 된다. 버전은 위치 하나와 (동적 위치면) 대상마다
 * 하나씩 두 겹이다 — 위치 슬라이더 설정이 바뀌면 그 위치의 모든 대상이, 배너가 바뀌면 그 대상만 무효가 된다.
 *
 * 동적 위치는 화면 값(게시판 slug·상품 코드 등)을 해석기로 대상 키(id)로 푼다. 푼 결과는 캐시 시간 동안 기억해 공개 요청마다 대상
 * 테이블을 묻지 않는다. 풀지 못한 값은 기억하지 않는다 — 아무 값이나 보내 캐시를 부풀릴 수 없고, 나중에 생긴 대상은 바로 풀린다(풀지 못하면
 * 빈 목록, D3). 대상이 생기거나 바뀌거나 지워지면 `forgetTargets()` 로 그 위치의 해석 기억을 버린다 — 지운 게시판의 slug 를 새 게시판이
 * 쓰게 돼도 지운 게시판의 배너가 나오지 않는다(D7). 배너 캐시는 버리지 않는다(대상 수정이 잦아도 배너를 다시 읽지 않는다).
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
     * 위치(동적 위치면 화면이 아는 대상 값)의 공개 노출 정보를 반환합니다.
     *
     * 응답의 `target` 은 해석에 쓴 화면 값(받은 값의 앞뒤 공백을 걷은 값, 정적 위치는 null)이다 — 화면이 SPA 로 다른 대상으로 옮겨 간 사이 도착한 앞 대상 응답을 가려낼 수 있게.
     *
     * @param  string  $position  위치 키 (등록 위치 — 요청이 확인했다)
     * @param  string|null  $target  화면 대상 값 (정적 위치는 쓰지 않는다)
     * @param  string  $locale  요청 언어
     * @return array{position: string, target: string|null, target_key: string|null, settings: array<string, mixed>, banners: array<int, array<string, mixed>>} 공개 응답 본문
     */
    public function forPosition(string $position, ?string $target, string $locale): array
    {
        $targetKey = null;
        $echo = null;
        if ($this->registry()->isDynamic($position)) {
            $echo = trim((string) $target);
            $targetKey = $this->resolveTarget($position, $echo);
            if ($targetKey === null) {
                return ['position' => $position, 'target' => $echo, 'target_key' => null, 'settings' => $this->positions->settingsFor($position), 'banners' => []];
            }
        }

        $placement = Placement::of($position, $targetKey);
        $now = now();
        $candidates = $this->candidates($placement, $locale, $now);

        // 2겹: 꺼낸 뒤 지금 시각으로 다시 판정 — 시작 전·종료 후 배너는 캐시에 있어도 나가지 않는다
        $exposed = array_values(array_filter($candidates['banners'], fn (array $row) => $this->isExposedAt($row['_period'], $now)));
        $banners = array_map(function (array $row) {
            unset($row['_period']);

            return $row;
        }, $exposed);

        $banners = HookManager::applyFilters('sirsoft-hero_section.hero.filter_public_banners', $banners, $position, $locale, $targetKey);

        return [
            'position' => $position,
            'target' => $echo,
            'target_key' => $targetKey,
            'settings' => $candidates['settings'],
            'banners' => array_values($banners),
        ];
    }

    /**
     * 공개 캐시를 비웁니다 — 대상을 주면 그 대상 버전을, 아니면 위치 버전(그 위치의 모든 대상)을 올립니다.
     *
     * @param  string|null  $position  위치 키 (null 이면 등록된 모든 위치)
     * @param  string|null  $targetKey  대상 키 (null 이면 위치 전체)
     * @return void 반환값 없음
     */
    public function invalidate(?string $position = null, ?string $targetKey = null): void
    {
        $positions = $position === null ? $this->registry()->keys() : [$position];

        foreach ($positions as $key) {
            $this->cache->put($this->versionKey($key, $position === null ? null : $targetKey), $this->newVersion(), self::VERSION_TTL);
        }
    }

    /**
     * 위치의 대상 해석 기억을 버립니다 — 대상(게시판·상품·카테고리 등)이 생기거나 주소 값이 바뀌거나 지워졌을 때 부른다.
     *
     * @param  string  $position  위치 키
     * @return void 반환값 없음
     */
    public function forgetTargets(string $position): void
    {
        $this->cache->put($this->resolveVersionKey($position), $this->newVersion(), self::VERSION_TTL);
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
     * 화면 대상 값을 대상 키로 풉니다 — 푼 결과는 캐시 시간 동안 기억하고, 풀지 못한 값은 기억하지 않습니다.
     *
     * @param  string  $position  동적 위치 키
     * @param  string  $value  화면 대상 값
     * @return string|null 대상 키 (못 풀면 null)
     */
    private function resolveTarget(string $position, string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $resolver = $this->registry()->resolver($position);
        $minutes = $this->cacheMinutes();
        if ($resolver === null || $minutes === 0) {
            return $resolver?->resolve($value);
        }

        $key = 'hero:target:'.$position.':r'.$this->marker($this->resolveVersionKey($position)).':'.md5($value);
        $cached = $this->cache->get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $resolved = $resolver->resolve($value);
        if ($resolved !== null) {
            $this->cache->put($key, $resolved, $minutes * 60);
        }

        return $resolved;
    }

    /**
     * 노출 후보를 캐시에서 꺼내거나 만들어 담습니다.
     *
     * @param  Placement  $placement  자리
     * @param  string  $locale  요청 언어
     * @param  CarbonInterface  $now  기준 시각
     * @return array{settings: array<string, mixed>, banners: array<int, array<string, mixed>>} 노출 후보
     */
    private function candidates(Placement $placement, string $locale, CarbonInterface $now): array
    {
        $minutes = $this->cacheMinutes();
        if ($minutes === 0) {
            return $this->build($placement, $locale, $now);
        }

        $key = 'hero:'.$placement->position.':v'.$this->version($placement->position)
            .($placement->targetKey === null ? '' : ':t'.$placement->targetKey.':v'.$this->version($placement->position, $placement->targetKey))
            .':'.$locale;
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $payload = $this->build($placement, $locale, $now);
        $ttl = $this->ttlSeconds($placement, $now, $minutes);
        if ($ttl > 0) {
            $this->cache->put($key, $payload, $ttl);
        }

        return $payload;
    }

    /**
     * 캐시 수명(초) — 설정 시간과 다음 상태 전환까지 남은 시간 중 짧은 쪽.
     *
     * @param  Placement  $placement  자리
     * @param  CarbonInterface  $now  기준 시각
     * @param  int  $minutes  설정 캐시 시간(분)
     * @return int 캐시 수명(초, 0 이면 담지 않음)
     */
    public function ttlSeconds(Placement $placement, CarbonInterface $now, int $minutes): int
    {
        $ttl = $minutes * 60;
        $next = $this->banners->nextTransitionAt($placement, $now);
        if ($next !== null) {
            $ttl = min($ttl, (int) floor($now->diffInSeconds($next, false)));
        }

        return max(0, $ttl);
    }

    /**
     * DB 에서 노출 후보와 설정을 만들어 언어를 골라 둡니다.
     *
     * @param  Placement  $placement  자리
     * @param  string  $locale  요청 언어
     * @param  CarbonInterface  $now  기준 시각
     * @return array{settings: array<string, mixed>, banners: array<int, array<string, mixed>>} 노출 후보
     */
    private function build(Placement $placement, string $locale, CarbonInterface $now): array
    {
        /** @var Collection<int, Banner> $rows */
        $rows = $this->banners->exposureCandidatesForPlacement($placement, $now);

        return [
            'settings' => $this->positions->settingsFor($placement->position),
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
     * 위치(또는 위치 + 대상) 캐시 버전 표지를 반환합니다.
     *
     * 표지는 다시 쓰지 않는 임의 값이다 — 순번(없으면 0)이면 표지가 배너 캐시보다 먼저 만료될 때(사이트 공통 캐시 시간이 더 짧을 때)
     * 처음 순번으로 돌아가, 아직 살아 있는 고치기 전 캐시와 같은 키를 만들어 옛 화면을 다시 낸다. 사라졌으면 새 표지를 만든다.
     *
     * @param  string  $position  위치 키
     * @param  string|null  $targetKey  대상 키 (null 이면 위치 버전)
     * @return string 버전 표지
     */
    private function version(string $position, ?string $targetKey = null): string
    {
        return $this->marker($this->versionKey($position, $targetKey));
    }

    /**
     * 버전 표지 하나를 꺼내거나(없으면) 새로 만들어 둡니다.
     *
     * @param  string  $key  버전 키
     * @return string 버전 표지
     */
    private function marker(string $key): string
    {
        $version = $this->cache->get($key);
        if (is_string($version) && $version !== '') {
            return $version;
        }

        $version = $this->newVersion();
        $this->cache->put($key, $version, self::VERSION_TTL);

        return $version;
    }

    /**
     * 위치의 대상 해석 버전 키를 반환합니다.
     *
     * @param  string  $position  위치 키
     * @return string 버전 키
     */
    private function resolveVersionKey(string $position): string
    {
        return 'hero:resolve-version:'.$position;
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
     * 캐시 버전 키를 반환합니다.
     *
     * @param  string  $position  위치 키
     * @param  string|null  $targetKey  대상 키 (null 이면 위치 버전)
     * @return string 버전 키
     */
    private function versionKey(string $position, ?string $targetKey = null): string
    {
        return 'hero:version:'.$position.($targetKey === null ? '' : ':'.$targetKey);
    }

    /**
     * 위치 레지스트리를 꺼냅니다 — 생성자에서 쥐지 않는다. 레지스트리는 요청 단위로 공유되므로, 오래 사는 객체(라우트가 기억한 컨트롤러·
     * 작업자)가 옛 위치 목록을 붙들지 않게 쓸 때마다 컨테이너에서 받는다.
     *
     * @return PositionRegistry 위치 레지스트리
     */
    private function registry(): PositionRegistry
    {
        return app(PositionRegistry::class);
    }
}
