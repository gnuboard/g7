<?php

namespace Plugins\Sirsoft\Popup\Services;

use App\Contracts\Extension\CacheInterface;
use App\Extension\HookManager;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\Popup\Enums\Audience;
use Plugins\Sirsoft\Popup\Enums\PopupStatus;
use Plugins\Sirsoft\Popup\Models\Popup;
use Plugins\Sirsoft\Popup\Repositories\Contracts\PopupRepositoryInterface;
use Plugins\Sirsoft\Popup\Support\DisplaySettings;
use Plugins\Sirsoft\Popup\Support\PublicPopupPresenter;

/**
 * 사용자 화면 공개 노출 서비스 — 요청자(손님·회원)·언어별 노출 후보와 표시 방식을 돌려준다.
 *
 * 노출 페이지·기기·「보지 않기」는 브라우저가 거른다(SPA 경로 이동마다 묻지 않고, 캐시를 대상·언어 단위로 두려고).
 * 캐시는 노출 기간 경계와 충돌하지 않게 두 겹으로 막는다(#810 과 같은 방식).
 *  1. 만료 = min(지금 + 설정 캐시 시간, 다음 상태 전환 시각).
 *  2. 캐시에는 노출 후보(사용 중 + 아직 안 끝난 팝업, 기간 포함)를 담고, 꺼낼 때 지금 시각으로 상태를 다시 판정한다.
 * 비우기는 버전 키를 올리는 방식이라 대상·언어 목록과 무관하게 모든 캐시가 함께 무효가 된다.
 * 검색엔진 봇에게는 빈 목록을 돌려주고 캐시를 읽지도 쓰지도 않는다.
 */
class PublicPopupService
{
    /**
     * 플러그인 식별자
     */
    private const PLUGIN = 'sirsoft-popup';

    /**
     * 캐시 버전 키
     */
    private const VERSION_KEY = 'popup:public:version';

    /**
     * PublicPopupService 생성자
     *
     * @param  PopupRepositoryInterface  $popups  팝업 Repository
     * @param  CacheInterface  $cache  플러그인 캐시 드라이버
     */
    public function __construct(
        private PopupRepositoryInterface $popups,
        private CacheInterface $cache,
    ) {}

    /**
     * 요청자에게 보일 수 있는 노출 후보와 표시 방식을 반환합니다.
     *
     * 조회 결과 훅(`before_public_list` → `filter_public_list_result` → `after_public_list`)은 캐시에서 꺼내 지금 시각으로
     * 다시 판정한 뒤에 발행한다 — 캐시 안의 값은 요청자와 무관하게 두고, 한 요청자의 가공이 다른 방문자에게 나가지 않게.
     * 봇 응답은 늘 빈 목록이라 훅을 발행하지 않는다.
     *
     * @param  User|null  $user  요청한 회원 (손님은 null)
     * @param  string  $locale  요청 언어
     * @param  bool  $isBot  검색엔진 봇 요청인지
     * @return array{settings: array<string, mixed>, popups: array<int, array<string, mixed>>, server_time?: string, refresh_at?: string|null} 공개 응답 본문
     */
    public function forRequest(?User $user, string $locale, bool $isBot = false): array
    {
        if ($isBot) {
            return ['settings' => DisplaySettings::current(), 'popups' => []];
        }

        $context = ['user' => $user, 'locale' => $locale];
        HookManager::doAction('sirsoft-popup.popup.before_public_list', $context);

        $now = now();
        $candidates = $this->candidates($user !== null, $locale, $now);

        // 2겹: 꺼낸 뒤 지금 시각으로 다시 판정 — 시작 전·종료 후 팝업은 캐시에 있어도 나가지 않는다
        $exposed = array_values(array_filter($candidates['popups'], fn (array $row) => $this->isExposedAt($row['_period'], $now)));
        $popups = array_map(function (array $row) {
            unset($row['_period']);

            return $row;
        }, $exposed);

        $popups = array_values(HookManager::applyFilters('sirsoft-popup.popup.filter_public_list_result', $popups, $context));

        HookManager::doAction('sirsoft-popup.popup.after_public_list', $popups, $context);

        return [
            'settings' => $candidates['settings'],
            'popups' => $popups,
            'server_time' => $now->toISOString(),
            'refresh_at' => $this->popups->nextTransitionAt($now)?->toISOString(),
        ];
    }

