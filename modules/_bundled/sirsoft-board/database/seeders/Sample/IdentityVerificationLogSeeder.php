<?php

namespace Modules\Sirsoft\Board\Database\Seeders\Sample;

use App\Database\Sample\AbstractIdentityVerificationLogSampleSeeder;
use Illuminate\Database\Eloquent\Builder;

/**
 * 게시판 모듈 본인인증 이력 샘플 시더.
 *
 * source_identifier='sirsoft-board' 인 IdentityPolicy(post.blind/delete, report.bulk_action/delete)만
 * 사용하여 게시판 운영 영역 본인인증 이력을 채운다.
 *
 * 수동 실행:
 *   php artisan module:seed sirsoft-board \
 *     --class="Sample\\IdentityVerificationLogSeeder" --sample
 */
class IdentityVerificationLogSeeder extends AbstractIdentityVerificationLogSampleSeeder
{
    /**
     * 게시판 모듈 정책만 필터링.
     *
     * @param  Builder  $query  IdentityPolicy 쿼리
     * @return Builder 게시판 영역 쿼리
     */
    protected function applyPolicyScope(Builder $query): Builder
    {
        return $query->where('source_identifier', 'sirsoft-board');
    }

    /**
     * @return string 카운트 옵션 키
     */
    protected function countKey(): string
    {
        return 'board_identity_verification_logs';
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
        return '[게시판]';
    }
}
