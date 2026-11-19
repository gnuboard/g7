<?php

namespace App\Http\Resources;

use App\Contracts\Repositories\NotificationDefinitionRepositoryInterface;
use App\Http\Resources\Traits\HasAbilityCheck;
use Illuminate\Http\Request;

/**
 * 관리자 회원 알림함 컬렉션
 *
 * 종류 라벨 맵은 여기서 **한 번만** 조회해 모든 행에 나눠 준다 — 행마다 조회하면
 * 목록 한 쪽이 행 수만큼의 조회를 부른다(UserNotificationCollection 과 같은 짜임).
 */
class MemberNotificationCollection extends BaseApiCollection
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
            'can_delete' => 'core.member-notifications.delete',
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
        $user = $request->user();
        $typeLabelMap = app(NotificationDefinitionRepositoryInterface::class)->getLabelMap($user?->locale);

        // 수신 회원 이름은 표시 이름으로 나간다. 추가 항목 값이 필요한 설정이면 여기서 한 번에 읽는다.
        $this->preloadMemberDisplayNames($this->collection, ['notifiable'], $request);

        return array_merge([
            // 행 메뉴 「삭제」가 읽는 행 abilities 는 MemberNotificationResource 가 표준 메타로 싣는다.
            'data' => $this->mapWithRowNumber(
                fn ($notification) => (new MemberNotificationResource($notification))
                    ->withTypeLabelMap($typeLabelMap)
                    ->toArray($request)
            ),
            'abilities' => $this->resolveAbilitiesFromMap($this->abilityMap(), $user),
        ], $this->paginationMeta());
    }
}
