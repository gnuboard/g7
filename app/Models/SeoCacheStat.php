<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * SEO 캐시 통계 모델
 *
 * 캐시 히트/미스 이력을 기록하고 통계를 조회합니다.
 *
 * @property int $id
 * @property string $url
 * @property string $locale
 * @property string|null $layout_name
 * @property string|null $module_identifier
 * @property string $type
 * @property int|null $response_time_ms
 * @property Carbon $created_at
 */
class SeoCacheStat extends Model
{
    /**
     * @var string 테이블명
     */
    protected $table = 'seo_cache_stats';

    /**
     * @var bool updated_at 컬럼 사용 안 함
     */
    public const UPDATED_AT = null;

    /**
     * @var array<int, string> 대량 할당 가능 필드
     */
    protected $fillable = [
        'url',
        'locale',
        'layout_name',
        'module_identifier',
        'type',
        'response_time_ms',
    ];

    /**
     * 속성 캐스팅 정의
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'response_time_ms' => 'integer',
        ];
    }

    /**
     * 캐시 히트만 조회하는 스코프
     *
     * @param Builder $query 쿼리 빌더
     * @return Builder
     */
    public function scopeHits(Builder $query): Builder
    {
        return $query->where('type', 'hit');
    }

    /**
     * 캐시 미스만 조회하는 스코프
     *
     * @param Builder $query 쿼리 빌더
     * @return Builder
     */
    public function scopeMisses(Builder $query): Builder
    {
        return $query->where('type', 'miss');
    }

    /**
     * 특정 레이아웃의 통계를 조회하는 스코프
     *
     * @param Builder $query 쿼리 빌더
     * @param string $layoutName 레이아웃명
     * @return Builder
     */
    public function scopeForLayout(Builder $query, string $layoutName): Builder
    {
        return $query->where('layout_name', $layoutName);
    }

    /**
     * 특정 모듈의 통계를 조회하는 스코프
     *
     * @param Builder $query 쿼리 빌더
     * @param string $moduleIdentifier 모듈 식별자
     * @return Builder
     */
    public function scopeForModule(Builder $query, string $moduleIdentifier): Builder
    {
        return $query->where('module_identifier', $moduleIdentifier);
    }

    /**
     * 특정 시점 이후의 통계만 조회하는 스코프
     *
     * @param Builder $query 쿼리 빌더
     * @param Carbon $since 기준 시점
     * @return Builder
     */
    public function scopeSince(Builder $query, Carbon $since): Builder
    {
        return $query->where('created_at', '>=', $since);
    }
}
