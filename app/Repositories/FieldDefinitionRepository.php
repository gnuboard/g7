<?php

namespace App\Repositories;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Enums\FieldSourceType;
use App\Models\FieldDefinition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * field_definitions Repository 구현체.
 *
 * 항목 목록은 요청 하나 안에서 여러 번(규칙 생성, 저장, 응답) 읽히므로 집합 단위로 메모리에 담아 둔다.
 * 쓰기 메서드는 모두 그 메모를 비운다.
 *
 * @since 7.2.0
 */
class FieldDefinitionRepository implements FieldDefinitionRepositoryInterface
{
    /**
     * 요청 안 메모 — 집합 식별자 => 항목 목록.
     *
     * @var array<string, Collection<int, FieldDefinition>>
     */
    protected array $memo = [];

    /** 테이블 존재 여부 메모 */
    protected ?bool $tableExists = null;

    /**
     * {@inheritDoc}
     */
    public function tableExists(): bool
    {
        return $this->tableExists ??= Schema::hasTable('field_definitions');
    }

    /**
     * {@inheritDoc}
     */
    public function allForSet(string $fieldSet): Collection
    {
        if (! $this->tableExists()) {
            return new Collection;
        }

        return $this->memo[$fieldSet] ??= FieldDefinition::query()
            ->where('field_set', $fieldSet)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id): ?FieldDefinition
    {
        return FieldDefinition::query()->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findByKey(string $fieldSet, string $key): ?FieldDefinition
    {
        return FieldDefinition::query()->where('field_set', $fieldSet)->where('key', $key)->first();
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $attributes): FieldDefinition
    {
        // 보내지 않은 속성은 DB 기본값(사용 켬, 본인 수정 범위 등)으로 저장된다 — 그 값까지 담아 돌려준다.
        $definition = FieldDefinition::query()->create($attributes)->refresh();
        $this->forgetMemo();

        return $definition;
    }

    /**
     * {@inheritDoc}
     */
    public function update(FieldDefinition $definition, array $attributes): bool
    {
        $saved = $definition->update($attributes);
        $this->forgetMemo();

        return $saved;
    }

    /**
     * {@inheritDoc}
     */
    public function delete(FieldDefinition $definition): bool
    {
        $deleted = (bool) $definition->delete();
        $this->forgetMemo();

        return $deleted;
    }

    /**
     * {@inheritDoc}
     */
    public function syncDeclared(string $fieldSet, string $key, array $attributes): FieldDefinition
    {
        $definition = FieldDefinition::syncOrCreateFromUpgrade(
            ['field_set' => $fieldSet, 'key' => $key],
            $attributes,
        );
        $this->forgetMemo();

        return $definition;
    }

    /**
     * {@inheritDoc}
     */
    public function findStale(string $sourceType, string $sourceIdentifier, array $currentKeys): Collection
    {
        // 운영자가 만든 항목은 어떤 동기화에서도 정리 대상이 아니다.
        if ($sourceType === FieldSourceType::Custom->value) {
            return new Collection;
        }

        return FieldDefinition::query()
            ->where('source_type', $sourceType)
            ->where('source_identifier', $sourceIdentifier)
            ->get()
            ->reject(fn (FieldDefinition $definition) => in_array($definition->field_set.'|'.$definition->key, $currentKeys, true))
            ->values();
    }

    /**
     * {@inheritDoc}
     */
    public function countBySource(string $sourceType, string $sourceIdentifier): int
    {
        return FieldDefinition::query()
            ->where('source_type', $sourceType)
            ->where('source_identifier', $sourceIdentifier)
            ->count();
    }

    /**
     * {@inheritDoc}
     */
    public function maxSortOrder(string $fieldSet): int
    {
        return (int) FieldDefinition::query()->where('field_set', $fieldSet)->max('sort_order');
    }

    /**
     * {@inheritDoc}
     */
    public function reorder(string $fieldSet, array $orderedIds): void
    {
        DB::transaction(function () use ($fieldSet, $orderedIds) {
            $definitions = FieldDefinition::query()->where('field_set', $fieldSet)->get()->keyBy('id');
            $position = 0;
            foreach ($orderedIds as $id) {
                $definition = $definitions->get((int) $id);
                if ($definition === null) {
                    continue;
                }
                $position += 10;
                if ($definition->sort_order !== $position) {
                    // 운영자가 바꾼 순서다 — 모델 저장으로 user_overrides 에 남긴다.
                    $definition->update(['sort_order' => $position]);
                }
            }
        });
        $this->forgetMemo();
    }

    /**
     * {@inheritDoc}
     */
    public function version(string $fieldSet): string
    {
        if (! $this->tableExists()) {
            return '0';
        }

        // 수정 시각은 초 단위라 같은 초 안의 두 번째 수정을 놓친다 — 내용 자체를 해시한다.
        $rows = $this->allForSet($fieldSet)
            ->map(fn (FieldDefinition $definition) => array_diff_key($definition->getAttributes(), ['created_at' => 1, 'updated_at' => 1]))
            ->all();

        return substr(sha1((string) json_encode($rows)), 0, 12);
    }

    /**
     * {@inheritDoc}
     */
    public function forgetMemo(): void
    {
        $this->memo = [];
    }
}
