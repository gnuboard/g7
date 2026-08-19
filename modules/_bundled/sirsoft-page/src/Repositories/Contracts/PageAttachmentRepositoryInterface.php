<?php

namespace Modules\Sirsoft\Page\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Modules\Sirsoft\Page\Models\PageAttachment;

/**
 * 페이지 첨부파일 Repository 인터페이스
 */
interface PageAttachmentRepositoryInterface
{
    /**
     * 첨부파일을 생성합니다.
     *
     * @param  array  $data  첨부파일 생성 데이터
     * @return PageAttachment 생성된 첨부파일 모델
     */
    public function create(array $data): PageAttachment;

    /**
     * 해시로 첨부파일을 조회합니다.
     *
     * @param  string  $hash  12자리 해시
     * @return PageAttachment|null 첨부파일 모델 또는 null
     */
    public function findByHash(string $hash): ?PageAttachment;

    /**
     * 페이지 ID로 첨부파일 목록을 조회합니다.
     *
     * @param  int  $pageId  페이지 ID
     * @return Collection 첨부파일 목록 (order 순)
     */
    public function getByPageId(int $pageId): Collection;

    /**
     * temp_key로 임시 첨부파일 목록을 조회합니다.
     *
     * @param  string  $tempKey  임시 키
     * @return Collection 임시 첨부파일 목록
     */
    public function getByTempKey(string $tempKey): Collection;

    /**
     * temp_key의 첨부파일을 페이지에 연결합니다.
     *
     * @param  string  $tempKey  임시 키
     * @param  int  $pageId  연결할 페이지 ID
     * @return int 연결된 첨부파일 수
     */
    public function attachToPage(string $tempKey, int $pageId): int;

    /**
     * 페이지의 모든 첨부파일을 삭제합니다 (소프트 삭제).
     *
     * @param  int  $pageId  페이지 ID
     * @return int 삭제된 첨부파일 수
     */
    public function deleteByPageId(int $pageId): int;

    /**
     * ID로 첨부파일을 조회하며, 없으면 예외를 발생시킵니다.
     *
     * @param  int  $id  첨부파일 ID
     * @return PageAttachment 첨부파일 모델
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): PageAttachment;

    /**
     * 첨부파일을 삭제합니다 (소프트 삭제).
     *
     * @param  PageAttachment  $attachment  첨부파일 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(PageAttachment $attachment): bool;

    /**
     * 페이지에 연결되지 않은 채 방치된 임시 첨부를 오래된 순으로 조회합니다.
     *
     * 페이지 작성 폼에서 업로드만 하고 저장 없이 이탈하면 `temp_key` 가 남은 채
     * `page_id` 가 비어 있는 행과 그 파일이 영구 잔존합니다. 그 회수 대상을 찾습니다.
     *
     * @param  Carbon  $threshold  기준 시각 (이 시각 이전 업로드가 대상)
     * @param  int  $limit  최대 조회 건수
     * @return Collection<int, PageAttachment> 임시 첨부 목록 (created_at 오름차순)
     */
    public function findStaleTempAttachments(Carbon $threshold, int $limit): Collection;

    /**
     * 첨부파일을 수정합니다.
     *
     * @param  PageAttachment  $attachment  첨부파일 모델
     * @param  array  $data  수정할 데이터
     * @return PageAttachment 수정된 첨부파일 모델
     */
    public function update(PageAttachment $attachment, array $data): PageAttachment;

    /**
     * 첨부파일 순서를 변경합니다.
     *
     * @param  array<int, int>  $orders  [첨부파일 ID => 순서] 매핑
     * @return bool 변경 성공 여부
     */
    public function reorder(array $orders): bool;

    /**
     * 컬렉션의 최대 order 값을 조회합니다.
     *
     * @param  int|null  $pageId  페이지 ID (null이면 임시 첨부파일)
     * @param  string|null  $tempKey  임시 키
     * @return int 최대 order 값
     */
    public function getMaxOrder(?int $pageId = null, ?string $tempKey = null): int;
}
