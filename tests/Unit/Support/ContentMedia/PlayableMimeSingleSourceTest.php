<?php

namespace Tests\Unit\Support\ContentMedia;

use App\Enums\ContentMediaKind;
use App\Helpers\InlineMediaPolicy;
use PHPUnit\Framework\TestCase;

/**
 * 재생 가능 MIME 단일 출처 — `InlineMediaPolicy` 의 인라인 재생 목록이 `ContentMediaKind` 에서 만들어진다 (#887, G11)
 *
 * 7.1.5 개발 중에는 같은 MIME 목록이 두 곳(종류별 확장자 표와 서빙 헤더 판정)에 따로 적혀 있었다. 한쪽만 고치면 업로드는
 * 받는데 서빙이 다운로드로 내려가는 어긋남이 생긴다. 출처를 하나로 모으면서 값은 바꾸지 않았다는 것을 옛 목록 그대로 잠근다.
 *
 * @scenario-manifest tests/scenarios/content-media-core-skeleton.yaml
 */
class PlayableMimeSingleSourceTest extends TestCase
{
    /** 단일 출처로 옮기기 전의 재생 가능 MIME 목록(순서 포함) */
    private const LEGACY_PLAYABLE = [
        'video/mp4',
        'video/webm',
        'video/ogg',
        'audio/mpeg',
        'audio/mp4',
        'audio/x-m4a',
        'audio/ogg',
        'audio/wav',
        'audio/x-wav',
        'audio/wave',
        'audio/vnd.wave',
    ];

    /**
     * 재생 가능 목록은 옛 목록과 값·순서가 같다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=playable_mime_parity, extension=module
     *
     * @effects playable_list_unchanged
     */
    public function test_playable_list_equals_legacy_literal(): void
    {
        $this->assertSame(self::LEGACY_PLAYABLE, InlineMediaPolicy::playableMimeTypes());
    }

    /**
     * 재생 가능 목록은 동영상·오디오 종류의 MIME 합집합 그 자체다 — 종류 표에 MIME 을 더하면 서빙 판정도 함께 따른다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=playable_mime_parity, extension=module
     *
     * @effects playable_list_derived_from_kinds
     */
    public function test_playable_list_is_video_and_audio_kind_mimes(): void
    {
        $this->assertSame(
            array_values(array_unique(array_merge(ContentMediaKind::Video->mimeTypes(), ContentMediaKind::Audio->mimeTypes()))),
            InlineMediaPolicy::playableMimeTypes()
        );

        foreach (self::LEGACY_PLAYABLE as $mime) {
            $this->assertTrue(InlineMediaPolicy::isInlinePlayable($mime), $mime);
            $this->assertNotSame(ContentMediaKind::Image, ContentMediaKind::fromMime($mime), $mime);
        }
    }

    /**
     * 이미지 종류의 MIME 은 모두 인라인 래스터 이미지다 — 본문 이미지가 다운로드로 내려가지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=playable_mime_parity, extension=module
     *
     * @effects image_kind_subset_of_raster
     */
    public function test_image_kind_mimes_are_inline_raster(): void
    {
        foreach (ContentMediaKind::Image->mimeTypes() as $mime) {
            $this->assertTrue(InlineMediaPolicy::isInlineImage($mime), $mime);
            $this->assertFalse(InlineMediaPolicy::isInlinePlayable($mime), $mime);
        }
    }
}
