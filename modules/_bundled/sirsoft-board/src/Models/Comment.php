<?php

namespace Modules\Sirsoft\Board\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
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
 * @property string $ip_address
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Comment extends Model
{
    use HasFactory, SoftDeletes;

    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'content' => ['label_key' => 'sirsoft-board::activity_log.fields.content', 'type' => 'text'],
        'is_secret' => ['label_key' => 'sirsoft-board::activity_log.fields.is_secret', 'type' => 'boolean'],
        'status' => ['label_key' => 'sirsoft-board::activity_log.fields.status', 'type' => 'enum', 'enum' => \Modules\Sirsoft\Board\Enums\PostStatus::class],
    ];

    /**
     * 테이블명
     */
    protected $table = 'board_comments';

    /**
     * 기본키
     *
     * DB 복합 PK (id, board_id) — MySQL 파티션 제약으로 파티션 키 포함 필수.
     * id는 AUTO_INCREMENT로 전역 유일하므로 Eloquent는 단일 PK로 동작.
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
    ];

    /**
     * 속성 캐스팅
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
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * 게시판과의 관계를 정의합니다.
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class, 'board_id');
    }

    /**
     * 게시글과의 관계를 정의합니다.
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    /**
     * 작성자와의 관계를 정의합니다 (회원).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 부모 댓글과의 관계를 정의합니다 (답글용).
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
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id', 'id');
    }
}
