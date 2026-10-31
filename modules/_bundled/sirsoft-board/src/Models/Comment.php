<?php

namespace Modules\Sirsoft\Board\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Enums\TriggerType;

/**
 * 댓글 모델
 *
 * board_comments 단일 테이블 사용. board_id 컬럼으로 게시판 구분.
 *
 * @property int $id
 * @property int $board_id
 * @property int $post_id
 * @property int|null $user_id
 * @property int|null $parent_id
 * @property string|null $author_name
 * @property string|null $password
 * @property string $content
 * @property bool $is_secret
 * @property PostStatus $status
 * @property TriggerType|null $trigger_type
 * @property array|null $action_logs
 * @property int $depth
 * @property array|null $reaction_counts
 * @property string|null $ip_address 작성 IP (보유기간 경과 시 NULL 로 파기)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Comment extends Model
{
    use HasFactory, SoftDeletes;

    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'content' => ['label_key' => 'sirsoft-board::activity_log.fields.content', 'type' => 'text'],
        'is_secret' => ['label_key' => 'sirsoft-board::activity_log.fields.is_secret', 'type' => 'boolean'],
        'status' => ['label_key' => 'sirsoft-board::activity_log.fields.status', 'type' => 'enum', 'enum' => PostStatus::class],
    ];

    /**
     * 테이블명
     */
    protected $table = 'board_comments';

    /**
     * 기본키
     *
     * 단일 PK(id). id는 AUTO_INCREMENT로 전역 유일.
     */
    protected $primaryKey = 'id';

    /**
     * 타임스탬프 사용 여부
     */
    public $timestamps = true;

    /**
     * API 응답에서 숨길 속성
     */
    protected $hidden = ['password'];

    /**
     * 대량 할당 가능한 속성
     */
    protected $fillable = [
        'board_id',
        'post_id',
        'user_id',
        'parent_id',
        'author_name',
        'password',
        'content',
        'is_secret',
        'status',
        'trigger_type',
        'action_logs',
        'depth',
        'ip_address',
        'replies_count',
        'reaction_counts',
    ];

    /**
     * 속성 캐스팅
     *
     * @return array<string, mixed> 캐스팅 정의
     */
    protected function casts(): array
    {
        return [
            'board_id' => 'integer',
            'is_secret' => 'boolean',
            'status' => PostStatus::class,
            'trigger_type' => TriggerType::class,
            'action_logs' => 'array',
            'depth' => 'integer',
            'replies_count' => 'integer',
            // 반응 유형별 개수 캐시 (키는 유형 ID 문자열 — board_posts.reaction_counts 와 같은 규약)
            'reaction_counts' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * 게시판과의 관계를 정의합니다.
     *
     * @return BelongsTo<Board, Comment> 게시판 관계
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class, 'board_id');
    }

    /**
     * 게시글과의 관계를 정의합니다.
     *
     * @return BelongsTo<Post, Comment> 게시글 관계
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    /**
     * 작성자와의 관계를 정의합니다 (회원).
     *
     * @return BelongsTo<User, Comment> 작성자 관계
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 부모 댓글과의 관계를 정의합니다 (답글용).
     *
     * @return BelongsTo<Comment, Comment> 부모 댓글 관계
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * 답글 목록과의 관계를 정의합니다.
     *
     * board_id 조건은 Eager loading 호환을 위해 관계에서 제외하고
     * Repository의 Eager loading 클로저에서 명시적으로 전달합니다.
     *
     * @return HasMany<Comment> 답글 목록 관계
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id', 'id');
    }
}
