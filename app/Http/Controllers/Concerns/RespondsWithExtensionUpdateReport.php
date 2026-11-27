<?php

namespace App\Http\Controllers\Concerns;

use App\Exceptions\ExtensionUpdateRequestFailedException;
use App\Extension\Helpers\ExtensionManualZipSources;
use App\Extension\Support\ExtensionUpdateReport;
use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * 확장 웹 업데이트 응답에 터미널과 같은 결과·실패 안내를 싣는 트레이트 (7.2.1 출시 전).
 *
 * - 성공: `data` 에 from_version·to_version·dependency_warnings·recovered·no_update 를 더한다.
 * - 실패: 422 검증 오류 모양은 그대로 두고 `data` 에 outcome·warnings·retry_command 를 싣는다.
 * - 입력: `source`(auto|bundled|github)·`zip_file`(대기 폴더의 파일 이름)을 서비스 인자로 바꾼다.
 */
trait RespondsWithExtensionUpdateReport
{
    /**
     * 요청 본문의 소스 선택을 서비스 이름 인자로 바꿉니다.
     *
     * 기본값(소스 자동·ZIP 없음)이면 빈 배열을 돌려준다 — 서비스는 종전과 같은 인자로 불린다.
     *
     * @param  array<string, mixed>  $validated  검증된 입력
     * @return array{sourceOverride?: string, zipPath?: string} 서비스 이름 인자
     *
     * @throws ValidationException 고른 ZIP 이 대기 폴더에 없을 때
     */
    protected function extensionUpdateSourceArgs(array $validated): array
    {
        $args = [];

        $source = $validated['source'] ?? null;
        if (is_string($source) && $source !== '' && $source !== 'auto') {
            $args['sourceOverride'] = $source;
        }

        $zipFile = $validated['zip_file'] ?? null;
        if (is_string($zipFile) && $zipFile !== '') {
            $zipPath = ExtensionManualZipSources::resolve($zipFile);
            if ($zipPath === null) {
                throw ValidationException::withMessages([
                    'zip_file' => [__('extensions.errors.manual_zip_not_found', ['name' => $zipFile])],
                ]);
            }
            $args['zipPath'] = $zipPath;
        }

        return $args;
    }

    /**
     * 성공 응답 `data` 에 업데이트 결과 값을 더합니다. 이미 있는 키는 덮지 않습니다.
     *
     * @param  JsonResponse  $response  성공 응답
     * @param  array<string, mixed>  $result  서비스 업데이트 결과
     * @return JsonResponse 값을 더한 응답
     */
    protected function withExtensionUpdateReport(JsonResponse $response, array $result): JsonResponse
    {
        // 객체로 읽는다 — 연관 배열로 풀면 빈 객체 `{}` 가 다시 쓸 때 `[]` 로 바뀐다(RespondsWithIntegrationNotices 와 같은 이유)
        $payload = $response->getData();
        if (! is_object($payload)) {
            return $response;
        }

        if (! isset($payload->data) || ! is_object($payload->data)) {
            $payload->data = new \stdClass;
        }

        foreach (ExtensionUpdateReport::successFields($result) as $key => $value) {
            if (! property_exists($payload->data, $key) || $key === 'no_update' || $key === 'recovered') {
                $payload->data->{$key} = $value;
            }
        }

        return $response->setData($payload);
    }

    /**
     * 업데이트 실패(검증 오류)를 응답으로 만듭니다 — 실패 뒤 상태·경고·재실행 명령을 `data` 에 싣습니다.
     *
     * @param  ValidationException  $e  서비스가 던진 검증 오류
     * @param  string  $fallbackMessage  오류 문구가 없을 때 쓸 문구
     * @return JsonResponse 422 응답
     */
    protected function extensionUpdateFailure(ValidationException $e, string $fallbackMessage): JsonResponse
    {
        // Service/Manager에서 이미 번역된 메시지를 errors에 포함하므로 첫 번째 에러를 top-level message로 직접 사용 (이중 래핑 방지)
        $firstError = collect($e->errors())->flatten()->first() ?? $fallbackMessage;
        $data = $e instanceof ExtensionUpdateRequestFailedException ? $e->failureData : null;

        return ResponseHelper::error('common.failure_reason', 422, $e->errors(), ['reason' => $firstError], 'core', $data);
    }

    /**
     * 수동 ZIP 대기 폴더의 파일 목록 응답을 만듭니다 (업데이트 창 「고급」의 ZIP 선택지).
     *
     * 폴더 절대 경로는 싣지 않는다 — 서버 경로 노출 금지. 운영자 안내는 문구(`storage/app/extension_pending`)로 한다.
     *
     * @return JsonResponse 목록 응답
     */
    protected function manualZipSourcesResponse(): JsonResponse
    {
        return ResponseHelper::success('common.success', ['sources' => ExtensionManualZipSources::list()]);
    }
}
