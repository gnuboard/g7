<?php

namespace Modules\Sirsoft\Board\Http\Controllers\User;

use App\Http\Controllers\Api\Base\AuthBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
                'sort' => $request->input('sort', 'latest'),
            ];

            $perPage = (int) $request->input('per_page', 20);
            $perPage = min(max($perPage, 1), 100);

            $comments = $this->commentService->getUserComments($userId, $filters, $perPage);

            $result = $comments->toArray();
            $result['query'] = [
                'board_slug' => $filters['board_slug'] ?? '',
                'search' => $filters['search'] ?? '',
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
     * 사용자의 게시판 활동 통계를 조회합니다.
     *
     * @return JsonResponse 활동 통계 응답 (total_posts, total_comments, total_views)
     */
    public function stats(): JsonResponse
    {
        try {
            $userId = Auth::id();

            $stats = $this->postService->getUserActivityStats($userId);

            return $this->success(
                'sirsoft-board::messages.user_activities.stats_success',
                $stats
            );
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.user_activities.stats_failed', 500, $e->getMessage());
        }
    }
}
