<?php

namespace Plugins\Sirsoft\HeroSection\Http\Controllers\Public;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Plugins\Sirsoft\HeroSection\Services\BannerImageService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 공개 배너 이미지 컨트롤러 — 해시 주소로 배너에 연결된 이미지만 내보낸다(임시 업로드는 공개하지 않는다).
 */
class BannerImageController extends PublicBaseController
{
    /**
     * 공개 미디어 캐시 유지 시간(초) — 해시 주소라 내용이 바뀌지 않는다.
     */
    private const MAX_AGE = 31536000;

    /**
     * BannerImageController 생성자
     *
     * @param  BannerImageService  $images  이미지 서비스
     */
    public function __construct(private BannerImageService $images) {}

    /**
     * 이미지를 내보냅니다.
     *
     * 응답 계약(ETag·304 재검증·`Cache-Control`·`nosniff`)은 코어 파일 서빙 헬퍼가 입힌다. 주소가 해시라 내용을 고유하게
     * 가리키므로 `immutable`, 사용자 업로드물이라 압축에서 뺀다. 로컬 영상은 범위 요청을 받는 파일 응답으로 낸다.
     *
     * @param  string  $hash  공개 해시
     * @return StreamedResponse|BinaryFileResponse|Response|JsonResponse 이미지·영상(영상은 범위 요청 지원), 304 또는 404
     */
    public function show(string $hash): StreamedResponse|BinaryFileResponse|Response|JsonResponse
    {
        $serving = $this->images->publicServing($hash);

        if ($serving === null) {
            return $this->notFound('sirsoft-hero_section::messages.image.not_found');
        }

        if (isset($serving['file'])) {
            return $this->fileResponse($serving['file'], $serving['mime_type'], self::MAX_AGE, immutable: true, skipCompression: true);
        }

        return $this->streamedFileResponseFromMeta($serving, self::MAX_AGE, immutable: true, skipCompression: true, logContext: ['hash' => $hash]);
    }
}
