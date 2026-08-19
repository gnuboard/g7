<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Enums\PermissionType;
use App\Enums\TotalRelation;
use App\Http\Resources\BaseApiCollection;
use App\Support\Query\BoundedCount;
use Illuminate\Http\Request;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;

/**
 * 게시글 목록 리소스 컬렉션
 *
 * 게시글 목록을 페이지네이션과 함께 반환합니다.
 * 순번, 공지사항, 답글 표시를 지원합니다.
 */
class PostCollection extends BaseApiCollection
{
    use ChecksBoardPermission;

    /**
     * 전체 일반 게시글(원글) 수 + 정확도
     */
    private ?BoundedCount $totalNormalPosts = null;

    /**
     * 정렬 방향
     */
    private string $orderDirection = 'desc';

    /**
     * 전체 일반 게시글 수를 설정합니다.
     *
     * @param  BoundedCount  $total  전체 일반 게시글 수 + 정확도
     */
    public function setTotalNormalPosts(BoundedCount $total): void
    {
        $this->totalNormalPosts = $total;
    }

    /**
     * 정렬 방향을 설정합니다.
     *
     * @param  string  $direction  정렬 방향 (asc 또는 desc, 대소문자 무관)
     */
    public function setOrderDirection(string $direction): void
    {
        // 대소문자 무관하게 처리하기 위해 소문자로 정규화
        $this->orderDirection = strtolower($direction);
    }

    /**
     * 리소스 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        $normalPostsTotal = $this->totalNormalPosts?->total() ?? 0;
        $currentPage = $this->currentPage();
        $perPage = $this->perPage();
        $isDescending = $this->orderDirection === 'desc';

        // 현재 페이지의 일반 게시글 시작 순번 계산
        $currentNumber = $this->calculateStartNumber($normalPostsTotal, $currentPage, $perPage, $isDescending);

        $data = $this->collection->map(function ($post) use ($request, &$currentNumber, $isDescending) {
            $postData = (new PostResource($post))->toListArray($request);
            $postData['row_type'] = $this->getRowType($post);
            $postData['number'] = $this->getRowNumber($post, $currentNumber, $isDescending);

            return $postData;
        });

        $pagination = $this->buildPagination($normalPostsTotal, $currentPage, $perPage, $isDescending, $data->count());

        return [
            'data' => $data,
            'pagination' => $pagination,
        ];
    }

    /**
     * 게시판 정보 및 권한이 포함된 형태의 배열을 반환합니다.
     *
     * @param  array  $boardInfo  게시판 정보 배열
     * @return array<string, mixed> 게시판 정보 및 권한이 포함된 게시글 컬렉션
     */
    public function withBoardInfo(array $boardInfo): array
    {
        $baseData = $this->toArray(request());
        $showCategory = $boardInfo['show_category'] ?? false;
        $slug = $boardInfo['slug'] ?? null;

        $postsData = collect($baseData['data'])->map(function ($post) use ($showCategory, $slug) {
            $post['show_category'] = $showCategory;
            $post['slug'] = $slug;

            return $post;
        })->toArray();

        return [
            'data' => $postsData,
            'pagination' => $baseData['pagination'],
            'board' => $boardInfo,
            'abilities' => $this->getBoardPermissions($slug),
        ];
    }

    // =========================================================================
    // 헬퍼 메서드 - 순번 계산
    // =========================================================================

