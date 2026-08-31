<?php

namespace Tests\Unit\Http\Responses;

use App\Http\Responses\FileServingMeta;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * `FileServingMeta` — 서빙 메타 키 검증 단일 지점의 판정 (DB 미사용 — 예외 문구의 `__()` 만 앱을 쓴다).
 *
 * @scenario response_kind=streamed, conditional_request=none
 */
class FileServingMetaTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function completeMeta(): array
    {
        return [
            'response' => new StreamedResponse(static function (): void {}),
            'etag_source' => 'disk|path|1|2',
            'mime_type' => 'image/png',
            'last_modified' => 1_700_000_000,
        ];
    }

    /**
     * 네 필수 키가 다 있으면 누락이 없고, 값 객체가 그 값을 그대로 든다.
     *
     * @scenario response_kind=streamed, conditional_request=none
     *
     * @effects file_serving_meta_missing_key_is_named_not_swallowed
     */
    public function test_complete_meta_yields_a_value_object(): void
    {
        $meta = $this->completeMeta();

        $this->assertNull(FileServingMeta::missingKey($meta));

        $serving = FileServingMeta::fromArray($meta);

        $this->assertSame($meta['response'], $serving->response);
        $this->assertSame('disk|path|1|2', $serving->etagSource);
        $this->assertSame('image/png', $serving->mimeType);
        $this->assertSame(1_700_000_000, $serving->lastModifiedAt);
        $this->assertNull($serving->maxAge);
    }

    /**
     * 키가 하나라도 없으면 **그 키 이름**으로 누락을 알린다 — `?? null` 폴백이 아니다.
     *
     * `last_modified` 가 `null` 인 것과 **없는** 것은 다르다: 전자는 「모른다」(정상), 후자는
     * 서비스 구현 결함이다.
     *
     * @scenario response_kind=streamed, conditional_request=none
     *
     * @effects file_serving_meta_missing_key_is_named_not_swallowed
     */
    public function test_each_required_key_is_reported_when_absent(): void
    {
        foreach (FileServingMeta::REQUIRED_KEYS as $key) {
            $meta = $this->completeMeta();
            unset($meta[$key]);

            $this->assertSame($key, FileServingMeta::missingKey($meta), "{$key} 누락이 이름으로 보고되지 않는다");
        }

        $nullLastModified = $this->completeMeta();
        $nullLastModified['last_modified'] = null;
        $this->assertNull(FileServingMeta::missingKey($nullLastModified), 'null 은 「모름」이지 누락이 아니다');
        $this->assertNull(FileServingMeta::fromArray($nullLastModified)->lastModifiedAt);

        $blankMime = $this->completeMeta();
        $blankMime['mime_type'] = '';
        $this->assertNull(FileServingMeta::missingKey($blankMime), '빈 MIME 은 헬퍼가 해석한다 — 누락이 아니다');
    }

    /**
     * `response` 는 존재만으로는 부족하다 — 스트림 응답이 아니면 헬퍼가 받을 수 없다.
     *
     * @scenario response_kind=streamed, conditional_request=none
     *
     * @effects file_serving_meta_missing_key_is_named_not_swallowed
     */
    public function test_response_must_be_a_streamed_response(): void
    {
        $meta = $this->completeMeta();
        $meta['response'] = 'not a response';

        $this->assertSame('response', FileServingMeta::missingKey($meta));

        $this->expectException(\InvalidArgumentException::class);
        FileServingMeta::fromArray($meta);
    }

    /**
     * 통로가 `max_age` 를 서비스에 맡기면 그 키도 필수다 (이커머스 이미지 4통로).
     *
     * @scenario response_kind=streamed, conditional_request=none
     *
     * @effects file_serving_meta_missing_key_is_named_not_swallowed
     */
    public function test_max_age_is_required_only_when_the_caller_delegates_it(): void
    {
        $meta = $this->completeMeta();

        $this->assertNull(FileServingMeta::missingKey($meta, requireMaxAge: false));
        $this->assertSame('max_age', FileServingMeta::missingKey($meta, requireMaxAge: true));

        $meta['max_age'] = 3600;
        $this->assertNull(FileServingMeta::missingKey($meta, requireMaxAge: true));
        $this->assertSame(3600, FileServingMeta::fromArray($meta, requireMaxAge: true)->maxAge);
    }
}
