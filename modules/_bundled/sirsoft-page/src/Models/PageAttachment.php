<?php

namespace Modules\Sirsoft\Page\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;
use Modules\Sirsoft\Page\Database\Factories\PageAttachmentFactory;

class PageAttachment extends Model
{
    use HasFactory;

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
     * 다운로드 URL을 반환합니다 (공개 hash 라우트).
     *
     * @return string 다운로드 URL
     */
    public function getDownloadUrlAttribute(): string
    {
        return $this->downloadUrl();
    }

    /**
     * 이미지 미리보기 URL을 반환합니다 (공개 hash 라우트).
     *
     * @return string|null 미리보기 URL (이미지가 아니면 null)
     */
    public function getPreviewUrlAttribute(): ?string
    {
        return $this->previewUrl();
    }

    /**
     * 다운로드 URL을 반환합니다.
     *
     * 썸네일 <img>·다운로드는 브라우저 직접 GET 이라 토큰을 실을 수 없으므로,
     * 게시판·이커머스 표준과 동일하게 공개 hash 라우트로 단일화한다.
     * 미발행 콘텐츠 다운로드 차단은 공개 라우트 내부의 권한 게이트가 담당한다.
     *
     * @return string 다운로드 URL
     */
    public function downloadUrl(): string
    {
        return route(
            'api.modules.sirsoft-page.pages.attachment.download',
            ['hash' => $this->hash],
            absolute: false
        );
    }

    /**
     * 이미지 미리보기 URL을 반환합니다 (공개 hash 라우트).
     *
     * 브라우저 <img src> 는 Authorization 헤더를 실을 수 없으므로, 미발행 페이지의
     * 첨부를 권한자 화면(관리자 상세 등)에 표시할 때는 한시 서명 URL 을 발급한다.
     * 서명 URL 은 게이트를 통과한 응답 직렬화 시점에만 발급되고(PageResource),
     * 서빙 엔드포인트가 유효 서명을 무인증 허용한다 — 무서명 게이트는 종전과 동일.
     *
     * @param  bool  $signed  한시 서명 URL 발급 여부 (미발행 첨부의 <img> 렌더용)
     * @return string|null 미리보기 URL (이미지가 아니면 null)
     */
    public function previewUrl(bool $signed = false): ?string
    {
        if (! $this->isImage()) {
            return null;
        }

        if ($signed) {
            return URL::temporarySignedRoute(
                'api.modules.sirsoft-page.pages.attachment.preview',
                now()->addMinutes(self::SIGNED_PREVIEW_TTL_MINUTES),
                ['hash' => $this->hash],
                absolute: false
            );
        }

        return route(
            'api.modules.sirsoft-page.pages.attachment.preview',
            ['hash' => $this->hash],
            absolute: false
        );
    }

    /**
     * 서명 preview URL 의 유효 시간(분).
     *
     * 관리자 상세 화면이 열려 있는 동안 썸네일이 유지될 만큼 길고,
     * URL 유출 시 노출 창을 좁힐 만큼 짧은 값.
     */
    public const SIGNED_PREVIEW_TTL_MINUTES = 30;
}
