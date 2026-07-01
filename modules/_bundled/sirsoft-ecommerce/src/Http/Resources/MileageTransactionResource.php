<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;

/**
 * 마일리지 거래 리소스
 *
 * @property MileageTransaction $resource
 */
class MileageTransactionResource extends BaseApiResource
{
    use HasMultiCurrencyPrices;

    /**
     * 행 레벨 능력(can_*) 매핑을 반환합니다.
     *
     * DataGrid 행 액션('수동 조정')의 disabledField('abilities.can_manage')가
     * 각 행 데이터의 abilities.can_manage 를 참조하므로, 컬렉션 레벨과 동일한
     * 능력을 행 단위로도 노출합니다.
     *
     * @return array<string, string> 능력 매핑
     */
    protected function abilityMap(): array
    {
        return [
            'can_manage' => 'sirsoft-ecommerce.mileage.manage',
        ];
    }

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  요청
     * @return array 변환된 배열
     */
    public function toArray(Request $request): array
    {
        $type = $this->resource->type instanceof MileageTransactionTypeEnum
            ? $this->resource->type
            : MileageTransactionTypeEnum::tryFrom((string) $this->resource->type);

        $isEarning = $type?->isEarning() ?? false;
        $earnAmount = (float) $this->resource->amount;

        // 마일리지 금액 표기 통화 — 거래에 기록된 통화(없으면 기본 통화)의 소수 자릿수를 따른다.
        $mileageCurrency = $this->resource->currency ?: $this->getDefaultCurrencyCode();

        // 소멸액(이 적립 lot 을 source 로 한 expired 거래 합 — paginateWithFilters 에서 eager 집계).
        // 단건 조회(연결 거래 등 집계 미주입) 시에는 0 으로 폴백.
        $expiredAmount = $this->resource->expired_amount !== null ? (float) $this->resource->expired_amount : 0.0;

        // 소멸 상태: 적립계만 의미가 있다. fully(전액 소멸) / partial(일부 소멸) / active(미소멸)
        $expiryState = 'active';
        if ($isEarning && $expiredAmount > 0) {
            $expiryState = ($expiredAmount + 0.001) >= $earnAmount ? 'fully_expired' : 'partial_expired';
        }

        // 기간 변경 가능: 적립계 + 미소멸 + 잔여 > 0 (소멸/전액사용 lot 은 만료일 변경 불가, memo 만 편집)
        $canEditExpiry = $isEarning
            && $this->resource->expired_at === null
            && (float) $this->resource->remaining_amount > 0;

        return [
            'id' => $this->resource->id,
            'user_id' => $this->resource->user_id,
            'currency' => $this->resource->currency,
            'type' => $type?->value,
            'type_label' => $type?->label(),
            'admin_badge_group' => $type?->adminBadgeGroup(),
            'user_display_category' => $type?->userDisplayCategory(),
            'amount' => $this->roundToCurrency($this->resource->amount, $mileageCurrency),
            'amount_formatted' => $this->formatCurrencyPrice((float) $this->resource->amount, $mileageCurrency),
            'remaining_amount' => $this->roundToCurrency($this->resource->remaining_amount, $mileageCurrency),
            'remaining_amount_formatted' => $this->formatCurrencyPrice((float) $this->resource->remaining_amount, $mileageCurrency),
            'balance_after' => $this->roundToCurrency($this->resource->balance_after, $mileageCurrency),
            'order_id' => $this->resource->order_id,
            'order_option_id' => $this->resource->order_option_id,
            'order_cancel_id' => $this->resource->order_cancel_id,
            'source_transaction_id' => $this->resource->source_transaction_id,
            'granted_by' => $this->resource->granted_by,
            'granted_by_name' => $this->whenLoaded('grantedByUser', fn () => $this->resource->grantedByUser?->name),
            'granted_by_uuid' => $this->whenLoaded('grantedByUser', fn () => $this->resource->grantedByUser?->uuid),
            'user_name' => $this->whenLoaded('user', fn () => $this->resource->user?->name),
            'user_uuid' => $this->whenLoaded('user', fn () => $this->resource->user?->uuid),
            'order_number' => $this->whenLoaded('order', fn () => $this->resource->order?->order_number),
            'description' => $this->resource->description,
            'memo' => $this->resource->memo,
            'expires_at' => $this->resource->expires_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: paired with *_formatted user-tz field
            'expires_at_formatted' => $this->formatDateTimeStringForUser($this->resource->expires_at),
            'expires_at_date' => $this->formatDateStringForSite($this->resource->expires_at),
            'expired_at' => $this->resource->expired_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: paired with *_formatted user-tz field
            'expired_at_formatted' => $this->formatDateTimeStringForUser($this->resource->expired_at),
            'created_at' => $this->resource->created_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: paired with *_formatted user-tz field
            'created_at_formatted' => $this->formatDateTimeStringForUser($this->resource->created_at),
            'created_at_date' => $this->formatDateStringForSite($this->resource->created_at),

            // 적립계 여부 + 소멸 집계 (행 편집 게이팅 + 일부/전체 소멸 표시 — B-3)
            'is_earning' => $isEarning,
            'can_edit_expiry' => $canEditExpiry,
            'expired_amount' => $this->roundToCurrency($expiredAmount, $mileageCurrency),
            'expired_amount_formatted' => $this->formatCurrencyPrice($expiredAmount, $mileageCurrency),
            'expiry_state' => $expiryState,

            // 행 레벨 권한 메타 (abilities.can_manage + can_edit) — DataGrid 행 액션 disabled 제어
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 행 레벨 능력을 계산합니다.
     *
     * 표준 can_manage 에 더해, 행 편집 메뉴('사유·기간 변경') 게이팅용 can_edit 를 노출합니다.
     * can_edit = manage 권한 보유 && 적립계 거래 (비적립계 행은 메뉴 비활성 — PO 합의 C-2).
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, bool> 능력 불리언 맵
     */
    protected function resolveAbilities(Request $request): array
    {
        $abilities = parent::resolveAbilities($request);

        $type = $this->resource->type instanceof MileageTransactionTypeEnum
            ? $this->resource->type
            : MileageTransactionTypeEnum::tryFrom((string) $this->resource->type);

        $abilities['can_edit'] = (bool) ($abilities['can_manage'] ?? false) && ($type?->isEarning() ?? false);

        return $abilities;
    }
}
