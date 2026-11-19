<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Repositories;

use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioDispatch;
use Plugins\Sirsoft\MessageBizppurio\Repositories\BizppurioDispatchRepository;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * BizppurioDispatchRepository — 생성·refkey 조회·갱신·필터 페이지네이션 검증.
 *
 * @scenario channel=sms
 */
class BizppurioDispatchRepositoryTest extends PluginTestCase
{
    private BizppurioDispatchRepository $repo;

    /**
     * 테스트마다 검증 대상 BizppurioDispatchRepository 를 새로 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new BizppurioDispatchRepository;
    }

    /**
     * 리포지토리로 sent 상태 sms 발송 이력 1건을 만든다.
     *
     * @param  array<string, mixed>  $overrides  기본값을 덮어쓸 속성
     * @return BizppurioDispatch 생성한 발송 이력
     */
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

    /**
     * 생성한 이력은 refkey 로 찾히고 없는 refkey 는 null 인지 확인한다.
     *
     * @effects repository_find_by_refkey_returns_match_or_null
     *
     * @return void 반환값 없음
     */
    public function test_create_and_find_by_refkey(): void
    {
        $this->makeDispatch(['refkey' => 'known']);
        $this->assertNotNull($this->repo->findByRefkey('known'));
        $this->assertNull($this->repo->findByRefkey('missing'));
    }

    /**
     * update 가 상태와 결과코드를 DB 에 반영하는지 확인한다.
     *
     * @scenario result_category=success
     *
     * @effects repository_update_persists_status_and_result_code
     *
     * @return void 반환값 없음
     */
    public function test_update(): void
    {
        $dispatch = $this->makeDispatch(['refkey' => 'upd', 'status' => 'sent']);
        $this->repo->update($dispatch, ['status' => 'success', 'result_code' => '4100']);

        $this->assertSame('success', $dispatch->fresh()->status->value);
        $this->assertSame('4100', $dispatch->fresh()->result_code);
    }

    /**
     * paginate 가 채널 필터와 상태 필터를 각각 적용해 건수를 좁히는지 확인한다.
     *
     * @effects repository_paginate_filters_by_channel_and_status
     *
     * @return void 반환값 없음
     */
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

    /**
     * paginate 키워드가 refkey 나 수신번호 일부와 일치하는 이력만 고르는지 확인한다.
     *
     * @effects repository_paginate_keyword_matches_number_or_refkey
     *
     * @return void 반환값 없음
     */
    public function test_paginate_keyword_matches_number_or_refkey(): void
    {
        $this->makeDispatch(['refkey' => 'refABC', 'to_number' => '01099998888']);
        $this->makeDispatch(['refkey' => 'other', 'to_number' => '01000000000']);

        $byRefkey = $this->repo->paginate(['keyword' => 'refABC']);
        $this->assertSame(1, $byRefkey->total());

        $byNumber = $this->repo->paginate(['keyword' => '9999']);
        $this->assertSame(1, $byNumber->total());
    }

    /**
     * linkNotificationLog 가 refkey 로 찾은 이력에 코어 로그 id 를 기록하고 참을 돌려주는지 확인한다.
     *
     * @scenario link_outcome=linked
     *
     * @effects link_notification_log_by_refkey_sets_log_id
     *
     * @return void 반환값 없음
     */
    public function test_link_notification_log_by_refkey(): void
    {
        $this->makeDispatch(['refkey' => 'to_link']);

        $linked = $this->repo->linkNotificationLog('to_link', 777);
        $this->assertTrue($linked);
        $this->assertSame(777, (int) $this->repo->findByRefkey('to_link')->notification_log_id);
    }

    /**
     * 없는 refkey 로 linkNotificationLog 를 부르면 거짓을 돌려주는지 확인한다.
     *
     * @scenario link_outcome=unmatched
     *
     * @effects link_missing_refkey_returns_false
     *
     * @return void 반환값 없음
     */
    public function test_link_notification_log_missing_refkey_returns_false(): void
    {
        $this->assertFalse($this->repo->linkNotificationLog('nope', 1));
    }

    /**
     * 로그 id 배열 조회가 연결된 이력만 로그 id 키 맵으로 돌려주고 없는 id 는 빼는지 확인한다.
     *
     * @scenario link_outcome=linked
     *
     * @effects find_by_log_ids_returns_keyed_map_excluding_unmatched
     *
     * @return void 반환값 없음
     */
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

    /**
     * 빈 로그 id 배열로 조회하면 빈 컬렉션을 돌려주는지 확인한다.
     *
     * @effects find_by_log_ids_empty_input_returns_empty
     *
     * @return void 반환값 없음
     */
    public function test_find_by_notification_log_ids_empty_returns_empty(): void
    {
        $this->assertTrue($this->repo->findByNotificationLogIdsKeyed([])->isEmpty());
    }

    /**
     * recentLinkedKeyed 가 로그에 연결되지 않은 이력을 빼고 로그 id 를 키로 묶는지 확인한다.
     *
     * @scenario link_outcome=linked
     *
     * @effects recent_linked_keyed_excludes_unlinked_and_keys_by_log_id
     *
     * @return void 반환값 없음
     */
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

    /**
     * recentLinkedKeyed 가 limit 만큼 최신(id 가 큰) 이력만 돌려주는지 확인한다.
     *
     * @effects recent_linked_keyed_respects_limit_newest_first
     *
     * @return void 반환값 없음
     */
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
