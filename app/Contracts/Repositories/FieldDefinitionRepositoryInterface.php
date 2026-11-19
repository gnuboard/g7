<?php

namespace App\Contracts\Repositories;

use App\Models\FieldDefinition;
use Illuminate\Support\Collection;

/**
 * field_definitions 테이블 Repository 계약.
 *
 * @since 7.2.0
 */
interface FieldDefinitionRepositoryInterface
{
    /**
     * 테이블이 있는지 반환합니다 (마이그레이션 전 구간 보호).
     *
     * @return bool 있으면 true
     */
    public function tableExists(): bool;

    /**
     * 집합의 모든 항목을 표시 순서로 반환합니다.
     *
     * @param  string  $fieldSet  집합 식별자
     * @return Collection<int, FieldDefinition> 항목 목록
     */
    public function allForSet(string $fieldSet): Collection;

    /**
     * ID 로 항목을 조회합니다.
     *
     * @param  int  $id  항목 ID
     * @return FieldDefinition|null 항목 또는 null
     */
    public function findById(int $id): ?FieldDefinition;

    /**
     * 집합과 키로 항목을 조회합니다.
     *
     * @param  string  $fieldSet  집합 식별자
     * @param  string  $key  항목 키
     * @return FieldDefinition|null 항목 또는 null
     */
    public function findByKey(string $fieldSet, string $key): ?FieldDefinition;

    /**
     * 항목을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  속성
     * @return FieldDefinition 만든 항목
     */
    public function create(array $attributes): FieldDefinition;

    /**
     * 항목을 수정합니다 (운영자 수정 — user_overrides 에 기록된다).
     *
     * @param  FieldDefinition  $definition  항목
     * @param  array<string, mixed>  $attributes  바꿀 속성
     * @return bool 성공 여부
     */
    public function update(FieldDefinition $definition, array $attributes): bool;

    /**
     * 항목을 삭제합니다.
     *
     * @param  FieldDefinition  $definition  항목
     * @return bool 성공 여부
     */
    public function delete(FieldDefinition $definition): bool;

    /**
     * 선언을 동기화합니다 (없으면 만들고, 있으면 운영자가 고친 속성을 보존하며 갱신).
     *
     * @param  string  $fieldSet  집합 식별자
     * @param  string  $key  항목 키
     * @param  array<string, mixed>  $attributes  선언 속성
     * @return FieldDefinition 동기화된 항목
     */
    public function syncDeclared(string $fieldSet, string $key, array $attributes): FieldDefinition;

    /**
     * 출처가 같은 선언 항목 가운데 지금 선언에 없는 행을 반환합니다.
     *
     * 운영자가 만든 항목(custom)은 어떤 인자로 불러도 돌려주지 않는다.
     *
     * @param  string  $sourceType  출처 (core|module|plugin)
     * @param  string  $sourceIdentifier  출처 식별자
     * @param  array<int, string>  $currentKeys  지금 선언된 `{집합}|{키}` 목록
     * @return Collection<int, FieldDefinition> stale 항목
     */
    public function findStale(string $sourceType, string $sourceIdentifier, array $currentKeys): Collection;

    /**
     * 출처별 항목 수를 반환합니다.
     *
     * @param  string  $sourceType  출처
     * @param  string  $sourceIdentifier  출처 식별자
     * @return int 항목 수
     */
    public function countBySource(string $sourceType, string $sourceIdentifier): int;

    /**
     * 집합 안에서 가장 큰 표시 순서를 반환합니다.
     *
     * @param  string  $fieldSet  집합 식별자
     * @return int 가장 큰 순서 (항목이 없으면 0)
     */
    public function maxSortOrder(string $fieldSet): int;

    /**
     * 표시 순서를 한꺼번에 바꿉니다.
     *
     * @param  string  $fieldSet  집합 식별자
     * @param  array<int, int>  $orderedIds  새 순서의 항목 ID 목록
     * @return void 반환값 없음
     */
    public function reorder(string $fieldSet, array $orderedIds): void;

    /**
     * 집합의 정의 버전을 반환합니다 — 항목이 하나라도 바뀌면 달라지는 문자열.
     *
     * @param  string  $fieldSet  집합 식별자
     * @return string 정의 버전
     */
    public function version(string $fieldSet): string;

    /**
     * 요청 안에 담아 둔 항목 목록을 비웁니다 (이 저장소를 거치지 않고 행을 바꾼 뒤에 부른다).
     *
     * @return void 반환값 없음
     */
    public function forgetMemo(): void;
}
