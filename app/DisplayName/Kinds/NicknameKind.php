<?php

namespace App\DisplayName\Kinds;

use App\Models\User;

/**
 * 표시 이름 종류: 닉네임.
 *
 * 닉네임이 비면 아이디 가림으로 보인다. 이름으로 대신하지 않는다. 닉네임 사이트에서 실명이 새지 않게 하기 위해서다.
 *
 * @since 7.2.0
 */
class NicknameKind extends AbstractDisplayNameKind
{
    /** 종류 키 */
    public const KEY = 'nickname';

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
     * 회원의 닉네임을 반환합니다.
     *
     * @param  User  $user  회원
     * @param  array<string, mixed>  $context  호출 문맥
     * @return string|null 닉네임 (비어 있으면 null)
     */
    public function compute(User $user, array $context = []): ?string
    {
        return $this->filled($user->nickname);
    }

    /**
     * 계산에 필요한 users 컬럼을 반환합니다.
     *
     * @return array<int, string> 컬럼명 목록
     */
    public function columns(): array
    {
        return ['nickname'];
    }

    /**
     * 사용자 화면의 작성자 검색이 찾을 users 컬럼을 반환합니다.
     *
     * @return array<int, string> 컬럼명 목록
     */
    public function searchColumns(): array
    {
        return ['nickname'];
    }

    /**
     * 작성자순 정렬에 쓸 SQL 식을 반환합니다.
     *
     * @param  string  $usersAlias  쿼리에서 users 테이블을 가리키는 참조 (접두와 인용이 적용된 것)
     * @return string|null SQL 식
     */
    public function sortExpression(string $usersAlias): ?string
    {
        return $usersAlias.'.nickname';
    }

    /**
     * 이 종류가 닉네임을 필요로 하는지 반환합니다.
     *
     * @return bool 항상 true
     */
    public function requiresNickname(): bool
    {
        return true;
    }
}
