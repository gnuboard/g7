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
}
