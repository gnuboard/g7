<?php

namespace App\DisplayName\Kinds;

use App\Models\User;
use App\Support\NameMasker;

/**
 * 표시 이름 종류: 아이디 가림 (gildong@example.com → gil***).
 *
 * 아이디는 이메일의 `@` 앞부분이다. 모든 종류의 마지막 대체 값이며 검색 대상이 아니다.
 *
 * @since 7.2.0
 */
class MaskedIdKind extends AbstractDisplayNameKind
{
    /** 종류 키 */
    public const KEY = 'masked_id';

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
     * 회원의 아이디를 가려 반환합니다.
     *
     * @param  User  $user  회원
     * @param  array<string, mixed>  $context  호출 문맥
     * @return string|null 가린 아이디 (이메일이 비어 있으면 null)
     */
    public function compute(User $user, array $context = []): ?string
    {
        // 앞 3자만 남기고 가린 값을 만든다(이메일 원문은 내보내지 않는다).
        return NameMasker::maskId($user->getAttribute('email'));
    }

    /**
     * 계산에 필요한 users 컬럼을 반환합니다.
     *
     * @return array<int, string> 컬럼명 목록
     */
    public function columns(): array
    {
        return ['email'];
    }

    /**
     * 작성자순 정렬에 쓸 SQL 식을 반환합니다 (보이는 앞 글자만으로 정렬한다).
     *
     * @param  string  $usersAlias  쿼리에서 users 테이블을 가리키는 참조 (접두와 인용이 적용된 것)
     * @return string|null SQL 식
     */
    public function sortExpression(string $usersAlias): ?string
    {
        return 'SUBSTR('.$usersAlias.'.email, 1, '.NameMasker::ID_VISIBLE_LENGTH.')';
    }

    /**
     * 값이 없을 때 대신 쓸 종류 키를 반환합니다.
     *
     * @return string|null 마지막 대체 값이라 null
     */
    public function fallback(): ?string
    {
        return null;
    }
}
