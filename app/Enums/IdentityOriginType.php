<?php

namespace App\Enums;

/**
 * 본인인증 트리거 출처 유형 Enum.
 *
 * `identity_verification_logs.origin_type` 에 저장되는 인증 호출 경로 분류.
 * 이슈 #297 요구사항 8.3 에 따라 인증이 어디서 시작되었는지 추적합니다.
 *
 * @since 7.0.0-beta.5
 */
enum IdentityOriginType: string
{
    /** 라우트 미들웨어 (가장 흔한 경로) */
    case Route = 'route';

    /** Service 훅 (예: core.user.before_update) */
    case Hook = 'hook';

    /** identity_policies 정책 강제 */
    case Policy = 'policy';

    /** 사용자 정의 미들웨어 */
    case Middleware = 'middleware';

    /** 클라이언트가 직접 호출한 API */
    case Api = 'api';

    /** 모듈/플러그인 커스텀 호출 */
    case Custom = 'custom';

    /** 시스템 자동 (cron 등) */
    case System = 'system';

    /**
     * 모든 origin_type 값 배열.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 번역된 origin_type 라벨
     */
    public function label(): string
    {
        return __('identity.origin_types.'.$this->value);
    }
}
