<?php

namespace Tests\Unit\Thumbnails;

use App\Thumbnails\ThumbnailQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * 썸네일 요청 파라미터 규약 테스트
 *
 * 서명 URL 뒤에 화면이 `size`·`w` 를 덧붙여도 서명이 깨지지 않게 두 파라미터를 서명 검증에서 뺀다(D20).
 * Laravel 은 원문 쿼리를 `&` 로 쪼갠 키를 인코딩 그대로 넘기므로, `size%5B%5D` 같은 인코딩 배열 키도 같은
 * 규칙으로 빼는지 잰다. 다른 파라미터는 그대로 서명 대상이어야 한다(서명 범위가 넓어지지 않는다).
 */
class ThumbnailQueryTest extends TestCase
{
    /**
     * 무시 Closure 판정 표.
     *
     * @effects signature_ignores_only_size_and_w
     *
     * @return void 반환값 없음
     */
    public function test_ignore_closure_matches_only_thumbnail_keys(): void
    {
        $ignore = ThumbnailQuery::ignoreClosure();

        foreach (['size', 'w', 'size[]', 'size%5B%5D', 'size%5b0%5d', 'w[]', 'w%5B%5D', 'size[a]'] as $key) {
            $this->assertTrue($ignore($key), $key);
        }

        foreach (['signature', 'expires', 'sizes', 'width', 'ws', 'v', 'x_size', 'Size', 'W', ''] as $key) {
            $this->assertFalse($ignore($key), $key);
        }
    }

    /**
     * 등장 횟수 — 같은 키 두 번·배열 키·인코딩 키를 원문에서 직접 센다.
     *
     * @effects invalid_params_fall_back_to_original
     *
     * @return void 반환값 없음
     */
    public function test_occurrences_count_the_raw_query(): void
    {
        $count = fn (string $query) => ThumbnailQuery::occurrences(Request::create('/x?'.$query));

        $this->assertSame(['size' => 1], $count('size=sm'));
        $this->assertSame(['size' => 2], $count('size=sm&size=md'));
        $this->assertSame(['size' => 1], $count('size[]=x'));
        $this->assertSame(['size' => 1], $count('size%5B%5D=x'));
        $this->assertSame(['w' => 1, 'size' => 1], $count('w=300&size=sm'));
        $this->assertSame([], $count('v=1&sizes=2&width=3'));
        $this->assertFalse(ThumbnailQuery::requested(Request::create('/x?v=1')));
        $this->assertTrue(ThumbnailQuery::requested(Request::create('/x?w=abc')));
    }

    /**
     * 서명 URL 에 `size`·`w`(배열·인코딩 포함)를 덧붙여도 서명이 유효하고, 다른 파라미터를 더하면 깨진다.
     *
     * @effects signature_ignores_only_size_and_w
     *
     * @return void 반환값 없음
     */
    public function test_signed_url_survives_thumbnail_params_but_not_others(): void
    {
        $signed = URL::temporarySignedRoute('api.public.templates.layout-attachment-file', now()->addMinutes(5), [
            'identifier' => 'sirsoft-basic',
            'attachment' => 1,
        ], absolute: false);

        $valid = fn (string $url) => Request::create($url)->hasValidRelativeSignatureWhileIgnoring(ThumbnailQuery::ignoreClosure());

        $this->assertTrue($valid($signed));

        foreach (['size=sm', 'w=300', 'size[]=x', 'size%5B%5D=x', 'size=sm&w=1'] as $suffix) {
            $this->assertTrue($valid($signed.'&'.$suffix), $suffix);
        }

        $this->assertFalse($valid($signed.'&download=1'), '썸네일 밖 파라미터는 서명 대상이다');
        $this->assertFalse($valid((string) preg_replace('/signature=[0-9a-f]+/', 'signature=deadbeef', $signed).'&size=sm'), '서명 위조는 썸네일 파라미터로 덮이지 않는다');
    }
}
