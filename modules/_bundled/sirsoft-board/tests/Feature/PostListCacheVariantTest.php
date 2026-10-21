<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

use App\Extension\Cache\ModuleCacheDriver;
use App\Extension\HookManager;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Support\PostQueryExtensions;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 목록 캐시 확장 축 훅 sirsoft-board.post.filter_list_cache_variant (#147)
 *
 * 캐시 7개마다 (1) 리스너가 없으면 키가 지금과 같고 (2) 변형 a·b 값이 서로 덮이지 않으며 (3) 태그 비우기로
 * 모든 변형이 비워지는지 본다. 변형마다 다른 결과가 나오도록 목록 쿼리 확장 훅이 변형 a 는 EN, b 는 KO 만 남긴다.
 * 축 cache 의 케이스 표시는 테스트 메서드마다 단다.
 */
class PostListCacheVariantTest extends BoardTestCase
{
    private const PREFIX = 'g7:module.sirsoft-board:';

    /** 지금 시험 중인 변형 (리스너가 읽는다) */
    private static string $variant = 'a';

    private User $author;

    /** @var array<string, int> 제목 → 글 ID */
    private array $ids = [];

    /** @var array<int, array{0: string, 1: callable}> 이 테스트가 건 필터 훅 */
    private array $registered = [];

    /**
     * EN 3 · KO 2 원글(모두 같은 작성자)을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        self::$variant = 'a';
        $this->updateBoardSettings(['order_by' => 'created_at', 'order_direction' => 'DESC', 'categories' => ['EN', 'KO']]);
        $this->author = User::factory()->create();

        $base = now()->addMinutes(30);
        foreach (['KO1', 'EN1', 'KO2', 'EN2', 'EN3'] as $i => $name) {
            $this->ids[$name] = $this->createTestPost([
                'title' => "캐시축 {$name}", 'category' => substr($name, 0, 2), 'user_id' => $this->author->id,
                'view_count' => 700000 + $i * 10, 'created_at' => $base->copy()->addMinutes($i),
            ]);
        }
    }

    /**
     * 테스트가 건 훅만 걷어냅니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->registered as [$name, $callback]) {
            HookManager::removeFilter($name, $callback);
        }

        parent::tearDown();
    }

    /**
     * 필터를 걸고 tearDown 에서 걷도록 기록합니다.
     *
     * @param  string  $name  훅 이름
     * @param  callable  $callback  콜백
     * @param  int  $priority  우선순위
     * @return void 반환값 없음
     */
    private function hook(string $name, callable $callback, int $priority = 10): void
    {
        HookManager::addFilter($name, $callback, $priority);
        $this->registered[] = [$name, $callback];
    }

    /**
     * 변형에 따라 이 게시판 글을 EN(a) 또는 KO(b) 로 좁히고, 같은 축을 캐시 키에 싣는 리스너 둘을 겁니다.
     *
     * @return void 반환값 없음
     */
    private function hookVariantListeners(): void
    {
        $boardId = $this->board->id;
        $this->hook('sirsoft-board.post.list_query_extensions', function (array $extensions) use ($boardId) {
            $category = self::$variant === 'a' ? 'EN' : 'KO';
            $extensions[] = fn (Builder $q) => $q->where(fn (Builder $w) => $w->where($q->qualifyColumn('board_id'), '<>', $boardId)->orWhere($q->qualifyColumn('category'), $category));

            return $extensions;
        });
        $this->hook('sirsoft-board.post.filter_list_cache_variant', fn (array $parts) => array_merge($parts, [self::$variant]));
    }

    /**
     * 캐시 하나의 키·변형·태그 계약을 확인합니다.
     *
     * @param  string  $baseKey  리스너가 없을 때의 키 (지금 형식)
     * @param  Closure  $read  캐시를 거쳐 값을 읽고, 비교할 지문을 돌려주는 함수
     * @return void 반환값 없음
     */
    private function assertVariantContract(string $baseKey, Closure $read): void
    {
        // (1) 리스너 없음 — 키는 바이트 그대로
        $plain = $read();
        $this->assertTrue(Cache::has(self::PREFIX.$baseKey), "리스너가 없으면 키가 그대로여야 한다: {$baseKey}");

        // (2) 변형 a·b — 서로 다른 키에 다른 값이 남는다
        $this->hookVariantListeners();
        self::$variant = 'a';
        $a = $read();
        self::$variant = 'b';
        $b = $read();
        self::$variant = 'a';
        $aAgain = $read();

        $keyA = $baseKey.'_ev'.md5('a');
        $keyB = $baseKey.'_ev'.md5('b');
        $this->assertTrue(Cache::has(self::PREFIX.$keyA), "변형 a 키: {$keyA}");
        $this->assertTrue(Cache::has(self::PREFIX.$keyB), "변형 b 키: {$keyB}");
        $this->assertNotEquals($a, $b, '변형 a·b 의 값이 같으면 시험이 무의미하다');
        $this->assertEquals($a, $aAgain, '변형 b 를 읽은 뒤에도 변형 a 값이 덮이지 않아야 한다');
        $this->assertNotEquals($plain, $b);

        // (3) 태그 비우기 — 모든 변형이 함께 비워진다(태그는 그대로)
        (new ModuleCacheDriver('sirsoft-board'))->flushTags(['board-stats', 'board-posts']);
        foreach ([$baseKey, $keyA, $keyB] as $key) {
            $this->assertFalse(Cache::has(self::PREFIX.$key), "태그 비우기 뒤에 남은 키: {$key}");
        }
    }

    /**
     * 일반 글 수 캐시 — 키는 C 형식(board_normal_count_{id}_{context}), 스칼라가 아닌 축 원소는 버린다.
     *
     * @scenario cache=normal_count
     *
     * @effects key_unchanged_without_variant, variants_do_not_overwrite, tag_flush_clears_all_variants, non_scalar_parts_dropped
     *
     * @return void 반환값 없음
     */
    public function test_normal_count_cache_variant(): void
    {
        $service = app(PostService::class);
        $this->assertVariantContract(
            "board_normal_count_{$this->board->id}_user",
            fn () => $service->getCachedNormalPostCount($this->board->slug, $this->board->id, [], false, 'user')->total
        );

        $this->hook('sirsoft-board.post.filter_list_cache_variant', fn (array $parts) => array_merge($parts, [['x'], null, 1.5]), 20);
        self::$variant = 'a';
        $this->assertSame('_ev'.md5('a'), PostQueryExtensions::cacheSuffix('normal_count'), '스칼라가 아닌 원소(배열·null·실수)는 버린다');
    }

    /**
     * 이전/다음 글 캐시.
     *
     * @scenario cache=adjacent
     *
     * @effects key_unchanged_without_variant, variants_do_not_overwrite, tag_flush_clears_all_variants
     *
     * @return void 반환값 없음
     */
    public function test_adjacent_cache_variant(): void
    {
        $service = app(PostService::class);
        $id = $this->ids['EN2'];
        $this->assertVariantContract(
            "adjacent_{$this->board->slug}_{$id}_created_at_DESC",
            function () use ($service, $id) {
                $adjacent = $service->getAdjacentPosts($this->board->slug, $id, [], false, $this->board, 'user');

                return [$adjacent['prev']?->id, $adjacent['next']?->id];
            }
        );
    }

    /**
     * 마이페이지 활동 총 건수 캐시.
     *
     * @scenario cache=user_activities_total
     *
     * @effects key_unchanged_without_variant, variants_do_not_overwrite, tag_flush_clears_all_variants
     *
     * @return void 반환값 없음
     */
    public function test_user_activities_total_cache_variant(): void
    {
        $service = app(PostService::class);
        $this->assertVariantContract(
            "user_activities_total_{$this->author->id}_authored",
            fn () => $service->getUserActivities($this->author->id, [], 20)->total()
        );
    }

    /**
     * 마이페이지 활동 통계 캐시.
     *
     * @scenario cache=user_activity_stats
     *
     * @effects key_unchanged_without_variant, variants_do_not_overwrite, tag_flush_clears_all_variants
     *
     * @return void 반환값 없음
     */
    public function test_user_activity_stats_cache_variant(): void
    {
        $service = app(PostService::class);
        $this->assertVariantContract(
            "user_activity_stats_{$this->author->id}",
            fn () => $service->getUserActivityStats($this->author->id)['total_posts']
        );
    }

    /**
     * 공개 프로필 통계 캐시 (비회원 열람 — 키에 제외 게시판 목록의 md5 가 붙는다).
     *
     * @scenario cache=user_public_stats
     *
     * @effects key_unchanged_without_variant, variants_do_not_overwrite, tag_flush_clears_all_variants
     *
     * @return void 반환값 없음
     */
    public function test_user_public_stats_cache_variant(): void
    {
        $service = app(PostService::class);
        $excluded = (new \ReflectionMethod(PostService::class, 'publicProfileExcludedBoardIds'))->invoke($service, $this->author->id);
        sort($excluded);

        $this->assertVariantContract(
            "user_public_stats_v2_{$this->author->id}_".md5(implode(',', $excluded)),
            fn () => $service->getUserPublicStats($this->author->id)['posts_count']
        );
    }

    /**
     * 홈 최근 게시글 캐시.
     *
     * @scenario cache=recent
     *
     * @effects key_unchanged_without_variant, variants_do_not_overwrite, tag_flush_clears_all_variants
     *
     * @return void 반환값 없음
     */
    public function test_recent_cache_variant(): void
    {
        $service = app(BoardService::class);
        $this->assertVariantContract(
            'recent_posts_v2_10_'.app()->getLocale(),
            fn () => array_values(array_intersect(array_column($service->getCachedRecentPosts(10), 'id'), $this->ids))
        );
    }

    /**
     * 인기 게시글 캐시.
     *
     * @scenario cache=popular
     *
     * @effects key_unchanged_without_variant, variants_do_not_overwrite, tag_flush_clears_all_variants
     *
     * @return void 반환값 없음
     */
    public function test_popular_cache_variant(): void
    {
        $service = app(BoardService::class);
        $this->assertVariantContract(
            'popular_posts_v2_week_10_'.app()->getLocale(),
            fn () => array_values(array_intersect(array_column($service->getCachedPopularPosts('week', 10), 'id'), $this->ids))
        );
    }
}
