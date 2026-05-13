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
}
