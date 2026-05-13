<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use App\Database\Sample\AbstractIdentityVerificationLogSampleSeeder;
use Illuminate\Database\Eloquent\Builder;

/**
 * 이커머스 모듈 본인인증 이력 샘플 시더.
 *
 * source_identifier='sirsoft-ecommerce' 인 IdentityPolicy(payment.cancel/approve/confirm_deposit,
 * checkout.before_pay)만 사용하여 결제·체크아웃 영역 본인인증 이력을 채운다.
 *
 * 수동 실행:
 *   php artisan module:seed sirsoft-ecommerce \
 *     --class="Sample\\IdentityVerificationLogSeeder" --sample
 */
class IdentityVerificationLogSeeder extends AbstractIdentityVerificationLogSampleSeeder
{
    /**
     * 이커머스 모듈 정책만 필터링.
     *
     * @param  Builder  $query  IdentityPolicy 쿼리
     * @return Builder 이커머스 영역 쿼리
     */
    protected function applyPolicyScope(Builder $query): Builder
    {
        return $query->where('source_identifier', 'sirsoft-ecommerce');
    }

    /**
     * @return string 카운트 옵션 키
     */
    protected function countKey(): string
    {
        return 'ecommerce_identity_verification_logs';
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
        return '[이커머스]';
    }
}
