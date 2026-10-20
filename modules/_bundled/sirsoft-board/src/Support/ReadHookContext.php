<?php

namespace Modules\Sirsoft\Board\Support;

use Illuminate\Support\Facades\Auth;

/**
 * 사용자 조회 결과 훅(before_* / filter_*_result / after_*)에 넘기는 요청 정보 배열을 만듭니다.
 *
 * 서버 훅은 어느 화면이 불렀는지 모르므로, 리스너가 사용자별로 결과를 고칠 수 있게 현재 사용자와
 * 화면 언어를 함께 넘긴다. 경로마다 필요한 값(게시판·필터·조회 대상 회원 등)은 `$extra` 로 더한다.
 * 훅 이름은 docgen 이 리터럴 호출을 훑으므로 각 Service 에서 직접 적는다 — 이 클래스는 배열만 만든다.
 */
final class ReadHookContext
{
    /**
     * 요청 정보 배열을 만듭니다.
     *
     * @param  array<string, mixed>  $extra  경로별 값
     * @return array<string, mixed> `user`(현재 사용자 또는 null)·`locale`(화면 언어) + 경로별 값
     */
    public static function make(array $extra = []): array
    {
        return ['user' => Auth::user(), 'locale' => app()->getLocale()] + $extra;
    }
}
