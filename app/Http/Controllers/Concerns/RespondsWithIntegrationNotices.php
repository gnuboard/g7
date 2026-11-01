<?php

namespace App\Http\Controllers\Concerns;

use App\Extension\Helpers\IntegrationNotices;
use Illuminate\Http\JsonResponse;

/**
 * 확장 생명주기 응답에 선택 연동(`integrations`) 안내를 싣습니다 (7.1.4).
 *
 * - 설치·활성화: `data.integration_notices`(연동 대상 중 미설치·비활성·버전 미달) + 응답 `message` 뒤에 한 줄 안내
 * - 비활성화·제거: `data.integrated_by`(이 확장을 연동으로 선언한 활성 확장)
 *
 * 안내는 막지 않는다 — HTTP 상태와 기존 키는 그대로 두고 키만 더한다. 응답 본문은 객체(stdClass)로 풀어 고친다 —
 * 연관 배열로 풀면 빈 객체 `{}` 가 다시 쓸 때 `[]` 로 바뀐다(#758 같은 결함군).
 */
trait RespondsWithIntegrationNotices
{
    /**
     * 응답에 `data.integration_notices` 를 싣고, 안내가 있으면 완료 문구 뒤에 한 줄을 이어 붙입니다.
     *
     * @param  JsonResponse  $response  성공 응답
     * @param  array<int, array<string, mixed>>  $notices  {@see IntegrationNotices::fromIntegrations()} 결과
     * @return JsonResponse 안내를 실은 응답
     */
    protected function withIntegrationNotices(JsonResponse $response, array $notices): JsonResponse
    {
        return $this->withDataKey($response, 'integration_notices', array_values($notices), function (object $payload) use ($notices): void {
            if (isset($payload->message) && is_string($payload->message)) {
                $payload->message = IntegrationNotices::appendToMessage($payload->message, $notices);
            }
        });
    }

    /**
     * 응답에 `data.integrated_by`(이 확장과 연동 중인 활성 확장)를 싣습니다.
     *
     * @param  JsonResponse  $response  성공 응답
     * @param  string  $type  대상 확장 유형 (module|plugin|template)
     * @param  string  $identifier  대상 확장 식별자
     * @return JsonResponse 목록을 실은 응답
     */
    protected function withIntegratedBy(JsonResponse $response, string $type, string $identifier): JsonResponse
    {
        return $this->withDataKey($response, 'integrated_by', IntegrationNotices::integratedBy($type, $identifier));
    }

    /**
     * 응답 `data` 객체에 키 하나를 더합니다. 목록 응답(`data` 가 비지 않은 배열)은 모양을 바꾸지 않습니다.
     *
     * @param  JsonResponse  $response  성공 응답
     * @param  string  $key  더할 키
     * @param  mixed  $value  값
     * @param  (callable(object): void)|null  $tap  본문 객체를 더 고칠 콜백
     * @return JsonResponse 고친 응답
     */
    private function withDataKey(JsonResponse $response, string $key, mixed $value, ?callable $tap = null): JsonResponse
    {
        $payload = $response->getData();
        if (! is_object($payload)) {
            return $response;
        }

        $data = $payload->data ?? null;
        if ($data === null || $data === []) {
            $data = new \stdClass;
        }

        if (! is_object($data)) {
            // 목록 응답은 모양을 바꾸지 않는다 — 생명주기 응답은 객체다
            return $response;
        }

        $data->{$key} = $value;
        $payload->data = $data;

        if ($tap !== null) {
            $tap($payload);
        }

        return $response->setData($payload);
    }
}
