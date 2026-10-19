<?php

namespace Plugins\Sirsoft\SocialLogin\Repositories;

use Plugins\Sirsoft\SocialLogin\Models\SocialLoginAttempt;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\AttemptRepositoryInterface;

/**
 * 소셜 로그인 시도 기록 저장소 구현.
 *
 * @since 1.0.0
 */
class AttemptRepository implements AttemptRepositoryInterface
{
    /**
     * 시도 1건을 기록합니다.
     *
     * 문자열 길이는 컬럼 한도에 맞춰 자른다 — 제공자 오류 본문이 길어도 기록이 실패하지 않게.
     *
     * @param  array<string, mixed>  $attributes  컬럼 값 (provider_key·intent·outcome 필수)
     * @return SocialLoginAttempt 기록한 행
     */
    public function record(array $attributes): SocialLoginAttempt
    {
        foreach (['error_code' => 50, 'error_message' => 500, 'user_agent' => 500, 'ip' => 45] as $column => $limit) {
            if (isset($attributes[$column]) && is_string($attributes[$column])) {
                $attributes[$column] = mb_substr($attributes[$column], 0, $limit);
            }
        }

        return SocialLoginAttempt::query()->create($attributes + ['created_at' => now()]);
    }
}
