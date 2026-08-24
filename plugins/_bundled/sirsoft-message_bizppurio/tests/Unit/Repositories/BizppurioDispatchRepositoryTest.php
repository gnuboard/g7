<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Repositories;

use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioDispatch;
use Plugins\Sirsoft\MessageBizppurio\Repositories\BizppurioDispatchRepository;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * BizppurioDispatchRepository — 생성·refkey 조회·갱신·필터 페이지네이션 검증.
 */
class BizppurioDispatchRepositoryTest extends PluginTestCase
{
    private BizppurioDispatchRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new BizppurioDispatchRepository;
    }

    private function makeDispatch(array $overrides = []): BizppurioDispatch
    {
        return $this->repo->create(array_merge([
            'refkey' => 'r'.uniqid(),
            'channel' => 'sms',
            'to_number' => '01011112222',
            'content' => 'x',
            'status' => 'sent',
            'source' => 'auto',
            'sent_at' => now(),
        ], $overrides));
    }

    public function test_create_and_find_by_refkey(): void
    {
        $this->makeDispatch(['refkey' => 'known']);
        $this->assertNotNull($this->repo->findByRefkey('known'));
        $this->assertNull($this->repo->findByRefkey('missing'));
    }

    public function test_update(): void
    {
        $dispatch = $this->makeDispatch(['refkey' => 'upd', 'status' => 'sent']);
        $this->repo->update($dispatch, ['status' => 'success', 'result_code' => '4100']);

        $this->assertSame('success', $dispatch->fresh()->status->value);
        $this->assertSame('4100', $dispatch->fresh()->result_code);
    }

    public function test_paginate_filters_by_channel_and_status(): void
    {
        $this->makeDispatch(['channel' => 'sms', 'status' => 'success']);
        $this->makeDispatch(['channel' => 'alimtalk', 'status' => 'failed']);
        $this->makeDispatch(['channel' => 'sms', 'status' => 'failed']);

        $smsPage = $this->repo->paginate(['channel' => 'sms']);
        $this->assertSame(2, $smsPage->total());

        $failedPage = $this->repo->paginate(['status' => 'failed']);
        $this->assertSame(2, $failedPage->total());
    }

    public function test_paginate_keyword_matches_number_or_refkey(): void
    {
        $this->makeDispatch(['refkey' => 'refABC', 'to_number' => '01099998888']);
        $this->makeDispatch(['refkey' => 'other', 'to_number' => '01000000000']);

        $byRefkey = $this->repo->paginate(['keyword' => 'refABC']);
        $this->assertSame(1, $byRefkey->total());

        $byNumber = $this->repo->paginate(['keyword' => '9999']);
        $this->assertSame(1, $byNumber->total());
    }

    public function test_link_notification_log_by_refkey(): void
    {
        $this->makeDispatch(['refkey' => 'to_link']);

        $linked = $this->repo->linkNotificationLog('to_link', 777);
        $this->assertTrue($linked);
        $this->assertSame(777, (int) $this->repo->findByRefkey('to_link')->notification_log_id);
    }

    public function test_link_notification_log_missing_refkey_returns_false(): void
    {
        $this->assertFalse($this->repo->linkNotificationLog('nope', 1));
    }

    public function test_find_by_notification_log_ids_keyed(): void
    {
        $this->makeDispatch(['refkey' => 'a', 'notification_log_id' => 10]);
        $this->makeDispatch(['refkey' => 'b', 'notification_log_id' => 20]);
        $this->makeDispatch(['refkey' => 'c', 'notification_log_id' => null]); // 미연결

        $map = $this->repo->findByNotificationLogIdsKeyed([10, 20, 30]);

        $this->assertCount(2, $map);
        $this->assertSame('a', $map[10]->refkey);
        $this->assertSame('b', $map[20]->refkey);
        $this->assertFalse($map->has(30));
    }

    public function test_find_by_notification_log_ids_empty_returns_empty(): void
    {
        $this->assertTrue($this->repo->findByNotificationLogIdsKeyed([])->isEmpty());
    }

    public function test_recent_linked_keyed_excludes_unlinked_and_keys_by_log_id(): void
    {
        $this->makeDispatch(['refkey' => 'x', 'notification_log_id' => 11]);
        $this->makeDispatch(['refkey' => 'y', 'notification_log_id' => 22]);
        $this->makeDispatch(['refkey' => 'z', 'notification_log_id' => null]); // 미연결 → 제외

        $map = $this->repo->recentLinkedKeyed();

        $this->assertCount(2, $map);
        $this->assertSame('x', $map[11]->refkey);
        $this->assertSame('y', $map[22]->refkey);
    }

    public function test_recent_linked_keyed_respects_limit(): void
    {
        foreach (range(1, 5) as $i) {
            $this->makeDispatch(['refkey' => 'r'.$i, 'notification_log_id' => $i]);
        }

        // 최신(id 큰) 것부터 limit 만큼 — 최근 2건만
        $map = $this->repo->recentLinkedKeyed(2);

        $this->assertCount(2, $map);
        $this->assertTrue($map->has(5));
        $this->assertTrue($map->has(4));
    }
}
