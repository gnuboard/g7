<?php

namespace Modules\Sirsoft\Board\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 게시판 일별 집계 모델
 *
 * board_stats 테이블 — 하루 1행. 대시보드 스케쥴러가 upsert 하고
 * 대시보드 API(overview/post-graph)가 읽는다. 원본 풀스캔을 피하기 위한 집계 캐시.
 *
 * @property int $id
 * @property \Illuminate\Support\Carbon $date
 * @property int $post_count
 * @property int $comment_count
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class BoardStat extends Model
{
    /**
     * 테이블명
     */
    protected $table = 'board_stats';

    /**
     * 대량 할당 가능 필드
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'date',
        'post_count',
        'comment_count',
    ];

    /**
     * 타입 캐스팅
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'post_count' => 'integer',
            'comment_count' => 'integer',
        ];
    }
}
