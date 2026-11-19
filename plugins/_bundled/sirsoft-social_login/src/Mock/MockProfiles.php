<?php

namespace Plugins\Sirsoft\SocialLogin\Mock;

/**
 * 모의 제공자 변종 카탈로그 — 제공자 모양별 사용자 정보 응답을 만듭니다.
 *
 * 변종은 계획 부록 C 와 같다. 모양(style)은 실제 드라이버가 읽는 응답 형태(kakao·naver·google·facebook·oidc)다.
 *
 * @since 1.0.0
 */
final class MockProfiles
{
    /** 변종 목록 */
    public const VARIANTS = [
        'full_verified', 'full_unverified', 'no_email', 'masked_email', 'nickname_only',
        'string_bool', 'existing_email', 'error_body', 'deny',
    ];

    /** 모양 목록 */
    public const STYLES = ['kakao', 'naver', 'google', 'facebook', 'oidc'];

    /**
     * 사용자 정보 응답을 만듭니다.
     *
     * @param  string  $style  응답 모양
     * @param  string  $variant  변종
     * @param  string  $subject  모의 사용자 ID
     * @param  string  $email  이메일 (existing_email 이면 폼 입력값)
     * @return array{status: int, body: array<string, mixed>} HTTP 상태와 본문
     */
    public static function userinfo(string $style, string $variant, string $subject, string $email): array
    {
        if ($variant === 'error_body') {
            return match ($style) {
                'naver' => ['status' => 200, 'body' => ['resultcode' => '024', 'message' => 'Authentication failed']],
                'kakao' => ['status' => 401, 'body' => ['msg' => 'this access token does not exist', 'code' => -401]],
                'facebook' => ['status' => 400, 'body' => ['error' => ['message' => 'Invalid OAuth access token.', 'type' => 'OAuthException', 'code' => 190]]],
                default => ['status' => 401, 'body' => ['error' => 'invalid_token', 'error_description' => 'mock error body']],
            };
        }

        $verified = $variant !== 'full_unverified';
        $hasEmail = ! in_array($variant, ['no_email', 'nickname_only'], true);
        $name = $variant === 'nickname_only' ? null : 'Mock User';
        $nickname = 'mocknick';
        $picture = 'https://example.test/mock-avatar.png';

        return ['status' => 200, 'body' => match ($style) {
            'kakao' => self::kakao($variant, $subject, $email, $verified, $nickname, $picture),
            'naver' => ['resultcode' => '00', 'message' => 'success', 'response' => array_filter([
                'id' => $subject,
                'email' => $hasEmail ? $email : null,
                'name' => $name,
                'nickname' => $nickname,
                'profile_image' => $picture,
            ], fn ($v) => $v !== null)],
            'facebook' => array_filter([
                'id' => $subject,
                'name' => $name ?? $nickname,
                'email' => $hasEmail ? $email : null,
                'picture' => ['data' => ['url' => $picture]],
            ], fn ($v) => $v !== null),
            default => array_filter([
                'sub' => $subject,
                'email' => $hasEmail ? $email : null,
                'email_verified' => $hasEmail ? ($variant === 'string_bool' ? ($verified ? 'true' : 'false') : $verified) : null,
                'name' => $name,
                'preferred_username' => $nickname,
                'picture' => $picture,
            ], fn ($v) => $v !== null),
        }];
    }

    /**
     * 카카오 모양 응답을 만듭니다.
     *
     * @param  string  $variant  변종
     * @param  string  $subject  사용자 ID
     * @param  string  $email  이메일
     * @param  bool  $verified  검증 여부
     * @param  string  $nickname  별명
     * @param  string  $picture  사진 주소
     * @return array<string, mixed> 응답 본문
     */
    private static function kakao(string $variant, string $subject, string $email, bool $verified, string $nickname, string $picture): array
    {
        $account = ['profile' => ['nickname' => $nickname, 'profile_image_url' => $picture]];

        if ($variant === 'no_email' || $variant === 'nickname_only') {
            $account['email_needs_agreement'] = true;
        } elseif ($variant === 'masked_email') {
            $account += ['email_needs_agreement' => false, 'email' => 'ka***@kakao.com', 'is_email_valid' => false, 'is_email_verified' => true];
        } else {
            $account += ['email_needs_agreement' => false, 'email' => $email, 'is_email_valid' => true, 'is_email_verified' => $verified];
        }

        return ['id' => ctype_digit($subject) ? (int) $subject : $subject, 'kakao_account' => $account];
    }
}
