<?php

namespace Modules\Sirsoft\Board\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
 * @property Carbon $created_at
 * @property Carbon $updated_at
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

    /**
     * 속성 캐스팅
     *
     * @return array<string, mixed> 캐스팅 정의
     */
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
     * 게시글 대상과의 관계.
     *
     * morph map 없는 폴리모픽이라 target_type 을 보지 않는 단순 BelongsTo 다 —
     * with('post') 로 일괄 로드하면 댓글 반응까지 board_posts 에서 찾아 잘못된 값을 붙인다.
     * 목록 경로는 ReactionRepository::hydrateTargets() 가 타입별로 나눠 채운다.
     *
     * @return BelongsTo<Post, Reaction>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'target_id');
    }

    /**
     * 댓글 대상과의 관계.
     *
     * post() 와 같은 이유로 with() 금지 — hydrateTargets() 를 쓴다.
     *
     * @return BelongsTo<Comment, Reaction>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'target_id');
    }

    /**
     * 특정 대상(타입+ID)의 반응만 조회하는 스코프.
     *
     * @param  Builder  $query
     * @param  string  $targetType  대상 타입 (예: post)
     * @param  int  $targetId  대상 ID
     * @return Builder
     */
    public function scopeByTarget($query, string $targetType, int $targetId)
    {
        return $query->where('target_type', $targetType)
            ->where('target_id', $targetId);
    }
}
