<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

use App\Extension\HookManager;
use App\Support\Query\PaginationLimits;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Repositories\PostRepository;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 첫 페이지 공지 조회의 안전상한 회귀 테스트 (공개 #74)
 *
 * 목록 1페이지는 그 게시판의 공지 전량을 상한 없이 `get()` 으로 읽어 왔다. 공지는
 * 운영자가 등록하는 데이터라 통상 소수지만, 개수를 제한하는 장치가 어디에도 없어
 * 잘못 늘어난 게시판에서는 한 페이지를 여는 것만으로 그 전량이 메모리에 올라온다.
 *
 * 상한은 코어 `PaginationLimits::resultCap('board.notices')` 가 해석하며 확장은
 * `core.pagination.filter_result_cap` 필터 훅으로 조정한다. 상한을 리터럴로 다시
 * 적으면 코어 설정과 어긋나므로 여기서도 훅으로만 검증한다.
 *
 * 5단계 병합 구조(공지 → 원글 → 답글 → 정렬 → 병합)는 손대지 않는다 — 이 테스트는
 * 상한 부가가 일반 게시글 목록과 총 건수에 영향을 주지 않는 것까지 함께 고정한다.
 *
 * @scenario case=notice_result_cap
 *
 * @effects notice_query_respects_result_cap,
 *          notice_cap_keeps_newest_first,
 *          notice_cap_does_not_affect_normal_posts,
 *          other_contexts_keep_their_own_cap
 */
class NoticeResultCapTest extends BoardTestCase
{
    /** 공지 상한 컨텍스트 키 */
    private const CONTEXT = 'board.notices';

    /** 훅 해제를 위해 등록한 콜백 */
    private $capFilter = null;

    protected function tearDown(): void
    {
        if ($this->capFilter !== null) {
            HookManager::removeFilter('core.pagination.filter_result_cap', $this->capFilter);
            $this->capFilter = null;
        }

        parent::tearDown();
    }

    /**
     * 공지 컨텍스트에만 적용되는 상한 필터를 등록합니다.
     *
     * 다른 컨텍스트(예: 답글 트리)는 원값을 그대로 통과시켜, 상한이 컨텍스트별로
     * 분리되어 해석되는지도 함께 확인한다.
     *
     * @param  int  $cap  공지 컨텍스트에 적용할 상한
     */
    private function capNoticesAt(int $cap): void
    {
        $this->capFilter = function ($value, $context = null) use ($cap) {
            return $context === self::CONTEXT ? $cap : $value;
        };

        HookManager::addFilter('core.pagination.filter_result_cap', $this->capFilter, priority: 1);
    }

    /**
     * 게시글을 생성합니다.
     *
     * @param  string  $title  제목
     * @param  bool  $isNotice  공지 여부
     * @param  int  $minutesAgo  작성 시각 (분 단위 과거)
     */
    private function makePost(string $title, bool $isNotice, int $minutesAgo): Post
    {
        $post = Post::create([
            'board_id' => $this->board->id,
            'user_id' => null,
            'parent_id' => null,
            'title' => $title,
            'content' => $title.' 본문',
            'author_name' => '작성자',
            'is_notice' => $isNotice,
            'status' => 'published',
            'depth' => 0,
            'ip_address' => '127.0.0.1',
        ]);

        // created_at 은 $fillable 이 아니라 create() 로는 들어가지 않는다.
        // 전부 같은 초로 저장되면 정렬 축이 사라져 "최신 우선 보존" 을 검증할 수 없다.
        $post->forceFill([
            'created_at' => now()->subMinutes($minutesAgo),
            'updated_at' => now()->subMinutes($minutesAgo),
        ])->saveQuietly();

        return $post;
    }

    public function test_공지_조회가_설정된_안전상한을_넘지_않는다(): void
    {
        // 공지 5건 (오래된 것부터 1..5 — 5번이 가장 최신)
        for ($i = 1; $i <= 5; $i++) {
            $this->makePost("공지 {$i}", true, 100 - $i);
        }

        $this->capNoticesAt(3);

        $result = app(PostRepository::class)->paginate($this->board->slug, ['page' => 1], 15, false, $this->board);
        $notices = collect($result->items())->filter(fn ($p) => (bool) $p->is_notice);

        $this->assertCount(3, $notices, '공지 상한이 조회에 반영되지 않았다');
    }

    public function test_상한에_걸리면_최신_공지가_남는다(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->makePost("공지 {$i}", true, 100 - $i);
        }

        $this->capNoticesAt(2);

        $result = app(PostRepository::class)->paginate($this->board->slug, ['page' => 1], 15, false, $this->board);
        $titles = collect($result->items())
            ->filter(fn ($p) => (bool) $p->is_notice)
            ->pluck('title')
            ->values()
            ->all();

        // created_at desc 정렬이므로 최신 2건(공지 5, 공지 4)이 남는다.
        // 잘린 공지는 후속 페이지에 노출될 경로가 없으므로 최신 우선 보존이 유일한 안전판이다.
        $this->assertSame(['공지 5', '공지 4'], $titles);
    }

    public function test_공지_상한이_일반_게시글_목록과_총건수를_바꾸지_않는다(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->makePost("공지 {$i}", true, 100 - $i);
        }
        for ($i = 1; $i <= 4; $i++) {
            $this->makePost("일반글 {$i}", false, 50 - $i);
        }

        $repository = app(PostRepository::class);

        $uncapped = $repository->paginate($this->board->slug, ['page' => 1], 15, false, $this->board);
        $normalBefore = collect($uncapped->items())->reject(fn ($p) => (bool) $p->is_notice)->pluck('title')->values()->all();
        $totalBefore = $repository->countNormalPosts($this->board->slug);

        $this->capNoticesAt(2);

        $capped = $repository->paginate($this->board->slug, ['page' => 1], 15, false, $this->board);
        $normalAfter = collect($capped->items())->reject(fn ($p) => (bool) $p->is_notice)->pluck('title')->values()->all();
        $totalAfter = $repository->countNormalPosts($this->board->slug);

        $this->assertSame($normalBefore, $normalAfter, '공지 상한이 일반 게시글 목록을 바꿨다');
        $this->assertCount(4, $normalAfter);

        // 화면이 보여주는 총 건수는 이 목록과 별개 술어(countNormalPosts)로 세어진다.
        // 공지 상한이 그 경로까지 새면 페이저 끝이 줄어 뒤쪽 게시글에 도달할 수 없게 된다.
        // 잘린 값과 정확한 값을 구분해야 하므로 건수뿐 아니라 정확도까지 비교한다.
        $this->assertSame($totalBefore->total(), $totalAfter->total(), '공지 상한이 목록 총 건수를 바꿨다');
        $this->assertSame($totalBefore->totalRelation(), $totalAfter->totalRelation(), '공지 상한이 총 건수의 정확도를 바꿨다');

        // 다음 페이지 존재 여부도 공지 상한과 무관해야 한다 (per_page + 1 실측 경로).
        $this->assertSame($uncapped->hasMorePages(), $capped->hasMorePages(), '공지 상한이 페이지 이동 범위를 바꿨다');
    }

    public function test_다른_컨텍스트는_자기_상한을_유지한다(): void
    {
        $this->capNoticesAt(2);

        $this->assertSame(2, PaginationLimits::resultCap(self::CONTEXT));
        $this->assertNotSame(2, PaginationLimits::resultCap('board.reply_tree'));
    }
}