    /**
     * 공개 캐시를 모두 비웁니다 — 버전을 올립니다.
     *
     * @return void 반환값 없음
     */
    public function invalidate(): void
    {
        $this->cache->put(self::VERSION_KEY, $this->version() + 1);
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
     * 캐시 수명(초) — 설정 시간과 다음 상태 전환까지 남은 시간 중 짧은 쪽.
     *
     * @param  CarbonInterface  $now  기준 시각
     * @param  int  $minutes  설정 캐시 시간(분)
     * @return int 캐시 수명(초, 0 이면 담지 않음)
     */
    public function ttlSeconds(CarbonInterface $now, int $minutes): int
    {
        $ttl = $minutes * 60;
        $next = $this->popups->nextTransitionAt($now);
        if ($next !== null) {
            $ttl = min($ttl, (int) floor($now->diffInSeconds($next, false)));
        }

        return max(0, $ttl);
    }

    /**
     * 노출 후보를 캐시에서 꺼내거나 만들어 담습니다.
     *
     * @param  bool  $isMember  로그인한 회원인지
     * @param  string  $locale  요청 언어
     * @param  CarbonInterface  $now  기준 시각
     * @return array{settings: array<string, mixed>, popups: array<int, array<string, mixed>>} 노출 후보
     */
    private function candidates(bool $isMember, string $locale, CarbonInterface $now): array
    {
        $minutes = $this->cacheMinutes();
        if ($minutes === 0) {
            return $this->build($isMember, $locale, $now);
        }

        $audience = $isMember ? Audience::Member->value : Audience::Guest->value;
        $key = 'popup:public:v'.$this->version().':'.$audience.':'.$locale;
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $payload = $this->build($isMember, $locale, $now);
        $ttl = $this->ttlSeconds($now, $minutes);
        if ($ttl > 0) {
            $this->cache->put($key, $payload, $ttl);
        }

        return $payload;
    }

    /**
     * DB 에서 노출 후보와 표시 방식을 만들어 언어를 골라 둡니다.
     *
     * @param  bool  $isMember  로그인한 회원인지
     * @param  string  $locale  요청 언어
     * @param  CarbonInterface  $now  기준 시각
     * @return array{settings: array<string, mixed>, popups: array<int, array<string, mixed>>} 노출 후보
     */
    private function build(bool $isMember, string $locale, CarbonInterface $now): array
    {
        $audiences = [Audience::All->value, $isMember ? Audience::Member->value : Audience::Guest->value];

        /** @var Collection<int, Popup> $rows */
        $rows = $this->popups->exposureCandidates($audiences, $now);

        return [
            'settings' => DisplaySettings::current(),
            'popups' => $rows->map(fn (Popup $popup) => PublicPopupPresenter::present($popup, $locale))->values()->all(),
        ];
    }

    /**
     * 캐시 후보 한 건이 그 시각에 노출 중인지 판정합니다 — `PopupStatus::resolve()`(모델과 같은 규칙).
     *
     * @param  array{is_active: bool, display_period: string, starts_at: string|null, ends_at: string|null}  $period  기간 정보
     * @param  CarbonInterface  $now  기준 시각
     * @return bool 노출 중이면 true
     */
    private function isExposedAt(array $period, CarbonInterface $now): bool
    {
        return PopupStatus::resolve(
            (bool) $period['is_active'],
            (string) $period['display_period'],
            $period['starts_at'] !== null ? Carbon::parse($period['starts_at']) : null,
            $period['ends_at'] !== null ? Carbon::parse($period['ends_at']) : null,
            $now,
        ) === PopupStatus::Active;
    }

    /**
     * 캐시 버전을 반환합니다.
     *
     * @return int 버전
     */
    private function version(): int
    {
        return (int) ($this->cache->get(self::VERSION_KEY) ?? 0);
    }
}
