<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiCollection;
use App\Http\Resources\Traits\HasAbilityCheck;
use Illuminate\Http\Request;

/**
 * 관리자 상품문의 목록 컬렉션
 *
 * 마일리지 내역 컬렉션과 같은 모양이다 — 행 번호를 붙이고, 화면이 삭제 버튼의 활성 여부를
 * 판정할 수 있도록 컬렉션 레벨 abilities 를 함께 싣는다(선택이 0건일 때도 상태를 정해야 한다).
 */
class AdminInquiryCollection extends BaseApiCollection
{
    use HasAbilityCheck;

    /**
     * 컬렉션 레벨 능력(can_*) 매핑을 반환합니다.
     *
     * @return array<string, string> 능력 매핑
     */
    protected function abilityMap(): array
    {
        return [
            // 일괄 삭제·선택 — 삭제 라우트(`inquiries.delete`)와 같은 식별자
            'can_manage' => 'sirsoft-ecommerce.inquiries.delete',
            // 답변 창의 [등록] — 답변 라우트(`inquiries.update`)와 같은 식별자 (7.1.0)
            'can_reply' => 'sirsoft-ecommerce.inquiries.update',
        ];
    }

    /**
     * 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<int|string, mixed> 변환된 배열
     */
    public function toArray(Request $request): array
    {
        return array_merge([
            'data' => $this->mapWithRowNumber(
                fn ($row) => (new AdminInquiryResource($row))->toArray($request)
            ),
            'abilities' => $this->resolveAbilitiesFromMap($this->abilityMap(), $request->user()),
        ], $this->paginationMeta());
    }
}
