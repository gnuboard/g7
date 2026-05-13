<?php

namespace Modules\Sirsoft\Page\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Repositories\Contracts\PageAttachmentRepositoryInterface;

/**
 * 페이지 첨부파일 Repository
 *
 * 페이지 첨부파일 데이터 접근 계층을 담당합니다.
 */
class PageAttachmentRepository implements PageAttachmentRepositoryInterface
{
    /**
     * 첨부파일을 생성합니다.
     *
     * @param  array  $data  첨부파일 생성 데이터
     * @return PageAttachment 생성된 첨부파일 모델
     */
    public function create(array $data): PageAttachment
    {
        return PageAttachment::create($data);
    }

    /**
     * 해시로 첨부파일을 조회합니다.
     *
     * @param  string  $hash  12자리 해시
     * @return PageAttachment|null 첨부파일 모델 또는 null
     */
    public function findByHash(string $hash): ?PageAttachment
    {
        return PageAttachment::where('hash', $hash)->first();
    }

    /**
     * 페이지 ID로 첨부파일 목록을 조회합니다.
     *
     * @param  int  $pageId  페이지 ID
     * @return Collection 첨부파일 목록 (order 순)
     */
    public function getByPageId(int $pageId): Collection
    {
        return PageAttachment::where('page_id', $pageId)
            ->orderBy('order')
            ->get();
    }

    /**
     * temp_key로 임시 첨부파일 목록을 조회합니다.
     *
     * @param  string  $tempKey  임시 키
     * @return Collection 임시 첨부파일 목록
     */
    public function getByTempKey(string $tempKey): Collection
    {
        return PageAttachment::where('temp_key', $tempKey)
            ->whereNull('page_id')
            ->orderBy('order')
            ->get();
    }

    /**
     * temp_key의 첨부파일을 페이지에 연결합니다.
     *
     * @param  string  $tempKey  임시 키
     * @param  int  $pageId  연결할 페이지 ID
     * @return int 연결된 첨부파일 수
     */
    public function attachToPage(string $tempKey, int $pageId): int
    {
        return PageAttachment::where('temp_key', $tempKey)
            ->whereNull('page_id')
            ->update([
                'page_id' => $pageId,
                'temp_key' => null,
            ]);
    }

    /**
     * 페이지의 모든 첨부파일을 삭제합니다 (소프트 삭제).
     *
     * @param  int  $pageId  페이지 ID
     * @return int 삭제된 첨부파일 수
     */
    public function deleteByPageId(int $pageId): int
    {
        return PageAttachment::where('page_id', $pageId)->delete();
    }

    /**
     * ID로 첨부파일을 조회하며, 없으면 예외를 발생시킵니다.
     *
     * @param  int  $id  첨부파일 ID
     * @return PageAttachment 첨부파일 모델
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOrFail(int $id): PageAttachment
    {
        return PageAttachment::findOrFail($id);
    }

    /**
     * 첨부파일을 삭제합니다 (소프트 삭제).
     *
     * @param  PageAttachment  $attachment  첨부파일 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(PageAttachment $attachment): bool
    {
        return (bool) $attachment->delete();
    }

    /**
     * 첨부파일을 수정합니다.
     *
     * @param  PageAttachment  $attachment  첨부파일 모델
     * @param  array  $data  수정할 데이터
     * @return PageAttachment 수정된 첨부파일 모델
     */
    public function update(PageAttachment $attachment, array $data): PageAttachment
    {
        $attachment->fill($data)->save();

        return $attachment->fresh();
    }

    /**
     * 첨부파일 순서를 변경합니다.
     *
     * @param  array<int, int>  $orders  [첨부파일 ID => 순서] 매핑
     * @return bool 변경 성공 여부
     */
    public function reorder(array $orders): bool
    {
        DB::beginTransaction();
        try {
            foreach ($orders as $id => $order) {
                PageAttachment::where('id', $id)->update(['order' => $order]);
            }
            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();

            return false;
        }
    }

    /**
     * 컬렉션의 최대 order 값을 조회합니다.
     *
     * @param  int|null  $pageId  페이지 ID (null이면 임시 첨부파일)
     * @param  string|null  $tempKey  임시 키
     * @return int 최대 order 값
     */
    public function getMaxOrder(?int $pageId = null, ?string $tempKey = null): int
    {
        $query = PageAttachment::query();

        if ($pageId) {
            $query->where('page_id', $pageId);
        } elseif ($tempKey) {
            $query->where('temp_key', $tempKey)->whereNull('page_id');
        }

        return (int) $query->max('order');
    }
}
