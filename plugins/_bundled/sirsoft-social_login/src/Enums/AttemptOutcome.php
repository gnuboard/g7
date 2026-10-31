<?php

namespace Plugins\Sirsoft\SocialLogin\Enums;

/**
 * 소셜 로그인 시도 결과
 *
 * social_login_attempts.outcome 컬럼 값. handoff = 코어 로그인 인계로 넘김, pending = 가입 대기, denied = 정책상 거절, failed = 오류.
 *
 * @since 1.0.0
 */
enum AttemptOutcome: string
{
    case Login = 'login';
    case Signup = 'signup';
    case Link = 'link';
    case Pending = 'pending';
    case Handoff = 'handoff';
    case Denied = 'denied';
    case Failed = 'failed';

    /**
     * 모든 케이스의 문자열 값 목록을 반환합니다.
     *
     * @return array<int, string> 값 목록
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
