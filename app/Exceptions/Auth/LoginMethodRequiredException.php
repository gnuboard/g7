<?php

namespace App\Exceptions\Auth;

use InvalidArgumentException;

/**
 * 외부 인증 로그인에 로그인 수단 이름(`login_method`)이 없을 때의 예외.
 *
 * `LoginHandoffService::issue()`·`AuthService::loginVerifiedUser()` 를 부르는 확장의 프로그래밍 오류다 — 이름이 없으면
 * 로그인 완료 훅·활동 로그가 그 로그인을 비밀번호 로그인(`password`)으로 기록하게 되므로 받지 않는다.
 * 요청 처리 중이 아니라 확장 코드에서 나는 오류라 HTTP 응답으로 바꾸지 않는다.
 *
 * @since 7.2.0
 */
class LoginMethodRequiredException extends InvalidArgumentException
{
    /**
     * 예외를 만듭니다.
     */
    public function __construct()
    {
        parent::__construct(__('exceptions.login_method_required'));
    }
}