    /**
     * 시작 순번을 계산합니다.
     *
     * 내림차순 순번은 "전체 몇 건 중 몇 번째" 라 총 건수를 알아야 나온다. 총 건수가 상한에
     * 걸려 잘렸으면 그 값으로 역산한 순번은 첫 페이지부터 이미 틀리고(12,027 건인데 10000
     * 부터 시작), 상한을 넘어선 페이지에서는 0 과 음수까지 내려간다. 틀린 숫자를 내보내는
     * 것보다 내보내지 않는 편이 낫으므로 `last_page` 와 같은 원칙으로 null 을 돌려준다.
     *
     * 오름차순은 offset 만으로 정해지므로 총 건수가 잘려도 그대로 정확하다.
     *
     * @param  int  $total  전체 게시글 수
     * @param  int  $currentPage  현재 페이지
     * @param  int  $perPage  페이지당 항목 수
     * @param  bool  $isDescending  내림차순 여부
     * @return int|null 시작 순번 (총 건수가 잘린 내림차순이면 null)
     */
    private function calculateStartNumber(int $total, int $currentPage, int $perPage, bool $isDescending): ?int
    {
        if ($isDescending) {
            if ($this->totalNormalPosts?->isTruncated()) {
                return null;
            }

            // 내림차순: 큰 숫자부터 (28, 27, 26, ...)
            return $total - (($currentPage - 1) * $perPage);
        }

        // 오름차순: 작은 숫자부터 (1, 2, 3, ...)
        return (($currentPage - 1) * $perPage) + 1;
    }

    /**
     * 게시글의 행 유형을 반환합니다.
     *
     * @param  mixed  $post  게시글 모델
     * @return string 행 유형 (notice, reply, normal)
     */
    private function getRowType($post): string
    {
        if ($post->is_notice) {
            return 'notice';
        }

        if ($post->parent_id !== null) {
            return 'reply';
        }

        return 'normal';
    }

    /**
     * 게시글의 순번을 반환합니다.
     *
     * @param  mixed  $post  게시글 모델
     * @param  int|null  &$currentNumber  현재 순번 (참조, 총 건수가 잘린 내림차순이면 null)
     * @param  bool  $isDescending  내림차순 여부
     * @return string|int|null 순번 또는 라벨 (순번을 알 수 없으면 null)
     */
    private function getRowNumber($post, ?int &$currentNumber, bool $isDescending): string|int|null
    {
        if ($post->is_notice) {
            return __('sirsoft-board::messages.post.notice');
        }

        if ($post->parent_id !== null) {
            return __('sirsoft-board::messages.post.reply');
        }

        // 총 건수를 모르면 순번도 없다. 여기서 임의의 값을 채우면 화면에는 그럴듯한 숫자가
        // 나가지만 실제 위치와 어긋난 값이라 그 사실이 드러나지 않는다.
        if ($currentNumber === null) {
            return null;
        }

        $number = $currentNumber;
        $currentNumber = $isDescending ? $currentNumber - 1 : $currentNumber + 1;

        return $number;
    }

    // =========================================================================
    // 헬퍼 메서드 - 페이지네이션
    // =========================================================================

    /**
     * 페이지네이션 정보를 구성합니다.
     *
     * @param  int  $total  전체 게시글 수
     * @param  int  $currentPage  현재 페이지
     * @param  int  $perPage  페이지당 항목 수
     * @param  bool  $isDescending  내림차순 여부
     * @param  int  $currentPageItemCount  현재 페이지 항목 수
     * @return array<string, mixed> 페이지네이션 정보
     */
    private function buildPagination(int $total, int $currentPage, int $perPage, bool $isDescending, int $currentPageItemCount): array
    {
        $isTruncated = (bool) $this->totalNormalPosts?->isTruncated();

        // 총 건수가 상한에 걸려 잘렸으면 마지막 페이지 번호를 계산할 수 없다. 1 이나 어림값을
        // 채우면 화면이 "여기가 끝" 으로 읽어 그 뒤 행에 도달할 방법이 사라진다. null 로
        // 내보내 마지막 페이지 점프만 감추게 하고, "다음" 은 has_more_pages 로 계속 열어 둔다.
        $lastPage = $isTruncated
            ? null
            : ($total > 0 ? (int) ceil($total / $perPage) : 1);

        [$from, $to] = $this->calculateFromTo($total, $currentPage, $perPage, $isDescending);

        return [
            'total' => $total,
            'all_total' => $total,
            'count' => $currentPageItemCount,
            'per_page' => $perPage,
            'current_page' => $currentPage,
            'last_page' => $lastPage,
            'from' => $from,
            'to' => $to,
            'has_more_pages' => $this->hasMorePages(),
            'total_relation' => $this->totalNormalPosts?->totalRelation()->value ?? TotalRelation::Exact->value,
            'total_is_exact' => ! $isTruncated,
            'result_cap' => $this->totalNormalPosts?->resultCap(),
        ];
    }

