<?php

namespace App\Support\ContentMedia;

use App\Contracts\Extension\StorageInterface;
use App\Contracts\Extension\StreamingStorageInterface;
use App\Enums\ContentMediaKind;
use App\Helpers\InlineMediaPolicy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 본문 미디어 서빙 응답 — Range(206·416)·인라인 판정·보안 헤더 (#883 5단계, D14)
 *
 * 게이트(글 읽기 권한·비밀·삭제·블라인드·서명)는 호출하는 확장이 먼저 건다. 이 클래스는 응답만 만든다.
 *
 * - 로컬 디스크(`localPath()`): `BinaryFileResponse` — Range·If-Range·206·416 을 Symfony 가 처리한다.
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
     * @param  array{mime_type: string, filename: string, playable?: bool, private?: bool, max_age?: int}  $spec  응답 사양
     * @param  Request  $request  요청 (Range 헤더)
     * @return Response|null 응답 (파일이 없으면 null)
     */
    public static function respond(StorageInterface $storage, string $category, string $path, array $spec, Request $request): ?Response
    {
        $mime = (string) ($spec['mime_type'] ?? 'application/octet-stream');
        $filename = (string) ($spec['filename'] ?? basename($path));

        if ($storage instanceof StreamingStorageInterface) {
            $local = $storage->localPath($category, $path);
            if ($local !== null) {
                $response = new BinaryFileResponse($local, 200, ['Content-Type' => $mime], true, null, false, true);
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

        return self::applyHeaders($response, $mime, $filename, $spec);
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
     * 보안·캐시 헤더를 붙입니다.
     *
     * @param  Response  $response  응답
     * @param  string  $mime  MIME
     * @param  string  $filename  파일명
     * @param  array<string, mixed>  $spec  응답 사양
     * @return Response 같은 응답
     */
    private static function applyHeaders(Response $response, string $mime, string $filename, array $spec): Response
    {
        if (InlineMediaPolicy::isInlineImage($mime)) {
            InlineMediaPolicy::harden($response, $mime, $filename);
        } elseif (! empty($spec['playable']) && InlineMediaPolicy::isInlinePlayable($mime)) {
            InlineMediaPolicy::hardenPlayable($response, $mime, $filename);
        } else {
            InlineMediaPolicy::harden($response, $mime, $filename);
        }

        if (! $response->headers->has('Accept-Ranges') && $response instanceof BinaryFileResponse) {
            $response->headers->set('Accept-Ranges', 'bytes');
        }

        $maxAge = max(0, (int) ($spec['max_age'] ?? 0));
        if (! empty($spec['private'])) {
            $response->headers->set('Cache-Control', 'private, max-age='.$maxAge);
        } elseif ($maxAge > 0) {
            $response->headers->set('Cache-Control', 'public, max-age='.$maxAge);
        }

        return $response;
    }
}
