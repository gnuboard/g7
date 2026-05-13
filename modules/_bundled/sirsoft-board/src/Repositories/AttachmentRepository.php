<?php

namespace Modules\Sirsoft\Board\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
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
     * 여러 ID로 첨부파일 조회 (order 정렬)
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array<int>  $ids  첨부파일 ID 배열
     * @return Collection<int, Attachment>
     */
    public function findByIds(string $slug, array $ids): Collection
    {
        if (empty($ids)) {
            return collect();
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

        $attachment = Attachment::query()
            ->where('board_id', $board?->id)
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
                Attachment::query()
                    ->where('board_id', $board?->id)
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
     * 게시판 ID 기준으로 첨부파일을 일괄 소프트 삭제합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @return int 삭제된 첨부파일 수
     */
    public function softDeleteByBoardId(int $boardId): int
    {
        return Attachment::where('board_id', $boardId)->delete();
    }
}
