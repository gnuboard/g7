<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sirsoft\Ecommerce\Enums\SearchPresetTargetScreen;

/**
 * 검색 프리셋 모델
 */
class SearchPreset extends Model
{
    protected $table = 'ecommerce_search_presets';

    protected $fillable = [
        'user_id',
        'target_screen',
        'preset_name',
        'conditions',
        'sort_order',
        'is_default',
    ];

    protected $casts = [
        'target_screen' => SearchPresetTargetScreen::class,
        'conditions' => 'array',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * 사용자 관계
     *
     * @return BelongsTo 프리셋 소유 사용자 관계
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 특정 화면의 프리셋 조회 스코프
     *
     * @param  Builder  $query
     * @param  SearchPresetTargetScreen|string  $screen  대상 화면
     * @return Builder
     */
    public function scopeForScreen($query, SearchPresetTargetScreen|string $screen)
    {
        $value = $screen instanceof SearchPresetTargetScreen ? $screen->value : $screen;

        return $query->where('target_screen', $value);
    }

    /**
     * 현재 사용자의 프리셋 조회 스코프
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeForCurrentUser($query)
    {
        return $query->where('user_id', auth()->id());
    }

    /**
     * 프론트엔드용 필터 쿼리 파라미터 생성
     *
     * @return array 쿼리스트링으로 바로 쓸 수 있는 파라미터 배열
     */
    public function toQueryParams(): array
    {
        $params = ['preset_id' => $this->id];

        foreach ($this->conditions as $key => $value) {
            if (is_array($value)) {
                $params["filters[{$key}]"] = implode(',', $value);
            } else {
                $params["filters[{$key}]"] = $value;
            }
        }

        return $params;
    }

    /**
     * 대상 화면의 라벨 반환 (UI 표시용)
     *
     * @return string 다국어 처리된 대상 화면 라벨
     */
    public function getScreenLabelAttribute(): string
    {
        return match ($this->target_screen) {
            SearchPresetTargetScreen::PRODUCTS => __('sirsoft-ecommerce::enums.target_screen.products'),
            SearchPresetTargetScreen::ORDERS => __('sirsoft-ecommerce::enums.target_screen.orders'),
            SearchPresetTargetScreen::CUSTOMERS => __('sirsoft-ecommerce::enums.target_screen.customers'),
            default => $this->target_screen->value ?? $this->target_screen,
        };
    }
}
