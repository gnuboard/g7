<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 배송정책 리소스
 */
class ShippingPolicyResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array
     */
    public function toArray(Request $request): array
    {
        // 컬렉션(ShippingPolicyCollection)이 이 배열을 그대로 응답에 실어 Laravel 의 MissingValue
        // 제거 단계를 거치지 않는다 — 미충족 조건부 필드를 직접 걸러낸다. 걸러내지 않으면
        // 국가별 설정을 싣지 않은 목록에서 `"country_settings": {}` 가 남는다.
        return $this->withoutMissing([
            'id' => $this->id,

            // 기본 정보
            'name' => $this->name,
            'name_localized' => $this->getLocalizedName(),

            // 국가별 설정
            //
            // whenLoaded 는 `collection()` 안이 아니라 **바깥**에 둔다. 안에 두면 미로드 시
            // MissingValue 가 컬렉션 생성자로 들어가 직렬화 단계에서 터진다(관계를 항상
            // 로드하던 동안에는 드러나지 않던 경로다).
            'country_settings' => $this->whenLoaded(
                'countrySettings',
                fn () => ShippingPolicyCountrySettingResource::collection($this->countrySettings)
            ),

            // 요약 정보
            //
            // 두 메서드는 관계가 로드돼 있지 않으면 국가별 설정을 **행마다 다시 조회**한다.
            // 목록에서 무조건 호출하면 한 페이지당 정책 수만큼 쿼리가 늘어난다(N+1). 관계가
            // 실려 있을 때만 계산하고, 그렇지 않으면 키를 내보내지 않는다 — 목록을 경량으로
            // 받은 화면은 애초에 이 값을 그리지 않는다.
            'fee_summary' => $this->whenLoaded('countrySettings', fn () => $this->getFeeSummary()),
            'countries_display' => $this->whenLoaded('countrySettings', fn () => $this->getCountriesWithFlags()),

            // 상태
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'sort_order' => $this->sort_order,

            // 날짜
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            'updated_at' => $this->formatDateTimeStringForUser($this->updated_at),

            ...$this->resourceMeta($request),
        ]);
    }

    /**
     * 목록 표현으로 변환합니다.
     *
     * 국가별 설정은 목록이 그리는 컬럼만 실은 `listCountrySettings` 에서 가져오고, 그 경량
     * 표현(`toListArray`)으로 직렬화한다. 요약 2필드는 관계가 실려 있으므로 재조회 없이
     * 계산된다 — 목록이 이 값을 실제로 그리기 때문에 조건부로 두지 않는다.
     *
     * `with_country_settings=1` 로 전체 컬럼을 요청한 호출자에게는 종전 표현을 그대로 준다
     * (외부 연동 하위호환).
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 목록용 배송정책 배열
     */
    public function toListArray(Request $request): array
    {
        if ($this->resource->relationLoaded('countrySettings')) {
            return $this->toArray($request);
        }

        return $this->withoutMissing([
            'id' => $this->id,

            'name' => $this->name,
            'name_localized' => $this->getLocalizedName(),

            'country_settings' => $this->whenLoaded(
                'listCountrySettings',
                fn () => $this->listCountrySettings
                    ->map(fn ($setting) => (new ShippingPolicyCountrySettingResource($setting))->toListArray($request))
                    ->values()
                    ->all()
            ),

            'fee_summary' => $this->whenLoaded('listCountrySettings', fn () => $this->getFeeSummary()),
            'countries_display' => $this->whenLoaded('listCountrySettings', fn () => $this->getCountriesWithFlags()),

            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'sort_order' => $this->sort_order,

            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            'updated_at' => $this->formatDateTimeStringForUser($this->updated_at),

            ...$this->resourceMeta($request),
        ]);
    }

    /**
     * 리소스별 권한 매핑을 반환합니다.
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'sirsoft-ecommerce.shipping-policies.create',
            'can_update' => 'sirsoft-ecommerce.shipping-policies.update',
            'can_delete' => 'sirsoft-ecommerce.shipping-policies.delete',
        ];
    }
}
