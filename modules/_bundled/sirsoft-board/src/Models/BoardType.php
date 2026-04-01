<?php

namespace Modules\Sirsoft\Board\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 게시판 유형 모델
 *
 * @property int $id
 * @property string $slug
 * @property array $name
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class BoardType extends Model
{
    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'slug' => ['label_key' => 'sirsoft-board::activity_log.fields.slug', 'type' => 'text'],
    ];

    protected $table = 'board_types';

    protected $fillable = [
        'slug',
        'name',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
        ];
    }
}
