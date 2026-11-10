<?php

namespace Tests\Unit\Support\ContentMedia;

use App\Contracts\Extension\StorageInterface;
use App\Contracts\Extension\StreamingStorageInterface;
use App\Helpers\InlineMediaPolicy;
use App\Support\ContentMedia\ContentMediaResponder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * 본문 미디어 서빙 응답 — Range(로컬·원격)·인라인 판정·보안 헤더, `harden()` 불변 (#883 5단계, D14)
 *
 * @scenario-manifest tests/scenarios/content-render-policy.yaml
 */
class ContentMediaResponderTest extends TestCase
{
    /**
     * Range 해석 — 한 구간·접미 구간·충족 불가(416)·여러 구간(200 전량).
     *
     * @return void 반환값 없음
     *
     * @scenario case=range_parse
     *
     * @effects range_edges
     */
    public function test_parse_range(): void
    {
        $this->assertSame([0, 0], ContentMediaResponder::parseRange('bytes=0-0', 1000));
        $this->assertSame([900, 999], ContentMediaResponder::parseRange('bytes=-100', 1000));
        $this->assertSame([10, 999], ContentMediaResponder::parseRange('bytes=10-', 1000));
        $this->assertFalse(ContentMediaResponder::parseRange('bytes=99999999-', 1000));
        $this->assertNull(ContentMediaResponder::parseRange('bytes=0-1,5-6', 1000));
        $this->assertNull(ContentMediaResponder::parseRange('', 1000));
    }

    /**
     * 원격(스트림) 디스크 — 206·Content-Range·Accept-Ranges, 416, 재생 허용이면 inline + media-src CSP, private 캐시.
     *
     * @return void 반환값 없음
     *
     * @scenario case=remote_range
     *
     * @effects remote_206_and_416, playable_inline_headers
     */
    public function test_remote_range_and_headers(): void
    {
        $storage = $this->remoteStorage(str_repeat('a', 1000));

        $partial = ContentMediaResponder::respond($storage, 'c', 'v.mp4', ['mime_type' => 'video/mp4', 'filename' => 'v.mp4', 'playable' => true, 'private' => true], $this->request('bytes=0-99'));
        $this->assertInstanceOf(StreamedResponse::class, $partial);
        $this->assertSame(206, $partial->getStatusCode());
        $this->assertSame('bytes 0-99/1000', $partial->headers->get('Content-Range'));
        $this->assertSame('bytes', $partial->headers->get('Accept-Ranges'));
        $this->assertSame('100', $partial->headers->get('Content-Length'));
        $this->assertStringStartsWith('inline', (string) $partial->headers->get('Content-Disposition'));
        $this->assertStringContainsString("media-src 'self'", (string) $partial->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('private', (string) $partial->headers->get('Cache-Control'));

        $this->assertSame(416, ContentMediaResponder::respond($storage, 'c', 'v.mp4', ['mime_type' => 'video/mp4', 'filename' => 'v.mp4'], $this->request('bytes=5000-'))->getStatusCode());
        $this->assertSame(200, ContentMediaResponder::respond($storage, 'c', 'v.mp4', ['mime_type' => 'video/mp4', 'filename' => 'v.mp4'], $this->request('bytes=0-1,5-6'))->getStatusCode());

        $notPlayable = ContentMediaResponder::respond($storage, 'c', 'v.mp4', ['mime_type' => 'video/mp4', 'filename' => 'v.mp4', 'playable' => false], $this->request(''));
        $this->assertStringStartsWith('attachment', (string) $notPlayable->headers->get('Content-Disposition'), '정책이 꺼지면 다운로드');
    }

    /**
     * 로컬 디스크 — BinaryFileResponse 가 Range 를 처리한다(206).
     *
     * @return void 반환값 없음
     *
     * @scenario case=local_range
     *
     * @effects local_binary_range
     */
    public function test_local_range(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cm');
        file_put_contents($file, str_repeat('b', 500));
        $storage = $this->createMock(StreamingStorageInterface::class);
        $storage->method('localPath')->willReturn($file);

        $response = ContentMediaResponder::respond($storage, 'c', 'a.mp4', ['mime_type' => 'video/mp4', 'filename' => 'a.mp4', 'playable' => true], $this->request('bytes=0-9'));

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('bytes 0-9/500', $response->headers->get('Content-Range'));
        @unlink($file);
    }

    /**
     * `harden()` 은 바뀌지 않았다(동영상도 다운로드 강제), `hardenPlayable()` 은 재생 형식만 inline.
     *
     * @return void 반환값 없음
     *
     * @scenario case=inline_policy
     *
     * @effects harden_unchanged, harden_playable_inline_only_for_media
     */
    public function test_inline_media_policy(): void
    {
        $hardened = InlineMediaPolicy::harden(new Response('x'), 'video/mp4', 'a.mp4');
        $this->assertStringStartsWith('attachment', (string) $hardened->headers->get('Content-Disposition'));
        $this->assertSame(InlineMediaPolicy::NON_INLINE_CSP, $hardened->headers->get('Content-Security-Policy'));

        $playable = InlineMediaPolicy::hardenPlayable(new Response('x'), 'audio/mpeg', 'a.mp3');
        $this->assertStringStartsWith('inline', (string) $playable->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $playable->headers->get('X-Content-Type-Options'));

        $html = InlineMediaPolicy::hardenPlayable(new Response('x'), 'text/html', 'a.html');
        $this->assertStringStartsWith('attachment', (string) $html->headers->get('Content-Disposition'), '재생 형식이 아니면 harden 으로 넘긴다');
    }

    /**
     * 원격 스트림 저장소 대역.
     *
     * @param  string  $content  내용
     * @return StreamingStorageInterface 대역
     */
    private function remoteStorage(string $content): StreamingStorageInterface
    {
        $storage = $this->createMock(StreamingStorageInterface::class);
        $storage->method('localPath')->willReturn(null);
        $storage->method('size')->willReturn(strlen($content));
        $storage->method('readStream')->willReturnCallback(function () use ($content) {
            $s = fopen('php://memory', 'r+');
            fwrite($s, $content);
            rewind($s);

            return $s;
        });
        $storage->method('readRangeStream')->willReturnCallback(function ($c, $p, $start) use ($content) {
            $s = fopen('php://memory', 'r+');
            fwrite($s, substr($content, $start));
            rewind($s);

            return $s;
        });

        return $storage;
    }

    /**
     * Range 헤더를 가진 요청.
     *
     * @param  string  $range  Range 값
     * @return Request 요청
     */
    private function request(string $range): Request
    {
        $request = Request::create('/x', 'GET');
        if ($range !== '') {
            $request->headers->set('Range', $range);
        }

        return $request;
    }
}
