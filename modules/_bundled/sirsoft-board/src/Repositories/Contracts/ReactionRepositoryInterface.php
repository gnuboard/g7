<?php

namespace Modules\Sirsoft\Board\Repositories\Contracts;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Board\Models\Reaction;

/**
 * 반응 이력 Repository 인터페이스
 */
interface ReactionRepositoryInterface
{
    /**
     * 사용자+대상 기준 기존 반응을 조회합니다 (유일 제약과 동일 키).
     *
     * @param  int  $userId  사용자 ID
     * @param  string  $targetType  대상 타입 (예: post)
     * @param  int  $targetId  대상 ID
     * @return Reaction|null 기존 반응 또는 null
     */
    public function findByUserAndTarget(int $userId, string $targetType, int $targetId): ?Reaction;

    /**
     * 대상 게시글 행을 `lockForUpdate` 로 잠급니다.
     *
     * 짧은 시간에 연속 전송된 반응 요청이 서버에 도착 순서와 다르게 처리되어도
     * (요청 A→B→C 전송, B→A→C 처리 등) `existing` 조회부터 upsert/delete·카운트
     * 재집계까지 동일 게시글에 대한 처리 전체가 이 잠금 하나로 직렬화되도록,
     * 반응 처리 트랜잭션 진입 직후 가장 먼저 호출해야 합니다.
     *
     * @param  int  $postId  게시글 ID
     * @return void
     */
    public function lockPostForReaction(int $postId): void;

    /**
     * 반응을 등록하거나 전환합니다 (없으면 INSERT, 있으면 유형 UPDATE).
     *
     * @param  int  $userId  사용자 ID
     * @param  string  $targetType  대상 타입
     * @param  int  $targetId  대상 ID
     * @param  int  $reactionTypeId  반응 유형 ID
     * @param  int|null  $boardId  게시판 ID
     * @return Reaction 등록/전환된 반응 모델
     */
    public function upsert(int $userId, string $targetType, int $targetId, int $reactionTypeId, ?int $boardId): Reaction;

    /**
     * 반응을 해제합니다 (이력 행 삭제).
     *
     * @param  Reaction  $reaction  삭제할 반응 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(Reaction $reaction): bool;

    /**
     * 게시글의 반응 카운트(JSON)를 board_reactions 실제 행 기준으로 재집계합니다.
     *
     * 반드시 트랜잭션 안에서 호출되어야 하며, 대상 게시글 행을 `lockForUpdate` 로 잠근 뒤
     * 그 잠금 범위 안에서 COUNT 재집계까지 수행해 동시 요청의 처리 순서가 뒤바뀌어도
     * (짧은 시간에 연속 전송된 반응 요청이 도착 순서와 다르게 처리되는 경우) 캐시된
     * 카운트가 실제 반응 행 수와 항상 일치하도록 보장합니다. 델타 누적 방식은 요청
     * 순서 역전 시 캐시가 실제 데이터와 어긋날 수 있어 사용하지 않습니다.
     *
     * @param  int  $postId  게시글 ID
     * @return array<string, int> 갱신 후 reaction_counts (키는 유형 ID 문자열, 값은 실제 COUNT)
     */
    public function recalculatePostReactionCounts(int $postId): array;

    /**
     * 대상 행(게시글·댓글)을 `lockForUpdate` 로 잠급니다.
     *
     * lockPostForReaction() 의 타입 일반화 버전. 삭제된 대상에도 잠금이 걸린다 —
     * 마이페이지·관리자 해제는 소프트 삭제된 대상의 반응도 지우고 카운트를 다시 집계한다.
     *
     * @param  string  $targetType  대상 타입 (post|comment)
     * @param  int  $targetId  대상 ID
     * @return void
     */
    public function lockTargetForReaction(string $targetType, int $targetId): void;

    /**
     * 대상(게시글·댓글)의 반응 카운트(JSON)를 실제 행 기준으로 재집계합니다.
     *
     * recalculatePostReactionCounts() 의 타입 일반화 버전이며 같은 잠금·집계 규약을 따른다.
     *
     * @param  string  $targetType  대상 타입 (post|comment)
     * @param  int  $targetId  대상 ID
     * @return array<string, int> 갱신 후 reaction_counts (키는 유형 ID 문자열)
     */
    public function recalculateReactionCounts(string $targetType, int $targetId): array;

