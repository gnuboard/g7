<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Models;

use App\Models\User;
use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchChannel;
use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchStatus;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioDispatch;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * BizppurioDispatch 모델 — 캐스트·마스킹 접근자·회원 관계 검증.
 *
 * @scenario dispatch-history-webhook
 */
class BizppurioDispatchTest extends PluginTestCase
{
    /**
     * enum 캐스트가 적용된다.
     *
     * @scenario channel=lms
     * @effects dispatch_model_casts_channel_and_status_to_enums
     *
     * @return void 반환값 없음
     */
    public function test_enum_casts(): void
    {
        $dispatch = BizppurioDispatch::create([
            'refkey' => 'r1',
            'channel' => 'lms',
            'to_number' => '01011112222',
            'content' => 'x',
            'status' => 'pending',
            'source' => 'auto',
        ]);

        $this->assertSame(DispatchChannel::Lms, $dispatch->channel);
        $this->assertSame(DispatchStatus::Pending, $dispatch->status);
    }

    /**
     * 마스킹 접근자는 가운데 4자리를 가린다.
     *
     * @effects dispatch_masked_number_hides_middle_four_digits
     *
     * @return void 반환값 없음
     */
    public function test_masked_number(): void
    {
        $dispatch = new BizppurioDispatch(['to_number' => '01012345678']);
        $this->assertSame('010-****-5678', $dispatch->masked_number);
    }

    /**
     * 하이픈 포함 번호도 정규화 후 마스킹한다.
     *
     * @effects dispatch_masked_number_hides_middle_four_digits
     *
     * @return void 반환값 없음
     */
    public function test_masked_number_with_hyphens(): void
    {
        $dispatch = new BizppurioDispatch(['to_number' => '010-1234-5678']);
        $this->assertSame('010-****-5678', $dispatch->masked_number);
    }

    /**
     * to_user_id 로 회원 관계를 로드한다.
     *
     * @scenario channel=sms, recipient_kind=member
     * @effects dispatch_user_relation_loads_member_by_to_user_id
     *
     * @return void 반환값 없음
     */
    public function test_user_relation(): void
    {
        $user = User::factory()->create();
        $dispatch = BizppurioDispatch::create([
            'refkey' => 'r2',
            'channel' => 'sms',
            'to_number' => '01011112222',
            'to_user_id' => $user->id,
            'content' => 'x',
            'status' => 'sent',
            'source' => 'auto',
        ]);

        $this->assertNotNull($dispatch->user);
        $this->assertSame($user->id, $dispatch->user->id);
    }

    /**
     * byRefkey 스코프로 조회한다.
     *
     * @effects dispatch_by_refkey_scope_finds_single_record
     *
     * @return void 반환값 없음
     */
    public function test_by_refkey_scope(): void
    {
        BizppurioDispatch::create([
            'refkey' => 'unique_ref',
            'channel' => 'sms',
            'to_number' => '01000000000',
            'content' => 'x',
            'status' => 'sent',
            'source' => 'auto',
        ]);

        $found = BizppurioDispatch::query()->byRefkey('unique_ref')->first();
        $this->assertNotNull($found);
        $this->assertSame('unique_ref', $found->refkey);
    }
}
