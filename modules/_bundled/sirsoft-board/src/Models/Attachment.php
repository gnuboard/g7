<?php

namespace Modules\Sirsoft\Board\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * 첨부파일 모델
 *
 * board_attachments 단일 테이블 사용. board_id 컬럼으로 게시판 구분.
 * board_id=0: 임시 업로드 (게시글 저장 완료 시 실제 board_id로 업데이트)
 *
 * @property int $id
 * @property int $board_id
 * @property int|null $post_id
 * @property string|null $temp_key
 * @property string $hash
 * @property string $original_filename
 * @property string $stored_filename
 * @property string $disk
 * @property string $path
 * @property string $mime_type
 * @property int $size
 * @property string $collection
 * @property int $order
 * @property array|null $meta
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read string $download_url
 * @property-read string|null $preview_url
 * @property-read bool $is_image
 * @property-read string $size_formatted
 */
class Attachment extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * 테이블명
     */
    protected $table = 'board_attachments';

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
     * 대량 할당 가능한 속성
     */
    protected $fillable = [
        'board_id',
        'post_id',
        'temp_key',
        'hash',
        'original_filename',
        'stored_filename',
        'disk',
        'path',
        'mime_type',
        'size',
        'collection',
        'order',
        'meta',
        'created_by',
    ];

    /**
     * 속성 캐스팅
     */
    protected function casts(): array
    {
        return [
            'board_id' => 'integer',
            'size' => 'integer',
            'order' => 'integer',
            'meta' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * 모델 부트 메서드
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Attachment $attachment) {
            if (empty($attachment->hash)) {
                $attachment->hash = self::generateUniqueHash();
            }
        });
    }

    /**
     * 고유한 해시를 생성합니다.
     *
     * @return string 생성된 고유 해시
     */
    protected static function generateUniqueHash(): string
    {
        do {
            $hash = Str::random(12);
        } while (self::withTrashed()->where('hash', $hash)->exists());

        return $hash;
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
     * 업로더와의 관계를 정의합니다.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 다운로드 URL 반환 (API 서빙 URL)
     *
     * @return string 다운로드 URL
     */
    public function getDownloadUrlAttribute(): string
    {
        $boardSlug = $this->relationLoaded('board')
            ? $this->board->slug
            : Board::find($this->board_id)?->slug;

        return '/api/modules/sirsoft-board/boards/'.$boardSlug.'/attachment/'.$this->hash;
    }

    /**
     * 이미지 미리보기 URL 반환
     *
     * @return string|null 미리보기 URL 또는 null
     */
    public function getPreviewUrlAttribute(): ?string
    {
        if (! $this->is_image) {
            return null;
        }

        $boardSlug = $this->relationLoaded('board')
            ? $this->board->slug
            : Board::find($this->board_id)?->slug;

        return '/api/modules/sirsoft-board/boards/'.$boardSlug.'/attachment/'.$this->hash.'/preview';
    }

    /**
     * 이미지 여부 확인
     *
     * @return bool 이미지 여부
     */
    public function getIsImageAttribute(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * 파일 크기 포맷팅 (KB, MB 등)
     *
     * @return string 포맷된 파일 크기
     */
    public function getSizeFormattedAttribute(): string
    {
        $bytes = $this->size;
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}