    /**
     * from/to 값을 계산합니다.
     *
     * @param  int  $total  전체 게시글 수
     * @param  int  $currentPage  현재 페이지
     * @param  int  $perPage  페이지당 항목 수
     * @param  bool  $isDescending  내림차순 여부
     * @return array{int|null, int|null} [from, to]
     */
    private function calculateFromTo(int $total, int $currentPage, int $perPage, bool $isDescending): array
    {
        if ($total === 0) {
            return [0, 0];
        }

        if ($isDescending) {
            // 순번과 같은 역산이므로 총 건수가 잘리면 이 값도 성립하지 않는다.
            if ($this->totalNormalPosts?->isTruncated()) {
                return [null, null];
            }

            // 내림차순: 큰 숫자 → 작은 숫자
            $from = $total - (($currentPage - 1) * $perPage);
            $to = max($from - $perPage + 1, 1);
        } else {
            // 오름차순: 작은 숫자 → 큰 숫자
            $from = (($currentPage - 1) * $perPage) + 1;
            $to = min($currentPage * $perPage, $total);
        }

        return [$from, $to];
    }

    // =========================================================================
    // 권한 관련 메서드
    // =========================================================================

    /**
     * 게시판 권한 정보를 반환합니다.
     *
     * @param  string|null  $slug  게시판 슬러그
     * @return array<string, bool> 권한 정보
     */
    private function getBoardPermissions(?string $slug): array
    {
        if (! $slug) {
            return [];
        }

        $isAdmin = $this->isAdminRequest(request());

        if ($isAdmin) {
            return $this->getAdminPermissions($slug);
        }

        return $this->getUserPermissions($slug);
    }

    /**
     * Admin 권한 정보를 반환합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, bool> Admin 권한 정보
     */
    private function getAdminPermissions(string $slug): array
    {
        return [
            'can_read' => $this->checkBoardPermission($slug, 'admin.posts.read'),
            'can_write' => $this->checkBoardPermission($slug, 'admin.posts.write'),
            'can_read_secret' => $this->checkBoardPermission($slug, 'admin.posts.read-secret'),
            'can_read_comments' => $this->checkBoardPermission($slug, 'admin.comments.read'),
            'can_write_comments' => $this->checkBoardPermission($slug, 'admin.comments.write'),
            'can_upload' => $this->checkBoardPermission($slug, 'admin.attachments.upload'),
            'can_download' => $this->checkBoardPermission($slug, 'admin.attachments.download'),
            'can_manage' => $this->checkBoardPermission($slug, 'admin.manage'),
        ];
    }

    /**
     * User 권한 정보를 반환합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, bool> User 권한 정보
     */
    private function getUserPermissions(string $slug): array
    {
        $canManage = $this->checkBoardPermission($slug, 'manager', PermissionType::User);

        return [
            'can_read' => $this->checkBoardPermission($slug, 'posts.read', PermissionType::User),
            'can_write' => $this->checkBoardPermission($slug, 'posts.write', PermissionType::User),
            'can_read_secret' => $this->checkBoardPermission($slug, 'posts.read-secret', PermissionType::User),
            'can_read_comments' => $this->checkBoardPermission($slug, 'comments.read', PermissionType::User),
            'can_write_comments' => $this->checkBoardPermission($slug, 'comments.write', PermissionType::User),
            'can_upload' => $this->checkBoardPermission($slug, 'attachments.upload', PermissionType::User),
            'can_download' => $this->checkBoardPermission($slug, 'attachments.download', PermissionType::User),
            'can_manage' => $canManage,
            'can_view_deleted' => $canManage,
            // 유저 화면에서 관리자 게시판 화면으로 진입 가능한지 여부 (Admin 타입 admin.manage 권한 보유자만 true).
            // 유저용 can_manage(게시판 매니저)와 다른, 관리자 화면 접근 게이트 전용 플래그.
            'can_access_admin' => $this->checkBoardPermission($slug, 'admin.manage'),
        ];
    }
}
