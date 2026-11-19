<?php

namespace App\Contracts\Extension;

use App\Extension\Auth\ExternalCredentialMatch;
use App\Extension\Auth\ExternalCredentialResult;

/**
 * 외부 자격 증명 검증자 계약 — 로그인 화면의 아이디·비밀번호를 G7 이 아닌 외부 디렉터리로 확인하는 확장.
 *
 * 확장은 필터 `core.auth.registered_credential_verifiers` 로 구현체를 등록한다. 코어 `AuthService::login()` 은 비밀번호 확인
 * 전에 사용 가능한 검증자를 등록 순서대로 `match()` 하고, 처음 일치한 검증자의 `verify()` 결과로 로그인한다
 * (인증 성공이면 `loginVerifiedUser()` 로 합류 — 잠금·상태·관리자 경로·2단계 인증·휴면 해제를 비밀번호 로그인과 같게).
 * 일치하는 검증자가 없으면 기존 비밀번호 로그인이다.
 *
 * @since 7.2.0
 */
interface ExternalCredentialVerifierInterface
{
    /**
     * 검증자 식별자를 돌려줍니다 (예: `vendor-plugin:directory`).
     *
     * @return string 식별자
     */
    public function getId(): string;

    /**
     * 지금 이 서버에서 쓸 수 있는지 돌려줍니다 (PHP 확장·설정 유무 — 부작용 없는 조회만).
     *
     * @return bool 사용 가능하면 true
     */
    public function isAvailable(): bool;

    /**
     * 이 로그인 경로에서 이메일이 아닌 아이디(사내 아이디 등)를 받는지 돌려줍니다.
     *
     * 참을 돌려주는 검증자가 하나라도 있으면 그 경로의 로그인 요청은 이메일 형식 검사를 하지 않는다.
     *
     * @param  string  $guard  로그인 경로 (user | admin)
     * @return bool 아이디를 받으면 true
     */
    public function acceptsUsernames(string $guard): bool;

    /**
     * 입력한 식별자가 이 검증자 담당인지 판정합니다 (디렉터리 바인드 없이 — 도메인·형식·설정으로만).
     *
     * @param  string  $identifier  로그인 칸에 입력한 값
     * @param  string  $guard  로그인 경로 (user | admin)
     * @return ExternalCredentialMatch|null 담당이면 일치 정보, 아니면 null
     */
    public function match(string $identifier, string $guard): ?ExternalCredentialMatch;

    /**
     * 비밀번호를 외부 디렉터리로 확인합니다.
     *
     * 빈 비밀번호는 코어가 이미 요청 검증에서 막지만 구현체도 바인드 전에 거절해야 한다(익명 바인드 방지).
     *
     * @param  ExternalCredentialMatch  $match  `match()` 결과
     * @param  string  $password  입력한 비밀번호 (저장·기록 금지)
     * @param  string  $guard  로그인 경로 (user | admin)
     * @return ExternalCredentialResult 인증 성공 / 거절 / 추가 단계 필요
     */
    public function verify(ExternalCredentialMatch $match, string $password, string $guard): ExternalCredentialResult;
}
