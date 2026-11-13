<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 공용 값 테이블의 한 행 — 항목 하나에 대한 소유자 한 명(건)의 값.
 *
 * @since 7.2.0
 *
 * @property int $id
 * @property int $field_definition_id
 * @property string $owner_type
 * @property int $owner_id
 * @property string|null $value_text
 * @property string|null $value_index
 * @property string|null $value_number
 * @property Carbon|null $value_date
 * @property Carbon|null $value_date_end
 * @property array<mixed>|null $value_json
 * @property string|null $unique_key
 */
class FieldValue extends Model
{
    /** 소유자: 회원 */
    public const OWNER_USER = 'user';

    /** 값 컬럼 목록 — 저장 직렬화가 채우는 칸 */
    public const VALUE_COLUMNS = ['value_text', 'value_index', 'value_number', 'value_date', 'value_date_end', 'value_json'];

    /** 「기타(직접 입력)」 선택을 나타내는 색인 값 */
    public const OTHER_INDEX = '__other__';

    protected $table = 'field_values';

    protected $fillable = [
        'field_definition_id', 'owner_type', 'owner_id',
        'value_text', 'value_index', 'value_number', 'value_date', 'value_date_end', 'value_json', 'unique_key',
    ];

    /**
     * 속성 캐스팅을 정의합니다.
     *
     * @return array<string, string> 속성 => 캐스트 타입
     */
    protected function casts(): array
    {
        return [
            'value_date' => 'datetime',
            'value_date_end' => 'datetime',
            'value_json' => 'array',
        ];
    }

    /**
     * 이 값의 항목 정의.
     *
     * @return BelongsTo<FieldDefinition, $this> 정의 관계
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(FieldDefinition::class, 'field_definition_id');
    }
}
