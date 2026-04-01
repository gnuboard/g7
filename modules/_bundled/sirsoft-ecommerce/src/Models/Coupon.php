<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Casts\AsUnicodeJson;
use App\Extension\HookManager;
use App\Models\User;
use App\Search\Contracts\FulltextSearchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;

/**
 * 쿠폰 모델
 */
class Coupon extends Model implements FulltextSearchable
{
    use HasFactory;
    use Searchable;
    use SoftDeletes;

    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'target_type' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.target_type', 'type' => 'enum', 'enum' => \Modules\Sirsoft\Ecommerce\Enums\CouponTargetType::class],
        'discount_type' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.discount_type', 'type' => 'enum', 'enum' => \Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType::class],
        'discount_value' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.discount_value', 'type' => 'currency'],
        'discount_max_amount' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.discount_max_amount', 'type' => 'currency'],
        'min_order_amount' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.min_order_amount', 'type' => 'currency'],
        'issue_method' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.issue_method', 'type' => 'enum', 'enum' => \Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod::class],
        'issue_condition' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.issue_condition', 'type' => 'enum', 'enum' => \Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition::class],
        'issue_status' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.issue_status', 'type' => 'enum', 'enum' => \Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus::class],
        'total_quantity' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.total_quantity', 'type' => 'number'],
        'per_user_limit' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.per_user_limit', 'type' => 'number'],
        'valid_type' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.valid_type', 'type' => 'text'],
        'valid_days' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.valid_days', 'type' => 'number'],
        'valid_from' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.valid_from', 'type' => 'datetime'],
        'valid_to' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.valid_to', 'type' => 'datetime'],
        'issue_from' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.issue_from', 'type' => 'datetime'],
        'issue_to' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.issue_to', 'type' => 'datetime'],
        'is_combinable' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.is_combinable', 'type' => 'boolean'],
    ];

    protected $table = 'ecommerce_promotion_coupons';

    protected $fillable = [
        'name',
        'description',
        'target_type',
        'discount_type',
        'discount_value',
        'discount_max_amount',
        'min_order_amount',
        'issue_method',
        'issue_condition',
        'issue_status',
        'total_quantity',
        'issued_count',
        'per_user_limit',
        'valid_type',
        'valid_days',
        'valid_from',
        'valid_to',
        'issue_from',
        'issue_to',
        'is_combinable',
        'target_scope',
        'created_by',
    ];

    protected $casts = [
        'name' => AsUnicodeJson::class,
        'description' => AsUnicodeJson::class,
        'target_type' => CouponTargetType::class,
        'discount_type' => CouponDiscountType::class,
        'discount_value' => 'decimal:2',
        'discount_max_amount' => 'decimal:2',
        'min_order_amount' => 'decimal:2',
        'issue_method' => CouponIssueMethod::class,
        'issue_condition' => CouponIssueCondition::class,
        'issue_status' => CouponIssueStatus::class,
        'total_quantity' => 'integer',
        'issued_count' => 'integer',
        'per_user_limit' => 'integer',
        'valid_days' => 'integer',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'issue_from' => 'datetime',
        'issue_to' => 'datetime',
        'is_combinable' => 'boolean',
        'target_scope' => CouponTargetScope::class,
    ];

    /**
     * 모델의 배열/JSON 변환 시 자동 포함되는 접근자
     *
     * @var array<string>
     */
    protected $appends = [
        'benefit_formatted',
    ];

    // ==================== Relations ====================

    /**
     * 발급 내역 관계
     *
     * @return HasMany 발급 내역 모델과의 관계
     */
    public function issues(): HasMany
    {
        return $this->hasMany(CouponIssue::class, 'coupon_id');
    }

    /**
     * 적용 상품 관계 (포함)
     *
     * @return BelongsToMany 상품 모델과의 관계
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'ecommerce_promotion_coupon_products', 'coupon_id', 'product_id')
            ->withPivot('type');
    }

    /**
     * 포함 상품 관계
     *
     * @return BelongsToMany 포함된 상품 모델과의 관계
     */
    public function includedProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'ecommerce_promotion_coupon_products', 'coupon_id', 'product_id')
            ->wherePivot('type', 'include');
    }

    /**
     * 제외 상품 관계
     *
     * @return BelongsToMany 제외된 상품 모델과의 관계
     */
    public function excludedProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'ecommerce_promotion_coupon_products', 'coupon_id', 'product_id')
            ->wherePivot('type', 'exclude');
    }

    /**
     * 적용 카테고리 관계
     *
     * @return BelongsToMany 카테고리 모델과의 관계
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'ecommerce_promotion_coupon_categories', 'coupon_id', 'category_id')
            ->withPivot('type');
    }

    /**
     * 포함 카테고리 관계
     *
     * @return BelongsToMany 포함된 카테고리 모델과의 관계
     */
    public function includedCategories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'ecommerce_promotion_coupon_categories', 'coupon_id', 'category_id')
            ->wherePivot('type', 'include');
    }

    /**
     * 제외 카테고리 관계
     *
     * @return BelongsToMany 제외된 카테고리 모델과의 관계
     */
    public function excludedCategories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'ecommerce_promotion_coupon_categories', 'coupon_id', 'category_id')
            ->wherePivot('type', 'exclude');
    }

    /**
     * 등록자 관계
     *
     * @return BelongsTo 사용자 모델과의 관계
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ==================== Accessors ====================

    /**
     * 다국어 쿠폰명 반환
     *
     * @param  string|null  $locale  로케일 (기본: 현재 로케일)
     * @return string 쿠폰명
     */
    public function getLocalizedName(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        return $this->name[$locale] ?? $this->name['ko'] ?? '';
    }

    /**
     * 다국어 설명 반환
     *
     * @param  string|null  $locale  로케일 (기본: 현재 로케일)
     * @return string|null 설명
     */
    public function getLocalizedDescription(?string $locale = null): ?string
    {
        if (! $this->description) {
            return null;
        }
        $locale = $locale ?? app()->getLocale();

        return $this->description[$locale] ?? $this->description['ko'] ?? null;
    }

    /**
     * 혜택 금액 포맷팅 반환
     *
     * @return string 포맷팅된 혜택 금액 (예: "정률 20% (최대 15,000원)")
     */
    public function getBenefitFormattedAttribute(): string
    {
        if ($this->discount_type === CouponDiscountType::FIXED) {
            return __('sirsoft-ecommerce::messages.coupons.benefit_fixed_format', [
                'amount' => ecommerce_format_price($this->discount_value),
            ]);
        }

        // 정률
        if ($this->discount_max_amount) {
            return __('sirsoft-ecommerce::messages.coupons.benefit_rate_with_max_format', [
                'rate' => $this->discount_value,
                'max' => ecommerce_format_price($this->discount_max_amount),
            ]);
        }

        return __('sirsoft-ecommerce::messages.coupons.benefit_rate_format', [
            'rate' => $this->discount_value,
        ]);
    }

    /**
     * 유효기간 포맷팅 반환
     *
     * @return string 포맷팅된 유효기간
     */
    public function getValidPeriodFormattedAttribute(): string
    {
        if ($this->valid_type === 'days_from_issue') {
            return __('sirsoft-ecommerce::messages.coupons.valid_days_format', [
                'days' => $this->valid_days,
            ]);
        }

        if ($this->valid_from && $this->valid_to) {
            return $this->valid_from->format('Y-m-d').' ~ '.$this->valid_to->format('Y-m-d');
        }

        if ($this->valid_from) {
            return $this->valid_from->format('Y-m-d').' ~';
        }

        if ($this->valid_to) {
            return '~ '.$this->valid_to->format('Y-m-d');
        }

        return '-';
    }

    /**
     * 발급기간 포맷팅 반환
     *
     * @return string 포맷팅된 발급기간
     */
    public function getIssuePeriodFormattedAttribute(): string
    {
        if ($this->issue_from && $this->issue_to) {
            return $this->issue_from->format('Y-m-d').' ~ '.$this->issue_to->format('Y-m-d');
        }

        if ($this->issue_from) {
            return $this->issue_from->format('Y-m-d').' ~';
        }

        if ($this->issue_to) {
            return '~ '.$this->issue_to->format('Y-m-d');
        }

        return __('sirsoft-ecommerce::messages.coupons.issue_period_unlimited');
    }

    /**
     * 발급수량 포맷팅 반환
     *
     * @return string 포맷팅된 발급수량 (예: "3/5" 또는 "3/무제한")
     */
    public function getIssueCountFormattedAttribute(): string
    {
        if ($this->total_quantity === null) {
            return $this->issued_count.'/'.__('sirsoft-ecommerce::messages.coupons.unlimited');
        }

        return $this->issued_count.'/'.$this->total_quantity;
    }

    /**
     * 발급 가능 여부 확인
     *
     * @return bool 발급 가능 여부
     */
    public function isIssuable(): bool
    {
        // 발급 중단 상태
        if ($this->issue_status !== CouponIssueStatus::ISSUING) {
            return false;
        }

        // 발급 수량 초과
        if ($this->total_quantity !== null && $this->issued_count >= $this->total_quantity) {
            return false;
        }

        // 발급 기간 확인
        $now = now();
        if ($this->issue_from && $now->lt($this->issue_from)) {
            return false;
        }
        if ($this->issue_to && $now->gt($this->issue_to)) {
            return false;
        }

        return true;
    }

    // ==================== Scopes ====================

    /**
     * 발급중 쿠폰 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeIssuing(Builder $query): Builder
    {
        return $query->where('issue_status', CouponIssueStatus::ISSUING);
    }

    /**
     * 발급중단 쿠폰 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeStopped(Builder $query): Builder
    {
        return $query->where('issue_status', CouponIssueStatus::STOPPED);
    }

    /**
     * 적용대상별 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  string  $type  적용대상 타입
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeByTargetType(Builder $query, string $type): Builder
    {
        return $query->where('target_type', $type);
    }

    /**
     * 발급방법별 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  string  $method  발급방법
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeByIssueMethod(Builder $query, string $method): Builder
    {
        return $query->where('issue_method', $method);
    }

    /**
     * 발급조건별 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  string  $condition  발급조건
     * @return Builder 필터링된 쿼리 빌더
     */
    public function scopeByIssueCondition(Builder $query, string $condition): Builder
    {
        return $query->where('issue_condition', $condition);
    }

    // ─── FulltextSearchable 구현 ─────────────────────────

    /**
     * FULLTEXT 검색 대상 컬럼 목록을 반환합니다.
     *
     * @return array<string>
     */
    public function searchableColumns(): array
    {
        return ['name', 'description'];
    }

    /**
     * 컬럼별 검색 가중치를 반환합니다.
     *
     * @return array<string, float>
     */
    public function searchableWeights(): array
    {
        return [
            'name' => 2.0,
            'description' => 1.0,
        ];
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
            'sirsoft-ecommerce.search.coupon.index_should_update',
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
            'name' => $this->name,
            'description' => $this->description,
        ];
    }
}
