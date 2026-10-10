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
     * Resource 수준 abilities 매핑.
     *
     * 프로바이더 설정값은 환경설정 저장(`POST /api/admin/settings`, core.settings.update)으로 바뀐다.
     * 저장 라우트와 같은 식별자로 판정해야 화면 버튼과 서버 403 이 어긋나지 않는다
     * (`core.admin.identity.providers.update` 를 요구하는 라우트는 없다).
     *
     * @return array<string, string>
     */
    public function abilityMap(): array
    {
        return [
            'can_update' => 'core.settings.update',
        ];
    }

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
            'channel_labels' => $p->getChannelLabels(),
            'render_hint' => $p->getRenderHint(),
            'is_available' => $p->isAvailable(),
            ...$this->resourceMeta($request),
        ];
    }
}
