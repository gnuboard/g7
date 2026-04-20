<?php

namespace Modules\Sirsoft\Board\Models;

use App\Casts\AsUnicodeJson;
use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Sirsoft\Board\Database\Factories\BoardFactory;
use Modules\Sirsoft\Board\Enums\BoardOrderBy;
use Modules\Sirsoft\Board\Enums\OrderDirection;
use Modules\Sirsoft\Board\Enums\SecretMode;

class Board extends Model
{
    use HasFactory;

    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'is_active' => ['label_key' => 'sirsoft-board::activity_log.fields.is_active', 'type' => 'boolean'],
        'per_page' => ['label_key' => 'sirsoft-board::activity_log.fields.per_page', 'type' => 'number'],
        'per_page_mobile' => ['label_key' => 'sirsoft-board::activity_log.fields.per_page_mobile', 'type' => 'number'],
        'order_by' => ['label_key' => 'sirsoft-board::activity_log.fields.order_by', 'type' => 'enum', 'enum' => \Modules\Sirsoft\Board\Enums\BoardOrderBy::class],
        'order_direction' => ['label_key' => 'sirsoft-board::activity_log.fields.order_direction', 'type' => 'enum', 'enum' => \Modules\Sirsoft\Board\Enums\OrderDirection::class],
        'type' => ['label_key' => 'sirsoft-board::activity_log.fields.type', 'type' => 'text'],
        'show_view_count' => ['label_key' => 'sirsoft-board::activity_log.fields.show_view_count', 'type' => 'boolean'],
        'secret_mode' => ['label_key' => 'sirsoft-board::activity_log.fields.secret_mode', 'type' => 'enum', 'enum' => \Modules\Sirsoft\Board\Enums\SecretMode::class],
        'use_comment' => ['label_key' => 'sirsoft-board::activity_log.fields.use_comment', 'type' => 'boolean'],
        'use_reply' => ['label_key' => 'sirsoft-board::activity_log.fields.use_reply', 'type' => 'boolean'],
        'max_reply_depth' => ['label_key' => 'sirsoft-board::activity_log.fields.max_reply_depth', 'type' => 'number'],
        'use_report' => ['label_key' => 'sirsoft-board::activity_log.fields.use_report', 'type' => 'boolean'],
        'use_file_upload' => ['label_key' => 'sirsoft-board::activity_log.fields.use_file_upload', 'type' => 'boolean'],
        'max_file_size' => ['label_key' => 'sirsoft-board::activity_log.fields.max_file_size', 'type' => 'number'],
        'max_file_count' => ['label_key' => 'sirsoft-board::activity_log.fields.max_file_count', 'type' => 'number'],
    ];

    /**
     * 팩토리 생성
     */
    protected static function newFactory(): BoardFactory
    {
        return BoardFactory::new();
    }

    /**
     * 테이블명
     */
    protected $table = 'boards';

    /**
     * 기본키
     */
    protected $primaryKey = 'id';

    /**
     * 타임스탬프 사용 여부
     */
    public $timestamps = true;

    /**
     * 모델 부트
     */
    protected static function boot(): void
    {
        parent::boot();

        // slug 필드 변경 방지 (생성 후 수정 불가)
        static::updating(function ($board) {
            if ($board->isDirty('slug')) {
                $board->slug = $board->getOriginal('slug');
            }
        });
    }

    /**
     * 대량 할당 가능한 속성
     */
    protected $fillable = [
        // 기본 정보
        'name',
        'slug',
        'is_active',
        'description',

        // 게시글 설정
        'per_page',
        'per_page_mobile',
        'order_by',
        'order_direction',

        // 타입 설정
        'type',

        // 기능 설정
        'categories',
        'show_view_count',
        'secret_mode',
        'use_comment',
        'use_reply',
        'max_reply_depth',
        'use_report',
        'new_display_hours',

        // 입력 제한 설정
        'min_title_length',
        'max_title_length',
        'min_content_length',
        'max_content_length',
        'min_comment_length',
        'max_comment_length',

        // 파일 업로드 설정
        'use_file_upload',
        'max_file_size',
        'max_file_count',
        'allowed_extensions',

        // 댓글 설정
        'comment_order',
        'max_comment_depth',

        // 알림 설정
        'notify_author',
        'notify_admin_on_post',

        // 기타 설정
        'blocked_keywords',

        // 생성자/수정자
        'created_by',
        'updated_by',
    ];

    /**
     * 속성 캐스팅
     */
    protected function casts(): array
    {
        return [
            'name' => AsUnicodeJson::class,
            'description' => AsUnicodeJson::class,
            'categories' => 'array',
            'allowed_extensions' => 'array',
            'blocked_keywords' => 'array',
            'is_active' => 'boolean',
            'show_view_count' => 'boolean',
            'use_comment' => 'boolean',
            'use_reply' => 'boolean',
            'max_reply_depth' => 'integer',
            'use_report' => 'boolean',
            'use_file_upload' => 'boolean',
            'notify_author' => 'boolean',
            'notify_admin_on_post' => 'boolean',
            'secret_mode' => SecretMode::class,
            'order_by' => BoardOrderBy::class,
            'order_direction' => OrderDirection::class,
            'max_comment_depth' => 'integer',
            'comment_order' => OrderDirection::class,
        ];
    }

    /**
     * 생성자와의 관계를 정의합니다.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 수정자와의 관계를 정의합니다.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * 게시글 목록과의 관계를 정의합니다.
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'board_id');
    }

    /**
     * 댓글 목록과의 관계를 정의합니다.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'board_id');
    }

    /**
     * 첨부파일 목록과의 관계를 정의합니다.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'board_id');
    }

    /**
     * 신고 목록과의 관계를 정의합니다.
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'board_id');
    }

    /**
     * 지정된 로케일의 게시판명 반환
     */
    public function getLocalizedName(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        if (! is_array($this->name)) {
            return (string) $this->name;
        }

        return $this->name[$locale]
            ?? $this->name[config('app.fallback_locale')]
            ?? (! empty($this->name) ? array_values($this->name)[0] : '')
            ?? '';
    }

    /**
     * 현재 로케일의 게시판명 반환
     */
    protected function localizedName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getLocalizedName()
        );
    }

    /**
     * 지정된 로케일의 게시판 설명 반환
     *
     * @param  string|null  $locale  로케일 (null이면 현재 로케일)
     * @return string 지정된 로케일의 게시판 설명
     */
    public function getLocalizedDescription(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        if (! is_array($this->description)) {
            return (string) $this->description;
        }

        return $this->description[$locale]
            ?? $this->description[config('app.fallback_locale')]
            ?? (! empty($this->description) ? array_values($this->description)[0] : '')
            ?? '';
    }

    /**
     * 현재 로케일의 게시판 설명 반환
     */
    protected function localizedDescription(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getLocalizedDescription()
        );
    }

    /**
     * 게시판에서 분류(카테고리)를 사용하는지 확인합니다.
     *
     * @return bool 분류 사용 여부
     */
    public function hasCategories(): bool
    {
        return is_array($this->categories) && ! empty($this->categories);
    }

    /**
     * 게시판의 권한 정보를 반환합니다.
     *
     * g7_permissions 및 role_permissions 테이블에서 권한 정보를 조회하여
     * 각 권한별로 할당된 역할 identifier 배열을 반환합니다.
     *
     * @return array 권한 정보 (키: permission_key, 값: [role_identifiers] or null)
     */
    protected function permissions(): Attribute
    {
        return Attribute::make(
            get: function () {
                $permissionDefinitions = config('sirsoft-board.board_permission_definitions', []);
                $permissions = [];

                foreach (array_keys($permissionDefinitions) as $key) {
                    $identifier = "sirsoft-board.{$this->slug}.{$key}";

                    // 권한 조회
                    $permission = \App\Models\Permission::where('identifier', $identifier)->first();

                    if (! $permission) {
                        // 권한이 없으면 null (전체 허용)
                        $permissions[$key] = null;

                        continue;
                    }

                    // 할당된 역할들의 identifier 조회
                    $roleIdentifiers = $permission->roles()->pluck('identifier')->toArray();

                    if (empty($roleIdentifiers)) {
                        // 역할이 없으면 null (전체 허용)
                        $permissions[$key] = null;
                    } else {
                        // 역할 identifier 배열
                        $permissions[$key] = $roleIdentifiers;
                    }
                }

                return $permissions;
            }
        );
    }
}
