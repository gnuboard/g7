<?php

namespace Plugins\Sirsoft\Ckeditor5\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Plugins\Sirsoft\Ckeditor5\Services\ImageServeService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CKEditor5 이미지 서빙 컨트롤러
 *
 * plugins 디스크에 저장된 이미지를 공개 API를 통해 서빙합니다.
 * 인증 없이 접근 가능한 공개 엔드포인트입니다.
 */
class ImageServeController extends PublicBaseController
{
    public function __construct(
        private ImageServeService $imageServeService
    ) {}

    /**
     * 이미지 서빙
     *
     * @param  string  $hash  이미지 해시 (12자)
     * @return StreamedResponse|Response|JsonResponse 이미지 스트림, 304, 또는 에러 응답
     */
    public function serve(string $hash): StreamedResponse|Response|JsonResponse
    {
        $image = $this->imageServeService->findByHash($hash);

        if (! $image) {
            return ResponseHelper::notFound('messages.image.not_found', [], 'sirsoft-ckeditor5');
        }

        $response = $this->imageServeService->serve($image);

        if (! $response) {
            return ResponseHelper::notFound('messages.image.not_found', [], 'sirsoft-ckeditor5');
        }

        // 내보낸 검증자를 **실제로 판정**한다. 서비스는 `ETag`·`Last-Modified` 를 싣는데
        // 종전에는 그것을 돌려보낸 `If-None-Match` / `If-Modified-Since` 를 아무도 보지 않아
        // 재검증이 **영원히 전문 200** 이었다 — 검증자를 싣지 않던 시절과 대역폭이 같다.
        // 오류도 로그도 없이 매 재검증이 전문으로 나가는 형태라 응답 헤더를 재는 것 말고는
        // 드러날 통로가 없다.
        //
        // 판정은 코어 단일 지점(`streamedFileResponse()`)이 소유한다 — `-gzip` 접미사·
        // 다중값·`W/` 약한 검증자 흡수, 304 에 200 의 `Cache-Control`/`Expires`/
        // `Last-Modified` 동반, 라우터가 벗기는 `Last-Modified` 복원까지 그 한 곳이 한다.
        // 형제 게시판(`sirsoft-board` AttachmentController)과 같은 형태다 (13차 G3 라운드).
        //
        // `immutable` 은 `false` 고정 — 이 URL 에는 `?v=` 가 없고 같은 주소의 내용이
        // 교체될 수 있다(코어 파일 서빙 계약).
        //
        // 사용자가 올린 파일이라 크기·건수가 유계가 아니다 — 압축 제외를 응답 클래스에
        // 기대지 않고 **선언**한다(공개 스토리지 통로와 같은 형태, 14차 G3 라운드).
        $validators = $this->imageServeService->cacheValidators($image);

        //
        // MIME 은 행 컬럼이 SSoT — 종전에는 이 통로만 `mimeType` 을 넘기지 않아 스토리지
        // 어댑터의 추정(사본)에 기댔다 (16차 G3 라운드 E-2). 키 검증은 코어 헬퍼
        // (`streamedFileResponseFromMeta()`)가 한다 (같은 라운드 E-6).
        return $this->streamedFileResponseFromMeta(
            [
                ...$validators,
                'response' => $response,
                'mime_type' => (string) $image->mime_type,
            ],
            ImageServeService::CACHE_MAX_AGE,
            immutable: false,
            skipCompression: true,
            logContext: ['hash' => $hash],
            errorMessageKey: 'sirsoft-ckeditor5::messages.image.not_found'
        );
    }
}
