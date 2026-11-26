<?php

namespace Tests\Unit\Extension;

use App\Extension\Support\ExtensionBatchUpdateContext;
use Tests\TestCase;

/**
 * 여러 확장을 한꺼번에 올리는 일괄 업데이트의 문맥 검증.
 *
 * 일괄(2개 이상)일 때만 항목마다의 부수 작업(정적 게시·시작 시 오토로드 재생성·큐 워커 재시작)을 끝으로 모은다.
 * 확장 하나만 올리는 업데이트는 종전과 같아야 한다 — 1개짜리 묶음도 일괄이 아니다.
 */
class ExtensionBatchUpdateContextTest extends TestCase
{
    /**
     * 2개 이상만 일괄이다 — 1개짜리 묶음은 단일 업데이트와 같다.
     *
     * @effects batch_context_requires_two_or_more_items
     *
     * @return void 반환값 없음
     */
    public function test_only_two_or_more_items_form_a_batch(): void
    {
        $this->assertFalse(ExtensionBatchUpdateContext::begin(0)->isBatch());
        $this->assertFalse(ExtensionBatchUpdateContext::begin(1)->isBatch());
        $this->assertTrue(ExtensionBatchUpdateContext::begin(2)->isBatch());
        $this->assertTrue(ExtensionBatchUpdateContext::begin(31)->isBatch());
    }

    /**
     * 시작한 쪽이 매니페스트에 싣고, 항목 프로세스가 읽으면 같은 묶음의 구성원이 된다(주인은 아니다).
     *
     * @effects batch_context_round_trips_through_manifest
     *
     * @return void 반환값 없음
     */
    public function test_manifest_round_trip_makes_a_member_of_the_same_batch(): void
    {
        $owner = ExtensionBatchUpdateContext::begin(3, 1_700_000_000);
        $this->assertTrue($owner->isOwnedHere());

        $member = ExtensionBatchUpdateContext::fromManifest([ExtensionBatchUpdateContext::MANIFEST_KEY => $owner->toManifest()]);

        $this->assertNotNull($member);
        $this->assertSame($owner->id, $member->id);
        $this->assertSame(3, $member->size);
        $this->assertSame(1_700_000_000, $member->startedAt);
        $this->assertTrue($member->isBatch());
        $this->assertFalse($member->isOwnedHere());
    }

    /**
     * 표시가 없거나 모양이 틀린 매니페스트(옛 부모·단독 실행)는 묶음 구성원이 아니다.
     *
     * @effects batch_context_absent_for_legacy_manifest
     *
     * @return void 반환값 없음
     */
    public function test_manifest_without_valid_batch_field_is_not_a_member(): void
    {
        $this->assertNull(ExtensionBatchUpdateContext::fromManifest([]));
        $this->assertNull(ExtensionBatchUpdateContext::fromManifest([ExtensionBatchUpdateContext::MANIFEST_KEY => 'x']));
        $this->assertNull(ExtensionBatchUpdateContext::fromManifest([ExtensionBatchUpdateContext::MANIFEST_KEY => ['id' => '', 'size' => 2]]));
        $this->assertNull(ExtensionBatchUpdateContext::fromManifest([ExtensionBatchUpdateContext::MANIFEST_KEY => ['id' => 'a', 'size' => '2']]));
    }

    /**
     * 묶음 안에서만 항목마다의 부수 작업을 미루고, 끝나면(예외로 끝나도) 바깥 상태로 돌아간다.
     *
     * @effects batch_context_scopes_deferral_to_callback
     *
     * @return void 반환값 없음
     */
    public function test_within_scopes_deferral_and_restores_on_exit(): void
    {
        $this->assertFalse(ExtensionBatchUpdateContext::defersPerExtensionWork());

        $seen = ExtensionBatchUpdateContext::within(ExtensionBatchUpdateContext::begin(2), static fn () => ExtensionBatchUpdateContext::defersPerExtensionWork());
        $this->assertTrue($seen);
        $this->assertFalse(ExtensionBatchUpdateContext::defersPerExtensionWork());

        $single = ExtensionBatchUpdateContext::within(ExtensionBatchUpdateContext::begin(1), static fn () => ExtensionBatchUpdateContext::defersPerExtensionWork());
        $this->assertFalse($single, '1개짜리 묶음이 항목마다의 작업을 미뤘다');

        try {
            ExtensionBatchUpdateContext::within(ExtensionBatchUpdateContext::begin(2), static function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        $this->assertFalse(ExtensionBatchUpdateContext::defersPerExtensionWork(), '예외 뒤에도 묶음 상태가 남았다');
        $this->assertNull(ExtensionBatchUpdateContext::current());
    }
}
