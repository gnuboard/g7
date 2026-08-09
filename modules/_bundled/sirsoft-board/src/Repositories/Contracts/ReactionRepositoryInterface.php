<?php

namespace Modules\Sirsoft\Board\Repositories\Contracts;

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
}
