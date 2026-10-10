<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Http\Resources\BaseApiCollection;
use App\Http\Resources\Traits\HasAbilityCheck;
use Illuminate\Http\Request;

/**
 * 게시판 환경설정 「본인인증 정책」 탭의 정책 목록 컬렉션
 *
 * 「정책 추가」·활성 토글·편집·삭제를 잠글 abilities 를 싣는다. 판정 권한은 이 탭의 쓰기 라우트와 같은
 * `sirsoft-board.identity.policies.update` 다.
 */
class IdentityPolicyCollection extends BaseApiCollection
{
    use HasAbilityCheck;

    /**
     * 컬렉션 레벨 능력 매핑.
     *
     * @return array<string, string> 능력 키 => 권한 식별자 매핑
     */
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'sirsoft-board.identity.policies.update',
            'can_update' => 'sirsoft-board.identity.policies.update',
            'can_delete' => 'sirsoft-board.identity.policies.update',
        ];
    }

    /**
     * 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 정책 행 목록 + abilities
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection->map(fn ($policy) => new IdentityPolicyResource($policy)),
            'abilities' => $this->resolveAbilitiesFromMap($this->abilityMap(), $request->user()),
        ];
    }
}
