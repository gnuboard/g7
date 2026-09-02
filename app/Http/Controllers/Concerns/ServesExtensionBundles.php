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
 *     - 선언한 산출물이 **소실·판독 불가**면 장애(배포 중 `dist` 가 잠깐 빔, 경로
 *       어긋남) → **503**. 빈 200 으로 내보내면 프론트는 404 도 오류도 받지 못한 채
 *       한참 뒤 "Unknown action handler" 로 죽어, 원인이 번들이라는 사실이 드러나지 않는다.
 *     - 산출물이 **존재하되 비어 있으면** 정상 → 빈 200. 스타일 소스가 자리표시 주석뿐이라
 *       0바이트 CSS 를 내보내는 확장은 정당한 상태다. 선언 축만으로 판정하면 그런 확장만
 *       설치된 기본 구성이 통째로 503 이 되어 사용자 화면마다 실패 안내가 뜬다.
 *     - 선언이 **0개**여도 정상 → 빈 200
 *
 * 세 갈래 모두 `nosniff` 를 싣는다 — 파일 응답만 그 헤더를 붙이면 같은 주소가 활성 확장
 * 유무에 따라 다른 헤더를 공표한다 (G8 실측 2026-09-02 · 계획서 §9.11.3 F2).
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
            return $this->withNoSniff(
                response($content, 200)
                    ->header('Content-Type', $mimeType)
                    ->header('Cache-Control', 'no-cache, private')
            );
        }

        $missing = $bundleService->findMissingDeclaredAssets($type, $kind);

        if ($missing !== []) {
            // (3) 장애 — 선언한 산출물이 소실·판독 불가 (배포 중 dist 가 잠깐 빔, 경로 어긋남).
            // 어느 파일이 없는지를 함께 남긴다 — 선언 수만으로는 운영자가 조치 대상을 짚을 수 없다.
            Log::error('확장 번들 병합 결과가 비었습니다 — 선언된 산출물이 없거나 읽을 수 없습니다', [
                'type' => $type,
                'kind' => $kind,
                'version' => $version,
                'declared_extensions' => $bundleService->countAssetDeclaringExtensions($type, $kind),
                'missing' => $missing,
            ]);

            return $this->withNoSniff(
                response('', 503)
                    ->header('Content-Type', $mimeType)
                    ->header('Cache-Control', 'no-cache, private')
            );
        }

        // (3) 정상 — 선언이 0 이거나, 선언한 산출물이 전부 존재하되 비어 있다
        // (스타일이 비어 있는 확장). 후자를 장애로 보면 0바이트 산출물 하나가 사이트
        // 전 화면에 실패 알림을 띄운다 (G8 실측 2026-09-02 · 계획서 §9.11.3 F2).
        // 빈 번들은 빈 번들로 서빙한다.
        return $this->withNoSniff(response('', 200)->header('Content-Type', $mimeType));
    }

    /**
     * 파일이 아닌 번들 응답에도 `X-Content-Type-Options: nosniff` 를 싣습니다.
     *
     * 파일이 있는 응답은 `fileResponse()` 가 그 헤더를 붙인다. 손으로 조립하는 세 갈래
     * (메모리 폴백·장애·빈 번들)만 빠지면 **같은 주소가 활성 확장 유무에 따라 다른 헤더를
     * 공표한다** — 번들에는 확장이 올린 코드가 실리므로 그 차이는 무해하지 않다.
     *
     * @param  Response  $response  조립한 응답
     * @return Response 헤더가 붙은 응답
     */
    private function withNoSniff(Response $response): Response
    {
        $this->declareNoSniff($response);

        return $response;
    }
}
