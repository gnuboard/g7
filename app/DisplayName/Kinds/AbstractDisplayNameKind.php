<?php

namespace App\DisplayName\Kinds;

use App\DisplayName\Contracts\DisplayNameKindInterface;

/**
 * 표시 이름 종류의 공통부.
 *
 * 확장 종류도 이 클래스를 이어받으면 `key()`, `compute()` 만 채우면 된다.
 *
 * @since 7.2.0
 */
abstract class AbstractDisplayNameKind implements DisplayNameKindInterface
{
    /**
     * 화면에 보일 종류 이름을 반환합니다 (기본: 번역 키 `display_name.kinds.{키}`).
     *
     * @param  string|null  $locale  로케일 (생략하면 현재 로케일)
     * @return string 종류 이름
     */
    public function label(?string $locale = null): string
    {
        $key = 'display_name.kinds.'.$this->key();
        $label = __($key, [], $locale);

        return is_string($label) && $label !== $key ? $label : $this->key();
    }

    /**
     * 계산에 필요한 users 컬럼을 반환합니다.
     *
     * @return array<int, string> 컬럼명 목록
     */
    public function columns(): array
    {
        return [];
    }

    /**
     * 사용자 화면의 작성자 검색이 찾을 users 컬럼을 반환합니다.
     *
     * @return array<int, string> 컬럼명 목록 (기본: 검색하지 않음)
     */
    public function searchColumns(): array
    {
        return [];
    }

    /**
     * 작성자순 정렬에 쓸 SQL 식을 반환합니다.
     *
     * @param  string  $usersAlias  쿼리에서 users 테이블을 가리키는 참조 (접두와 인용이 적용된 것)
     * @return string|null SQL 식 (기본: 정렬하지 않음)
     */
    public function sortExpression(string $usersAlias): ?string
    {
        return null;
    }

    /**
     * 이 종류가 닉네임을 필요로 하는지 반환합니다.
     *
     * @return bool 닉네임이 필요하면 true (기본: false)
     */
    public function requiresNickname(): bool
    {
        return false;
    }

    /**
     * 값이 없을 때 대신 쓸 종류 키를 반환합니다.
     *
     * @return string|null 대체 종류 키 (기본: 아이디 가림)
     */
    public function fallback(): ?string
    {
        return MaskedIdKind::KEY;
    }

    /**
     * 빈 문자열을 null 로 바꿉니다.
     *
     * @param  mixed  $value  값
     * @return string|null 앞뒤 공백을 뗀 문자열 (비어 있으면 null)
     */
    protected function filled(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
