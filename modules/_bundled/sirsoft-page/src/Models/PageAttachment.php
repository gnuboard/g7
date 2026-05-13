<?php

namespace Modules\Sirsoft\Page\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Sirsoft\Page\Database\Factories\PageAttachmentFactory;

class PageAttachment extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * 팩토리 클래스를 반환합니다.
     */
    protected static function newFactory(): PageAttachmentFactory
    {
        return PageAttachmentFactory::new();
    }

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'page_attachments';

    /**
     * 모델 부트
     */
    protected static function boot(): void
    {
        parent::boot();

        // 생성 시 hash 자동 생성 (URL용 고유 키)
        static::creating(function (self $model) {
            if (empty($model->hash)) {
                $model->hash = self::generateHash();
            }
        });
    }

    /**
     * 고유 해시를 생성합니다.
     *
     * @return string 12자리 고유 해시
     */
    public static function generateHash(): string
    {
        do {
            $hash = substr(bin2hex(random_bytes(6)), 0, 12);
        } while (self::where('hash', $hash)->exists());

        return $hash;
    }

    /**
     * 대량 할당 가능한 속성
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'page_id',
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
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'size' => 'integer',
            'order' => 'integer',
        ];
    }

    /**
     * 페이지와의 관계를 정의합니다.
     *
     * @return BelongsTo<Page, PageAttachment>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    /**
     * 업로더와의 관계를 정의합니다.
     *
     * @return BelongsTo<User, PageAttachment>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 이미지 파일 여부를 확인합니다.
     *
     * @return bool 이미지 여부
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * 다운로드 URL을 반환합니다.
     *
     * @return string 다운로드 URL
     */
    public function getDownloadUrlAttribute(): string
    {
        return '/api/modules/sirsoft-page/pages/attachment/'.$this->hash;
    }

    /**
     * 이미지 미리보기 URL을 반환합니다.
     *
     * @return string|null 미리보기 URL (이미지가 아니면 null)
     */
    public function getPreviewUrlAttribute(): ?string
    {
        if (! $this->isImage()) {
            return null;
        }

        return '/api/modules/sirsoft-page/pages/attachment/'.$this->hash.'/preview';
    }
}
