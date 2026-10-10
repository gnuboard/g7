<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\Identity\PolicyResource;

/**
 * 이커머스 환경설정 「본인인증 정책」 탭의 정책 한 건 리소스
 *
 * 내려 주는 칸은 코어 정책 리소스와 같다. 행 abilities 만 이 탭의 쓰기 라우트가 요구하는
 * 이커머스 권한(`sirsoft-ecommerce.identity.policies.update`)으로 판정한다 — 코어 권한으로 판정하면
 * 화면의 잠금과 서버 403 이 서로 다른 권한을 보게 된다.
 */
class IdentityPolicyResource extends PolicyResource
{
    /**
     * Resource 수준 abilities 매핑.
     *
     * @return array<string, string> 능력 키 => 권한 식별자 매핑
     */
    public function abilityMap(): array
    {
        return [
            'can_update' => 'sirsoft-ecommerce.identity.policies.update',
            'can_delete' => 'sirsoft-ecommerce.identity.policies.update',
        ];
    }
}
