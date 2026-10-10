<?php

namespace Modules\Sirsoft\Board\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Enums\TriggerType;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Repositories\Contracts\AttachmentRepositoryInterface;

/**
 * 게시판 첨부파일 Repository 구현체
 */
class AttachmentRepository implements AttachmentRepositoryInterface
{
    /**
     * ID로 첨부파일 조회
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  첨부파일 ID
     * @return Attachment|null 첨부파일 또는 null
     */
    public function findById(string $slug, int $id): ?Attachment
    {
        $board = Board::where('slug', $slug)->first();

        return Attachment::query()
            ->where('board_id', $board?->id)
            ->where('id', $id)
            ->first();
    }

    /**
     * 이 게시판에 올렸지만 아직 글에 이어지지 않은 임시 첨부를 ID 로 조회
     *
     * 임시 첨부는 board_id=0 으로 저장되고 경로가 `{slug}/temp/{temp_key}/…` 이다. 경로 앞부분으로
     * 게시판을 가려 다른 게시판 주소로는 찾지 못하게 한다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  첨부파일 ID
     * @return Attachment|null 임시 첨부 또는 null
     */
    public function findTempById(string $slug, int $id): ?Attachment
    {
        return Attachment::query()
            ->where('id', $id)
            ->where(fn ($query) => $this->whereBoardTemp($query, $slug))
            ->first();
    }

    /**
     * 조회를 이 게시판의 임시 첨부(board_id=0, 글 미연결, `{slug}/temp/` 경로)로 좁힙니다.
     *
     * @param  Builder<Attachment>  $query  첨부 쿼리
     * @param  string  $slug  게시판 슬러그
     * @return void 반환값 없음
     */
    private function whereBoardTemp($query, string $slug): void
    {
        $query->where('board_id', 0)
            ->whereNull('post_id')
            ->where('path', 'like', addcslashes($slug, '\%_').'/temp/%');
    }

    /**
     * 조회를 이 게시판 첨부 또는 이 게시판의 임시 첨부로 좁힙니다(삭제·순서 변경 대상).
     *
     * @param  Builder<Attachment>  $query  첨부 쿼리
     * @param  string  $slug  게시판 슬러그
     * @param  int|null  $boardId  게시판 ID
     * @return void 반환값 없음
     */
    private function whereBoardOrBoardTemp($query, string $slug, ?int $boardId): void
    {
        $query->where(function ($scope) use ($slug, $boardId) {
            $scope->where('board_id', $boardId)
                ->orWhere(fn ($temp) => $this->whereBoardTemp($temp, $slug));
        });
    }

    /**
     * 해시로 첨부파일 조회
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $hash  첨부파일 해시
     * @return Attachment|null 첨부파일 또는 null
     */
    public function findByHash(string $slug, string $hash): ?Attachment
    {
        $board = Board::where('slug', $slug)->first();

        return Attachment::query()
            ->where('board_id', $board?->id)
            ->where('hash', $hash)
            ->first();
    }

    /**
     * 첨부파일이 속한 게시글이 삭제(soft delete) 상태인지 확인합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @return bool 소속 게시글이 삭제 상태인지 여부
     */
    public function isPostDeleted(string $slug, int $postId): bool
    {
        $board = Board::where('slug', $slug)->first();

        if (! $board) {
            return false;
        }

        $post = Post::withTrashed()
            ->where('board_id', $board->id)
            ->where('id', $postId)
            ->first(['id', 'status', 'deleted_at']);

        if (! $post) {
            return false;
        }

        return $post->deleted_at !== null || $post->status === PostStatus::Deleted;
    }

    /**
     * 첨부파일이 속한 게시글을 게시판 스코프로 조회합니다(비밀 게이팅용).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @return Post|null 게시글 모델 또는 null
     */
    public function findPostForGate(string $slug, int $postId): ?Post
    {
        $board = Board::where('slug', $slug)->first();

        if (! $board) {
            return null;
        }

        return Post::withTrashed()
            ->where('board_id', $board->id)
            ->where('id', $postId)
            ->first(['id', 'board_id', 'user_id', 'is_secret', 'status', 'deleted_at']);
    }

    /**
     * 여러 ID로 첨부파일 조회 (order 정렬)
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array<int>  $ids  첨부파일 ID 배열
     * @return Collection<int, Attachment>
     */
    public function findByIds(string $slug, array $ids): Collection
    {
        if (empty($ids)) {
            // `collect()` 는 Support 라 선언과 어긋난다 — 빈 결과도 모델 컬렉션이어야 한다.
            return new Collection;
        }

        $board = Board::where('slug', $slug)->first();

        return Attachment::query()
            ->where('board_id', $board?->id)
            ->whereIn('id', $ids)
            ->orderBy('order')
            ->get();
    }

