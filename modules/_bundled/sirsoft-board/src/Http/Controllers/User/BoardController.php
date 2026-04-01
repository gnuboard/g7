<?php

namespace Modules\Sirsoft\Board\Http\Controllers\User;

use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Models\User;
use App\Services\CacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sirsoft\Board\Http\Resources\BoardMenuResource;
use Modules\Sirsoft\Board\Http\Resources\BoardResource;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Services\BoardService;

/**
 * 사용자용 게시판 컨트롤러
 *
 * - 비로그인 사용자도 접근 가능한 공개 API
 * - 활성화된 게시판 목록 조회
 * - 게시판 상세 정보 조회 (권한 체크 포함)
 */
class BoardController extends PublicBaseController
{
    /**
     * BoardController 생성자
     *
     * @param  BoardService  $boardService  게시판 서비스
     */
    public function __construct(
        protected BoardService $boardService
    ) {}

    /**
     * 활성화된 게시판 목록 조회 (경량)
     *
     * 전체 게시판 목록 페이지에서 사용하는 경량 API입니다.
     * - id, name, slug, description, posts_count만 반환
     * - 각 게시판별 최신글 N개 포함 (답변글, 삭제, 블라인드 제외)
     * - limit 파라미터로 게시글 개수 조절 가능 (기본: 0, 최대: 10)
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        // 활성화된 게시판만 조회
        $boards = $this->boardService->getActiveBoards();

        // limit 파라미터 (0이면 최신글 미포함, 최대 10개)
        $recentPostsLimit = min(max((int) $request->input('limit', 0), 0), 10);

        // toListArray() 메서드를 사용하여 경량 데이터 반환
        $data = $boards->map(function ($board) use ($recentPostsLimit) {
            $boardData = (new BoardResource($board))->toListArray();

            // limit가 0보다 크면 최신글 조회
            if ($recentPostsLimit > 0) {
                $boardData['recent_posts'] = $this->boardService->getBoardRecentPosts($board->slug, $recentPostsLimit);
            }

            return $boardData;
        });

        return $this->success(
            'common.success',
            $data
        );
    }

    /**
     * 특정 게시판 상세 정보 조회
     *
     * @param  string  $slug  게시판 슬러그
     * @return JsonResponse
     */
    public function show(string $slug): JsonResponse
    {
        $board = $this->boardService->getBoardBySlug($slug, checkScope: false);

        // 게시판이 없거나 비활성화된 경우 404 (보안: 존재 여부 숨김)
        if (! $board || ! $board->is_active) {
            return $this->notFound();
        }

        return $this->success(
            'common.success',
            BoardResource::make($board)
        );
    }

    /**
     * 네비게이션 메뉴용 게시판 목록 조회 (경량)
     *
     * 헤더 메뉴 등에서 사용할 최소한의 게시판 정보만 반환합니다.
     * - id, name, slug만 포함
     * - 활성화된 게시판만 조회
     * - 오래된 순으로 정렬 (created_at ASC)
     *
     * @return JsonResponse
     */
    public function boardMenu(): JsonResponse
    {
        // 활성화된 게시판을 오래된 순으로 조회
        $boards = $this->boardService->getActiveBoards(orderBy: 'created_at', orderDirection: 'asc');

        // 경량 리소스로 변환하여 반환
        return $this->success(
            'common.success',
            BoardMenuResource::collection($boards)->resolve()
        );
    }

    /**
     * 게시판 관련 통계 조회
     *
     * 홈 페이지 등에서 표시할 게시판 관련 통계 정보를 반환합니다.
     * - 활성화된 게시판 수
     * - 전체 게시글 수
     * - 전체 댓글 수
     *
     * @return JsonResponse
     */
    public function stats(): JsonResponse
    {
        $stats = CacheService::remember(
            'sirsoft-board',
            'stats',
            fn () => $this->calculateStats(),
            config('sirsoft-board.cache.ttl', 60)
        );

        return $this->success('common.success', $stats);
    }

    /**
     * 최근 게시글 조회
     *
     * 모든 게시판의 최근 게시글을 통합하여 반환합니다.
     * - 기본 5개, 최대 20개까지 조회 가능
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function recentPosts(Request $request): JsonResponse
    {
        $limit = min((int) $request->input('limit', 5), 20);

        $posts = CacheService::remember(
            'sirsoft-board',
            "recent_posts_{$limit}",
            fn () => $this->boardService->getRecentPosts($limit),
            config('sirsoft-board.cache.ttl', 60)
        );

        return $this->success('common.success', $posts);
    }

    /**
     * 인기 게시글 조회 (조회수 기준)
     *
     * 조회수가 많은 순으로 게시글 목록을 반환합니다.
     * - 기본 20개, 최대 50개까지 조회 가능
     * - 기간별 필터링: today, week, month, all (기본: week)
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function popular(Request $request): JsonResponse
    {
        $period = $request->input('period', 'week'); // today, week, month, all
        $limit = min((int) $request->input('limit', 20), 50);
        $locale = app()->getLocale();

        // 캐시 키에 period, locale 포함
        $posts = CacheService::remember(
            'sirsoft-board',
            "popular_posts_{$locale}_{$period}_{$limit}",
            fn () => $this->boardService->getPopularPosts($period, $limit),
            config('sirsoft-board.cache.ttl', 60)
        );

        return $this->success('common.success', $posts);
    }

    /**
     * 인기 게시판 조회 (게시글 수 기준)
     *
     * 게시글 수가 많은 순으로 게시판 목록을 반환합니다.
     * - 기본 4개, 최대 20개까지 조회 가능
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function popularBoards(Request $request): JsonResponse
    {
        $limit = min((int) $request->input('limit', 4), 20);
        $locale = app()->getLocale();

        $boards = CacheService::remember(
            'sirsoft-board',
            "popular_boards_{$locale}_{$limit}",
            fn () => $this->boardService->getPopularBoards($limit),
            config('sirsoft-board.cache.ttl', 60)
        );

        return $this->success('common.success', $boards);
    }

    /**
     * 통계 계산 (홈페이지용)
     *
     * @return array{users: int, boards: int, posts: int, comments: int}
     */
    private function calculateStats(): array
    {
        return [
            'users' => User::count(),
            'boards' => Board::where('is_active', true)->count(),
            'posts' => $this->boardService->getTotalPostsCount(),
            'comments' => $this->boardService->getTotalCommentsCount(),
        ];
    }
}
