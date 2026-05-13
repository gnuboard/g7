<?php

namespace App\Contracts\Repositories;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Collection;

/**
 * 첨부파일 Repository 인터페이스
 */
interface AttachmentRepositoryInterface
{
    /**
     * ID로 첨부파일 조회
     *
     * @param  int  $id  첨부파일 ID
     * @return Attachment|null 첨부파일 또는 null
     */
    public function findById(int $id): ?Attachment;

    /**
     * 여러 ID로 첨부파일 조회 (order 정렬)
     *
     * @param  array<int>  $ids  첨부파일 ID 배열
     * @return Collection<int, Attachment>
     */
    public function findByIds(array $ids): Collection;

    /**
     * 해시로 첨부파일 조회
     *
     * @param  string  $hash  첨부파일 해시
     * @return Attachment|null 첨부파일 또는 null
     */
    public function findByHash(string $hash): ?Attachment;

    /**
     * 첨부 대상별 첨부파일 조회
     *
     * @param  string  $type  attachmentable_type
     * @param  int  $id  attachmentable_id
     * @param  string|null  $collection  컬렉션 필터 (null이면 전체)
     * @return Collection<int, Attachment>
     */
    public function getByAttachmentable(string $type, int $id, ?string $collection = null): Collection;

    /**
     * 첨부파일 생성
     *
     * @param  array<string, mixed>  $data  생성 데이터
     * @return Attachment 생성된 첨부파일
     */
    public function create(array $data): Attachment;

    /**
     * 첨부파일 업데이트
     *
     * @param  int  $id  첨부파일 ID
     * @param  array<string, mixed>  $data  업데이트 데이터
     * @return Attachment 업데이트된 첨부파일
     */
    public function update(int $id, array $data): Attachment;

    /**
     * 첨부파일 삭제 (soft delete)
     *
     * @param  int  $id  첨부파일 ID
     * @return bool 삭제 성공 여부
     */
    public function delete(int $id): bool;

    /**
     * 첨부파일 영구 삭제
     *
     * @param  int  $id  첨부파일 ID
     * @return bool 삭제 성공 여부
     */
    public function forceDelete(int $id): bool;

    /**
     * 순서 변경
     *
     * @param  array<int, array{id: int, order: int}>  $orderData  순서 데이터
     */
    public function reorder(array $orderData): void;

    /**
     * 특정 소스 식별자의 첨부파일 목록 조회
     *
     * @param  string  $identifier  소스 식별자
     * @return Collection 첨부파일 컬렉션
     */
    public function getBySourceIdentifier(string $identifier): Collection;

    /**
     * 특정 소스 식별자의 첨부파일 일괄 삭제
     *
     * @param  string  $identifier  소스 식별자
     * @return int 삭제된 개수
     */
    public function deleteBySourceIdentifier(string $identifier): int;

    /**
     * 컬렉션 내 최대 order 값 조회
     *
     * @param  string  $type  attachmentable_type
     * @param  int  $id  attachmentable_id
     * @param  string  $collection  컬렉션명
     * @return int 최대 order 값
     */
    public function getMaxOrder(string $type, int $id, string $collection): int;

    /**
     * 컬렉션명으로만 최대 order 값 조회 (attachmentable 없는 경우)
     *
     * @param  string  $collection  컬렉션명
     * @return int 최대 order 값
     */
    public function getMaxOrderByCollection(string $collection): int;

    /**
     * 컬렉션명으로 첨부파일 조회 (attachmentable 없는 경우)
     *
     * @param  string  $collection  컬렉션명
     * @return Collection<int, Attachment>
     */
    public function getByCollection(string $collection): Collection;

    /**
     * 미연결 첨부파일을 특정 엔티티에 연결합니다.
     *
     * @param  string  $collection  컬렉션명
     * @param  string  $attachmentableType  연결할 모델 클래스명
     * @param  int  $attachmentableId  연결할 모델 ID
     * @return int 연결된 첨부파일 수
     */
    public function linkUnattachedFiles(string $collection, string $attachmentableType, int $attachmentableId): int;

    /**
     * 특정 엔티티의 첨부파일 순서를 재정렬합니다.
     * 삭제 등으로 중간에 빈 순서가 생긴 경우 1부터 연속된 순서로 재정렬합니다.
     *
     * @param  string  $attachmentableType  첨부 대상 타입
     * @param  int  $attachmentableId  첨부 대상 ID
     * @param  string  $collection  컬렉션명
     */
    public function reorderAfterDelete(string $attachmentableType, int $attachmentableId, string $collection): void;
}
