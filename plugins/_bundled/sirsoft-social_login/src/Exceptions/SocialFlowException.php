<?php

namespace Plugins\Sirsoft\SocialLogin\Exceptions;

use Illuminate\Support\Facades\Lang;
use RuntimeException;

/**
 * 가입·연결·본인 재확인 흐름의 거절.
 *
 * 오류 코드(`errorCode`)는 화면 문구 키(`sirsoft-social_login.errors.{code}`)·시도 기록에 쓰고, 응답 문구는
 * `messageKey()`(`lang/{ko,en}/exceptions.php` 의 `flow.{code}`)로 번역한다. `status` 는 API 가 돌려줄 HTTP 상태다.
 * 회원 존재 여부처럼 알려 주면 안 되는 정보는 코드에 담지 않는다.
 *
 * @since 1.0.0
 */
class SocialFlowException extends RuntimeException
{
    /**
     * @param  string  $errorCode  오류 코드
     * @param  int  $status  HTTP 상태
     */
    public function __construct(public readonly string $errorCode, public readonly int $status = 422)
    {
        parent::__construct(__('sirsoft-social_login::'.$this->messageKey()));
    }

    /**
     * 응답 문구 키를 반환합니다 (플러그인 번역 도메인 안의 키).
     *
     * 제공자·드라이버가 정한 코드(일반 OIDC 의 `discovery_failed` 등)처럼 문구가 없는 코드는 「제공자 연결 실패」
     * 공통 문구로 대신한다 — 번역 키 원문이 화면에 뜨지 않게. 코드 자체는 응답 `errors.code` 에 그대로 남는다.
     *
     * @return string 문구 키 (예: `exceptions.flow.last_method`)
     */
    public function messageKey(): string
    {
        $key = 'exceptions.flow.'.$this->errorCode;

        return Lang::has('sirsoft-social_login::'.$key) ? $key : 'exceptions.flow.provider_error';
    }
}
