<?php

namespace App\Repositories;

use App\Contracts\Repositories\FieldValueRepositoryInterface;
use App\Models\FieldDefinition;
use App\Models\FieldValue;
use Illuminate\Support\Collection;

/**
 * field_values Repository 구현체.
 *
 * @since 7.2.0
 */
class FieldValueRepository implements FieldValueRepositoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function forOwners(string $ownerType, array $ownerIds, array $definitionIds = []): Collection
    {
        if ($ownerIds === []) {
            return new Collection;
        }

        return FieldValue::query()
            ->where('owner_type', $ownerType)
            ->whereIn('owner_id', $ownerIds)
            ->when($definitionIds !== [], fn ($query) => $query->whereIn('field_definition_id', $definitionIds))
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function upsert(int $definitionId, string $ownerType, int $ownerId, array $columns): FieldValue
    {
        // 값 칸은 유형마다 일부만 채운다 — 채우지 않은 칸은 null 로 되돌려 앞선 값이 남지 않게 한다.
        $defaults = array_fill_keys([...FieldValue::VALUE_COLUMNS, 'unique_key'], null);

        return FieldValue::query()->updateOrCreate(
            ['field_definition_id' => $definitionId, 'owner_type' => $ownerType, 'owner_id' => $ownerId],
            array_merge($defaults, array_intersect_key($columns, $defaults)),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function deleteOne(int $definitionId, string $ownerType, int $ownerId): int
    {
        return FieldValue::query()
            ->where('field_definition_id', $definitionId)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteForOwner(string $ownerType, int $ownerId): int
    {
        return FieldValue::query()->where('owner_type', $ownerType)->where('owner_id', $ownerId)->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteForOwners(string $ownerType, array $ownerIds): int
    {
        if ($ownerIds === []) {
            return 0;
        }

        return FieldValue::query()->where('owner_type', $ownerType)->whereIn('owner_id', $ownerIds)->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteForDefinition(int $definitionId): int
    {
        return FieldValue::query()->where('field_definition_id', $definitionId)->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function countForDefinition(int $definitionId): int
    {
        return FieldValue::query()->where('field_definition_id', $definitionId)->count();
    }

    /**
     * {@inheritDoc}
     */
    public function countByIndex(int $definitionId): array
    {
        return FieldValue::query()
            ->where('field_definition_id', $definitionId)
            ->whereNotNull('value_index')
            ->groupBy('value_index')
            ->selectRaw('value_index, COUNT(*) as total')
            ->pluck('total', 'value_index')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * {@inheritDoc}
     */
    public function uniqueKeyExists(int $definitionId, string $uniqueKey, ?string $exceptOwnerType = null, ?int $exceptOwnerId = null): bool
    {
        return FieldValue::query()
            ->where('field_definition_id', $definitionId)
            ->where('unique_key', $uniqueKey)
            ->when($exceptOwnerType !== null && $exceptOwnerId !== null, fn ($query) => $query->where(
                fn ($inner) => $inner->where('owner_type', '!=', $exceptOwnerType)->orWhere('owner_id', '!=', $exceptOwnerId)
            ))
            ->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteByIndex(int $definitionId, array $indexValues): int
    {
        if ($indexValues === []) {
            return 0;
        }

        return FieldValue::query()
            ->where('field_definition_id', $definitionId)
            ->whereIn('value_index', $indexValues)
            ->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function chunkForDefinition(int $definitionId, callable $callback, int $chunk = 500): void
    {
        FieldValue::query()
            ->where('field_definition_id', $definitionId)
            ->chunkById($chunk, $callback);
    }

    /**
     * {@inheritDoc}
     */
    public function countOrphans(): int
    {
        return FieldValue::query()
            ->whereNotIn('field_definition_id', FieldDefinition::query()->select('id'))
            ->count();
    }
}
