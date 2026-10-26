<?php

namespace Plugins\Sirsoft\Sso\DTO;

/**
 * 프로토콜 드라이버의 기능 표.
 *
 * 관리자 화면은 지원하지 않는 기능의 칸을 비활성으로 그리고 이유를 보인다(예: 로그아웃 범위, IdP-initiated).
 *
 * @since 1.0.0
 */
final class DriverCapabilities
{
    /**
     * @param  bool  $redirect  브라우저 리디렉션 로그인 (OIDC, SAML)
     * @param  bool  $credential  아이디·비밀번호 확인 (LDAP)
     * @param  bool  $slo  G7 → IdP 로그아웃
     * @param  bool  $backchannel  IdP → G7 로그아웃 수신
     * @param  bool  $idpInitiated  IdP 가 시작한 로그인
     * @param  bool  $mfaSignal  IdP 가 다중 인증 여부를 알려 줌
     * @param  bool  $groups  그룹 정보 제공
     * @param  bool  $periodicCheck  재직 여부 주기 점검 수단
     * @param  bool  $metadata  IdP 메타데이터(디스커버리) 자동 조회
     */
    public function __construct(
        public readonly bool $redirect = false,
        public readonly bool $credential = false,
        public readonly bool $slo = false,
        public readonly bool $backchannel = false,
        public readonly bool $idpInitiated = false,
        public readonly bool $mfaSignal = false,
        public readonly bool $groups = false,
        public readonly bool $periodicCheck = false,
        public readonly bool $metadata = false,
    ) {}

    /**
     * 배열로 바꿉니다 (API 응답).
     *
     * @return array<string, bool> 기능 이름 → 지원 여부
     */
    public function toArray(): array
    {
        return [
            'redirect' => $this->redirect,
            'credential' => $this->credential,
            'slo' => $this->slo,
            'backchannel' => $this->backchannel,
            'idp_initiated' => $this->idpInitiated,
            'mfa_signal' => $this->mfaSignal,
            'groups' => $this->groups,
            'periodic_check' => $this->periodicCheck,
            'metadata' => $this->metadata,
        ];
    }
}
