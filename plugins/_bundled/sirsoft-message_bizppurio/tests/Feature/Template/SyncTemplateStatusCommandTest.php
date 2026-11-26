<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Feature\Template;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Plugins\Sirsoft\MessageBizppurio\Enums\BizppurioTemplateStatus;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioTemplate;
use Plugins\Sirsoft\MessageBizppurio\Plugin;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * bizppurio:sync-template-status 커맨드 테스트.
 *
 * 검수중(requested) 행이 없으면 kapi 를 호출하지 않고, 있으면 senderKey 별
 * template/list 일괄 대조로 상태를 전이한다. 스케줄 등록은 plugin.php::getSchedules()
 * 가 담당한다(별도 단언).
 */
class SyncTemplateStatusCommandTest extends PluginTestCase
{
    /**
     * kapi 자격증명을 플러그인 설정에 저장합니다.
     *
     * @return void 반환값 없음
     */
    private function seedKakaoSettings(): void
    {
        app(PluginSettingsService::class)->save('sirsoft-message_bizppurio', [
            'bizppurio_id' => 'biz1',
            'api_key' => 'key1',
            'sender_key' => 'SK_TEST',
        ]);
    }

    /**
     * 검수 중 행이 없으면 카카오 조회를 보내지 않는지 확인합니다.
     *
     * @effects scheduler_skips_kapi_when_no_requested_rows
     *
     * @return void 반환값 없음
     */
    public function test_검수중_행이_없으면_kapi를_호출하지_않는다(): void
    {
        Http::fake();
        BizppurioTemplate::create(['notification_type' => 'welcome', 'status' => 'draft']);

        $this->artisan('bizppurio:sync-template-status')->assertExitCode(0);

        Http::assertNothingSent();
    }

    /**
     * 검수 중 행을 목록 일괄 조회 한 번으로 대조해 승인 상태로 바꾸는지 확인합니다.
     *
     * @effects scheduler_uses_list_batch_not_per_row_detail
     *
     * @return void 반환값 없음
     */
    public function test_검수중_행을_list_일괄_대조로_전이한다(): void
    {
        Http::fake([
            '*template/list*' => Http::response([
                'code' => '200', 'message' => 'ok',
                'data' => ['totalPage' => 1, 'list' => [['templateCode' => 'g7_aaaa1111_1', 'serviceStatus' => 'ACT']]],
            ]),
        ]);
        $this->seedKakaoSettings();
        $row = BizppurioTemplate::create([
            'notification_type' => 'welcome',
            'status' => 'requested',
            'template_code' => 'g7_aaaa1111_1',
            'sender_key' => 'SK_TEST',
            'content' => ['templateContent' => '본문'],
        ]);

        $this->artisan('bizppurio:sync-template-status')->assertExitCode(0);

        $this->assertSame(BizppurioTemplateStatus::Approved, $row->fresh()->status);
        $this->assertNotNull($row->fresh()->approved_content, '승인 전이 시 스냅샷이 동결돼야 한다.');
        Http::assertSentCount(1);
    }

    /**
     * 카카오 일괄 조회가 실패해도 행 상태를 건드리지 않고 커맨드는 정상 종료하는지 확인합니다.
     *
     * @effects scheduler_list_failure_keeps_rows_and_exits_zero
     *
     * @return void 반환값 없음
     */
    public function test_kapi_조회_실패는_커맨드를_실패시키지_않는다(): void
    {
        // 일괄 조회 실패는 로그만 남기고 다음 주기에 재시도한다 — 종료 코드는 0.
        Http::fake([
            '*template/list*' => Http::response(['code' => '403', 'message' => '권한 없음']),
        ]);
        $this->seedKakaoSettings();
        $row = BizppurioTemplate::create([
            'notification_type' => 'welcome',
            'status' => 'requested',
            'template_code' => 'g7_aaaa1111_1',
            'sender_key' => 'SK_TEST',
        ]);

        $this->artisan('bizppurio:sync-template-status')->assertExitCode(0);

        $this->assertSame(BizppurioTemplateStatus::Requested, $row->fresh()->status, '조회 실패 시 상태를 건드리지 않는다.');
    }

    /**
     * 플러그인이 동기화 커맨드를 30분 주기(옛 등록과 같은 시각)로 선언하는지 확인합니다.
     *
     * @effects schedule_declared_every_thirty_minutes
     *
     * @return void 반환값 없음
     */
    public function test_플러그인이_30분_주기_스케줄을_선언한다(): void
    {
        $plugin = new Plugin;

        $schedules = $plugin->getSchedules();

        $this->assertCount(1, $schedules);
        $this->assertSame('bizppurio:sync-template-status', $schedules[0]['command']);
        // 옛 등록(everyThirtyMinutes)과 같은 시각 — 매시 0·30분
        $this->assertSame('*/30 * * * *', $schedules[0]['expression']);
    }
}
