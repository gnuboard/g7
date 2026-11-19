<?php

namespace App\Fields\Storage;

use App\Models\FieldDefinition;
use Illuminate\Database\Eloquent\Model;

/**
 * 소유 테이블의 컬럼에 값을 두는 저장소.
 *
 * `storage_target` 은 컬럼명이다. 주소처럼 하위 값이 여러 컬럼인 항목은 하위 값 순서대로 쉼표로 적는다
 * (`zipcode,address,address_detail`). 이 저장소는 모델을 저장하지 않는다 — 컬럼 값을 돌려주면 호출자가
 * 소유자 생성·수정 데이터에 합쳐 한 번에 저장한다.
 *
 * @since 7.2.0
 */
class ColumnFieldStorage
{
    /**
     * 항목이 쓰는 컬럼 목록을 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @return array<int, string> 컬럼명 목록
     */
    public function columns(FieldDefinition $definition): array
    {
        $target = (string) ($definition->storage_target ?: $definition->key);

        return array_values(array_filter(array_map('trim', explode(',', $target)), fn ($column) => $column !== ''));
    }

    /**
     * 소유자 모델에서 값을 읽습니다.
     *
     * @param  Model  $owner  소유자
     * @param  FieldDefinition  $definition  항목 정의
     * @return mixed 값 (여러 컬럼이면 컬럼명 => 값 배열)
     */
    public function read(Model $owner, FieldDefinition $definition): mixed
    {
        $columns = $this->columns($definition);
        if (count($columns) === 1) {
            return $owner->getAttribute($columns[0]);
        }

        $value = [];
        foreach ($columns as $column) {
            $value[$column] = $owner->getAttribute($column);
        }

        return $value;
    }

    /**
     * 정규화한 값을 컬럼 값 배열로 풉니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  mixed  $value  정규화한 값 (여러 컬럼이면 하위 값 배열)
     * @return array<string, mixed> 컬럼명 => 값
     */
    public function toColumns(FieldDefinition $definition, mixed $value): array
    {
        $columns = $this->columns($definition);
        if (count($columns) === 1) {
            return [$columns[0] => $value];
        }

        $result = [];
        $parts = is_array($value) ? array_values($value) : [];
        $named = is_array($value) ? $value : [];
        foreach ($columns as $index => $column) {
            $result[$column] = array_key_exists($column, $named) ? $named[$column] : ($parts[$index] ?? null);
        }

        return $result;
    }
}
