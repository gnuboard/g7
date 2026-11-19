<?php

namespace App\Thumbnails\Http;

use App\Http\Controllers\Api\Base\BaseApiController;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 썸네일 스트림 응답에 코어 파일 서빙 캐시 계약(ETag·304·Cache-Control·nosniff)을 입히는 어댑터.
 *
 * 그 계약은 `BaseApiController::streamedFileResponse()` 한 곳이 소유한다. 썸네일 헬퍼는 서비스에서도
 * 불리므로(컨트롤러가 아니다) 사본을 만들지 않고 이 어댑터로 같은 지점을 지난다.
 */
final class ThumbnailFileResponse extends BaseApiController
{
    /**
     * 스토리지 스트림에 캐시 계약을 입힙니다.
     *
     * @param  StreamedResponse  $response  썸네일 디스크의 인라인 스트림
     * @param  string  $etagSource  ETag 소스 (`{키}|{파생본 수정 시각}`)
     * @param  int  $maxAge  캐시 유지 시간(초)
     * @param  int|null  $lastModifiedAt  파생본 수정 시각
     * @param  string  $mimeType  파생본 MIME
     * @param  bool  $private  권한 원본의 썸네일인지 (공유 캐시 저장 금지)
     * @return StreamedResponse|Response 스트림 또는 304
     */
    public function stream(
        StreamedResponse $response,
        string $etagSource,
        int $maxAge,
        ?int $lastModifiedAt,
        string $mimeType,
        bool $private
    ): StreamedResponse|Response {
        return $this->streamedFileResponse(
            $response,
            $etagSource,
            $maxAge,
            // 파생본 키는 규격 지문을 담지만 원본이 같은 경로에서 교체될 수 있다 — 첨부 통로와 같이 false 고정
            immutable: false,
            lastModifiedAt: $lastModifiedAt,
            mimeType: $mimeType,
            skipCompression: true,
            private: $private
        );
    }
}
