<?php

namespace Modules\Sirsoft\Board\Models;

use App\Extension\HookManager;
use App\Models\User;
use App\Search\Contracts\FulltextSearchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Enums\TriggerType;

/**
 * 게시글 모델
 *
 * board_posts 단일 테이블 사용. board_id 컬럼으로 게시판 구분.
 */
class Post extends Model implements FulltextSearchable
{
    use HasFactory, Searchable, SoftDeletes;

    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'category' => ['label_key' => 'sirsoft-board::activity_log.fields.category', 'type' => 'text'],
        'title' => ['label_key' => 'sirsoft-board::activity_log.fields.title', 'type' => 'text'],
        'content_mode' => ['label_key' => 'sirsoft-board::activity_log.fields.content_mode', 'type' => 'text'],
        'is_notice' => ['label_key' => 'sirsoft-board::activity_log.fields.is_notice', 'type' => 'boolean'],
        'is_secret' => ['label_key' => 'sirsoft-board::activity_log.fields.is_secret', 'type' => 'boolean'],
        'status' => ['label_key' => 'sirsoft-board::activity_log.fields.status', 'type' => 'enum', 'enum' => \Modules\Sirsoft\Board\Enums\PostStatus::class],
    ];

    /**
     * 테이블명
     */
    protected $table = 'board_posts';

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
        'category',
        'title',
        'content',
        'content_mode',
        'user_id',
        'author_name',
        'password',
        'ip_address',
        'is_notice',
        'is_secret',
        'status',
        'trigger_type',
        'action_logs',
        'view_count',
        'parent_id',
        'depth',
    ];

    /**
     * 속성 캐스팅
     */
    protected function casts(): array
    {
        return [
            'board_id' => 'integer',
            'content_mode' => 'string',
            'trigger_type' => TriggerType::class,
            'is_notice' => 'boolean',
            'is_secret' => 'boolean',
            'status' => PostStatus::class,
            'action_logs' => 'array',
            'view_count' => 'integer',
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
     * 작성자와의 관계를 정의합니다 (회원).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 부모 게시글과의 관계를 정의합니다 (답글용).
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
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * 댓글 목록과의 관계를 정의합니다.
     *
     * board_id 조건은 Eager loading 호환을 위해 관계에서 제외하고
     * Repository의 Eager loading 클로저에서 명시적으로 전달합니다.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id')
            ->with(['user', 'parent']);
    }

    /**
     * 첨부파일 목록과의 관계를 정의합니다.
     *
     * board_id 조건은 Eager loading 호환을 위해 관계에서 제외하고
     * Repository의 Eager loading 클로저에서 명시적으로 전달합니다.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'post_id')
            ->orderBy('order');
    }

    /**
     * 목록 썸네일용 첫 번째 이미지 첨부파일과의 관계를 정의합니다.
     *
     * 전체 attachments를 eager loading하는 대신 이미지 1건만 가져와 성능을 확보합니다.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function thumbnailAttachment(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Attachment::class, 'post_id')
            ->where('mime_type', 'like', 'image/%')
            ->orderBy('order');
    }

    /**
     * 신규 게시글 여부를 확인합니다.
     *
     * @return bool 신규 게시글 여부
     */
    public function isNew(): bool
    {
        if (! $this->created_at) {
            return false;
        }

        // board relation이 로드되어 있으면 해당 설정 사용, 없으면 기본값 24시간
        $newDisplayHours = $this->relationLoaded('board')
            ? ($this->board->new_display_hours ?? 24)
            : 24;

        return $this->created_at->greaterThan(now()->subHours($newDisplayHours));
    }

    // ─── Scout 조건부 Searchable (외부 검색엔진용) ──────────

    /**
     * 외부 검색엔진(Meilisearch 등) 사용 시에만 검색 인덱스에 포함합니다.
     *
     * MySQL FULLTEXT는 board_posts에 이미 적용되어 있으며, 외부 검색엔진 플러그인
     * 설치 시 자동 활용됩니다.
     *
     * @return bool
     */
    public function shouldBeSearchable(): bool
    {
        return config('scout.driver') !== 'mysql-fulltext';
    }

    /**
     * MySQL FULLTEXT 엔진에서는 인덱스 업데이트가 불필요합니다.
     *
     * @return bool
     */
    public function searchIndexShouldBeUpdated(): bool
    {
        $default = config('scout.driver') !== 'mysql-fulltext';

        return HookManager::applyFilters(
            'sirsoft-board.search.post.index_should_update',
            $default,
            $this
        );
    }

    /**
     * 검색 인덱스용 배열을 반환합니다.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'title' => $this->title,
            'content' => $this->content,
        ];
    }

    // ─── FulltextSearchable 구현 ─────────────────────────

    /**
     * FULLTEXT 검색 대상 컬럼 목록을 반환합니다.
     *
     * @return array<string>
     */
    public function searchableColumns(): array
    {
        return ['title', 'content'];
    }

    /**
     * 컬럼별 검색 가중치를 반환합니다.
     *
     * @return array<string, float>
     */
    public function searchableWeights(): array
    {
        return [
            'title' => 2.0,
            'content' => 1.0,
        ];
    }
}
