<?php

namespace App\Helpers;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * 업로드 파일을 브라우저에 인라인으로 보여 줘도 되는지 판정하고 서빙 응답 헤더를 굳힙니다.
 *
 * `image/` 접두사만으로 이미지를 판정하면 `image/svg+xml` 도 이미지로 취급되어 인라인 서빙된다.
 * SVG 는 스크립트를 품을 수 있는 문서라, 앱 오리진에서 최상위로 열리면 그 스크립트가 로그인
 * 토큰에 닿는다. 그래서 인라인 표시는 **스크립트를 실행할 수 없는 래스터 이미지**로만 한정하고,
 * 그 밖의 형식은 다운로드(Content-Disposition: attachment)로 내보낸다.
 *
 * 첨부·게시판·페이지·상품 이미지 등 업로드 파일을 서빙하는 모든 지점이 이 판정을 공유한다.
 */
final class InlineMediaPolicy
{
    /**
     * 인라인 표시를 허용하는 래스터 이미지 MIME 타입
     *
     * @var array<int, string>
     */
    public const RASTER_IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/pjpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
        'image/bmp',
        'image/x-ms-bmp',
    ];

    /**
     * 비래스터 응답에 거는 CSP — 다운로드를 무시하고 렌더하는 환경에서도 스크립트·외부 로드를 막는다.
     */
    public const NON_INLINE_CSP = "default-src 'none'; style-src 'unsafe-inline'; sandbox";

    /**
     * MIME 타입이 인라인 표시 가능한 래스터 이미지인지 판정합니다.
     *
     * @param  string|null  $mimeType  MIME 타입 (파라미터 `; charset=` 등은 무시)
     * @return bool 래스터 이미지이면 true
     */
    public static function isInlineImage(?string $mimeType): bool
    {
        if ($mimeType === null || $mimeType === '') {
            return false;
        }

        $normalized = strtolower(trim(explode(';', $mimeType, 2)[0]));

        return in_array($normalized, self::RASTER_IMAGE_MIME_TYPES, true);
    }

    /**
     * 업로드 파일 서빙 응답에 보안 헤더를 붙입니다.
     *
     * 항상 `X-Content-Type-Options: nosniff` 를 붙여 브라우저의 내용 추측을 막는다.
     * 래스터 이미지가 아니면 다운로드로 강제하고(Content-Disposition: attachment) CSP 로 격리한다.
     *
     * @param  Response  $response  서빙 응답
     * @param  string|null  $mimeType  파일 MIME 타입 (null 이면 응답의 Content-Type 사용)
     * @param  string|null  $filename  다운로드 파일명 (null 이면 기존 Content-Disposition 의 파일명 유지 시도)
     * @return Response 헤더가 적용된 같은 응답
     */
    public static function harden(Response $response, ?string $mimeType = null, ?string $filename = null): Response
    {
        $mimeType ??= $response->headers->get('Content-Type');

        $response->headers->set('X-Content-Type-Options', 'nosniff');

        if (self::isInlineImage($mimeType)) {
            return $response;
        }

        $response->headers->set('Content-Disposition', self::attachmentDisposition(
            $filename ?? self::filenameFromDisposition($response->headers->get('Content-Disposition'))
        ));
        $response->headers->set('Content-Security-Policy', self::NON_INLINE_CSP);

        return $response;
    }

    /**
     * attachment Content-Disposition 헤더 값을 만듭니다 (비 ASCII 파일명은 RFC 6266 filename* 로 인코딩).
     *
     * @param  string|null  $filename  파일명
     * @return string Content-Disposition 헤더 값
     */
    private static function attachmentDisposition(?string $filename): string
    {
        if ($filename === null || $filename === '') {
            return HeaderUtils::DISPOSITION_ATTACHMENT;
        }

        $fallback = preg_replace('/[^\x20-\x7e]|[\/\\\\%"]/', '_', $filename) ?: 'download';

        return HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, $fallback);
    }

    /**
     * 기존 Content-Disposition 헤더에서 파일명을 꺼냅니다.
     *
     * @param  string|null  $disposition  Content-Disposition 헤더 값
     * @return string|null 파일명 (없으면 null)
     */
    private static function filenameFromDisposition(?string $disposition): ?string
    {
        if ($disposition === null || $disposition === '') {
            return null;
        }

        $parts = HeaderUtils::combine(HeaderUtils::split($disposition, ';='));

        if (isset($parts['filename*']) && is_string($parts['filename*'])) {
            $encoded = $parts['filename*'];
            $pos = strpos($encoded, "''");

            return rawurldecode($pos !== false ? substr($encoded, $pos + 2) : $encoded);
        }

        return isset($parts['filename']) && is_string($parts['filename']) ? $parts['filename'] : null;
    }
}
