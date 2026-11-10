<?php

namespace Modules\Sirsoft\Board\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Post;

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
     * 이 게시판에 올렸지만 아직 글에 이어지지 않은 임시 첨부를 ID 로 조회
     *
     * 임시 첨부는 board_id=0 이라 findById 로는 찾지 못한다. 게시판 임시 경로(`{slug}/temp/…`)의
     * 것만 돌려주므로 다른 게시판 주소로는 찾을 수 없다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  첨부파일 ID
     * @return Attachment|null 임시 첨부 또는 null
     */
    public function findTempById(string $slug, int $id): ?Attachment;

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
     * 첨부파일이 속한 게시글을 게시판 스코프로 조회합니다(비밀 게이팅용).
     *
     * 비밀글 첨부 서빙 시 부모 게시글의 소유자/비밀 여부를 판정하기 위해 사용합니다.
     * 게시판을 찾을 수 없거나 게시글이 없으면 null 을 반환합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @return Post|null 게시글 모델 또는 null
     */
    public function findPostForGate(string $slug, int $postId): ?Post;

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
     * 주어진 ID 중 이 요청이 이 게시판 글에 이을 수 있는 임시 첨부의 ID 를 돌려줍니다.
     *
     * 회원은 본인 업로드, 비회원은 비회원 업로드이면서 임시 키가 같은 것만. 다른 게시판 임시 첨부는 제외.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array<int>  $ids  첨부파일 ID 배열
     * @param  int|null  $userId  현재 사용자 ID (null 이면 비회원)
     * @param  string|null  $tempKey  요청의 임시 업로드 키
     * @param  string|null  $guestOwnerHash  요청의 비회원 소유 쿠키 해시 (회원의 로그인 전 업로드 연결)
     * @return array<int, int> 이을 수 있는 임시 첨부 ID 목록
     */
    public function findLinkableTempAttachmentIds(string $slug, array $ids, ?int $userId, ?string $tempKey, ?string $guestOwnerHash = null): array;

    /**
     * 게시글 첨부 중 개수 상한에 세는 것의 수 (예약 컬렉션 제외).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @return int 첨부 수
     */
    public function countForLimitByPost(string $slug, int $postId): int;

    /**
     * 임시 키의 임시 첨부 중 개수 상한에 세는 것의 수 (이 게시판 임시 경로, 예약 컬렉션 제외).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $tempKey  임시 업로드 키
     * @return int 첨부 수
     */
    public function countForLimitByTempKey(string $slug, string $tempKey): int;

    /**
     * 주어진 ID 중 다른 사람이 올린 임시 첨부(아직 글에 이어지지 않은 것)의 ID 를 돌려줍니다.
     *
     * @param  array<int>  $ids  첨부파일 ID 배열
     * @param  int|null  $userId  현재 사용자 ID (null 이면 비회원 — 회원이 올린 임시 첨부가 남의 것)
     * @return array<int, int> 남이 올린 임시 첨부 ID 목록
     *
     * @deprecated 7.1.5 findLinkableTempAttachmentIds() 를 쓴다(#883).
     */
    public function findForeignTempAttachmentIds(array $ids, ?int $userId): array;

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
     * 여러 게시글의 살아있는 첨부를 cascade 로 일괄 소프트 삭제합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array<int>  $postIds  게시글 ID 배열
     * @return int 삭제된 첨부 수
     */
    public function softDeleteByPostIds(string $slug, array $postIds): int;

    /**
     * 여러 게시글의 cascade 로 지워진 첨부만 일괄 복원합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array<int>  $postIds  게시글 ID 배열
     * @return int 복원된 첨부 수
     */
    public function restoreCascadedByPostIds(string $slug, array $postIds): int;

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

    /**
     * 게시글에 연결되지 않은 채 방치된 임시 첨부를 오래된 순으로 조회합니다.
     *
     * 글쓰기 폼에서 업로드만 하고 저장 없이 이탈하면 `temp_key` 가 남은 채
     * `board_id = 0` 인 행과 그 파일이 영구 잔존합니다. 그 회수 대상을 찾습니다.
     *
     * @param  Carbon  $threshold  기준 시각 (이 시각 이전 업로드가 대상)
     * @param  int  $limit  최대 조회 건수
     * @return Collection 임시 첨부 목록 (created_at 오름차순)
     */
    public function findStaleTempAttachments(Carbon $threshold, int $limit): Collection;

    /**
     * 소프트 삭제된 지 오래된 첨부를 오래된 순으로 조회합니다.
     *
     * 첨부 삭제는 소프트 삭제만 수행하므로(휴지통 복원 대비) 물리 파일은 남아 있습니다.
     * 보존기간이 지난 뒤 파일과 기록을 함께 파기할 대상을 찾습니다.
     *
     * @param  Carbon  $threshold  기준 시각 (이 시각 이전 삭제분이 대상)
     * @param  int  $limit  최대 조회 건수
     * @return Collection 소프트 삭제 첨부 목록 (deleted_at 오름차순)
     */
    public function findSoftDeletedOlderThan(Carbon $threshold, int $limit): Collection;

    /**
     * 게시글들에 딸린 첨부를 삭제 여부와 무관하게 조회합니다 (개인정보 보유기간 파기용).
     *
     * @param  array<int, int>  $postIds  게시글 ID 목록
     * @return Collection 첨부 목록 (소프트 삭제분 포함)
     */
    public function findWithTrashedByPostIds(array $postIds): Collection;

    /**
     * 첨부 레코드를 영구 삭제합니다.
     *
     * @param  Attachment  $attachment  첨부파일 모델
     * @return bool 삭제 성공 여부
     */
    public function forceDelete(Attachment $attachment): bool;

    /**
     * 임시 키로 올린, 아직 글에 이어지지 않은 이 게시판 본문 미디어 수 (#883)
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $tempKey  임시 업로드 키
     * @return int 개수
     */
    public function countTempContentMedia(string $slug, string $tempKey): int;

    /**
     * 임시 키로 올린 이 게시판 임시 본문 미디어 중 해시가 맞는 것 (#883)
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $tempKey  임시 업로드 키
     * @param  array<int, string>  $hashes  본문이 참조한 해시
     * @return Collection<int, Attachment> 임시 본문 미디어
     */
    public function getTempContentMediaByHashes(string $slug, string $tempKey, array $hashes): Collection;

    /**
     * 글에 이어진 본문 미디어 (#883)
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $postId  게시글 ID
     * @return Collection<int, Attachment> 본문 미디어
     */
    public function getContentMediaByPost(int $boardId, int $postId): Collection;

    /**
     * 해시로 본문 미디어를 찾습니다 — 이 게시판 글에 이어진 것 또는 이 게시판의 임시 본문 미디어 (#883)
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $hash  해시
     * @return Attachment|null 본문 미디어
     */
    public function findContentMediaByHash(string $slug, string $hash): ?Attachment;

    /**
     * 임시 본문 미디어를 글에 잇습니다 — DB 갱신뿐(파일은 처음부터 최종 경로) (#883)
     *
     * @param  Attachment  $attachment  임시 본문 미디어
     * @param  int  $boardId  게시판 ID
     * @param  int  $postId  게시글 ID
     * @param  int  $order  본문 안 순서
     * @return void 반환값 없음
     */
    public function linkContentMedia(Attachment $attachment, int $boardId, int $postId, int $order): void;

    /**
     * 본문에서 빠진 본문 미디어를 소프트 삭제합니다 (#883)
     *
     * @param  Attachment  $attachment  본문 미디어
     * @param  string  $triggerType  삭제 주체 (`TriggerType` 값)
     * @return void 반환값 없음
     */
    public function softDeleteContentMedia(Attachment $attachment, string $triggerType): void;

    /**
     * 해시로 본문 미디어를 모든 게시판에서 찾습니다 — 글에 이어진 것 또는 임시 본문 미디어, 소프트 삭제 제외 (#887 복제 원본)
     *
     * @param  string  $hash  해시
     * @return Attachment|null 본문 미디어
     */
    public function findContentMediaByHashInAnyBoard(string $hash): ?Attachment;

    /**
     * 정리할 본문 미디어 — 기준 시각 전에 올리고 끝내 잇지 않은 임시 행 + 기준 시각 전에 소프트 삭제된 행 (#887)
     *
     * @param  Carbon  $threshold  기준 시각
     * @param  int  $limit  종류마다 최대 건수
     * @return Collection<int, Attachment> 본문 미디어 (소프트 삭제분 포함)
     */
    public function findStaleContentMedia(Carbon $threshold, int $limit): Collection;

    /**
     * ID 로 본문 미디어 행 전체 열을 다시 읽습니다 — 소프트 삭제분 포함 (#887 영구 삭제 훅 인자)
     *
     * @param  array<int, int>  $ids  첨부 ID
     * @return Collection<int, Attachment> 본문 미디어 (본문 미디어가 아닌 ID 는 빠진다)
     */
    public function findContentMediaWithTrashedByIds(array $ids): Collection;

    /**
     * 게시판에 속한 본문 미디어 행을 ID 순 묶음으로 돌며 콜백을 부릅니다 — 소프트 삭제분 포함 (#887 게시판 영구 삭제)
     *
     * ID 기준 묶음(chunkById)이라 콜백 안에서 행을 지워도 건너뛰지 않는다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $size  묶음 크기
     * @param  callable(Collection<int, Attachment>): mixed  $callback  묶음 콜백
     * @return void 반환값 없음
     */
    public function chunkContentMediaWithTrashedByBoardId(int $boardId, int $size, callable $callback): void;

    /**
     * 게시글들에 딸린 본문 미디어 행 — 소프트 삭제분 포함 (#887 글 영구 삭제)
     *
     * @param  array<int, int>  $postIds  게시글 ID 목록
     * @return Collection<int, Attachment> 본문 미디어
     */
    public function findContentMediaWithTrashedByPostIds(array $postIds): Collection;
}
