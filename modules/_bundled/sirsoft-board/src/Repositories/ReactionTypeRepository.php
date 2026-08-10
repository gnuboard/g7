<?php

namespace Modules\Sirsoft\Board\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionTypeRepositoryInterface;

/**
 * 반응 유형 Repository
 *
 * 반응 유형 데이터 접근 계층을 담당합니다.
 */
class ReactionTypeRepository implements ReactionTypeRepositoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function getActive(): Collection
    {
        return ReactionType::query()
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findByCodes(array $codes): Collection
    {
        if (empty($codes)) {
            return ReactionType::query()->whereRaw('1 = 0')->get();
        }

        return ReactionType::query()
            ->where('is_active', true)
            ->whereIn('code', $codes)
            ->orderBy('display_order')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id): ?ReactionType
    {
        return ReactionType::query()->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findByIds(array $ids): Collection
    {
        if (empty($ids)) {
            return ReactionType::query()->whereIn('id', [])->get();
        }

        return ReactionType::query()
            ->whereIn('id', $ids)
            ->orderBy('display_order')
            ->get();
    }
}