    /**
     * 게시글별 첨부파일 조회
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @param  string|null  $collection  컬렉션 필터 (null이면 전체)
     * @return Collection<int, Attachment>
     */
    public function getByPost(string $slug, int $postId, ?string $collection = null): Collection
    {
        $board = Board::where('slug', $slug)->first();

        $query = Attachment::query()
            ->where('board_id', $board?->id)
            ->where('post_id', $postId)
            ->orderBy('order');

        if ($collection !== null) {
            $query->where('collection', $collection);
        }

        return $query->get();
    }

    /**
     * 첨부파일 생성
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array<string, mixed>  $data  생성 데이터
     * @return Attachment 생성된 첨부파일
     */
    public function create(string $slug, array $data): Attachment
    {
        return Attachment::create($data);
    }

    /**
     * 첨부파일 업데이트
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  첨부파일 ID
     * @param  array<string, mixed>  $data  업데이트 데이터
     * @return Attachment 업데이트된 첨부파일
     */
    public function update(string $slug, int $id, array $data): Attachment
    {
        $board = Board::where('slug', $slug)->first();

        $attachment = Attachment::query()
            ->where('board_id', $board?->id)
            ->where('id', $id)
            ->firstOrFail();

        $attachment->update($data);

        return $attachment->fresh();
    }

    /**
     * 첨부파일 삭제
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  첨부파일 ID
     * @return bool 삭제 성공 여부
     */
    public function delete(string $slug, int $id): bool
    {
        $board = Board::where('slug', $slug)->first();

        // 글에 이어지지 않은 임시 첨부(board_id=0)도 대상이다 — 저장이 실패한 글쓰기 화면에서 지울 수 있어야 한다
        $attachment = Attachment::query()
            ->where(fn ($query) => $this->whereBoardOrBoardTemp($query, $slug, $board?->id))
            ->where('id', $id)
            ->firstOrFail();

        return $attachment->delete();
    }

    /**
     * 첨부파일 순서 재정렬
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array<int, int>  $orders  첨부파일 ID => order 매핑
     * @return bool 성공 여부
     */
    public function reorder(string $slug, array $orders): bool
    {
        $board = Board::where('slug', $slug)->first();

        DB::beginTransaction();
        try {
            foreach ($orders as $id => $order) {
                // 임시 첨부(board_id=0)의 순서도 바꾼다 — 글에 이을 때 이 순서를 따른다
                Attachment::query()
                    ->where(fn ($query) => $this->whereBoardOrBoardTemp($query, $slug, $board?->id))
                    ->where('id', $id)
                    ->update(['order' => $order]);
            }
            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();

            return false;
        }
    }

    /**
     * 현재 컬렉션의 최대 order 조회
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @param  string  $collection  컬렉션명
     * @return int 최대 order 값
     */
    public function getMaxOrder(string $slug, int $postId, string $collection): int
    {
        $board = Board::where('slug', $slug)->first();

        $maxOrder = Attachment::query()
            ->where('board_id', $board?->id)
            ->where('post_id', $postId)
            ->where('collection', $collection)
            ->max('order');

        return $maxOrder ?? 0;
    }

    /**
     * 임시 업로드의 최대 order 조회
     *
     * 임시 업로드 레코드는 board_id=0으로 저장됩니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string|null  $tempKey  임시 업로드 키
     * @param  string  $collection  컬렉션명
     * @return int 최대 order 값
     */
    public function getMaxOrderByTempKey(string $slug, ?string $tempKey, string $collection): int
    {
        if (! $tempKey) {
            return 0;
        }

        // 임시 업로드 레코드: board_id=0
        $maxOrder = Attachment::query()
            ->where('board_id', 0)
            ->whereNull('post_id')
            ->where('temp_key', $tempKey)
            ->where('collection', $collection)
            ->max('order');

        return $maxOrder ?? 0;
    }

    /**
     * 임시 업로드 키로 첨부파일 조회
     *
     * 임시 업로드 레코드는 board_id=0으로 저장됩니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $tempKey  임시 업로드 키
     * @param  string|null  $collection  컬렉션 필터 (null이면 전체)
     * @return Collection<int, Attachment>
     */
    public function getByTempKey(string $slug, string $tempKey, ?string $collection = null): Collection
    {
        // 임시 업로드 레코드: board_id=0
        $query = Attachment::query()
            ->where('board_id', 0)
            ->whereNull('post_id')
            ->where('temp_key', $tempKey)
            ->orderBy('order');

        if ($collection !== null) {
            $query->where('collection', $collection);
        }

        return $query->get();
    }

