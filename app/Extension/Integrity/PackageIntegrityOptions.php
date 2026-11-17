<?php

namespace App\Extension\Integrity;

/**
 * 설치·업데이트 요청 하나가 무결성 검증에 넘기는 운영자 선택.
 *
 * - `allowUnsigned`: 미서명(또는 이 서버에서 검증할 수 없는) 패키지를 운영자가 확인하고 계속한다. 웹은 409 경고 창의
 *   「미서명으로 계속」이 같은 요청을 `allow_unsigned=1` 로 다시 보낼 때, Artisan 은 `--allow-unsigned` 또는 대화형 확인.
 *   서명 불일치·미신뢰 키는 이 값으로 통과시키지 못하고, 환경설정 「미서명 패키지 차단」이 켜져 있으면 이 값을 무시한다.
 * - `signaturePath`: 서명 파일(`.sig.json`) 경로 — ZIP 업로드 옆 서명 파일 칸, `--signature=`.
 * - `legacyClient`: 무결성 표식(`integrity_aware`)을 보내지 않은 옛 관리자 화면(7.1.x 템플릿 복사본)의 요청. 옛 화면은
 *   「미서명으로 계속」을 그릴 수 없으므로 7.1 동작대로 미서명을 허용한다(경고 로그). 차단 스위치·서명 불일치·미신뢰 키는 그대로 막는다.
 *   판정은 `UnsignedPackagePolicy::decide()` 가 한다. CLI 와 서비스 기본값은 false 다.
 *
 * @since 7.2.0
 */
final class PackageIntegrityOptions
{
    /**
     * 선택을 만듭니다.
     *
     * @param  bool  $allowUnsigned  미서명 패키지를 확인하고 계속할지
     * @param  string|null  $signaturePath  서명 파일 경로 (없으면 null)
     * @param  bool  $legacyClient  무결성 표식이 없는 옛 관리자 화면의 요청인지
     */
    public function __construct(
        public readonly bool $allowUnsigned = false,
        public readonly ?string $signaturePath = null,
        public readonly bool $legacyClient = false,
    ) {}

    /**
     * 아무것도 고르지 않은 기본 선택을 돌려줍니다 (미서명이면 409).
     *
     * @return self 기본 선택
     */
    public static function none(): self
    {
        return new self;
    }

    /**
     * 미서명 계속을 허용한 같은 선택을 돌려줍니다.
     *
     * @return self 허용한 선택
     */
    public function allowingUnsigned(): self
    {
        return new self(true, $this->signaturePath, $this->legacyClient);
    }
}
