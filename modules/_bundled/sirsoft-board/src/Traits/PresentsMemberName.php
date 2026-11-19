<?php

namespace Modules\Sirsoft\Board\Traits;

use App\Models\User;
use Illuminate\Http\Request;
use Modules\Sirsoft\Board\DisplayName\BoardDisplayNames;

/**
 * 회원 이름을 응답에 싣는 Trait
 *
 * 리소스가 회원 이름을 `users.name` 에서 직접 읽지 않고 그 화면의 표시 이름으로 싣게 한다.
 * 관리자 화면 요청이고 요청자에게 회원 조회 권한이 있으면 실명을 `real_name` 으로 더한다(표시 이름과 같으면 싣지 않는다).
 */
trait PresentsMemberName
{
    /**
     * 회원 이름 묶음을 반환합니다 (`name` 과, 볼 수 있을 때만 `real_name`).
     *
     * 영역은 요청이 관리자 컨트롤러에 도달했는지로 고른다. 게시판을 주면 사용자 화면에서 그 게시판의 설정을 따른다.
     *
     * @param  User|null  $user  회원
     * @param  Request|null  $request  HTTP 요청 (생략하면 현재 요청)
     * @param  mixed  $board  게시판 (모델, ID, 슬러그. 사용자 화면에서만 쓰인다)
     * @param  string  $nameKey  이름 키
     * @return array<string, string|null> 이름 묶음
     */
    protected function boardMemberNameFields(?User $user, ?Request $request = null, mixed $board = null, string $nameKey = 'name'): array
    {
        $displayNames = app(BoardDisplayNames::class);
        $displayName = $displayNames->name($user, null, $displayNames->areaFor($request), $board);

        return $displayNames->withRealName([$nameKey => $displayName], $user, $displayName, $request);
    }

    /**
     * 회원의 표시 이름만 반환합니다.
     *
     * @param  User|null  $user  회원
     * @param  Request|null  $request  HTTP 요청 (생략하면 현재 요청)
     * @param  mixed  $board  게시판
     * @return string|null 표시 이름
     */
    protected function boardMemberDisplayName(?User $user, ?Request $request = null, mixed $board = null): ?string
    {
        $displayNames = app(BoardDisplayNames::class);

        return $displayNames->name($user, null, $displayNames->areaFor($request), $board);
    }
}
