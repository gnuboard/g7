<?php

namespace App\Http\Controllers\Concerns;

use App\Services\ExtensionBundleService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 확장(모듈/플러그인) 병합 번들 서빙의 공통 판정 (#122 E1/E2).
 *
 * 모듈 컨트롤러와 플러그인 컨트롤러가 **같은 판정**을 해야 한다. 각자 구현하면 한쪽만
 * 고쳐진 채 다른 쪽이 조용히 옛 동작으로 남는다 — 실제로 두 컨트롤러는 지금까지 동일
 * 코드를 복제하고 있었다.
 *
 * 판정은 셋으로 갈린다:
 *
 *  1. 병합 결과가 있고 디스크 캐시도 성공 → 파일 응답 (ETag/304/immutable 재사용)
 *  2. 병합 결과는 있는데 디스크 캐시가 실패 → **메모리 결과를 그대로 200** 으로 서빙.
 *     디스크 캐시는 최적화이므로 쓰기 실패가 공개 엔드포인트의 500 이 되면 안 된다.
 *  3. 병합 결과가 비었음 →
 *     - 에셋을 선언한 활성 확장이 **0개**면 정상 → 빈 200
 *     - 선언은 있는데 결과가 0 이면 장애(배포 중 `dist` 가 잠깐 빔, 경로 어긋남) →
 *       **503**. 빈 200 으로 내보내면 프론트는 404 도 오류도 받지 못한 채 한참 뒤
 *       "Unknown action handler" 로 죽어, 원인이 번들이라는 사실이 드러나지 않는다.
 */
trait ServesExtensionBundles
{
    /**
     * 확장 병합 번들을 서빙합니다.
     *
     * @param  ExtensionBundleService  $bundleService  번들 서비스
     * @param  string  $type  'module' | 'plugin'
     * @param  string  $kind  'js' | 'css'
     * @param  string  $mimeType  응답 Content-Type
     * @return BinaryFileResponse|Response 번들 응답
     */
    protected function bundleResponse(
        ExtensionBundleService $bundleService,
        string $type,
        string $kind,
        string $mimeType
    ): BinaryFileResponse|Response {
        $version = $bundleService->getCurrentVersion();
        $path = $bundleService->getBundleFilePath($type, $kind, $version);

        if ($path !== '') {
            return $this->fileResponse($path, $mimeType, 31536000);
        }

        // 파일 경로가 비었다 — 병합 결과 자체가 비었거나, 디스크 캐시가 실패했다.
        $content = $bundleService->buildBundleContent($type, $kind);

        if ($content !== '') {
            // 디스크 캐시 실패 (2) — 메모리 결과로 서빙한다. 캐시 헤더는 붙이지 않는다:
            // 이 응답은 캐시에 실패한 상태의 산출물이라 다음 요청에서 정상 경로로
            // 돌아갈 수 있어야 한다.
            return response($content, 200)
                ->header('Content-Type', $mimeType)
                ->header('Cache-Control', 'no-cache, private');
        }

        $declared = $bundleService->countAssetDeclaringExtensions($type, $kind);

        if ($declared > 0) {
            // (3) 장애 — 선언은 있는데 결과가 0
            Log::error('확장 번들 병합 결과가 비었습니다 — 에셋을 선언한 활성 확장이 있습니다', [
                'type' => $type,
                'kind' => $kind,
                'version' => $version,
                'declared_extensions' => $declared,
            ]);

            return response('', 503)
                ->header('Content-Type', $mimeType)
                ->header('Cache-Control', 'no-cache, private');
        }

        // (3) 정상 — 선언이 0개면 빈 번들이 맞다
        return response('', 200)->header('Content-Type', $mimeType);
    }
}
