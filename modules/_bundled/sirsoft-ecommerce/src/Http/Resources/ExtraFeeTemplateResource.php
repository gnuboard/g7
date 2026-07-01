<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;

/**
 * 추가배송비 템플릿 리소스
 */
class ExtraFeeTemplateResource extends BaseApiResource
{
    use HasMultiCurrencyPrices;

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 추가배송비 템플릿 리소스 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // 우편번호 및 배송비
            'zipcode' => $this->zipcode,
            'fee' => $this->roundToBaseCurrency($this->fee),
            'fee_formatted' => $this->formatBaseCurrency($this->fee),

            // 지역 정보
            'region' => $this->region,
            'description' => $this->description,

            // 상태
            'is_active' => $this->is_active,

            // 시스템 정보
            'created_by' => $this->creator?->uuid,
            'updated_by' => $this->updater?->uuid,

            // 날짜
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            'updated_at' => $this->formatDateTimeStringForUser($this->updated_at),

            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 리소스별 권한 매핑을 반환합니다.
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'sirsoft-ecommerce.settings.update',
            'can_update' => 'sirsoft-ecommerce.settings.update',
            'can_delete' => 'sirsoft-ecommerce.settings.update',
        ];
    }
}
