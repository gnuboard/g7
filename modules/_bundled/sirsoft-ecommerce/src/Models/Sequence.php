<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Sirsoft\Ecommerce\Enums\SequenceAlgorithm;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;

/**
 * 시퀀스 모델
 *
 * 상품코드, 주문번호 등의 채번을 관리합니다.
 */
class Sequence extends Model
{
    /**
     * 테이블명
     */
    protected $table = 'ecommerce_sequences';

    /**
     * 대량 할당 가능한 속성
     */
    protected $fillable = [
        'type',
        'algorithm',
        'prefix',
        'current_value',
        'increment',
        'min_value',
        'max_value',
        'cycle',
        'pad_length',
        'max_history_count',
        'date_format',
        'last_reset_date',
    ];

    /**
     * 속성 캐스팅
     */
    protected $casts = [
        'type' => SequenceType::class,
        'algorithm' => SequenceAlgorithm::class,
        'current_value' => 'integer',
        'increment' => 'integer',
        'min_value' => 'integer',
        'max_value' => 'integer',
        'cycle' => 'boolean',
        'pad_length' => 'integer',
        'max_history_count' => 'integer',
        'last_reset_date' => 'date',
    ];

    /**
     * 타입으로 조회 스코프
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param SequenceType $type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, SequenceType $type)
    {
        return $query->where('type', $type->value);
    }

    /**
     * 알고리즘으로 조회 스코프
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param SequenceAlgorithm $algorithm
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfAlgorithm($query, SequenceAlgorithm $algorithm)
    {
        return $query->where('algorithm', $algorithm->value);
    }
}
