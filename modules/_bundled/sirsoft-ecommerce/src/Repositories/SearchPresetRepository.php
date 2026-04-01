<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\SearchPreset;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\SearchPresetRepositoryInterface;

/**
 * 검색 프리셋 Repository 구현체
 */
class SearchPresetRepository implements SearchPresetRepositoryInterface
{
    public function __construct(
        protected SearchPreset $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getByUserAndScreen(int $userId, string $targetScreen): Collection
    {
        return $this->model
            ->where('user_id', $userId)
            ->where('target_screen', $targetScreen)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findByName(int $userId, string $targetScreen, string $name): ?SearchPreset
    {
        return $this->model
            ->where('user_id', $userId)
            ->where('target_screen', $targetScreen)
            ->where('preset_name', $name)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): SearchPreset
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(SearchPreset $preset, array $data): SearchPreset
    {
        $preset->update($data);

        return $preset->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(SearchPreset $preset): bool
    {
        return $preset->delete();
    }
}
