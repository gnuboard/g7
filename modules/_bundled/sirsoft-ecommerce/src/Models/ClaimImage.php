<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimImageFactory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimImageUploaderTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;

/**
 * 클레임 첨부 이미지 모델
 *
 * 교환/반품/환불 신청서가 공유하는 다형 부속물이다.
 *
 * 이 모델에는 `HasDirectAssetUrl` 을 배선하지 않는다 — 클레임 첨부는 신청자 본인과
 * 관리자만 볼 수 있는 권한 검사 대상이라, 직접(CDN) URL 을 노출하면 서버 서빙 경로의
 * 권한 검사를 우회하게 된다. 서빙 경로는 첨부 서비스가 게이트를 통과한 뒤 발급한다.
 */
class ClaimImage extends Model
{
    use HasFactory;

    /**
     * 신청서 작성 중(미귀속) 첨부의 claimable_type 값 — claimable_id 는 주문 ID 다.
     *
     * 신청서가 만들어지기 전에 올린 이미지를 주문에 묶어 두는 임시 유형이며, 신청 접수 시
     * 실제 클레임 유형으로 귀속된다.
     */
    public const PENDING_CLAIMABLE_TYPE = 'pending';

    /**
     * 모델 팩토리를 반환합니다.
     *
     * @return ClaimImageFactory 첨부 이미지 팩토리
     */
    protected static function newFactory()
    {
        return ClaimImageFactory::new();
    }

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'ecommerce_claim_images';

    /**
     * 대량 할당 가능 필드
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'claimable_type',
        'claimable_id',
        'hash',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'width',
        'height',
        'sort_order',
        'created_by',
        'uploader_type',
    ];

    /**
     * 타입 캐스팅
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'claimable_id' => 'integer',
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
            'uploader_type' => ClaimImageUploaderTypeEnum::class,
        ];
    }

    /**
     * 모델 부트 메서드
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // 생성 시 hash 자동 생성 (서빙 URL 용 고유 키)
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
     * 첨부 대상(교환/반품/환불 신청서)과의 다형 관계
     *
     * @return MorphTo 첨부 대상 관계
     */
    public function claimable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * 업로더와의 관계
     *
     * @return BelongsTo 업로더 사용자 관계
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 첨부 대상 클레임 유형을 반환합니다.
     *
     * @return ClaimTypeEnum|null 클레임 유형 (해석 불가하면 null)
     */
    public function claimType(): ?ClaimTypeEnum
    {
        return ClaimTypeEnum::tryFrom((string) $this->claimable_type);
    }

    /**
     * 특정 클레임 유형의 첨부만 조회하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  string|ClaimTypeEnum  $type  클레임 유형
     * @return Builder 필터가 적용된 쿼리 빌더
     */
    public function scopeOfClaimType($query, string|ClaimTypeEnum $type)
    {
        $value = $type instanceof ClaimTypeEnum ? $type->value : $type;

        return $query->where('claimable_type', $value);
    }

    /**
     * 정렬 순서대로 조회하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @return Builder 정렬이 적용된 쿼리 빌더
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
