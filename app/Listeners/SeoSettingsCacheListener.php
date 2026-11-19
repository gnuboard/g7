<?php

namespace App\Listeners;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 코어 SEO 설정 변경 시 SEO 캐시 전체 무효화 리스너
 *
 * 코어 환경설정의 SEO 탭·일반 탭·「본문 콘텐츠」 탭 저장, 템플릿 설정 저장 시 전체 SEO 캐시를 삭제합니다.
 * SEO 설정(title suffix, cache TTL 등)과 일반 탭의 사이트 이름·로고·파비콘, 템플릿 파비콘은
 * 모든 봇 화면에 실리므로 전체 캐시를 무효화합니다(#434 — 종전에는 SEO 탭만 구독해 일반 탭 변경이
 * 캐시 TTL 까지 봇 화면에 반영되지 않았다). 봇 화면은 본문 표시 규칙(캡션·외부 영상·추가 태그)으로 정화되므로
 * 「본문 콘텐츠」 탭의 규칙이 바뀌어도 캐시된 봇 화면을 비운다(#887).
 *
 * 업로드 탭의 이미지 썸네일 설정(`thumbnail_*`, #869)이 실제로 바뀌어도 비운다. 봇 화면의 og:image 와 본문 이미지 주소에
 * 규격(`?size=`)이 구워지므로(SeoMetaResolver, ThumbnailUrl), 규격을 끄거나 기능을 끄면 캐시의 주소가 원본과 어긋난다.
 * 바뀌었는지는 저장 전 값(`core.settings.before_save` 에서 떠 둔 사본)과 비교해 정한다. 업로드 탭의 다른 칸만 바꾼
 * 저장으로 SEO 캐시 전체를 비우지 않기 위해서다. 확장 활성·비활성으로 규격과 서빙 경로 패턴이 바뀌는 경우는
 * `SeoExtensionCacheListener` 가 같은 훅에서 이미 전체를 비우므로 여기서 다시 구독하지 않는다(한 사건에 두 번 비우지 않는다).
 *
 * 비우기는 저장한 요청 안에서 한다(sync) — 큐로 가면 워커가 돌 때까지(워커가 없으면 TTL 까지) 봇이 옛 화면을
 * 받는다. 본문은 DB::afterCommit 으로 감싸 트랜잭션 안에서 발화하면 커밋 뒤에 비운다 — 커밋 전에 비우면
 * 다른 요청이 옛 데이터로 다시 채울 틈이 생긴다(#724). 트랜잭션 밖이면 즉시 비운다.
 */
class SeoSettingsCacheListener implements HookListenerInterface
{
    /**
     * 저장하면 봇 화면 캐시를 비워야 하는 코어 설정 카테고리 — seo(제목 접미·메타), general(사이트 이름·로고·파비콘),
     * content(본문 표시 규칙 — 본문 콘텐츠, #887)
     *
     * @var array<int, string>
     */
    private const BOT_VISIBLE_CATEGORIES = ['seo', 'general', 'content'];

    /**
     * 구독할 훅 목록 반환
     *
     * @return array 훅 이름 → 메서드/우선순위 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // 업로드 탭 썸네일 값의 저장 전 사본을 뜬다 — after_save 는 병합된 새 값만 넘긴다.
            'core.settings.before_save' => [
                'method' => 'onBeforeSettingsSave',
                'priority' => 20,
                'sync' => true,
            ],
            'core.settings.after_save' => [
                'method' => 'onSettingsSave',
                'priority' => 20,
                'sync' => true,
            ],
            // 단건 저장(`PUT /api/admin/settings/{key}`)은 after_save 가 아니라 after_set 을
            // 발화한다. 이 구독이 없으면 SEO 설정을 단건으로 바꿔도 캐시가 남아 있다.
            // (after_save 를 추가 발화하는 대신 구독을 늘리는 이유: 활동로그 리스너가 두 훅을
            //  각각 기록해 저장 1회가 로그 2건이 된다)
            'core.settings.after_set' => [
                'method' => 'onSettingSet',
                'priority' => 20,
                'sync' => true,
            ],
            // 템플릿 설정(파비콘 등)도 봇 화면 `<head>` 에 실린다 (#434)
            'core.template_settings.after_save' => [
                'method' => 'onTemplateSettingsSave',
                'priority' => 20,
                'sync' => true,
            ],
            // 기본값 되돌리기도 봇 화면에 실리는 값을 바꾼다 — 인자 모양($identifier, $section, $result)의 결과 위치가 같다
            'core.template_settings.after_reset' => [
                'method' => 'onTemplateSettingsSave',
                'priority' => 20,
                'sync' => true,
            ],
        ];
    }

    /**
     * 업로드 탭 썸네일 설정의 저장 전 사본 (before_save 에서 뜨고 after_save 에서 쓰고 비운다).
     *
     * 훅 리스너 인스턴스가 두 훅 사이에 같다는 보장이 없어 정적 칸에 둔다.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $uploadThumbnailSnapshot = null;

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    /**
     * 코어 설정 저장 시 SEO 캐시를 무효화합니다.
     *
     * 봇 화면에 실리는 탭(SEO·일반·「본문 콘텐츠」)이 저장된 경우 전체 캐시를 삭제합니다. 업로드 탭은 썸네일 값이
     * 실제로 바뀐 경우에만 삭제합니다.
     * 봇 화면은 본문 표시 규칙(캡션·외부 영상·추가 태그)으로 정화되므로 그 규칙이 바뀌면 캐시된 봇 화면도 비운다 (#887).
     *
     * @param  mixed  ...$args  훅 인자 ($tab, $mergedSettings, $result)
     * @return void 반환값 없음
     */
    public function onSettingsSave(...$args): void
    {
        $tab = $args[0] ?? null;

        // 사본은 이 저장 한 번에만 쓴다 — 트랜잭션 콜백을 기다리기 전에 꺼내고 비운다.
        $snapshot = self::$uploadThumbnailSnapshot;
        self::$uploadThumbnailSnapshot = null;

        DB::afterCommit(function () use ($args, $tab, $snapshot) {
            // 봇 화면에 실리는 탭(SEO·일반·본문 콘텐츠)이면 전체를 비운다
            if (in_array($tab, self::BOT_VISIBLE_CATEGORIES, true)) {
                $this->clearSeoCaches(['tab' => $tab]);

                return;
            }

            if ($tab !== 'upload' || ($args[2] ?? null) === false) {
                return;
            }

            $merged = is_array($args[1] ?? null) ? $args[1] : [];

            // 사본이 없으면(before_save 를 거치지 않은 발화) 바뀌었는지 알 수 없다 — 비우는 쪽이 안전하다.
            if ($snapshot !== null && self::sameThumbnailValues($snapshot, self::thumbnailValues($merged))) {
                return;
            }

            $this->clearSeoCaches(['tab' => $tab, 'reason' => 'thumbnail_settings']);
        });
    }

    /**
     * 업로드 탭 저장 직전에 썸네일 값의 사본을 뜹니다.
     *
     * @param  mixed  ...$args  훅 인자 ($settings — `_tab` 을 담은 저장 요청 전체)
     * @return void 반환값 없음
     */
    public function onBeforeSettingsSave(...$args): void
    {
        $settings = $args[0] ?? null;

        if (! is_array($settings) || ($settings['_tab'] ?? null) !== 'upload') {
            self::$uploadThumbnailSnapshot = null;

            return;
        }

        try {
            self::$uploadThumbnailSnapshot = self::thumbnailValues(
                app(ConfigRepositoryInterface::class)->getCategory('upload')
            );
        } catch (\Throwable $e) {
            // 스냅샷이 없으면 저장 뒤 SEO 캐시를 비우는 쪽으로 간다 — 저장은 막지 않는다.
            Log::warning('업로드 탭 썸네일 설정 스냅샷 실패', ['error' => $e->getMessage()]);
            self::$uploadThumbnailSnapshot = null;
        }
    }

    /**
     * 업로드 설정에서 썸네일 값(`thumbnail_*`)만 뽑습니다.
     *
     * @param  array<string, mixed>  $settings  업로드 설정
     * @return array<string, mixed> 썸네일 값 (키 정렬)
     */
    private static function thumbnailValues(array $settings): array
    {
        $values = array_filter(
            $settings,
            fn ($key) => is_string($key) && str_starts_with($key, 'thumbnail_'),
            ARRAY_FILTER_USE_KEY
        );

        ksort($values);

        return $values;
    }

    /**
     * 두 썸네일 값 묶음이 같은지 (JSON 표현 기준 — 행 순서도 비교한다)
     *
     * @param  array<string, mixed>  $before  저장 전
     * @param  array<string, mixed>  $after  저장 후
     * @return bool 같으면 true
     */
    private static function sameThumbnailValues(array $before, array $after): bool
    {
        return json_encode($before) === json_encode($after);
    }

    /**
     * 코어 설정 단건 저장 시 SEO 캐시를 무효화합니다.
     *
     * 봇 화면에 실리는 카테고리(seo·general·content) 키나 업로드 탭 썸네일 키가 실제로 저장된 경우에만 전체 캐시를 삭제합니다.
     *
     * @param  mixed  ...$args  훅 인자 ($key, $value, $result)
     * @return void 반환값 없음
     */
    public function onSettingSet(...$args): void
    {
        DB::afterCommit(function () use ($args) {
            $key = $args[0] ?? null;
            $result = $args[2] ?? false;

            // 봇 화면에 실리는 카테고리(seo·general·content) 키, 그리고 업로드 탭 썸네일 키(#869)도 봇 화면의 이미지 주소를
            // 바꾼다 — 클래스 설명 참조.
            $botVisible = is_string($key) && str_contains($key, '.')
                && in_array(explode('.', $key)[0], self::BOT_VISIBLE_CATEGORIES, true);
            if (! is_string($key) || (! $botVisible && ! str_starts_with($key, 'upload.thumbnail_'))) {
                return;
            }

            // 저장 실패는 상태를 바꾸지 않았으므로 캐시도 그대로 둔다
            if ($result !== true) {
                return;
            }

            $this->clearSeoCaches(['key' => $key]);
        });
    }

    /**
     * 템플릿 설정 저장 시 SEO 캐시를 무효화합니다 (#434).
     *
     * @param  mixed  ...$args  훅 인자 ($identifier, $mergedSettings, $result)
     * @return void 반환값 없음
     */
    public function onTemplateSettingsSave(...$args): void
    {
        DB::afterCommit(function () use ($args) {
            $identifier = $args[0] ?? null;
            $result = $args[2] ?? false;

            // 저장 실패는 상태를 바꾸지 않았으므로 캐시도 그대로 둔다
            if ($result !== true) {
                return;
            }

            $this->clearSeoCaches(['template' => $identifier]);
        });
    }

    /**
     * SEO 전체 캐시와 sitemap 캐시를 삭제합니다.
     *
     * @param  array  $logContext  로그에 남길 컨텍스트
     * @return void 반환값 없음
     */
    private function clearSeoCaches(array $logContext): void
    {
        try {
            $cache = app(SeoCacheManagerInterface::class);

            // 전체 SEO 캐시 삭제 (title suffix, cache TTL 등 전역 영향)
            $cache->clearAll();

            // Sitemap 캐시 삭제
            app(CacheInterface::class)->forget('seo.sitemap');

            Log::info('[SEO] Core SEO settings changed — all cache cleared', $logContext);
        } catch (\Throwable $e) {
            Log::warning('[SEO] Core SEO settings cache invalidation failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
