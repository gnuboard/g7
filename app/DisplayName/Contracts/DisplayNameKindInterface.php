<?php

namespace App\DisplayName\Contracts;

use App\Models\User;

/**
 * 표시 이름 종류 계약.
 *
 * 종류는 「회원의 어떤 값을 어떤 모양으로 보일지」다. 코어가 이름, 닉네임, 가린 이름, 아이디 가림, 이름(닉네임),
 * 추가 항목 값을 제공하고, 확장은 `getDisplayNameKinds()` 또는 필터 `core.display_name.filter_kinds` 로 더한다.
 *
 * @since 7.2.0
 */
interface DisplayNameKindInterface
{
    /**
     * 종류 키를 반환합니다 (설정과 `users.display_name_kind` 에 저장되는 값).
     *
     * 영문 소문자, 숫자, `_`, `-`, `:` 만 쓴다. 확장 종류는 `{확장 식별자}:{이름}` 형식을 권한다.
     *
     * @return string 종류 키
     */
    public function key(): string;

    /**
     * 화면에 보일 종류 이름을 반환합니다.
     *
     * @param  string|null  $locale  로케일 (생략하면 현재 로케일)
     * @return string 종류 이름
     */
    public function label(?string $locale = null): string;

    /**
     * 회원의 표시 이름을 계산합니다.
     *
     * 이 종류로 보일 값이 없으면(닉네임 종류인데 닉네임이 비어 있는 경우 등) null 을 돌려준다. 그러면 계산기가 대체 종류로 넘어간다.
     * 탈퇴 회원과 비회원은 계산기가 먼저 처리하므로 여기까지 오지 않는다.
     *
     * @param  User  $user  회원 (`columns()` 가 돌려준 컬럼이 적재돼 있어야 한다)
     * @param  array<string, mixed>  $context  호출 문맥 (영역 키 `area`, 호출자가 넘긴 값)
     * @return string|null 표시 이름 (없으면 null)
     */
    public function compute(User $user, array $context = []): ?string;

    /**
     * 계산에 필요한 users 컬럼을 반환합니다 (회원 관계를 컬럼 제한으로 읽을 때 쓴다).
     *
     * @return array<int, string> 컬럼명 목록
     */
    public function columns(): array;

    /**
     * 사용자 화면의 작성자 검색이 찾을 users 컬럼을 반환합니다.
     *
     * 가린 종류는 빈 배열을 돌려준다. 가려 보이는 값을 검색으로 되짚지 못하게 하기 위해서다.
     *
     * @return array<int, string> 컬럼명 목록
     */
    public function searchColumns(): array;

    /**
     * 작성자순 정렬에 쓸 SQL 식을 반환합니다.
     *
     * 화면에 보이는 값과 같은 순서가 나오는 식이어야 한다(가린 종류는 보이는 앞 글자만으로 정렬한다).
     *
     * @param  string  $usersAlias  쿼리에서 users 테이블을 가리키는 참조 (접두와 인용이 적용된 테이블명 또는 별칭)
     * @return string|null SQL 식 (정렬할 수 없는 종류면 null)
     */
    public function sortExpression(string $usersAlias): ?string;

    /**
     * 이 종류가 닉네임을 필요로 하는지 반환합니다.
     *
     * true 인 종류가 어떤 영역에 고정되면 닉네임 항목이 사용, 필수로 잠긴다.
     *
     * @return bool 닉네임이 필요하면 true
     */
    public function requiresNickname(): bool;

    /**
     * 값이 없을 때 대신 쓸 종류 키를 반환합니다.
     *
     * @return string|null 대체 종류 키 (더 없으면 null)
     */
    public function fallback(): ?string;
}
