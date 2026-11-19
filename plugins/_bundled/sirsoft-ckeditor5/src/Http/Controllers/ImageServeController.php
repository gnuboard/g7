<?php

// audit:allow api-doc-coverage 609 후속 — serve 가 내보낸 검증자를 코어 파일 서빙 단일 지점에서 판정하게 되어 조건부 요청 304 와 ETag, Last-Modified, Cache-Control 헤더가 더해졌다(라우트, 요청은 그대로). 그 캐시 계약과 304 는 docs/api/images.md 가 이미 다룬다.

namespace Plugins\Sirsoft\Ckeditor5\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Thumbnails\ThumbnailQuery;
use App\Thumbnails\ThumbnailResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\Sirsoft\Ckeditor5\Services\ImageServeService;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * CKEditor5 이미지 서빙 컨트롤러
 *
 * plugins 디스크에 저장된 이미지를 공개 API를 통해 서빙합니다.
 * 인증 없이 접근 가능한 공개 엔드포인트입니다.
 */
class ImageServeController extends PublicBaseController
{
    /**
     * 해시 조회와 응답 조립을 맡는 이미지 서빙 서비스를 주입받는다.
     *
     * @param  ImageServeService  $imageServeService  이미지 조회와 스트림 응답을 만드는 서비스
     * @param  ThumbnailResponder  $thumbnails  `?size=`·`?w=` 썸네일 헬퍼
     */
    public function __construct(
        private ImageServeService $imageServeService,
        private ThumbnailResponder $thumbnails
    ) {}

    /**
     * 이미지 서빙
     *
     * `?size={규격}`·`?w={숫자}` 가 붙으면 래스터 이미지는 썸네일로 낸다. 본문 이미지는 인증 없이 보이는
     * 완전 공개 원본이라 썸네일도 공개 썸네일 디스크에 두고, 그 디스크가 공개 자산 디스크(CDN 등)면
     * 302 로 직접 주소를 알려 준다. 낼 수 없으면(미정의 규격·작은 원본·움직이는 이미지 등) 원본에
     * `X-G7-Thumbnail: bypass:{사유}` 를 붙인다.
     *
     * @param  Request  $request  요청 (`size`·`w`)
     * @param  string  $hash  이미지 해시 (12자)
     * @return SymfonyResponse|JsonResponse 이미지·썸네일 스트림(200·304), 302, 또는 에러 응답
     */
    // audit:allow controller-base-request-injection reason: 썸네일 파라미터(size·w)를 원문 쿼리로 판정한다(중복 키·배열 키 거부)
    public function serve(Request $request, string $hash): SymfonyResponse|JsonResponse
    {
        $image = $this->imageServeService->findByHash($hash);

        if (! $image) {
            return ResponseHelper::notFound('messages.image.not_found', [], 'sirsoft-ckeditor5');
        }

        // 썸네일을 요청했으면 원본이 저장소에 있는지부터 본다 — 원격 저장소에서 원본만 지워진 경우 남은 파생본으로
        // 200 을 내면 무파라미터 주소(404)와 갈린다. 무파라미터 요청은 아래 `serve()` 가 같은 확인을 한다.
        if (ThumbnailQuery::requested($request) && ! $this->imageServeService->originalExists($image)) {
            return ResponseHelper::notFound('messages.image.not_found', [], 'sirsoft-ckeditor5');
        }

        // 공개 원본이라 게이트는 해시 조회가 전부다 — 그 뒤, 원본 응답 바로 앞에서 썸네일을 판정한다.
        // 서비스 `serve()` 안에서 부르지 않는 까닭: 원본의 304 판정을 이 컨트롤러가 하므로, 서비스가
        // 썸네일을 돌려주면 아래 `streamedFileResponseFromMeta()` 가 그것을 원본 검증자로 다시 싸 버린다.
        if ($thumbnail = $this->thumbnails->respond($request, $this->imageServeService->thumbnailSource($image))) {
            return $thumbnail;
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
        // 형제 게시판(`sirsoft-board` AttachmentController)과 같은 형태다.
        //
        // `immutable` 은 `false` 고정 — 이 URL 에는 `?v=` 가 없고 같은 주소의 내용이
        // 교체될 수 있다(코어 파일 서빙 계약).
        //
        // 사용자가 올린 파일이라 크기·건수가 유계가 아니다 — 압축 제외를 응답 클래스에
        // 기대지 않고 **선언**한다(공개 스토리지 통로와 같은 형태).
        $validators = $this->imageServeService->cacheValidators($image);

        //
        // MIME 은 행 컬럼이 SSoT — 종전에는 이 통로만 `mimeType` 을 넘기지 않아 스토리지
        // 어댑터의 추정(사본)에 기댔다. 키 검증은 코어 헬퍼
        // (`streamedFileResponseFromMeta()`)가 한다 (같은 라운드 E-6).
        $served = $this->streamedFileResponseFromMeta(
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

        return $served instanceof JsonResponse ? $served : ThumbnailResponder::markBypass($served, $request);
    }
}
