<?php

namespace Modules\Sirsoft\Board\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 게시판 반응 이력 모델.
 *
 * 사용자당 대상(게시글)에 1행. 등록=INSERT, 전환=UPDATE, 해제=DELETE
 * (이슈 #525 확정 03, 05). target_type/target_id 폴리모픽 구조 (확정 10).
 *
 * @property int $id
 * @property int $user_id
 * @property string $target_type
 * @property int $target_id
 * @property int $reaction_type_id
 * @property int|null $board_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class Reaction extends Model
{
    protected $table = 'board_reactions';

    protected $fillable = [
        'user_id',
        'target_type',
        'target_id',
        'reaction_type_id',
        'board_id',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'target_id' => 'integer',
            'reaction_type_id' => 'integer',
            'board_id' => 'integer',
        ];
    }

    /**
     * 반응한 사용자와의 관계.
     *
     * @return BelongsTo<User, Reaction>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 반응 유형과의 관계.
     *
     * @return BelongsTo<ReactionType, Reaction>
     */
    public function reactionType(): BelongsTo
    {
        return $this->belongsTo(ReactionType::class, 'reaction_type_id');
    }

    /**
     * 게시판과의 관계 (nullable).
     *
     * @return BelongsTo<Board, Reaction>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class, 'board_id');
    }

    /**
     * 특정 대상(타입+ID)의 반응만 조회하는 스코프.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $targetType  대상 타입 (예: post)
     * @param  int  $targetId  대상 ID
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByTarget($query, string $targetType, int $targetId)
    {
        return $query->where('target_type', $targetType)
            ->where('target_id', $targetId);
    }
}
