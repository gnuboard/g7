<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Support\MemberDisplayName;

/**
 * 클레임 사유 API 리소스
 */
class ClaimReasonResource extends BaseApiResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array<string, mixed> 변환된 배열
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type instanceof \BackedEnum ? $this->type->value : $this->type,
            'code' => $this->code,
            'name' => $this->name,
            'localized_name' => $this->getLocalizedName(),
            'fault_type' => $this->fault_type instanceof \BackedEnum ? $this->fault_type->value : $this->fault_type,
            'fault_type_label' => $this->fault_type instanceof \BackedEnum
                ? $this->fault_type->label()
                : __('sirsoft-ecommerce::enums.claim_reason_fault_type.'.$this->fault_type),
            'is_user_selectable' => $this->is_user_selectable,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            'updated_at' => $this->formatDateTimeStringForUser($this->updated_at),

            // 관계
            // 등록자와 수정자의 이름은 표시 이름이다(관리자 화면은 이커머스 관리자 영역). 실명은 회원 조회 권한이 있는 관리자에게만 `real_name` 으로 더해진다.
            'creator' => $this->whenLoaded('creator', function () use ($request) {
                return [
                    'id' => $this->creator->id,
                    ...MemberDisplayName::staffFields($this->creator, $request),
                ];
            }),
            'updater' => $this->whenLoaded('updater', function () use ($request) {
                return [
                    'id' => $this->updater->id,
                    ...MemberDisplayName::staffFields($this->updater, $request),
                ];
            }),

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
