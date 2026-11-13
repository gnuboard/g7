<?php

namespace App\DisplayName\Kinds;

use App\Models\User;

/**
 * 표시 이름 종류: 이름(닉네임) (홍길동(길동이)).
 *
 * 이름과 닉네임이 모두 있어야 이 모양으로 보인다. 하나라도 비면 「이름」 종류로 넘어간다
 * (이름 → 닉네임 → 아이디 가림).
 *
 * @since 7.2.0
 */
class NameNicknameKind extends AbstractDisplayNameKind
{
    /** 종류 키 */
    public const KEY = 'name_nickname';

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
     * 회원의 이름과 닉네임을 합쳐 반환합니다.
     *
     * @param  User  $user  회원
     * @param  array<string, mixed>  $context  호출 문맥
     * @return string|null 「이름(닉네임)」 (둘 중 하나라도 비어 있으면 null)
     */
    public function compute(User $user, array $context = []): ?string
    {
        $name = $this->filled($user->name);
        $nickname = $this->filled($user->nickname);

        return $name !== null && $nickname !== null ? $name.'('.$nickname.')' : null;
    }

    /**
     * 계산에 필요한 users 컬럼을 반환합니다.
     *
     * @return array<int, string> 컬럼명 목록
     */
    public function columns(): array
    {
        return ['name', 'nickname'];
    }

    /**
     * 사용자 화면의 작성자 검색이 찾을 users 컬럼을 반환합니다.
     *
     * @return array<int, string> 컬럼명 목록
     */
    public function searchColumns(): array
    {
        return ['name', 'nickname'];
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
     * 이 종류가 닉네임을 필요로 하는지 반환합니다.
     *
     * @return bool 항상 true
     */
    public function requiresNickname(): bool
    {
        return true;
    }

    /**
     * 값이 없을 때 대신 쓸 종류 키를 반환합니다.
     *
     * @return string|null 대체 종류 키
     */
    public function fallback(): ?string
    {
        return NameKind::KEY;
    }
}
