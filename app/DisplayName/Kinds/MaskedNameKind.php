<?php

namespace App\DisplayName\Kinds;

use App\Models\User;
use App\Support\NameMasker;

/**
 * 표시 이름 종류: 가린 이름 (홍길동 → 홍*동).
 *
 * 이름이 비면 아이디 가림으로 보인다. 검색 대상이 아니다.
 *
 * @since 7.2.0
 */
class MaskedNameKind extends AbstractDisplayNameKind
{
    /** 종류 키 */
    public const KEY = 'masked_name';

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
     * 회원의 이름을 가려 반환합니다.
     *
     * @param  User  $user  회원
     * @param  array<string, mixed>  $context  호출 문맥
     * @return string|null 가린 이름 (이름이 비어 있으면 null)
     */
    public function compute(User $user, array $context = []): ?string
    {
        return NameMasker::maskName($user->name);
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
     * 작성자순 정렬에 쓸 SQL 식을 반환합니다 (보이는 첫 글자만으로 정렬한다).
     *
     * @param  string  $usersAlias  쿼리에서 users 테이블을 가리키는 참조 (접두와 인용이 적용된 것)
     * @return string|null SQL 식
     */
    public function sortExpression(string $usersAlias): ?string
    {
        return 'SUBSTR('.$usersAlias.'.name, 1, 1)';
    }
}
