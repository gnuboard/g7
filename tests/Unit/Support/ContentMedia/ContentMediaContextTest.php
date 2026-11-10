<?php

namespace Tests\Unit\Support\ContentMedia;

use App\Enums\ContentMediaKind;
use App\Support\ContentMedia\ContentMediaContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * 본문 미디어 문맥 값 객체 — 불변 사본·본인 판정·훅 인자 배열 (#887, G1·G2)
 *
 * @scenario-manifest tests/scenarios/content-media-core-skeleton.yaml
 */
class ContentMediaContextTest extends TestCase
{
    /**
     * 모듈·플러그인 문맥을 만들고, with* 는 원본을 바꾸지 않은 사본을 돌려준다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=context, extension=plugin
     *
     * @effects context_immutable_copies, context_rejects_unknown_extension_type
     */
    public function test_with_methods_return_copies(): void
    {
        $base = ContentMediaContext::plugin('vendor-notes', 'note');
        $changed = $base->withDocument(5)->withTempKey('tk')->withScope('lobby')->withActor(3, 'ignored')
            ->withKind(ContentMediaKind::Video)->withExtra(['trigger' => 'admin']);

        $this->assertSame('plugin', $base->extensionType);
        $this->assertNull($base->documentId);
        $this->assertSame([5, 'tk', 'lobby', 3, null, 'video', 'admin'], [
            $changed->documentId, $changed->tempKey, $changed->scope, $changed->actorId, $changed->guestOwnerHash,
            $changed->kind?->value, $changed->extra('trigger'),
        ]);
        $this->assertNull($base->withTempKey('')->tempKey, '빈 임시 키는 null');
        $this->assertSame('module', ContentMediaContext::module('vendor-board', 'post', 1)->extensionType);

        $this->expectException(InvalidArgumentException::class);
        new ContentMediaContext('template', 'x', 'y');
    }

    /**
     * 본인 판정: 회원은 올린 회원 ID, 비회원은 소유 증명 해시가 같고 회원이 올린 행이 아닐 때만.
     *
     * @return void 반환값 없음
     *
     * @scenario case=context, extension=module
     *
     * @effects member_owns_by_id, guest_owns_by_hash_only
     */
    public function test_owns_rules(): void
    {
        $member = ContentMediaContext::module('m', 'doc')->withActor(7);
        $this->assertTrue($member->owns(7));
        $this->assertTrue($member->owns('7'));
        $this->assertFalse($member->owns(8));
        $this->assertFalse($member->owns(null, 'h'));

        $guest = ContentMediaContext::module('m', 'doc')->withActor(null, 'h');
        $this->assertTrue($guest->owns(null, 'h'));
        $this->assertFalse($guest->owns(null, 'other'));
        $this->assertFalse($guest->owns(null, null));
        $this->assertFalse($guest->owns(7, 'h'), '회원이 올린 행은 비회원 소유가 아니다');
        $this->assertFalse(ContentMediaContext::module('m', 'doc')->owns(null, null), '증명이 없는 비회원은 누구의 행도 아니다');
    }

    /**
     * 훅·로그용 배열에는 비회원 소유 증명 해시를 싣지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=context, extension=module
     *
     * @effects context_array_hides_guest_hash
     */
    public function test_to_array_hides_guest_owner_hash(): void
    {
        $array = ContentMediaContext::module('m', 'doc', 4)->withActor(null, 'secret')->toArray();

        $this->assertSame('m', $array['extension']);
        $this->assertSame(4, $array['document_id']);
        $this->assertNotContains('secret', $array);
        $this->assertArrayNotHasKey('guest_owner_hash', $array);
    }
}
