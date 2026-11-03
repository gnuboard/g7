<?php

namespace Modules\Sirsoft\Ecommerce\Services\Concerns;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 공개 이미지 서빙 정보 trait (서비스 공용)
 *
 * 상품·카테고리·리뷰 이미지 서빙은 `Cache-Control: max-age=31536000` 만 싣고 검증자
 * (`ETag` · `Last-Modified`)를 하나도 싣지 않았습니다. 그러면 만료 전에는 재검증 자체가
 * 불가능하고(운영자가 파일을 갈아 끼워도 클라이언트는 1년간 옛것을 본다), 만료 뒤에는
 * 내용이 그대로여도 매번 전문을 받아 갑니다 — 304 로 끝날 왕복이 하나도 없습니다.
 * 오류도 로그도 남지 않고 대역폭과 신선도 양쪽에서만 조용히 손해가 납니다
 * (11차 G3 라운드 B11-12).
 *
 * 검증자는 **행 메타**에서 만듭니다 — 파일 stat 이 없으므로 원격 디스크(S3 등)에서도
 * 결정적이고, 코어 형제(`App\Services\AttachmentService::getFileInfo()`)가 쓰는 소스 구성
 * (disk|path|updated_at|size)과 같습니다.
 *
 * **캐시 계약은 이 trait 이 만들지 않습니다.** 13차까지는 여기서 `Cache-Control`·`ETag` 를
 * 직접 세우고 Symfony `isNotModified()` 로 판정한 뒤 자체 304 객체를 돌려줬는데, 그것은
 * 코어 단일 지점(`BaseApiController::streamedFileResponse()`)의 **사본**이었고 세 축에서
 * 이미 갈려 있었습니다 — ① `Cache-Control` 이 환경과 무관하게 고정(코어는 비운영 환경에서
 * `no-cache`), ② 판정이 `-gzip` 접미사를 흡수하지 못함(앞단 CDN·정적 서빙이 붙인 값을
 * 클라이언트가 되보내면 영원히 전문 200), ③ `Expires` 부재(코어는 200·304 모두 싣는다).
 * 사본은 갈리는 것이 정상이므로 계약을 여기에 두지 않고, 이 trait 은 코어 형제와 같은
 * 형태의 **서빙 정보**(스트림 + 검증자 메타)만 돌려주며 컨트롤러가 그것을 코어 지점에
 * 넘깁니다 — 형제 `sirsoft-page`·`sirsoft-ckeditor5` 가 13차에 택한 형태입니다
 * (14차 G3 라운드).
 *
 * 사용 서비스는 `ResolvesRowStorage`(행 disk 해석)를 함께 씁니다.
 */
trait ServesImageWithRowValidators
{
    /**
     * 공개 이미지 서빙의 캐시 유지 시간 (초) — 1년.
     *
     * 세 서비스가 같은 값을 리터럴로 들고 있던 것을 한 자리로 모읍니다. 컨트롤러가 코어
     * 지점에 넘기는 값이므로 서빙 정보(`max_age`)에 실어 보냅니다 — 컨트롤러마다 리터럴을
     * 두면 다시 세 벌이 됩니다.
     */
    public const IMAGE_CACHE_MAX_AGE = 31536000;

    /**
     * 행 메타 기준 서빙 정보를 만듭니다 (스트림 + 검증자 메타).
     *
     * 스트림에는 `Content-Type` 과 `Last-Modified` 만 세웁니다. 검증자 비교·304 생성·
     * `Cache-Control`/`Expires`/`Vary` 동반은 코어 지점이 하며, 그 지점은 `Last-Modified`
     * 를 스스로 세우므로 여기서 세운 값은 같은 값으로 덮입니다(형제 통로와 같은 상태).
     *
     * 13차 기록 정정 — 종전 독블록은 「`StreamedResponse` 는 상태가 304 여도 `sendContent()`
     * 가 콜백을 그대로 실행해 본문이 실렸다」고 적었는데 **사실이 아닙니다.** Symfony
     * `Response::setNotModified()` 는 `setContent(null)` 을 부르고, `StreamedResponse::setContent()`
     * 는 `null` 이 아닌 값에만 예외를 던지며 `streamed = true` 로 표시하므로, 그 뒤의
     * `sendContent()` 는 첫 줄에서 반환합니다(no-op). 즉 `isNotModified()` 가 제자리에서
     * 바꾼 304 도 본문을 싣지 않았고, 그 단언은 어떤 구현으로도 red 가 되지 않는
     * 공허한 것이었습니다. 실제 결함은 ① `Last-Modified` 소실(`setNotModified()` 의 제거
     * 목록)과 ② 위 독블록의 세 축이었습니다.
     *
     * @param  Model  $image  이미지 행 (disk·path·mime_type·original_filename·file_size·updated_at 보유)
     * @return array{response: StreamedResponse, etag_source: string, mime_type: string, filename: string, last_modified: int|null, max_age: int}|null 서빙 정보 (파일 미존재 시 null)
     */
    protected function imageServeInfo(Model $image): ?array
    {
        $lastModifiedAt = $image->updated_at?->getTimestamp();

        $headers = ['Content-Type' => $image->mime_type];

        if ($lastModifiedAt !== null) {
            $headers['Last-Modified'] = gmdate('D, d M Y H:i:s', $lastModifiedAt).' GMT';
        }

        $response = $this->storageForRow($image->disk)->response(
            'images',
            $image->path,
            $image->original_filename,
            $headers
        );

        if ($response === null) {
            return null;
        }

        return [
            'response' => $response,
            // 파일 stat 없이 결정적인 ETag 소스 — 코어 형제와 같은 구성.
            'etag_source' => implode('|', [
                (string) $image->disk,
                (string) $image->path,
                (string) $lastModifiedAt,
                (string) $image->file_size,
            ]),
            'mime_type' => (string) $image->mime_type,
            'filename' => (string) $image->original_filename,
            'last_modified' => $lastModifiedAt,
            'max_age' => self::IMAGE_CACHE_MAX_AGE,
        ];
    }
}
