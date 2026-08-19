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
        // 추가배송비 템플릿의 write 라우트는 모두 shipping-policies.{create,update,delete} 로
        // 게이팅된다(라우트 SSoT). 능력 플래그도 그 리소스의 권한과 일치해야 한다 — 형제
        // ExtraFeeTemplateCollection 과 동일. 과거 settings.update(타 리소스)로 게이팅해
        // shipping-policies 권한만 가진 액터가 상세 화면에서 편집 능력이 false 로 보이던
        // 결함을 정정한다.
        return [
            'can_create' => 'sirsoft-ecommerce.shipping-policies.create',
            'can_update' => 'sirsoft-ecommerce.shipping-policies.update',
            'can_delete' => 'sirsoft-ecommerce.shipping-policies.delete',
        ];
    }
}
