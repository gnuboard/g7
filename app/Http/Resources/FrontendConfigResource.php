<?php

namespace App\Http\Resources;

use App\Services\FrontendConfigService;
use Illuminate\Http\Request;

/**
 * 공개 설정 API 응답 리소스
 *
 * {@see FrontendConfigService::build()} 가 조립한 배열을 그대로 싣는다 — 셸(`window.G7Config`)과
 * 같은 값이어야 하므로 리소스에서 값을 다시 가공하지 않는다.
 *
 * 응답 키: scope · locale · settings · modules · plugins · app_config · websocket · custom_routes · (user) consent_initial
 */
class FrontendConfigResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 공개 설정
     */
    public function toArray(Request $request): array
    {
        return (array) $this->resource;
    }
}
