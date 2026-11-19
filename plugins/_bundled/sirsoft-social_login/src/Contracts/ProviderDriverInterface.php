<?php

namespace Plugins\Sirsoft\SocialLogin\Contracts;

use Illuminate\Http\Request;
use Plugins\Sirsoft\SocialLogin\DTO\AuthorizationRequest;
use Plugins\Sirsoft\SocialLogin\DTO\NormalizedProfile;
use Plugins\Sirsoft\SocialLogin\DTO\TokenSet;
use Plugins\Sirsoft\SocialLogin\Exceptions\ProviderException;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginProvider;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginWebhookEvent;

/**
 * 제공자 드라이버 계약.
 *
 * 다른 확장이 필터 훅 `sirsoft-social_login.provider.filter_registered` 로 새 제공자를 더할 때도 이 계약을 구현한다.
 *
 * @since 1.0.0
 */
interface ProviderDriverInterface
{
    /**
     * 제공자 키를 반환합니다.
     *
     * @return string 제공자 키
     */
    public function key(): string;

    /**
     * 표시 이름을 반환합니다.
     *
     * @param  string|null  $locale  로케일
     * @return string 표시 이름
     */
    public function label(?string $locale = null): string;

    /**
     * 지원 기능을 반환합니다.
     *
     * @return array<string, mixed> pkce·oidc·refresh·revoke·webhook(bool), callback_methods(string[])
     */
    public function supports(): array;

    /**
     * 설정 화면에 보일 자격증명 필드 정의를 반환합니다.
     *
     * @return array<int, array<string, mixed>> [{key,type,label{ko,en},hint,required}]
     */
    public function credentialSchema(): array;

    /**
     * 콜백 주소 등록 때의 주의사항 다국어 키 목록을 반환합니다.
     *
     * @return array<int, string> 다국어 키
     */
    public function redirectNotes(): array;

    /**
     * 제공자 인가 화면 주소를 만듭니다.
     *
     * @param  AuthorizationRequest  $req  시작 정보
     * @return string 인가 주소
     */
    public function authorizeUrl(AuthorizationRequest $req): string;

    /**
     * 인가 코드를 토큰으로 바꿉니다.
     *
     * @param  string  $code  인가 코드
     * @param  AuthorizationRequest  $req  시작 정보
     * @return TokenSet 토큰
     */
    public function exchangeCode(string $code, AuthorizationRequest $req): TokenSet;

    /**
     * 프로필을 가져와 정규화합니다.
     *
     * @param  TokenSet  $tokens  토큰
     * @param  AuthorizationRequest  $req  시작 정보
     * @param  array<string, mixed>  $callbackInput  콜백 입력 (form_post 제공자용)
     * @return NormalizedProfile 정규화 프로필
     */
    public function fetchProfile(TokenSet $tokens, AuthorizationRequest $req, array $callbackInput = []): NormalizedProfile;

    /**
     * 제공자 쪽 연결을 끊습니다.
     *
     * @param  TokenSet  $tokens  토큰
     * @param  SocialAccount  $account  연결
     * @return bool 성공 여부
     */
    public function revoke(TokenSet $tokens, SocialAccount $account): bool;

    /**
     * 웹훅 요청을 검증해 이벤트로 바꿉니다 (지원하지 않으면 null).
     *
     * @param  Request  $request  웹훅 요청
     * @return SocialLoginWebhookEvent|null 저장 전 이벤트
     */
    public function verifyWebhook(Request $request): ?SocialLoginWebhookEvent;

    /**
     * 토큰 요청에 쓸 클라이언트 비밀값을 반환합니다.
     *
     * @return string 비밀값
     *
     * @throws ProviderException 저장된 비밀값을 풀 수 없음(`credentials_unreadable`)
     */
    public function clientSecret(): string;

    /**
     * 설정을 입힌 새 인스턴스를 반환합니다.
     *
     * @param  SocialLoginProvider|array<string, mixed>  $config  제공자 행 또는 설정 배열
     * @return static 설정된 드라이버
     */
    public function withConfig(SocialLoginProvider|array $config): static;
}
