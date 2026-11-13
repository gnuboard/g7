<?php

namespace Modules\Sirsoft\Board\Models;

use App\Models\Concerns\HasUserOverrides;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 게시판 유형 모델.
 *
 * @property int $id
 * @property string $slug
 * @property array $name
 * @property array|null $user_overrides
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @since 7.0.0-beta.2 (HasUserOverrides 적용)
 */
class BoardType extends Model
{
    use HasUserOverrides;

    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'slug' => ['label_key' => 'sirsoft-board::activity_log.fields.slug', 'type' => 'text'],
    ];

    /**
     * 사용자 수정 보존 대상 필드.
     *
     * @var array<int, string>
     */
    protected array $trackableFields = ['name'];

    /**
     * 다국어 JSON 컬럼 — sub-key dot-path 단위 user_overrides 보존.
     *
     * @var array<int, string>
     */
    protected array $translatableTrackableFields = ['name'];

    protected $table = 'board_types';

    protected $fillable = [
        'slug',
        'name',
        'user_overrides',
    ];

    /**
     * 속성 캐스팅 규칙을 반환합니다.
     *
     * @return array<string, string> 컬럼별 캐스트 타입 (name, user_overrides 는 배열)
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'user_overrides' => 'array',
        ];
    }
}
