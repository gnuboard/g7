<?php

namespace Modules\Sirsoft\Board\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Board\Models\Attachment;

/**
 * 게시판 첨부파일 Repository 인터페이스
 */
interface AttachmentRepositoryInterface
{
    /**
     * ID로 첨부파일 조회
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  첨부파일 ID
     * @return Attachment|null 첨부파일 또는 null
     */
    public function findById(string $slug, int $id): ?Attachment;

    /**
     * 해시로 첨부파일 조회
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $hash  첨부파일 해시
     * @return Attachment|null 첨부파일 또는 null
     */
    public function findByHash(string $slug, string $hash): ?Attachment;

    /**
     * 첨부파일이 속한 게시글이 삭제(soft delete) 상태인지 확인합니다.
     *
     * 게시글이 없거나(임시 업로드 등) 게시판을 찾을 수 없으면 false 를 반환합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @return bool 소속 게시글이 삭제 상태인지 여부
     */
    public function isPostDeleted(string $slug, int $postId): bool;

    /**
     * 여러 ID로 첨부파일 조회 (order 정렬)
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array<int>  $ids  첨부파일 ID 배열
     * @return Collection<int, Attachment>
     */
    public function findByIds(string $slug, array $ids): Collection;

    /**
     * 게시글별 첨부파일 조회
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @param  string|null  $collection  컬렉션 필터 (null이면 전체)
     * @return Collection<int, Attachment>
     */
    public function getByPost(string $slug, int $postId, ?string $collection = null): Collection;

    /**
     * 첨부파일 생성
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array<string, mixed>  $data  생성 데이터
     * @return Attachment 생성된 첨부파일
     */
    public function create(string $slug, array $data): Attachment;

    /**
     * 첨부파일 업데이트
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  첨부파일 ID
     * @param  array<string, mixed>  $data  업데이트 데이터
     * @return Attachment 업데이트된 첨부파일
     */
    public function update(string $slug, int $id, array $data): Attachment;

    /**
     * 첨부파일 삭제
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  첨부파일 ID
     * @return bool 삭제 성공 여부
     */
    public function delete(string $slug, int $id): bool;

    /**
     * 첨부파일 순서 재정렬
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array<int, int>  $orders  첨부파일 ID => order 매핑
     * @return bool 성공 여부
     */
    public function reorder(string $slug, array $orders): bool;

    /**
     * 현재 컬렉션의 최대 order 조회
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @param  string  $collection  컬렉션명
     * @return int 최대 order 값
     */
    public function getMaxOrder(string $slug, int $postId, string $collection): int;

    /**
     * 임시 업로드의 최대 order 조회
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string|null  $tempKey  임시 업로드 키
     * @param  string  $collection  컬렉션명
     * @return int 최대 order 값
     */
    public function getMaxOrderByTempKey(string $slug, ?string $tempKey, string $collection): int;

    /**
     * 임시 업로드 키로 첨부파일 조회
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $tempKey  임시 업로드 키
     * @param  string|null  $collection  컬렉션 필터 (null이면 전체)
     * @return Collection<int, Attachment>
     */
    public function getByTempKey(string $slug, string $tempKey, ?string $collection = null): Collection;

    /**
     * 임시 첨부파일을 게시글에 연결
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $tempKey  임시 업로드 키
     * @param  int  $postId  게시글 ID
     * @return int 연결된 첨부파일 수
     */
    public function linkTempAttachments(string $slug, string $tempKey, int $postId): int;

    /**
     * 첨부파일 ID 배열로 게시글에 연결
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array<int>  $ids  첨부파일 ID 배열
     * @param  int  $postId  게시글 ID
     * @return int 연결된 첨부파일 수
     */
    public function linkAttachmentsByIds(string $slug, array $ids, int $postId): int;

    /**
     * 게시판 ID 기준으로 첨부파일을 일괄 소프트 삭제합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @return int 삭제된 첨부파일 수
     */
    public function softDeleteByBoardId(int $boardId): int;

    /**
     * 게시글 ID 기준으로 살아있는 첨부를 cascade 로 일괄 소프트 삭제합니다.
     *
     * 게시글 삭제 연쇄로 지워졌음을 trigger_type='cascade' 로 마킹합니다.
     * 이미 삭제된 첨부(사용자 직접 삭제 등)는 영향을 받지 않습니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @return int 삭제된 첨부 수
     */
    public function softDeleteByPostId(string $slug, int $postId): int;

    /**
     * 게시글 ID 기준으로 cascade 로 지워진 첨부만 복원합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @return int 복원된 첨부 수
     */
    public function restoreCascadedByPostId(string $slug, int $postId): int;

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
    public function forceDeleteByBoardId(int $boardId): int;
}
