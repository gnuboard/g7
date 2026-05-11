<?php

namespace App\Http\Resources\Identity;

use App\Contracts\Extension\IdentityVerificationInterface;
use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 등록된 IdentityVerification 프로바이더 Resource.
 *
 * 공개 API (GET /api/identity/providers) 는 민감 설정을 노출하지 않고
 * 프론트 Challenge UI 가 필요한 메타데이터만 반환합니다.
 */
class ProviderResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 프로바이더 공개 메타데이터
     */
    public function toArray(Request $request): array
    {
        /** @var IdentityVerificationInterface $p */
        $p = $this->resource;

        return [
            'id' => $p->getId(),
            'label' => $p->getLabel(),
            'channels' => $p->getChannels(),
            'render_hint' => $p->getRenderHint(),
            'is_available' => $p->isAvailable(),
            ...$this->resourceMeta($request),
        ];
    }
}
