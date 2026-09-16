<?php

namespace Modules\Sirsoft\Board\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Board\Models\ReactionType;

/**
 * 반응 유형 Repository 인터페이스
 */
interface ReactionTypeRepositoryInterface
{
    /**
     * 활성 반응 유형 전체를 display_order 순으로 조회합니다.
     *
     * @return Collection<int, ReactionType>
     */
    public function getActive(): Collection;

    /**
     * 전 반응 유형(활성+비활성)을 display_order 순으로 조회합니다.
     *
     * 관리자 편집기·필터 셀렉트가 비활성 유형까지 봐야 하므로 getActive() 와 나눈다.
     * 아이콘 첨부를 함께 싣고 유형별 반응 수(reactions_count)를 집계한다 —
     * 삭제 가능 여부(0건일 때만 삭제) 판정이 이 값에 걸린다.
     *
     * @return Collection<int, ReactionType>
     */
    public function all(): Collection;

    /**
     * code 로 반응 유형 1건을 조회합니다 (비활성 포함).
     *
     * @param  string  $code  유형 code
     * @return ReactionType|null 유형 모델 또는 null
     */
    public function findByCode(string $code): ?ReactionType;

    /**
     * code 목록으로 활성 반응 유형을 조회합니다 (display_order 순).
     *
     * @param  array<int, string>  $codes  유형 code 목록
     * @return Collection<int, ReactionType>
     */
    public function findByCodes(array $codes): Collection;

    /**
     * ID로 반응 유형을 조회합니다.
     *
     * @param  int  $id  유형 ID
     * @return ReactionType|null 유형 모델 또는 null
     */
    public function findById(int $id): ?ReactionType;

    /**
     * ID 목록으로 반응 유형을 한 번에 조회합니다 (N+1 방지).
     *
     * @param  array<int, int>  $ids  유형 ID 목록
     * @return Collection<int, ReactionType>
     */
    public function findByIds(array $ids): Collection;

    /**
     * 반응 유형을 생성합니다.
     *
     * @param  array<string, mixed>  $data  유형 속성
     * @return ReactionType 생성된 유형
     */
    public function create(array $data): ReactionType;

    /**
     * 반응 유형을 수정합니다.
     *
     * @param  ReactionType  $type  대상 유형
     * @param  array<string, mixed>  $data  변경할 속성
     * @return ReactionType 수정된 유형
     */
    public function update(ReactionType $type, array $data): ReactionType;

    /**
     * 반응 유형을 삭제합니다.
     *
     * 참조하는 반응이 있으면 FK(restrictOnDelete)가 막는다 — 호출측이 먼저 0건을 확인한다.
     *
     * @param  ReactionType  $type  대상 유형
     * @return bool 삭제 여부
     */
    public function delete(ReactionType $type): bool;

    /**
     * 특정 첨부를 아이콘으로 쓰는 유형들의 참조를 끊습니다.
     *
     * @param  int  $attachmentId  첨부 ID
     * @return int 갱신된 행 수
     */
    public function clearIconAttachment(int $attachmentId): int;
}
