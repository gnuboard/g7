<?php

namespace App\Http\Resources\Identity;

use App\Extension\IdentityVerification\DTO\VerificationChallenge;
use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * VerificationChallenge DTO 를 그대로 노출하는 Resource.
 *
 * 프론트가 Challenge 모달을 렌더링할 때 필요한 공개 정보만 반환합니다.
 * 민감 metadata(code_hash, link_token_hash 등)는 제외합니다.
 */
class ChallengeResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 직렬화된 Challenge 페이로드
     */
    public function toArray(Request $request): array
    {
        /** @var VerificationChallenge $c */
        $c = $this->resource;

        return [
            'id' => $c->id,
            'provider_id' => $c->providerId,
            'purpose' => $c->purpose,
            'channel' => $c->channel,
            'render_hint' => $c->renderHint,
            'redirect_url' => $c->redirectUrl,
            'expires_at' => $c->expiresAt->toIso8601String(),
            'public_payload' => $c->publicPayload,
            ...$this->resourceMeta($request),
        ];
    }
}
