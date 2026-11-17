<?php

namespace App\Http\Resources;

use App\Models\ExtensionTrustKey;
use Illuminate\Http\Request;

/**
 * 확장 신뢰 키(제작자 공개키) 응답 모양.
 *
 * @mixin ExtensionTrustKey
 *
 * @since 7.2.0
 */
class ExtensionTrustKeyResource extends BaseApiResource
{
    /**
     * 응답 배열로 바꿉니다.
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 응답
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key_id' => $this->key_id,
            'label' => $this->label,
            'public_key' => $this->public_key,
            'vendor_scope' => array_values((array) $this->vendor_scope),
            // 등록한 관리자 ID(created_by)는 싣지 않는다 — 민감 정보 (api-resources.md), 화면도 쓰지 않는다
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
        ];
    }
}
