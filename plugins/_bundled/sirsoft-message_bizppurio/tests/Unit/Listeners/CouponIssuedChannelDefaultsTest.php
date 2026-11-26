<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Listeners;

use App\Models\NotificationDefinition;
use Mockery;
use Plugins\Sirsoft\MessageBizppurio\Listeners\PurgeTemplateOnDefinitionDeleteListener;
use Plugins\Sirsoft\MessageBizppurio\Listeners\SeedChannelTemplatesListener;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioTemplate;
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioTemplateRepositoryInterface;
use Tests\PureTestCase;

/**
 * 새 회원 알림의 문자·알림톡 기본값과 정의 삭제 시 템플릿 정리 (1.2.1)
 */
class CouponIssuedChannelDefaultsTest extends PureTestCase
{
    /**
     * Mockery 를 닫습니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 회원 대상 알림 선언을 만듭니다.
     *
     * @param  bool|null  $mailActive  메일 템플릿 is_active (null 이면 키를 두지 않는다)
     * @return array<string, array<string, mixed>> 선언
     */
    private function definition(?bool $mailActive): array
    {
        $mail = ['channel' => 'mail', 'recipients' => [['type' => 'trigger_user']], 'subject' => ['ko' => '제목'], 'body' => ['ko' => '<p>{name}</p>']];
        if ($mailActive !== null) {
            $mail['is_active'] = $mailActive;
        }

        return [
            'coupon_issued.1' => [
                'hook_prefix' => 'sirsoft-ecommerce',
                'channels' => ['mail', 'database'],
                'templates' => [
                    $mail,
                    ['channel' => 'database', 'recipients' => [['type' => 'trigger_user']], 'subject' => ['ko' => '알림'], 'body' => ['ko' => '{name}님 쿠폰']],
                ],
            ],
        ];
    }

    /**
     * 메일을 꺼진 채로 선언한 알림은 문자·알림톡도 꺼진 채로 더하고, 종전 선언(키 없음·켜짐)은 켠 채로 더한다.
     *
     * @scenario case=filter_seed_definitions_external_default
     *
     * @effects bizppurio_external_channel_default_follows_mail
     *
     * @return void 반환값 없음
     */
    public function test_external_channels_follow_mail_default(): void
    {
        $listener = new SeedChannelTemplatesListener;

        foreach ([[false, false], [true, true], [null, true]] as [$mailActive, $expected]) {
            $templates = collect($listener->augment($this->definition($mailActive))['coupon_issued.1']['templates'])->keyBy('channel');

            $this->assertSame($expected, $templates['sms']['is_active'], 'sms mail='.var_export($mailActive, true));
            $this->assertSame($expected, $templates['alimtalk']['is_active'], 'alimtalk mail='.var_export($mailActive, true));
        }
    }

    /**
     * 정의가 지워지면 그 알림 종류의 비즈뿌리오 템플릿 행을 지운다. 행이 없거나 정의가 아니면 아무것도 하지 않는다.
     *
     * @scenario case=notification_definition_after_delete
     *
     * @effects bizppurio_template_purged_on_definition_delete
     *
     * @return void 반환값 없음
     */
    public function test_purges_template_when_definition_deleted(): void
    {
        $row = new BizppurioTemplate;
        $repository = Mockery::mock(BizppurioTemplateRepositoryInterface::class);
        $repository->shouldReceive('findByType')->with('coupon_issued.1')->once()->andReturn($row);
        $repository->shouldReceive('findByType')->with('coupon_issued.2')->once()->andReturn(null);
        $repository->shouldReceive('delete')->with($row)->once();

        $listener = new PurgeTemplateOnDefinitionDeleteListener($repository);
        $listener->purge((new NotificationDefinition)->forceFill(['type' => 'coupon_issued.1']));
        $listener->purge((new NotificationDefinition)->forceFill(['type' => 'coupon_issued.2']));
        $listener->purge('not-a-definition');

        $hook = PurgeTemplateOnDefinitionDeleteListener::getSubscribedHooks()['core.notification_definition.after_delete'];
        $this->assertTrue($hook['sync']);
    }
}
