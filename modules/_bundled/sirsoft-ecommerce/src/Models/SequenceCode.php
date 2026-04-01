<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;

/**
 * 시퀀스 코드 이력 모델
 *
 * 발급된 모든 코드를 저장하여 재사용을 방지합니다.
 */
class SequenceCode extends Model
{
    /**
     * 테이블명
     */
    protected $table = 'ecommerce_sequence_codes';

    /**
     * updated_at 컬럼 비활성화
     */
    public const UPDATED_AT = null;

    /**
     * 대량 할당 가능한 속성
     */
    protected $fillable = [
        'type',
        'code',
    ];

    /**
     * 속성 캐스팅
     */
    protected $casts = [
        'type' => SequenceType::class,
        'created_at' => 'datetime',
    ];
}
