<?php

namespace Modules\Sirsoft\Board\Privacy\Concerns;

use App\Models\User;
use App\Privacy\DTO\PrivacyRetentionTarget;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;

/**
 * 게시판 보유기간 핸들러의 대상 목록 — 게시글과 댓글을 한 목록으로 이어 붙인다 (이슈 #636).
 *
 * 두 게시판 핸들러(작성 IP · 비회원 작성자)는 모두 「게시글 쿼리 + 댓글 쿼리」 두 모집단을
 * 합친 건수를 낸다. 목록도 같은 두 쿼리를 **그대로** 합쳐(UNION ALL) 한 페이지씩 자른다 —
 * 건수와 명단이 다른 쿼리에서 나오면 모달의 행 수와 배지 숫자가 어긋난다.
 *
 * 행: 제목(댓글이면 그 글의 제목) · 작성자(비회원 이름, 회원은 닉네임) · 게시판 이름과
 * 글/댓글 구분 · 작성일. 상세 링크는 그 글의 사용자 화면 주소다.
 *
 * 비밀글: 행에는 **제목만** 싣고 본문·첨부·썸네일은 싣지 않는다. `SecretContentGate` 가 가리는
 * 것은 원문(본문·첨부·썸네일)이고 제목은 게시판 목록에서도 공개되므로 이 경로는 게이트 재적용
 * 대상이 아니다 — 본문을 싣게 되면 그때 게이트를 거쳐야 한다.
 */
trait PaginatesBoardTargets
{
    /**
     * 게시글·댓글 대상 쿼리를 합쳐 한 페이지를 돌려줍니다.
     *
     * @param  Builder  $postQuery  대상 게시글 쿼리 (건수와 같은 쿼리)
     * @param  Builder  $commentQuery  대상 댓글 쿼리 (건수와 같은 쿼리)
     * @param  int  $page  페이지 번호 (1부터)
     * @param  int  $perPage  페이지 크기
     * @return LengthAwarePaginator<int, PrivacyRetentionTarget> 대상 행 페이지
     */
    protected function paginateBoardTargets(Builder $postQuery, Builder $commentQuery, int $page, int $perPage): LengthAwarePaginator
    {
        $posts = $postQuery->toBase()
            ->select(['id', 'board_id', 'id as post_id', 'title', 'user_id', 'author_name', 'created_at'])
            ->selectRaw("'post' as kind");

        $comments = $commentQuery->toBase()
            ->select(['id', 'board_id', 'post_id'])
            ->selectRaw('NULL as title')
            ->addSelect(['user_id', 'author_name', 'created_at'])
            ->selectRaw("'comment' as kind");

        $result = DB::query()
            ->fromSub($posts->unionAll($comments), 'board_targets')
            // 가장 먼저 대상이 된(가장 오래된) 것부터 — 같은 시각이면 글을 댓글보다 먼저.
            ->orderBy('created_at')
            ->orderByDesc('kind')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $rows = collect($result->items());
        $boards = Board::query()->whereIn('id', $rows->pluck('board_id')->unique()->all())->get(['id', 'slug', 'name'])->keyBy('id');
        $postTitles = $this->commentPostTitles($rows);
        $members = User::query()->whereIn('id', $rows->pluck('user_id')->filter()->unique()->all())->get(['id', 'name', 'nickname'])->keyBy('id');

        return $result->through(function (object $row) use ($boards, $postTitles, $members) {
            $board = $boards->get($row->board_id);
            $isPost = $row->kind === 'post';
            $member = $row->user_id !== null ? $members->get($row->user_id) : null;
            $kind = __('sirsoft-board::messages.privacy_retention.target_kinds.'.$row->kind);

            return new PrivacyRetentionTarget(
                id: $row->kind.'-'.$row->id,
                title: (string) ($isPost ? $row->title : ($postTitles[$row->post_id] ?? '')),
                subtitle: $member !== null ? ($member->nickname ?: $member->name) : $row->author_name,
                context: $board !== null ? $board->getLocalizedName().' · '.$kind : $kind,
                date: $row->created_at !== null ? Carbon::parse($row->created_at) : null,
                url: $board !== null ? '/admin/board/'.$board->slug.'/post/'.$row->post_id : null,
            );
        });
    }

    /**
     * 댓글 행이 달린 게시글의 제목을 한 번에 읽습니다.
     *
     * @param  Collection<int, object>  $rows  현재 페이지 행
     * @return array<int, string> 게시글 ID => 제목
     */
    private function commentPostTitles(Collection $rows): array
    {
        $postIds = $rows->where('kind', 'comment')->pluck('post_id')->unique()->all();

        if ($postIds === []) {
            return [];
        }

        return Post::withTrashed()->whereIn('id', $postIds)->pluck('title', 'id')->all();
    }

    /**
     * 게시판 대상 목록의 열 라벨을 돌려줍니다.
     *
     * @return array<string, string> 열 이름 => 라벨
     */
    protected function boardTargetColumns(): array
    {
        return [
            'title' => __('sirsoft-board::messages.privacy_retention.target_columns.title'),
            'subtitle' => __('sirsoft-board::messages.privacy_retention.target_columns.author'),
            'context' => __('sirsoft-board::messages.privacy_retention.target_columns.board'),
            'date' => __('sirsoft-board::messages.privacy_retention.target_columns.created_at'),
        ];
    }

    /**
     * 게시판 대상 목록을 볼 수 있는 권한 — 설정 읽기 + 게시판 관리 읽기.
     *
     * @return array<int, string> 권한 식별자 목록
     */
    protected function boardTargetPermissions(): array
    {
        return ['sirsoft-board.settings.read', 'sirsoft-board.boards.read'];
    }

    /**
     * 게시판 보유기간 처리를 관리자 직권으로 실행할 수 있는 권한 — 설정 수정 + 게시판 관리 수정.
     *
     * 목록 권한({@see boardTargetPermissions()})의 쓰기 대칭이다.
     *
     * @return array<int, string> 권한 식별자 목록
     */
    protected function boardRunPermissions(): array
    {
        return ['sirsoft-board.settings.update', 'sirsoft-board.boards.update'];
    }
}