    /**
     * 임시 첨부파일을 게시글에 연결
     *
     * 임시 레코드(board_id=0)를 실제 board_id로 이동합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $tempKey  임시 업로드 키
     * @param  int  $postId  게시글 ID
     * @return int 연결된 첨부파일 수
     */
    public function linkTempAttachments(string $slug, string $tempKey, int $postId): int
    {
        $board = Board::where('slug', $slug)->first();

        // 임시 파일(board_id=0)을 실제 board_id + post_id로 업데이트
        return Attachment::query()
            ->where('board_id', 0)
            ->whereNull('post_id')
            ->where('temp_key', $tempKey)
            ->update([
                'board_id' => $board?->id ?? 0,
                'post_id' => $postId,
                'temp_key' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * 첨부파일 ID 배열로 게시글에 연결
     *
     * 임시 업로드(board_id=0)된 첨부파일을 실제 board_id로 이동하고 게시글에 연결합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array<int>  $ids  첨부파일 ID 배열
     * @param  int  $postId  게시글 ID
     * @return int 연결된 첨부파일 수
     */
    public function linkAttachmentsByIds(string $slug, array $ids, int $postId): int
    {
        if (empty($ids)) {
            return 0;
        }

        $board = Board::where('slug', $slug)->first();

        // 임시 업로드(board_id=0)된 첨부파일을 실제 board_id로 이동하며 게시글에 연결
        return Attachment::query()
            ->where('board_id', 0)
            ->whereIn('id', $ids)
            ->whereNull('post_id')
            ->update([
                'board_id' => $board?->id ?? 0,
                'post_id' => $postId,
                'temp_key' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * 주어진 ID 중 다른 사람이 올린 임시 첨부(아직 글에 이어지지 않은 것)의 ID 를 돌려줍니다.
     *
     * 첨부 번호는 순번이라 추측할 수 있다 — 임시 키처럼 비밀이 아니므로, 번호로 잇는 경로는
     * 올린 사람이 같은지로만 판단한다. 이미 글에 이어진 첨부는 대상이 아니다(수정 화면이 기존
     * 첨부 번호를 함께 보내며, 그 연결은 linkAttachmentsByIds 가 손대지 않는다).
     *
     * @param  array<int>  $ids  첨부파일 ID 배열
     * @param  int|null  $userId  현재 사용자 ID (null 이면 비회원)
     * @return array<int, int> 남이 올린 임시 첨부 ID 목록
     */
    public function findForeignTempAttachmentIds(array $ids, ?int $userId): array
    {
        if (empty($ids)) {
            return [];
        }

        return Attachment::query()
            ->whereIn('id', $ids)
            ->whereNull('post_id')
            ->where(function ($query) use ($userId) {
                if ($userId === null) {
                    $query->whereNotNull('created_by');

                    return;
                }

                $query->whereNull('created_by')->orWhere('created_by', '!=', $userId);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * 게시판 ID 기준으로 첨부파일을 일괄 소프트 삭제합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @return int 삭제된 첨부파일 수
     */
    public function softDeleteByBoardId(int $boardId): int
    {
        return Attachment::where('board_id', $boardId)->delete();
    }

    /**
     * 게시글 ID 기준으로 살아있는 첨부를 cascade 로 일괄 소프트 삭제합니다.
     *
     * 게시글 삭제 연쇄로 지워졌음을 trigger_type='cascade' 로 마킹합니다.
     * 이미 삭제된(trashed) 첨부는 Eloquent 기본 스코프가 제외하므로,
     * 사용자가 먼저 지운 첨부(trigger_type='user')는 영향을 받지 않습니다.
     * 물리 파일은 보존하며 deleted_at 마킹만 수행합니다. (복원 가능성 유지)
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @return int 삭제된 첨부 수
     */
    public function softDeleteByPostId(string $slug, int $postId): int
    {
        $board = Board::where('slug', $slug)->first();

        return Attachment::where('board_id', $board?->id)
            ->where('post_id', $postId)
            ->update([
                'trigger_type' => TriggerType::Cascade->value,
                'deleted_at' => now(),
            ]);
    }

    /**
     * 게시글 ID 기준으로 cascade 로 지워진 첨부만 복원합니다.
     *
     * 게시글 복원 시, 게시글 삭제 연쇄로 지워진(trigger_type='cascade') 첨부만
     * 되살립니다. 사용자가 직접 지운(trigger_type='user') 첨부는 trashed 로 유지됩니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @return int 복원된 첨부 수
     */
    public function restoreCascadedByPostId(string $slug, int $postId): int
    {
        $board = Board::where('slug', $slug)->first();

        return Attachment::onlyTrashed()
            ->where('board_id', $board?->id)
            ->where('post_id', $postId)
            ->where('trigger_type', TriggerType::Cascade->value)
            ->update([
                'deleted_at' => null,
            ]);
    }

    /**
     * 여러 게시글의 살아있는 첨부를 cascade 로 일괄 소프트 삭제합니다.
     *
     * 답글 트리 연쇄 삭제(softDeleteCascadeByParentId)로 지워지는 자손 게시글들의
     * 첨부 정리용입니다. 단건 softDeleteByPostId 와 동일한 쿼리를 whereIn 으로 확장했습니다.
     * 물리 파일은 보존하며 deleted_at 마킹만 수행합니다. (복원 가능성 유지)
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array<int>  $postIds  게시글 ID 배열
     * @return int 삭제된 첨부 수
     */
    public function softDeleteByPostIds(string $slug, array $postIds): int
    {
        if ($postIds === []) {
            return 0;
        }

        $board = Board::where('slug', $slug)->first();

        return Attachment::where('board_id', $board?->id)
            ->whereIn('post_id', $postIds)
            ->update([
                'trigger_type' => TriggerType::Cascade->value,
                'deleted_at' => now(),
            ]);
    }

    /**
     * 여러 게시글의 cascade 로 지워진 첨부만 일괄 복원합니다.
     *
     * 답글 트리 연쇄 복원(restoreCascadedByParentId)으로 되살아난 자손 게시글들의
     * 첨부 복원용입니다. 사용자가 직접 지운 첨부(trigger_type='user')는 유지됩니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array<int>  $postIds  게시글 ID 배열
     * @return int 복원된 첨부 수
     */
    public function restoreCascadedByPostIds(string $slug, array $postIds): int
    {
        if ($postIds === []) {
            return 0;
        }

        $board = Board::where('slug', $slug)->first();

        return Attachment::onlyTrashed()
            ->where('board_id', $board?->id)
            ->whereIn('post_id', $postIds)
            ->where('trigger_type', TriggerType::Cascade->value)
            ->update([
                'deleted_at' => null,
            ]);
    }

    /**
     * 게시판 ID 기준으로 첨부파일을 일괄 영구 삭제합니다.
     *
     * 게시판 영구 삭제(deleteBoard) 시 사용합니다. 소프트 삭제와 달리
     * deleted_at 마킹이 아니라 레코드를 물리적으로 제거합니다.
     * (물리 파일은 BoardService::deleteAttachmentFiles 가 별도로 삭제)
     *
     * @param  int  $boardId  게시판 ID
     * @return int 삭제된 첨부파일 수
     */
    public function forceDeleteByBoardId(int $boardId): int
    {
        return Attachment::where('board_id', $boardId)->forceDelete();
    }

    /**
     * 게시글에 연결되지 않은 채 방치된 임시 첨부를 오래된 순으로 조회합니다.
     *
     * @param  Carbon  $threshold  기준 시각
     * @param  int  $limit  최대 조회 건수
     * @return Collection 임시 첨부 목록
     */
    public function findStaleTempAttachments(Carbon $threshold, int $limit): Collection
    {
        return Attachment::query()
            ->whereNotNull('temp_key')
            ->whereNull('post_id')
            ->where('created_at', '<', $threshold)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'board_id', 'temp_key', 'disk', 'path', 'created_at']);
    }

    /**
     * 소프트 삭제된 지 오래된 첨부를 오래된 순으로 조회합니다.
     *
     * @param  Carbon  $threshold  기준 시각
     * @param  int  $limit  최대 조회 건수
     * @return Collection 소프트 삭제 첨부 목록
     */
    public function findSoftDeletedOlderThan(Carbon $threshold, int $limit): Collection
    {
        return Attachment::onlyTrashed()
            ->where('deleted_at', '<', $threshold)
            ->orderBy('deleted_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'board_id', 'post_id', 'disk', 'path', 'deleted_at']);
    }

    /**
     * 게시글들에 딸린 첨부를 삭제 여부와 무관하게 조회합니다 (개인정보 보유기간 파기용).
     *
     * @param  array<int, int>  $postIds  게시글 ID 목록
     * @return Collection 첨부 목록 (소프트 삭제분 포함)
     */
    public function findWithTrashedByPostIds(array $postIds): Collection
    {
        if ($postIds === []) {
            return new Collection;
        }

        return Attachment::withTrashed()
            ->whereIn('post_id', $postIds)
            ->orderBy('id')
            ->get(['id', 'board_id', 'post_id', 'disk', 'path', 'deleted_at']);
    }

    /**
     * 첨부 레코드를 영구 삭제합니다.
     *
     * @param  Attachment  $attachment  첨부파일 모델
     * @return bool 삭제 성공 여부
     */
    public function forceDelete(Attachment $attachment): bool
    {
        return (bool) $attachment->forceDelete();
    }
}
