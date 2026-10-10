<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Enums\UserStatus;
use App\Http\Resources\BaseApiResource;
use App\Http\Resources\Traits\ExposesMemberContact;
use Illuminate\Http\Request;

/**
 * 관리자 회원 배송지 목록 행 리소스
 *
 * “어느 회원의 어떤 배송지인가” 가 행의 주어다. 수령인 연락처는 마스킹하지 않는다 —
 * 배송 사고 처리에 원문이 필요한 자리이고, 이 응답은 관리자 라우트에서만 나간다.
 * 국내/해외 주소가 서로 다른 컬럼에 담기므로 한 줄 요약으로 합쳐 싣는다.
 * 이메일은 canExposeMemberEmail 게이트를 통과할 때만 싣는다
 * (docs/api/README.md 「회원 이메일 노출 범위」).
 */
class AdminUserAddressResource extends BaseApiResource
{
    use ExposesMemberContact;

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 변환된 관리자 목록 행
     */
    public function toArray(Request $request): array
    {
        $member = $this->relationLoaded('user') ? $this->user : null;

        return [
            'id' => $this->id,
            'member' => $member !== null ? $this->resolveMember($member, $request) : null,
            'label' => $this->name,
            'recipient_name' => $this->recipient_name,
            'recipient_phone' => $this->recipient_phone,
            'country_code' => $this->country_code,
            'address_summary' => $this->resolveAddressSummary(),
            'is_default' => (bool) $this->is_default,
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
        ];
    }

    /**
     * 회원 블록을 구성합니다 (탈퇴 회원은 이름 익명화, 이메일은 게이트 통과 시에만).
     *
     * 이 Resource 는 관리자 컨트롤러에서만 쓰이지만 게이트를 거치지 않으면, 나중에
     * 사용자 경로가 이 Resource 를 재사용하는 순간 회원 주소록이 그대로 새어나간다.
     *
     * @param  mixed  $member  회원 모델
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 회원 정보
     */
    private function resolveMember($member, Request $request): array
    {
        $isWithdrawn = UserStatus::tryFrom((string) $member->status) === UserStatus::Withdrawn;

        return [
            'uuid' => $member->uuid,
            'name' => $isWithdrawn ? __('user.withdrawn_user') : $member->name,
            'email' => $this->canExposeMemberEmail($request, (int) $member->id) ? $member->email : null,
            'is_withdrawn' => $isWithdrawn,
        ];
    }

    /**
     * 주소를 한 줄로 요약합니다 (국내/해외 컬럼 차이를 흡수).
     *
     * @return string 요약 주소
     */
    private function resolveAddressSummary(): string
    {
        $parts = $this->country_code === 'KR'
            ? [$this->zipcode, $this->address, $this->address_detail]
            : [$this->address_line_1, $this->address_line_2, $this->city, $this->state, $this->country_code];

        return trim(implode(' ', array_filter($parts, fn ($v) => $v !== null && $v !== '')));
    }
}
