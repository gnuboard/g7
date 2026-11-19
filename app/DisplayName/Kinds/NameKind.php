<?php

namespace App\DisplayName\Kinds;

use App\Models\User;

/**
 * 표시 이름 종류: 이름.
 *
 * 이름이 비면 닉네임, 그것도 비면 아이디 가림으로 보인다.
 *
 * @since 7.2.0
 */
class NameKind extends AbstractDisplayNameKind
{
    /** 종류 키 */
    public const KEY = 'name';

    /**
     * 종류 키를 반환합니다.
     *
     * @return string 종류 키
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * 회원의 이름을 반환합니다.
     *
     * @param  User  $user  회원
     * @param  array<string, mixed>  $context  호출 문맥
     * @return string|null 이름 (비어 있으면 null)
     */
    public function compute(User $user, array $context = []): ?string
    {
        return $this->filled($user->name);
    }

    /**
     * 계산에 필요한 users 컬럼을 반환합니다.
     *
     * @return array<int, string> 컬럼명 목록
     */
    public function columns(): array
    {
        return ['name'];
    }

    /**
     * 사용자 화면의 작성자 검색이 찾을 users 컬럼을 반환합니다.
     *
     * @return array<int, string> 컬럼명 목록
     */
    public function searchColumns(): array
    {
        return ['name'];
    }

    /**
     * 작성자순 정렬에 쓸 SQL 식을 반환합니다.
     *
     * @param  string  $usersAlias  쿼리에서 users 테이블을 가리키는 참조 (접두와 인용이 적용된 것)
     * @return string|null SQL 식
     */
    public function sortExpression(string $usersAlias): ?string
    {
        return $usersAlias.'.name';
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
