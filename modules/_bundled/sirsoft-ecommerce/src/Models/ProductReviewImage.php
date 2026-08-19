<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\URL;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductReviewImageFactory;
use Modules\Sirsoft\Ecommerce\Models\Concerns\HasDirectAssetUrl;

/**
 * 상품 리뷰 이미지 모델
 */
class ProductReviewImage extends Model
{
    use HasDirectAssetUrl;
    use HasFactory, SoftDeletes;

    protected $table = 'ecommerce_product_review_images';

    /**
     * @return ProductReviewImageFactory
     */
    protected static function newFactory(): ProductReviewImageFactory
    {
        return ProductReviewImageFactory::new();
    }

    /**
     * 모델 부트 메서드
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

    protected $fillable = [
        'review_id',
        'hash',
        'original_filename',
        'stored_filename',
        'disk',
        'path',
        'mime_type',
        'file_size',
        'width',
        'height',
        'alt_text',
        'collection',
        'is_thumbnail',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'alt_text' => 'array',
        'is_thumbnail' => 'boolean',
        'sort_order' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'file_size' => 'integer',
    ];

    /**
     * 리뷰 관계
     *
     * @return BelongsTo
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(ProductReview::class, 'review_id');
    }

    /**
     * 업로더 관계
     *
     * @return BelongsTo
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * hash 기반 리뷰 이미지 서빙 API URL (직접 URL 불가 시 폴백).
     *
     * @return string
     */
    protected function apiDownloadUrl(): string
    {
        return '/api/modules/sirsoft-ecommerce/review-image/'.$this->hash;
    }

    /**
     * 숨김(HIDDEN) 리뷰 이미지의 <img> 렌더용 한시 서명 다운로드 URL 을 반환합니다.
     *
     * 브라우저 <img src> 는 Authorization 헤더를 실을 수 없으므로, 숨김 리뷰의
     * 이미지를 권한자 화면(관리자 리뷰 목록·상세)에 표시할 때는 한시 서명 URL 을
     * 발급한다. 서명 URL 은 게이트(관리자 권한 미들웨어)를 통과한 응답 직렬화
     * 시점에만 발급되고(ProductReviewResource), 서빙 엔드포인트가 유효 서명을
     * 무인증 허용한다 — 무서명 요청의 상태 게이트는 종전과 동일하다.
     *
     * 직접 URL(CDN)과 무관하게 항상 API 서빙 경로에 서명한다 — 게이트가 있는
     * 경로는 API 서빙뿐이고, 직접 URL 가용 여부로 분기하면 저장 모드에 따라
     * 관리자 화면 동작이 갈린다.
     *
     * @return string 한시 서명 다운로드 URL (상대경로)
     */
    public function signedDownloadUrl(): string
    {
        return URL::temporarySignedRoute(
            'api.modules.sirsoft-ecommerce.review-image.download',
            now()->addMinutes(self::SIGNED_DOWNLOAD_TTL_MINUTES),
            ['hash' => $this->hash],
            absolute: false
        );
    }

    /**
     * 서명 다운로드 URL 의 유효 시간(분).
     *
     * 관리자 리뷰 화면이 열려 있는 동안 썸네일이 유지될 만큼 길고,
     * URL 유출 시 노출 창을 좁힐 만큼 짧은 값.
     */
    public const SIGNED_DOWNLOAD_TTL_MINUTES = 30;
}
