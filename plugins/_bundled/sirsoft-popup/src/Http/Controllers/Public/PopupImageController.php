<?php

namespace Plugins\Sirsoft\Popup\Http\Controllers\Public;

use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Thumbnails\ThumbnailQuery;
use App\Thumbnails\ThumbnailResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\Sirsoft\Popup\Services\PopupImageService;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
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
     * @param  ThumbnailResponder  $thumbnails  `?size=`·`?w=` 썸네일 헬퍼
     */
    public function __construct(
        private PopupImageService $images,
        private ThumbnailResponder $thumbnails,
    ) {}

    /**
     * 이미지를 내보냅니다.
     *
     * 응답 계약(ETag·304 재검증·`Cache-Control`·`nosniff`)은 코어 파일 서빙 헬퍼가 입힌다. 주소가 해시라 내용을 고유하게
     * 가리키므로 `immutable`(썸네일을 요청했는데 원본을 낼 때는 코어 `markBypass()` 가 끄고 유지 시간도 썸네일과 같게 줄인다), 사용자 업로드물이라 압축에서 뺀다.
     *
     * `?size={규격}`·`?w={숫자}` 가 붙으면 래스터 이미지는 썸네일로 낸다. PC·모바일 이미지는 기기별로 따로 올린 각자의 원본이라
     * 각자 썸네일을 갖는다. 해시만 알면 받는 공개 원본이라 썸네일도 공개 썸네일 디스크에 두고, 그 디스크가 공개 자산 디스크(CDN 등)면
     * 302 로 직접 주소를 알려 준다. 낼 수 없으면(미정의 규격·작은 원본·움직이는 이미지 등) 원본에 `X-G7-Thumbnail: bypass:{사유}` 를 붙인다.
     *
     * @param  Request  $request  요청 (`size`·`w`)
     * @param  string  $hash  공개 해시
     * @return StreamedResponse|SymfonyResponse|JsonResponse 이미지·썸네일(200·304·302) 또는 404
     */
    // audit:allow controller-base-request-injection reason: 썸네일 파라미터(size·w)를 원문 쿼리로 판정한다(중복 키·배열 키 거부)
    public function show(Request $request, string $hash): StreamedResponse|SymfonyResponse|JsonResponse
    {
        // 게이트 — 해시 조회가 전부다(공개 원본)
        $image = $this->images->findPublic($hash);

        if ($image === null) {
            return $this->notFound('sirsoft-popup::messages.image.not_found');
        }

        // 썸네일을 요청했으면 원본이 저장소에 있는지부터 본다 — 원격 저장소에서 원본만 지워진 경우 남은 파생본으로 200 을 내면
        // 무파라미터 주소(404)와 갈린다. 무파라미터 요청은 아래 `publicServing()` 이 같은 판정을 한다.
        if (ThumbnailQuery::requested($request) && ! $this->images->originalExists($image)) {
            return $this->notFound('sirsoft-popup::messages.image.not_found');
        }

        // 게이트 바로 뒤·원본 응답 바로 앞 — 서비스 `publicServing()` 안에서 부르지 않는 까닭은 원본의 304 판정을 이 컨트롤러가
        // 하기 때문이다(서비스가 썸네일을 돌려주면 아래 `streamedFileResponseFromMeta()` 가 원본 검증자로 다시 싼다).
        if ($thumbnail = $this->thumbnails->respond($request, $this->images->thumbnailSource($image))) {
            return $thumbnail;
        }

        $serving = $this->images->publicServing($image);

        if ($serving === null) {
            return $this->notFound('sirsoft-popup::messages.image.not_found');
        }

        // 썸네일을 요청했는데 원본을 내는 경우(미정의 규격·작은 원본·움직이는 이미지 등)의 캐시(유지 시간을 썸네일 응답과 같은
        // `cache.layout_ttl` 로, immutable 은 빼고)는 아래 `markBypass()` 가 맞춘다 — 원본 응답은 무파라미터 주소와 같게 만든다.
        $response = $this->streamedFileResponseFromMeta($serving, self::MAX_AGE, immutable: true, skipCompression: true, logContext: ['hash' => $hash]);

        return $response instanceof JsonResponse ? $response : ThumbnailResponder::markBypass($response, $request);
    }
}
