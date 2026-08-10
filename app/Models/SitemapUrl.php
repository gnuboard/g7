<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 사이트맵 URL 영속 모델
 *
 * sitemap_urls 테이블의 한 행 = 사이트맵에 노출되는 하나의 리소스 URL.
 * 리소스 단위 증분 갱신(공개=upsert / 비공개=remove)과 도메인 재쿼리 없는
 * 파일 재작성의 저장소입니다. 데이터 접근은 SitemapUrlRepository 를 경유합니다.
 *
 * @property int $id
 * @property string $resource_type
 * @property string|null $resource_id
 * @property string $loc
 * @property string $loc_hash
 * @property Carbon|null $lastmod
 * @property string|null $changefreq
 * @property float|null $priority
 * @property string $contributor
 * @property bool $is_visible
 */
class SitemapUrl extends Model
{
    /**
     * @var string 테이블명
     */
    protected $table = 'sitemap_urls';

    /**
     * @var list<string> 대량 할당 가능 컬럼
     */
    protected $fillable = [
        'resource_type',
        'resource_id',
        'loc',
        'loc_hash',
        'lastmod',
        'changefreq',
        'priority',
        'contributor',
        'is_visible',
    ];

    /**
     * 속성 캐스팅을 반환합니다.
     *
     * @return array<string, string> 캐스팅 맵
     */
    protected function casts(): array
    {
        return [
            'lastmod' => 'datetime',
            'priority' => 'float',
            'is_visible' => 'boolean',
        ];
    }
}
