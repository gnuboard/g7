<?php

namespace App\Fields\Contracts;

use App\Fields\FieldContext;
use App\Models\FieldDefinition;
use Illuminate\Database\Eloquent\Model;

/**
 * 확장 저장소 어댑터 계약.
 *
 * 저장 방식이 `handler` 인 항목은 값을 확장의 테이블에 둔다. 확장은 이 계약을 구현한 클래스명을
 * 선언의 `storage_target` 에 적고, 코어는 컨테이너에서 꺼내 읽기·쓰기·파기를 맡긴다.
 *
 * @since 7.2.0
 */
interface FieldValueHandlerInterface
{
    /**
     * 소유자의 값을 읽습니다.
     *
     * @param  Model  $owner  값의 소유자 (회원 등)
     * @param  FieldDefinition  $definition  항목 정의
     * @return mixed 값 (없으면 null)
     */
    public function read(Model $owner, FieldDefinition $definition): mixed;

    /**
     * 소유자의 값을 저장합니다.
     *
     * 코어가 소유자를 저장한 뒤 같은 트랜잭션 안에서 부른다.
     *
     * @param  Model  $owner  값의 소유자
     * @param  FieldDefinition  $definition  항목 정의
     * @param  mixed  $value  유형이 정규화한 값 (비우면 null)
     * @param  FieldContext  $context  요청 문맥
     * @return void 반환값 없음
     */
    public function write(Model $owner, FieldDefinition $definition, mixed $value, FieldContext $context): void;

    /**
     * 유형 규칙에 더할 검증 규칙을 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  string  $attribute  요청 속성 경로
     * @param  FieldContext  $context  요청 문맥
     * @return array<int, mixed> 속성에 더할 규칙
     */
    public function extraRules(FieldDefinition $definition, string $attribute, FieldContext $context): array;

    /**
     * 소유자의 값을 파기합니다 (탈퇴 회원 파기, 소유자 삭제).
     *
     * @param  Model  $owner  값의 소유자
     * @param  FieldDefinition  $definition  항목 정의
     * @return void 반환값 없음
     */
    public function purge(Model $owner, FieldDefinition $definition): void;
}
