<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 추가배송비 템플릿 모델
 *
 * 도서산간 지역 등 추가배송비 설정을 위한 템플릿 테이블
 */
class ExtraFeeTemplate extends Model
{
    use HasFactory;

    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'zipcode' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.zipcode', 'type' => 'text'],
        'fee' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.fee', 'type' => 'currency'],
        'region' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.region', 'type' => 'text'],
        'description' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.description', 'type' => 'text'],
        'is_active' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.is_active', 'type' => 'boolean'],
    ];

    protected $table = 'ecommerce_shipping_policy_extra_fee_templates';

    protected $fillable = [
        'zipcode',
        'fee',
        'region',
        'description',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'fee' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * 활성 템플릿 스코프
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * 지역명 필터 스코프
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInRegion($query, ?string $region)
    {
        if (empty($region)) {
            return $query;
        }

        return $query->where('region', $region);
    }

    /**
     * 우편번호 검색 스코프
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSearchByZipcode($query, string $search)
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where('zipcode', 'like', "%{$search}%");
    }

    /**
     * 우편번호 또는 지역명 검색 스코프
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSearch($query, string $search)
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where('zipcode', 'like', "%{$search}%")
                ->orWhere('region', 'like', "%{$search}%");
        });
    }

    /**
     * 정렬 스코프
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOrderByField($query, string $sortBy = 'zipcode', string $sortOrder = 'asc')
    {
        $allowedFields = ['id', 'zipcode', 'fee', 'region', 'is_active', 'created_at', 'updated_at'];

        if (! in_array($sortBy, $allowedFields)) {
            $sortBy = 'zipcode';
        }

        return $query->orderBy($sortBy, $sortOrder);
    }

    /**
     * 등록자 관계
     *
     * @return BelongsTo
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 수정자 관계
     *
     * @return BelongsTo
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * 배송정책 JSON 형식으로 변환
     * 배송정책의 extra_fee_settings에 저장할 형식
     */
    public function toExtraFeeSetting(): array
    {
        return [
            'zipcode' => $this->zipcode,
            'fee' => (float) $this->fee,
            'region' => $this->region ?? '',
        ];
    }

    /**
     * 활성 템플릿 전체를 배송정책용 JSON 배열로 변환
     */
    public static function getAllAsExtraFeeSettings(): array
    {
        return self::active()
            ->orderBy('zipcode')
            ->get()
            ->map(fn ($template) => $template->toExtraFeeSetting())
            ->toArray();
    }
}
