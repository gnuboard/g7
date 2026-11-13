<?php

namespace App\DisplayName\Kinds;

use App\DisplayName\DisplayNameFieldValues;
use App\DisplayName\DisplayNameKindRegistry;
use App\Models\FieldDefinition;
use App\Models\FieldValue;
use App\Models\User;

/**
 * 표시 이름 종류: 추가 항목 값 (`field:{항목 키}`).
 *
 * 운영자가 「표시 이름으로 사용 가능」을 켠 회원 추가 항목마다 종류가 하나씩 생긴다(예: 별명, 활동명).
 * 값이 비면 닉네임, 그것도 비면 아이디 가림으로 보인다.
 *
 * 값은 회원 행이 아니라 값 테이블에 있다. 여러 회원을 그릴 때는 `DisplayNameResolver::preload()` 로 한 번에 읽어 둔다.
 *
 * @since 7.2.0
 */
class FieldValueKind extends AbstractDisplayNameKind
{
    /**
     * @param  FieldDefinition  $definition  항목 정의
     * @param  DisplayNameFieldValues  $values  표시 이름용 항목 값 메모
     */
    public function __construct(
        protected FieldDefinition $definition,
        protected DisplayNameFieldValues $values,
    ) {}

    /**
     * 종류 키를 반환합니다.
     *
     * @return string `field:{항목 키}`
     */
    public function key(): string
    {
        return DisplayNameKindRegistry::fieldKindKey($this->definition->key);
    }

    /**
     * 화면에 보일 종류 이름(항목 이름)을 반환합니다.
     *
     * @param  string|null  $locale  로케일 (생략하면 현재 로케일)
     * @return string 항목 이름
     */
    public function label(?string $locale = null): string
    {
        return $this->definition->localized('name', $locale) ?? $this->definition->key;
    }

    /**
     * 이 종류의 항목 정의를 반환합니다.
     *
     * @return FieldDefinition 항목 정의
     */
    public function definition(): FieldDefinition
    {
        return $this->definition;
    }

    /**
     * 회원의 항목 값을 반환합니다.
     *
     * 문맥의 `field_values[{항목 키}]` 가 있으면 그 값을 쓴다(저장 전 미리보기용).
     *
     * @param  User  $user  회원
     * @param  array<string, mixed>  $context  호출 문맥
     * @return string|null 항목 값의 보기용 문자열 (비어 있으면 null)
     */
    public function compute(User $user, array $context = []): ?string
    {
        $given = $context['field_values'] ?? null;
        if (is_array($given) && array_key_exists($this->definition->key, $given)) {
            return $this->filled($given[$this->definition->key]);
        }

        return $this->filled($this->values->get($user, $this->definition));
    }

    /**
     * 작성자순 정렬에 쓸 SQL 식을 반환합니다 (값 테이블의 정렬용 값을 회원마다 읽는 하위 쿼리).
     *
     * @param  string  $usersAlias  쿼리에서 users 테이블을 가리키는 참조 (접두와 인용이 적용된 것)
     * @return string|null SQL 식 (저장하지 않은 항목이면 null)
     */
    public function sortExpression(string $usersAlias): ?string
    {
        if (! $this->definition->exists) {
            return null;
        }

        $values = new FieldValue;
        $table = $values->getConnection()->getQueryGrammar()->wrapTable($values->getTable());

        return sprintf(
            "(SELECT dn_fv.value_index FROM %s dn_fv WHERE dn_fv.field_definition_id = %d AND dn_fv.owner_type = '%s' AND dn_fv.owner_id = %s.id LIMIT 1)",
            $table,
            (int) $this->definition->id,
            FieldValue::OWNER_USER,
            $usersAlias,
        );
    }

    /**
     * 값이 없을 때 대신 쓸 종류 키를 반환합니다.
     *
     * @return string|null 대체 종류 키
     */
    public function fallback(): ?string
    {
        return NicknameKind::KEY;
    }
}
