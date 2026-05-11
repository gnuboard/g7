<?php

namespace Database\Seeders\Sample;

use App\Database\Sample\AbstractIdentityVerificationLogSampleSeeder;
use Illuminate\Database\Eloquent\Builder;

/**
 * 코어 본인인증 이력 샘플 시더.
 *
 * source_type='core' 인 IdentityPolicy(auth / profile / account / admin 9종)만 사용하여
 * 코어 영역 본인인증 이력을 채운다. 모듈 정책은 각 모듈 자체 시더에서 처리.
 *
 * 수동 실행:
 *   php artisan db:seed --class="Database\Seeders\Sample\IdentityVerificationLogSeeder"
 *   php artisan db:seed --class="Database\Seeders\Sample\IdentityVerificationLogSeeder" --count=core_identity_verification_logs=300
 */
class IdentityVerificationLogSeeder extends AbstractIdentityVerificationLogSampleSeeder
{
    /**
     * 코어 정책만 필터링.
     *
     * @param  Builder  $query  IdentityPolicy 쿼리
     * @return Builder 코어 영역 쿼리
     */
    protected function applyPolicyScope(Builder $query): Builder
    {
        return $query->where('source_type', 'core');
    }

    /**
     * @return string 카운트 옵션 키
     */
    protected function countKey(): string
    {
        return 'core_identity_verification_logs';
    }

    /**
     * @return int 기본 건수
     */
    protected function defaultCount(): int
    {
        return 100;
    }

    /**
     * @return string 영역 라벨
     */
    protected function scopeLabel(): string
    {
        return '[코어]';
    }
}
