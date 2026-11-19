<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * 큐 실패 작업 상세 리소스 (#17)
 *
 * 예외 전문과 payload 메타를 싣는다. payload 의 직렬화된 작업 객체(data.command)는 **싣지 않는다** —
 * 작업 인자(수신자 주소·토큰 등)가 그대로 들어 있어 화면에 노출할 이유가 없다.
 */
class FailedJobDetailResource extends FailedJobResource
{
    /** 화면에 싣는 payload 메타 키 */
    private const PAYLOAD_META_KEYS = ['uuid', 'displayName', 'job', 'maxTries', 'maxExceptions', 'failOnTimeout', 'backoff', 'timeout', 'retryUntil'];

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        $decoded = json_decode((string) $this->getValue('payload', ''), true);
        $meta = is_array($decoded) ? array_intersect_key($decoded, array_flip(self::PAYLOAD_META_KEYS)) : [];

        if (is_array($decoded) && isset($decoded['data']['commandName'])) {
            $meta['commandName'] = $decoded['data']['commandName'];
        }

        return [
            ...$this->baseFields(),
            'exception' => (string) $this->getValue('exception', ''),
            'payload_meta' => $meta,
            ...$this->resourceMeta($request),
        ];
    }
}