    /**
     * 특정 반응 유형에 남아 있는 반응 건수를 셉니다.
     *
     * 유형 삭제 가능 여부(0건일 때만 삭제) 판정에 쓴다.
     *
     * @param  int  $reactionTypeId  반응 유형 ID
     * @return int 반응 건수
     */
    public function countByReactionType(int $reactionTypeId): int;

    /**
     * 대상 ID 목록에 대한 사용자의 반응 유형 ID 를 한 번에 조회합니다 (N+1 방지).
     *
     * @param  int  $userId  사용자 ID
     * @param  string  $targetType  대상 타입 (post|comment)
     * @param  array<int, int>  $targetIds  대상 ID 목록
     * @return array<int, int> [대상 ID => 반응 유형 ID]
     */
    public function getUserReactionTypeIdsByTargets(int $userId, string $targetType, array $targetIds): array;

    /**
     * 반응 목록의 대상(게시글·댓글)을 타입별로 일괄 조회해 관계에 채웁니다.
     *
     * morph map 없는 폴리모픽 구조라 with('post') 로는 타입 구분이 안 된다 —
     * 목록 경로는 반드시 이 메서드로 채운다. 삭제된 대상도 withTrashed 로 싣는다.
     *
     * @param  Collection<int, Reaction>  $reactions  반응 목록 (Eloquent·Support 둘 다 — 지연 조인 결과는 Support)
     * @return void
     */
    public function hydrateTargets(Collection $reactions): void;

    /**
     * 회원 본인의 반응 목록을 조회합니다 (마이페이지).
     *
     * @param  int  $userId  사용자 ID
     * @param  array<string, mixed>  $filters  필터 (target_type·reaction_type_id·board_slug·search·search_field·sort)
     * @param  int  $perPage  페이지당 건수
     * @return LengthAwarePaginator 반응 목록
     */
    public function paginateByUser(int $userId, array $filters, int $perPage): LengthAwarePaginator;

    /**
     * 관리자 반응 관리 목록을 조회합니다 (전 회원).
     *
     * @param  array<string, mixed>  $filters  필터 (user_id·board_id·reaction_type_id·target_type·기간·검색)
     * @param  int  $perPage  페이지당 건수
     * @return Paginator 반응 목록
     */
    public function paginateForAdmin(array $filters, int $perPage = 20): Paginator;

    /**
     * ID 로 반응 1건을 조회합니다.
     *
     * @param  int  $id  반응 ID
     * @return Reaction|null 반응 또는 null
     */
    public function findById(int $id): ?Reaction;

    /**
     * ID 로 반응을 삭제합니다 (관리자).
     *
     * @param  int  $id  반응 ID
     * @return Reaction|null 삭제된 반응 (없으면 null)
     */
    public function deleteById(int $id): ?Reaction;

    /**
     * ID 목록으로 반응을 일괄 삭제합니다 (관리자).
     *
     * @param  array<int, int>  $ids  반응 ID 목록
     * @return EloquentCollection<int, Reaction> 삭제된 반응 목록
     */
    public function deleteManyByIds(array $ids): EloquentCollection;

    /**
     * 본인 소유 반응 1건을 삭제합니다 (마이페이지).
     *
     * @param  int  $id  반응 ID
     * @param  int  $userId  소유자 ID
     * @return Reaction|null 삭제된 반응 (없거나 남의 것이면 null)
     */
    public function deleteByIdAndUser(int $id, int $userId): ?Reaction;

    /**
     * 본인 소유 반응을 일괄 삭제합니다 (마이페이지).
     *
     * 남의 ID 가 섞여 있으면 조용히 무시한다 (ID 탐색 방지).
     *
     * @param  array<int, int>  $ids  반응 ID 목록
     * @param  int  $userId  소유자 ID
     * @return EloquentCollection<int, Reaction> 실제로 삭제된 반응 목록
     */
    public function deleteManyByIdsAndUser(array $ids, int $userId): EloquentCollection;

    /**
     * 회원의 반응 요약(총계·대상별·최근)을 반환합니다.
     *
     * @param  int  $userId  사용자 ID
     * @return array{count: int, post_count: int, comment_count: int, latest_reacted_at: mixed}
     */
    public function summaryByUser(int $userId): array;
}
