<?php

namespace App\Http\Responses;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 스트림 파일 서빙 메타 값 객체 — 서비스가 돌려준 서빙 정보의 **키 검증 단일 지점**.
 *
 * 서비스(코어 첨부·게시판 첨부·이커머스 이미지·사이트맵 등)는 스트림 응답과 검증자 메타를
 * 배열로 돌려주고, 컨트롤러가 그것을 코어 헬퍼(`BaseApiController::streamedFileResponse()`)에
 * 넘긴다. 그 사이의 「키가 다 있는가」 판정이 종전에는 컨트롤러마다 `array_key_exists` 가드
 * 사본으로 있었다(코어 첨부·게시판 미리보기 두 곳) — 나머지 아홉 통로에는 가드가 없어,
 * 서비스가 키를 빠뜨리면 배열 접근 경고가 `ErrorException` 으로 올라 무인증 표면이 500 이
 * 되거나(`etag_source`), 조용히 축 하나가 사라졌다(`last_modified`). 서비스는 확장에 열려
 * 있으므로 「지금 구현이 채운다」는 방어가 아니다. 판정을 한 곳으로 모아 열한 통로가 같은
 * 규칙을 갖게 한다.
 *
 * 값은 배열 형태 그대로다 — `mime_type` 은 빈 문자열일 수 있고(그 해석은 헬퍼가 한다:
 * 알 수 없으면 `application/octet-stream`), `last_modified` 는 `null` 일 수 있다(모르면
 * `Last-Modified` 를 싣지 않는다). 키의 **존재**만 묻는다 — `?? null` 폴백은 그 축을 소리
 * 없이 없애므로 쓰지 않는다.
 */
final class FileServingMeta
{
    /**
     * 모든 통로가 반드시 채워야 하는 키.
     *
     * @var array<string>
     */
    public const REQUIRED_KEYS = ['response', 'etag_source', 'mime_type', 'last_modified'];

    /**
     * @param  StreamedResponse  $response  스토리지가 만든 스트림 응답 (inline 또는 attachment)
     * @param  string  $etagSource  ETag 소스 (행 메타 — 디스크·경로·수정시각·크기)
     * @param  string  $mimeType  200 응답의 Content-Type 근거 (빈 문자열 = 모름)
     * @param  int|null  $lastModifiedAt  마지막 수정 시각(Unix time), 모르면 null
     * @param  int|null  $maxAge  서비스가 정한 캐시 유지 시간(초). 통로가 자기 값을 쓰면 null
     */
    public function __construct(
        public readonly StreamedResponse $response,
        public readonly string $etagSource,
        public readonly string $mimeType,
        public readonly ?int $lastModifiedAt,
        public readonly ?int $maxAge = null,
    ) {}

    /**
     * 서비스가 돌려준 배열에서 값 객체를 만듭니다.
     *
     * @param  array<string, mixed>  $meta  서빙 정보 (`response`·`etag_source`·`mime_type`·`last_modified`[·`max_age`])
     * @param  bool  $requireMaxAge  통로가 `max_age` 를 서비스에 맡기는가
     * @return self 값 객체
     *
     * @throws \InvalidArgumentException 필수 키가 없거나 `response` 가 스트림 응답이 아닐 때
     */
    public static function fromArray(array $meta, bool $requireMaxAge = false): self
    {
        $missing = self::missingKey($meta, $requireMaxAge);

        if ($missing !== null) {
            throw new \InvalidArgumentException(__('exceptions.file_serving.meta_key_missing', ['key' => $missing]));
        }

        return new self(
            $meta['response'],
            (string) $meta['etag_source'],
            (string) $meta['mime_type'],
            $meta['last_modified'] === null ? null : (int) $meta['last_modified'],
            $requireMaxAge ? (int) $meta['max_age'] : null,
        );
    }

    /**
     * 빠진(또는 형태가 맞지 않는) 첫 번째 키를 돌려줍니다. 온전하면 `null`.
     *
     * `response` 는 존재만으로는 부족하다 — 스트림 응답이 아니면 헬퍼가 받을 수 없으므로
     * 같은 「누락」으로 본다.
     *
     * @param  array<string, mixed>  $meta  서빙 정보
     * @param  bool  $requireMaxAge  `max_age` 도 필수인가
     * @return string|null 누락 키
     */
    public static function missingKey(array $meta, bool $requireMaxAge = false): ?string
    {
        $required = self::REQUIRED_KEYS;

        if ($requireMaxAge) {
            $required[] = 'max_age';
        }

        foreach ($required as $key) {
            if (! array_key_exists($key, $meta)) {
                return $key;
            }
        }

        if (! $meta['response'] instanceof StreamedResponse) {
            return 'response';
        }

        return null;
    }
}
