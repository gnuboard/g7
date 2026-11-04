<?php

namespace Plugins\Sirsoft\Popup\Http\Controllers\Public;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Plugins\Sirsoft\Popup\Services\PopupImageService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 공개 팝업 이미지 컨트롤러 — 해시 주소로 이미지를 내보낸다(저장 전 미리보기를 위해 임시 업로드도 64자 해시로 내보낸다).
 */
class PopupImageController extends PublicBaseController
{
    /**
     * 공개 이미지 캐시 유지 시간(초) — 해시 주소라 내용이 바뀌지 않는다.
     */
    private const MAX_AGE = 31536000;

    /**
     * PopupImageController 생성자
     *
     * @param  PopupImageService  $images  이미지 서비스
     */
    public function __construct(private PopupImageService $images) {}

    /**
     * 이미지를 내보냅니다.
     *
     * 응답 계약(ETag·304 재검증·`Cache-Control`·`nosniff`)은 코어 파일 서빙 헬퍼가 입힌다. 주소가 해시라 내용을 고유하게
     * 가리키므로 `immutable`, 사용자 업로드물이라 압축에서 뺀다.
     *
     * @param  string  $hash  공개 해시
     * @return StreamedResponse|Response|JsonResponse 이미지, 304 또는 404
     */
    public function show(string $hash): StreamedResponse|Response|JsonResponse
    {
        $serving = $this->images->publicServing($hash);

        if ($serving === null) {
            return $this->notFound('sirsoft-popup::messages.image.not_found');
        }

        return $this->streamedFileResponseFromMeta($serving, self::MAX_AGE, immutable: true, skipCompression: true, logContext: ['hash' => $hash]);
    }
}
