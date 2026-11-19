<?php

namespace App\Extension\Auth;

use App\Models\User;

/**
 * 외부 자격 증명 검증자의 식별자 일치 결과 DTO.
 *
 * {@see \App\Contracts\Extension\ExternalCredentialVerifierInterface::match()} 의 반환값. 회원이 이미 연결돼 있으면 `user` 가 있고,
 * 코어는 디렉터리에 비밀번호를 보내기 **전에** 그 회원의 잠금을 확인한다(잠긴 회원은 `verify()` 를 부르지 않는다).
 *
 * @since 7.2.0
 */
final class ExternalCredentialMatch
{
    /**
     * @param  User|null  $user  이미 알고 있는 G7 회원 (처음 오는 사람이면 null)
     * @param  string  $handle  검증자 내부 식별 값 (예: 디렉터리 IdP id 와 입력 정규화 값 — 응답에 싣지 않는다)
     * @param  string  $display  기록·화면 표시용 이름 (비밀값 금지)
     */
    public function __construct(
        public readonly ?User $user,
        public readonly string $handle,
        public readonly string $display = '',
    ) {}
}
