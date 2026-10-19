<?php

namespace Plugins\Sirsoft\SocialLogin\Repositories\Contracts;

use Plugins\Sirsoft\SocialLogin\Models\SocialLoginAttempt;

/**
 * 소셜 로그인 시도 기록 저장소.
 *
 * @since 1.0.0
 */
interface AttemptRepositoryInterface
{
    /**
     * 시도 1건을 기록합니다.
     *
     * @param  array<string, mixed>  $attributes  컬럼 값 (provider_key·intent·outcome 필수)
     * @return SocialLoginAttempt 기록한 행
     */
    public function record(array $attributes): SocialLoginAttempt;
}
