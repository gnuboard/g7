<?php

namespace App\Fields\Storage;

use App\Contracts\Repositories\FieldValueRepositoryInterface;
use App\Fields\Contracts\FieldTypeInterface;
use App\Models\FieldDefinition;
use App\Models\FieldValue;

/**
 * 공용 값 테이블(field_values)에 값을 두는 저장소.
 *
 * @since 7.2.0
 */
class ValueTableFieldStorage
{
    /**
     * @param  FieldValueRepositoryInterface  $values  값 저장소
     */
    public function __construct(
        protected FieldValueRepositoryInterface $values,
    ) {}

    /**
     * 값 행을 유형의 값으로 되살립니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  FieldTypeInterface  $type  유형
     * @param  FieldValue|null  $row  값 행
     * @return mixed 값 (행이 없으면 null)
     */
    public function hydrate(FieldDefinition $definition, FieldTypeInterface $type, ?FieldValue $row): mixed
    {
        if ($row === null) {
            return null;
        }

        return $type->deserialize($definition, [
            'value_text' => $row->value_text,
            'value_index' => $row->value_index,
            'value_number' => $row->value_number,
            'value_date' => $row->value_date,
            'value_date_end' => $row->value_date_end,
            'value_json' => $row->value_json,
        ]);
    }

    /**
     * 값을 저장합니다. 빈 값이면 행을 지웁니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  FieldTypeInterface  $type  유형
     * @param  string  $ownerType  소유자 종류
     * @param  int  $ownerId  소유자 ID
     * @param  mixed  $value  정규화한 값
     * @return void 반환값 없음
     */
    public function write(FieldDefinition $definition, FieldTypeInterface $type, string $ownerType, int $ownerId, mixed $value): void
    {
        // 「아니오」는 빈 값으로 보지만(필수 판정) 저장은 한다 — null 만 행을 지운다.
        if ($value === null || $value === '' || $value === []) {
            $this->values->deleteOne($definition->id, $ownerType, $ownerId);

            return;
        }

        $columns = $type->serialize($definition, $value);
        $columns['unique_key'] = $definition->constraint('unique', false) ? $type->uniqueKey($definition, $value) : null;

        $this->values->upsert($definition->id, $ownerType, $ownerId, $columns);
    }
}
