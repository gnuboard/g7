<?php

namespace Plugins\Sirsoft\Sso\Mock\Oidc;

/**
 * 모의 OIDC IdP 의 응답 변종 카탈로그 (계획서 부록 C-1 — `OidcDriverTest` 행 수와 같다).
 *
 * 변종은 인가 화면에서 고르고, 토큰 응답의 id_token 클레임과 서명을 바꾼다.
 *
 * @since 1.0.0
 */
final class MockOidcVariants
{
    /** @var array<int, string> 변종 목록 */
    public const ALL = [
        'full', 'mfa', 'acr_mfa', 'no_email', 'email_unverified', 'email_verified_string', 'groups_string',
        'nonce_mismatch', 'aud_other', 'azp_missing_multi_aud', 'alg_none', 'alg_hs256', 'expired', 'iat_future',
        'kid_rotated', 'deny', 'token_error', 'refresh_invalid',
    ];

    /** `acr_mfa` 변종의 acr 값 — IdP 설정 `mfa_acr_values` 에 넣으면 다중 인증으로 본다 */
    public const MFA_ACR = 'urn:g7:mock:mfa';

    /** @var array<int, string> `full` 변종의 그룹 */
    public const GROUPS = ['인사팀', 'g7-admins'];

    /**
     * 변종에 맞는 id_token 클레임을 만듭니다.
     *
     * @param  string  $variant  변종
     * @param  array{issuer: string, client_id: string, nonce: string|null, subject: string, email: string, name: string, sid: string}  $grant  인가 정보
     * @param  int  $now  기준 시각 (유닉스 초)
     * @return array<string, mixed> 클레임
     */
    public static function claims(string $variant, array $grant, int $now): array
    {
        $claims = [
            'iss' => $grant['issuer'],
            'sub' => $grant['subject'],
            'aud' => $grant['client_id'],
            'exp' => $now + 600,
            'iat' => $now,
            'nonce' => $grant['nonce'],
            'email' => $grant['email'],
            'email_verified' => true,
            'name' => $grant['name'],
            'groups' => self::GROUPS,
            'amr' => ['pwd'],
            'sid' => $grant['sid'],
        ];

        switch ($variant) {
            case 'mfa':
                $claims['amr'] = ['pwd', 'otp'];
                break;
            case 'acr_mfa':
                unset($claims['amr']);
                $claims['acr'] = self::MFA_ACR;
                break;
            case 'no_email':
                unset($claims['email'], $claims['email_verified']);
                break;
            case 'email_unverified':
                $claims['email_verified'] = false;
                break;
            case 'email_verified_string':
                $claims['email_verified'] = 'true';
                break;
            case 'groups_string':
                $claims['groups'] = implode(' ', ['sales', 'g7-users']);
                break;
            case 'nonce_mismatch':
                $claims['nonce'] = 'mock-wrong-nonce';
                break;
            case 'aud_other':
                $claims['aud'] = 'mock-other-client';
                break;
            case 'azp_missing_multi_aud':
                $claims['aud'] = [$grant['client_id'], 'mock-other-client'];
                break;
            case 'expired':
                $claims['iat'] = $now - 3600;
                $claims['exp'] = $now - 1800;
                break;
            case 'iat_future':
                $claims['iat'] = $now + 900;
                $claims['exp'] = $now + 1800;
                break;
        }

        if ($grant['nonce'] === null || $grant['nonce'] === '') {
            unset($claims['nonce']);
        }

        return $claims;
    }
}
