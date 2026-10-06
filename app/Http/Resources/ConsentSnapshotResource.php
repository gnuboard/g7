<?php

namespace App\Http\Resources;

use App\Extension\Consent\ConsentSnapshot;
use Illuminate\Http\Request;

/**
 * 동의 스냅샷 공개 응답 리소스
 *
 * 공개 계약 5키(`contract_version`, `status`, `categories`, `needs_renewal`, `policy_version`)만
 * 내보냅니다. 회원 번호·게스트 식별자·제공자 내부 값은 싣지 않습니다 — 이 응답은 다른 확장의
 * 브라우저 코드가 읽는 공개 계약이라, 식별자가 실리면 그 코드가 방문자를 추적할 수 있게 됩니다.
 *
 * @property ConsentSnapshot $resource
 */
class ConsentSnapshotResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array{contract_version: int, status: string, categories: array<string, bool>, needs_renewal: bool, policy_version: string|null}
     *                                                                                                                                         공개 스냅샷
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
