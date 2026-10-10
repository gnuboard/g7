<?php

namespace Modules\Sirsoft\Board\Http\Controllers\User;

use App\Http\Controllers\Api\Base\AuthBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;
use Modules\Sirsoft\Board\Services\CommentService;
use Modules\Sirsoft\Board\Services\PostService;

/**
 * 사용자 게시글 활동 컨트롤러
 *
 * 마이페이지에서 사용자의 게시글 활동(작성, 댓글, 신고)을 조회합니다.
 */
class UserActivityController extends AuthBaseController
{
    /**
     * 내 글 검색 대상 허용값
     *
     * @var array<int, string>
     */
    private const POST_SEARCH_FIELDS = ['title_content', 'title', 'content'];

    /**
     * 내 댓글 검색 대상 허용값
     *
     * @var array<int, string>
     */
    private const COMMENT_SEARCH_FIELDS = ['content', 'post_title'];

    /**
     * UserActivityController 생성자
     *
     * @param  PostService  $postService  게시글 서비스
     * @param  CommentService  $commentService  댓글 서비스
     */
    public function __construct(
        private PostService $postService,
        private CommentService $commentService
    ) {}

    /**
     * 사용자의 게시글 활동 목록을 조회합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return JsonResponse 게시글 활동 목록 응답
     */
    // audit:allow controller-base-request-injection reason: GET 본인 활동 목록. 필터·페이징 파라미터만 input() 으로 읽고, per_page 는 아래에서 1~100 으로 잘라낸다 (같은 모듈 PostController::userPosts 와 동일 패턴)
    public function index(Request $request): JsonResponse
    {
        try {
            $userId = Auth::id();

            $filters = [
                'board_slug' => $request->input('board_slug'),
                'search' => $request->input('search'),
                'search_field' => $this->resolveSearchField(
                    $request->input('search_field'),
                    self::POST_SEARCH_FIELDS,
                    'title_content'
                ),
                'activity_type' => $request->input('activity_type'),
                'sort' => $request->input('sort', 'latest'), // latest, oldest, views
            ];

            // 요청 per_page 는 그대로 페이지네이터에 넘기지 않는다. 0/음수는 페이지네이터에서
            // 나눗셈 오류(500)가 되고, 큰 값은 목록 전량을 한 번에 읽는다.
            // 같은 컨트롤러의 myComments 와 동일한 상·하한을 적용한다.
            $perPage = (int) $request->input('per_page', 20);
            $perPage = min(max($perPage, 1), 100);

            $activities = $this->postService->getUserActivities($userId, $filters, $perPage);

            // 페이지네이션 결과를 배열로 변환하고 query 파라미터 추가
            $result = $activities->toArray();
            $result['query'] = [
                'board_slug' => $filters['board_slug'] ?? '',
                'search' => $filters['search'] ?? '',
                // 화면이 셀렉트 값을 복원할 수 있도록 실제로 적용된 값을 되돌려준다
                // (모르는 값이 왔을 때 무엇으로 떨어졌는지도 이 값으로 드러난다).
                'search_field' => $filters['search_field'],
                'sort' => $filters['sort'] ?? 'latest',
                'activity_type' => $filters['activity_type'] ?? '',
            ];

            return $this->success(
                'sirsoft-board::messages.user_activities.fetch_success',
                $result
            );
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.user_activities.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 사용자가 작성한 댓글 목록을 조회합니다.
     *
     * 댓글 기준 페이지네이션으로 작성일, 댓글 내용, 게시글 제목, 게시판 정보를 반환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return JsonResponse 댓글 목록 응답
     */
    // audit:allow controller-base-request-injection reason: GET 본인 댓글 목록. 필터·페이징 파라미터만 input() 으로 읽고, per_page 는 아래에서 1~100 으로 잘라낸다
    public function myComments(Request $request): JsonResponse
    {
        try {
            $userId = Auth::id();

            $filters = [
                'board_slug' => $request->input('board_slug'),
                'search' => $request->input('search'),
                'search_field' => $this->resolveSearchField(
                    $request->input('search_field'),
                    self::COMMENT_SEARCH_FIELDS,
                    'content'
                ),
                'sort' => $request->input('sort', 'latest'),
            ];

            $perPage = (int) $request->input('per_page', 20);
            $perPage = min(max($perPage, 1), 100);

            $comments = $this->commentService->getUserComments($userId, $filters, $perPage);

            $result = $comments->toArray();
            $result['query'] = [
                'board_slug' => $filters['board_slug'] ?? '',
                'search' => $filters['search'] ?? '',
                'search_field' => $filters['search_field'],
                'sort' => $filters['sort'] ?? 'latest',
            ];

            return $this->success(
                'sirsoft-board::messages.user_activities.fetch_success',
                $result
            );
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.user_activities.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 검색 대상 값을 허용 목록으로 좁힙니다.
     *
     * 422 로 되돌리지 않고 기본값으로 떨어뜨린다 — 이 두 엔드포인트는 마이페이지 탭이
     * 링크·뒤로가기로 오가며 옛 쿼리스트링을 그대로 실어 오는 자리라, 모르는 값 하나로
     * 목록이 통째로 에러가 되면 화면이 빈다. 대신 실제로 적용된 값을 data.query 로
     * 되돌려 화면이 셀렉트를 그 값에 맞춘다.
     *
     * @param  mixed  $value  요청 값
     * @param  array<int, string>  $allowed  허용 목록
     * @param  string  $default  기본값
     * @return string 적용할 검색 대상
     */
    private function resolveSearchField($value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? (string) $value : $default;
    }

    /**
     * 사용자의 게시판 활동 통계를 조회합니다.
     *
     * @return JsonResponse 활동 통계 응답 (total_posts, total_comments, total_views)
     */
    public function stats(): JsonResponse
    {
        try {
            $userId = Auth::id();

            $stats = $this->postService->getUserActivityStats($userId);

            // 마이페이지 게시판 활동 카드가 반응·신고 카운터도 보여준다.
            $stats['total_reactions'] = app(ReactionRepositoryInterface::class)
                ->summaryByUser((int) $userId)['count'];
            $stats['total_reports'] = app(ReportRepositoryInterface::class)
                ->countLogsByReporter((int) $userId);

            return $this->success(
                'sirsoft-board::messages.user_activities.stats_success',
                $stats
            );
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.user_activities.stats_failed', 500, $e->getMessage());
        }
    }
}
