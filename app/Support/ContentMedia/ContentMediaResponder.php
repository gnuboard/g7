<?php

namespace App\Support\ContentMedia;

use App\Contracts\Extension\StorageInterface;
use App\Contracts\Extension\StreamingStorageInterface;
use App\Enums\ContentMediaKind;
use App\Helpers\InlineMediaPolicy;
use App\Http\Responses\DelegatedFileResponse;
use App\Thumbnails\DTO\ThumbnailSource;
use App\Thumbnails\ThumbnailResponder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 본문 미디어 서빙 응답 — Range(206·416)·인라인 판정·보안 헤더 (#883 5단계, D14)
 *
 * 게이트(글 읽기 권한·비밀·삭제·블라인드·서명)는 호출하는 확장이 먼저 건다. 이 클래스는 응답만 만든다.
 *
 * - 로컬 디스크(`localPath()`): `BinaryFileResponse`(`DelegatedFileResponse`) — Range·If-Range·206·416 을 Symfony 가 처리한다.
 *   웹서버 파일 위임(환경설정 > 드라이버, #878)이 켜져 있으면 본문과 Range 는 웹서버가 처리한다.
 * - 원격 디스크(`StreamingStorageInterface`): 단일 구간 Range 를 직접 처리하는 `StreamedResponse`(206·416·`Accept-Ranges`).
 *   여러 구간 요청과 `If-Range` 가 붙은 요청은 200 전량으로 응답한다(RFC 9110 이 허용하는 보수적 처리).
 * - 제3자 드라이버(스트림 계약 미구현): 기존 `response()` 200.
 *
 * 원격 임시 URL 로 리다이렉트하지 않는다 — 리다이렉트하면 게이트·헤더를 통제할 수 없다.
 *
 * 헤더: 래스터 이미지는 `InlineMediaPolicy::harden`(인라인 유지). 동영상·오디오는 `$spec['playable']`(확장 정책 켬 + 코어 후보
 * 켬)이고 재생 가능 형식일 때만 `hardenPlayable`(inline + `media-src 'self'`), 아니면 `harden`(다운로드). 게이트 콘텐츠는
 * `Cache-Control: private`.
 *
 * 썸네일: 래스터 이미지에 `?size={규격}`·`?w={숫자}` 가 붙으면 다른 업로드 이미지 서빙 지점과 같은 헬퍼(`ThumbnailResponder`)로
 * 줄인 이미지를 낸다. 게이트를 지난 뒤에만 이 클래스가 불리므로 원본을 못 보는 요청은 썸네일에도 닿지 못한다. 본문 미디어는
 * 문서 공개 여부가 바뀔 수 있어 늘 권한 원본(`gated`)으로 다룬다 — 파생본은 비공개 썸네일 디스크에 두고 `private` 로 낸다.
 * 낼 수 없으면 원본에 `X-G7-Thumbnail: bypass:{사유}` 를 붙인다. 동영상·오디오와 `Range` 요청은 썸네일 분기를 지나지 않는다.
 *
 * @since 7.1.5
 */
final class ContentMediaResponder
{
    /**
     * 서빙 응답을 만듭니다.
     *
     * @param  StorageInterface  $storage  확장 저장소
     * @param  string  $category  카테고리
     * @param  string  $path  상대 경로
     * @param  array{mime_type: string, filename: string, playable?: bool, private?: bool, max_age?: int, thumbnail_source?: ThumbnailSource|null}  $spec  응답 사양 (`thumbnail_source` 를 주지 않으면 저장소와 경로로 만든다)
     * @param  Request  $request  요청 (Range 헤더, 썸네일 `size`·`w`)
     * @return Response|null 응답 (파일이 없으면 null)
     */
    public static function respond(StorageInterface $storage, string $category, string $path, array $spec, Request $request): ?Response
    {
        $mime = (string) ($spec['mime_type'] ?? 'application/octet-stream');
        $filename = (string) ($spec['filename'] ?? basename($path));
        $image = InlineMediaPolicy::isInlineImage($mime);

        // 게이트는 호출자가 이미 지났다 — 래스터 이미지에 size·w 가 오면 원본 응답 앞에서 썸네일로 낸다.
        // Range 요청은 원본 통로의 구간 응답 그대로 둔다(동영상·오디오는 이 분기에 들어오지 않는다).
        if ($image && ! $request->headers->has('Range')) {
            $source = array_key_exists('thumbnail_source', $spec)
                ? ($spec['thumbnail_source'] instanceof ThumbnailSource ? $spec['thumbnail_source'] : null)
                : self::thumbnailSource($storage, $category, $path, $mime, maxAge: self::thumbnailMaxAge($spec));
            $thumbnail = app(ThumbnailResponder::class)->respond($request, $source);

            if ($thumbnail !== null) {
                return $thumbnail;
            }
        }

        if ($storage instanceof StreamingStorageInterface) {
            $local = $storage->localPath($category, $path);
            if ($local !== null) {
                // 웹서버 파일 위임(#878)이 켜져 있으면 본문·Range 를 웹서버가 맡는다. 게이트(권한·발행·전시)는
                // 호출자가 이 함수를 부르기 전에 통과시켰다 — 위임 위치는 nginx `internal;` 이라 바깥에서 직접 열 수 없다.
                $response = new DelegatedFileResponse($local, 200, ['Content-Type' => $mime], true, null, false, true);
                $response->prepare($request);
            } else {
                $response = self::remoteResponse($storage, $category, $path, $mime, $request);
            }
        } else {
            $response = $storage->response($category, $path, $filename, ['Content-Type' => $mime]);
        }

        if ($response === null) {
            return null;
        }

        if (! $image && ! empty($spec['playable']) && InlineMediaPolicy::isInlinePlayable($mime)) {
            InlineMediaPolicy::hardenPlayable($response, $mime, $filename);
        } else {
            InlineMediaPolicy::harden($response, $mime, $filename);
        }

        self::applyCacheHeaders($response, $spec);

        // 썸네일을 요청했는데 원본을 낼 때만 사유 헤더를 붙이고 그 주소의 캐시를 맞춘다(그 밖의 응답은 그대로)
        return ThumbnailResponder::markBypass($response, $request);
    }

    /**
     * 본문 미디어 파일의 썸네일 원본 — 늘 권한 원본이다.
     *
     * 문서가 지금 공개여도 나중에 비공개가 될 수 있다(게시글 비밀 전환, 페이지 발행 취소, 상품 전시 중단). 같은 원본의 파생본이
     * 문서 상태에 따라 공개·비공개 두 저장 위치로 갈리지 않게 상태와 무관하게 고정한다.
     *
     * @param  StorageInterface  $storage  그 행이 저장된 디스크의 저장소
     * @param  string  $category  카테고리
     * @param  string  $path  카테고리 기준 경로
     * @param  string|null  $mime  MIME
     * @param  int|null  $width  원본 가로 (알면)
     * @param  int|null  $height  원본 세로 (알면)
     * @param  int|null  $maxAge  썸네일 캐시 유지 상한(초) — 원본 통로의 수명 (null 이면 코어 기본값)
     * @return ThumbnailSource|null 원본 (래스터 이미지가 아니거나 저장소가 원본 정보를 낼 수 없으면 null)
     */
    public static function thumbnailSource(StorageInterface $storage, string $category, string $path, ?string $mime, ?int $width = null, ?int $height = null, ?int $maxAge = null): ?ThumbnailSource
    {
        if (! InlineMediaPolicy::isInlineImage($mime)) {
            return null;
        }

        return ThumbnailSource::fromStorage($storage, $category, $path, $mime, gated: true, width: $width, height: $height, maxAge: $maxAge);
    }

    /**
     * 응답 사양에서 썸네일 캐시 유지 상한을 정합니다 — 썸네일이 원본보다 오래 캐시되지 않게 원본 통로의 수명을 따른다.
     *
     * @param  array<string, mixed>  $spec  응답 사양 (`private`·`max_age`)
     * @return int|null 상한(초). 수명을 정하지 않은 공개 응답은 null(코어 기본값)
     */
    public static function thumbnailMaxAge(array $spec): ?int
    {
        $maxAge = max(0, (int) ($spec['max_age'] ?? 0));

        return (! empty($spec['private']) || $maxAge > 0) ? $maxAge : null;
    }

    /**
     * 동영상·오디오 인라인 재생을 허용할지 — 확장 정책과 코어 후보가 모두 그 종류를 켰을 때.
     *
     * @param  ContentMediaPolicy  $policy  확장 정책
     * @param  string|null  $mime  파일 MIME
     * @return bool 허용하면 true
     */
    public static function playableUnder(ContentMediaPolicy $policy, ?string $mime): bool
    {
        $kind = ContentMediaKind::fromMime($mime);

        return $kind !== null && $kind !== ContentMediaKind::Image
            && $policy->allows($kind) && ContentMediaPolicy::coreAllows($kind)
            && InlineMediaPolicy::isInlinePlayable($mime);
    }

    /**
     * 원격 디스크의 전량·단일 구간 응답.
     *
     * @param  StreamingStorageInterface  $storage  저장소
     * @param  string  $category  카테고리
     * @param  string  $path  경로
     * @param  string  $mime  MIME
     * @param  Request  $request  요청
     * @return Response|null 응답
     */
    private static function remoteResponse(StreamingStorageInterface $storage, string $category, string $path, string $mime, Request $request): ?Response
    {
        $size = $storage->size($category, $path);
        if ($size === null) {
            return null;
        }

        $range = $request->headers->has('If-Range') ? null : self::parseRange((string) $request->headers->get('Range', ''), $size);

        if ($range === false) {
            return new Response('', 416, [
                'Content-Range' => "bytes */{$size}",
                'Accept-Ranges' => 'bytes',
                'Content-Type' => $mime,
            ]);
        }

        [$start, $end, $status] = $range === null ? [0, max(0, $size - 1), 200] : [$range[0], $range[1], 206];
        $length = $size === 0 ? 0 : $end - $start + 1;

        $response = new StreamedResponse(function () use ($storage, $category, $path, $start, $end, $length, $status) {
            if ($length === 0) {
                return;
            }
            $stream = $status === 206
                ? $storage->readRangeStream($category, $path, $start, $end)
                : $storage->readStream($category, $path);
            if (! is_resource($stream)) {
                return;
            }
            $remaining = $length;
            while ($remaining > 0 && ! feof($stream)) {
                $chunk = fread($stream, min(1048576, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $remaining -= strlen($chunk);
                echo $chunk;
                flush();
            }
            fclose($stream);
        }, $status, [
            'Content-Type' => $mime,
            'Content-Length' => (string) $length,
            'Accept-Ranges' => 'bytes',
        ]);

        if ($status === 206) {
            $response->headers->set('Content-Range', "bytes {$start}-{$end}/{$size}");
        }

        return $response;
    }

    /**
     * Range 헤더를 해석합니다 — 단일 구간만.
     *
     * @param  string  $header  Range 헤더 값
     * @param  int  $size  파일 크기
     * @return array{0: int, 1: int}|false|null 구간 [start, end] / 충족 불가(416)는 false / 범위 요청 아님·여러 구간은 null(200)
     */
    public static function parseRange(string $header, int $size): array|false|null
    {
        $header = trim($header);
        if ($header === '' || ! str_starts_with(strtolower($header), 'bytes=')) {
            return null;
        }

        $spec = trim(substr($header, 6));
        if ($spec === '' || str_contains($spec, ',')) {
            return null;
        }

        if (! preg_match('/^(\d*)-(\d*)$/', $spec, $m) || ($m[1] === '' && $m[2] === '')) {
            return null;
        }

        if ($size <= 0) {
            return false;
        }

        if ($m[1] === '') {
            $suffix = (int) $m[2];
            if ($suffix === 0) {
                return false;
            }

            return [max(0, $size - $suffix), $size - 1];
        }

        $start = (int) $m[1];
        if ($start >= $size) {
            return false;
        }

        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        if ($end < $start) {
            return false;
        }

        return [$start, $end];
    }

    /**
     * 구간 지원·캐시 헤더를 붙입니다(보안 헤더는 `respond()` 가 원본 응답을 만든 자리에서 붙인다).
     *
     * @param  Response  $response  응답
     * @param  array<string, mixed>  $spec  응답 사양
     * @return void 반환값 없음
     */
    private static function applyCacheHeaders(Response $response, array $spec): void
    {
        if (! $response->headers->has('Accept-Ranges') && $response instanceof BinaryFileResponse) {
            $response->headers->set('Accept-Ranges', 'bytes');
        }

        $maxAge = max(0, (int) ($spec['max_age'] ?? 0));
        if (! empty($spec['private'])) {
            $response->headers->set('Cache-Control', 'private, max-age='.$maxAge);
        } elseif ($maxAge > 0) {
            $response->headers->set('Cache-Control', 'public, max-age='.$maxAge);
        }
    }
}
