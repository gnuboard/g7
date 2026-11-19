<?php

namespace App\Http\Resources\Traits;

use App\DisplayName\MemberNamePresenter;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * 응답에 회원 이름을 표시 이름으로 싣는 공통 도우미를 제공합니다.
 *
 * Resource 와 Collection 이 같은 규칙(이름 키에는 표시 이름, 실명은 `real_name` 보조)을 쓰도록 트레이트로 둔다.
 * 판정과 계산은 `App\DisplayName\MemberNamePresenter` 한 곳이 한다.
 */
trait ExposesMemberDisplayName
{
    /**
     * 응답에 실을 회원 이름 묶음을 반환합니다.
     *
     * `name` 은 그 자리의 표시 이름이다. 관리자 요청이고 보는 사람에게 회원 조회 권한이 있으며
     * 실명이 표시 이름과 다를 때만 `real_name` 이 더해진다.
     *
     * @param  mixed  $user  회원 (User 가 아니면 이름 없음으로 본다)
     * @param  Request|null  $request  HTTP 요청 (생략하면 현재 요청)
     * @param  string|null  $area  영역 키 (생략하면 관리자 요청은 `core:admin`, 그 밖은 `core:site`)
     * @param  array<string, mixed>  $context  호출 문맥
     * @return array<string, string|null> `['name' => 표시 이름]` 또는 여기에 `real_name` 이 더해진 배열
     */
    protected function memberNameFields(mixed $user, ?Request $request = null, ?string $area = null, array $context = []): array
    {
        return app(MemberNamePresenter::class)->fields($user instanceof User ? $user : null, $request, $area, $context);
    }

    /**
     * 회원의 표시 이름을 반환합니다.
     *
     * @param  mixed  $user  회원 (User 가 아니면 null)
     * @param  Request|null  $request  HTTP 요청 (생략하면 현재 요청)
     * @param  string|null  $area  영역 키 (생략하면 관리자 요청은 `core:admin`, 그 밖은 `core:site`)
     * @param  array<string, mixed>  $context  호출 문맥
     * @return string|null 표시 이름
     */
    protected function memberDisplayName(mixed $user, ?Request $request = null, ?string $area = null, array $context = []): ?string
    {
        return app(MemberNamePresenter::class)->name($user instanceof User ? $user : null, $request, $area, $context);
    }

    /**
     * 목록의 회원 관계를 모아 표시 이름 계산에 필요한 값을 한 번에 읽어 둡니다 (N+1 방지).
     *
     * @param  iterable<int, mixed>  $models  모델 목록
     * @param  array<int, string>  $relations  회원 관계 이름 목록
     * @param  Request|null  $request  HTTP 요청 (생략하면 현재 요청)
     * @param  string|null  $area  영역 키
     * @return void 반환값 없음
     */
    protected function preloadMemberDisplayNames(iterable $models, array $relations, ?Request $request = null, ?string $area = null): void
    {
        app(MemberNamePresenter::class)->preloadRelations($models, $relations, $request, $area);
    }
}
