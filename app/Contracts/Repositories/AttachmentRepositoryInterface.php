<?php

namespace App\Contracts\Repositories;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

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
     * 소프트삭제된 행까지 포함해 ID로 첨부파일 조회
     *
     * 영구 삭제(파일+forceDelete) 경로 전용이다. 소프트삭제된 행은 기본 조회에서 빠지므로,
     * 이 메서드 없이 영구 삭제를 시도하면 대상을 찾지 못해 매 회차 실패만 누적된다.
     *
     * @param  int  $id  첨부파일 ID
     * @return Attachment|null 첨부파일 또는 null
     */
    public function findByIdWithTrashed(int $id): ?Attachment;

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
     * 첨부 대상 여럿의 지정한 컬렉션 첨부파일을 한 번에 조회합니다.
     *
     * @param  string  $type  attachmentable_type
     * @param  array<int, int>  $ids  attachmentable_id 목록
     * @param  array<int, string>  $collections  컬렉션 이름 목록
     * @return Collection<int, Attachment> 첨부 목록 (대상, 컬렉션, 순서 순)
     *
     * @since 7.2.0
     */
    public function getByAttachmentablesAndCollections(string $type, array $ids, array $collections): Collection;

    /**
     * 한 종류의 첨부 대상 전체에서 지정한 컬렉션에 첨부를 가진 대상 수를 셉니다 (항목 삭제 창의 「값 N건」 — DB 집계).
     *
     * @param  string  $type  attachmentable_type
     * @param  string  $collection  컬렉션 이름
     * @return int 첨부를 가진 대상(attachmentable_id) 수
     *
     * @since 7.2.0
     */
    public function countAttachmentablesByTypeAndCollection(string $type, string $collection): int;

    /**
     * 한 종류의 첨부 대상 전체에서 지정한 컬렉션의 첨부 ID 만 조회합니다 (항목 삭제 때 지울 대상 — 모델을 올리지 않는다).
     *
     * @param  string  $type  attachmentable_type
     * @param  string  $collection  컬렉션 이름
     * @return array<int, int> 첨부 ID 목록 (오름차순)
     *
     * @since 7.2.0
     */
    public function idsByAttachmentableTypeAndCollection(string $type, string $collection): array;

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
     * 아직 어디에도 묶이지 않은 첨부만 주어진 엔티티에 묶습니다 (조건부 갱신 한 번 — 동시 요청 중 한쪽만 이긴다).
     *
     * @param  int  $id  첨부파일 ID
     * @param  string  $attachmentableType  연결할 모델 클래스명
     * @param  int  $attachmentableId  연결할 모델 ID
     * @return bool 이번 호출이 묶었으면 true, 이미 묶여 있었으면 false
     */
    public function bindIfUnbound(int $id, string $attachmentableType, int $attachmentableId): bool;

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
     * @return void 반환값 없음
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
     * 컬렉션명 접두사로 첨부파일 조회 (attachmentable 없는 경우)
     *
     * 템플릿 삭제 시 그 템플릿 설정 첨부(`template_settings.{템플릿}.*`)를 한 번에 찾는 데 쓴다.
     *
     * @param  string  $prefix  컬렉션명 접두사 (LIKE 와일드카드는 이스케이프된다)
     * @return Collection<int, Attachment>
     */
    public function getByCollectionPrefix(string $prefix): Collection;

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
     * @return void 반환값 없음
     */
    public function reorderAfterDelete(string $attachmentableType, int $attachmentableId, string $collection): void;

    /**
     * 소유자 없이 방치된 고아 첨부 후보를 오래된 순으로 조회합니다.
     *
     * 폼 저장 전에 즉시 업로드되는 첨부는 소유자(attachmentable) 없이 먼저 생성되므로,
     * 폼을 저장하지 않고 이탈하면 그대로 남습니다. 그 회수 대상을 찾습니다.
     *
     * 확장이 소유한 첨부(source_identifier 보유)는 그 확장의 라이프사이클 소관이므로 제외하고,
     * 현역으로 쓰이는 첨부 ID 는 호출자가 넘겨 보호합니다.
     *
     * @param  Carbon  $threshold  기준 시각 (이 시각 이전 업로드가 대상)
     * @param  int  $limit  최대 조회 건수
     * @param  array<int, int>  $protectedIds  보호할 첨부 ID 목록 (현역 사이트 로고 등)
     * @return Collection 고아 첨부 후보 (created_at 오름차순, 소프트 삭제분 포함)
     */
    public function findOrphanCandidates(Carbon $threshold, int $limit, array $protectedIds = []): Collection;

    /**
     * 래스터 이미지 MIME 행 수 (썸네일 미리 만들기 진행률)
     *
     * @param  list<string>  $mimeTypes  MIME 목록
     * @return int 행 수
     */
    public function countByMimeTypes(array $mimeTypes): int;

    /**
     * 래스터 이미지 MIME 행을 id 순으로 나눠 조회합니다 (썸네일 미리 만들기 — 커서는 마지막 id).
     *
     * @param  list<string>  $mimeTypes  MIME 목록
     * @param  int  $afterId  이 id 보다 큰 행부터
     * @param  int  $limit  최대 행 수
     * @return Collection<int, Attachment> 행 (id·disk·path·mime_type 등)
     */
    public function chunkByMimeTypes(array $mimeTypes, int $afterId, int $limit): Collection;
}
