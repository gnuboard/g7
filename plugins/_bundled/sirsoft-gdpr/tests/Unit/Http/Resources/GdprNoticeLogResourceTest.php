<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Http\Resources;

use App\Services\NotificationChannelService;
use Illuminate\Http\Request;
use Plugins\Sirsoft\Gdpr\Enums\PrivacyNoticeStatus;
use Plugins\Sirsoft\Gdpr\Http\Resources\GdprNoticeLogResource;
use Plugins\Sirsoft\Gdpr\Models\GdprPrivacyNoticeLog;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 개인정보 통지 이력 「발송 채널」 칸이 채널 이름을 보이는지 검증합니다.
 *
 * 이력 화면의 채널 칸이 `database` 같은 식별자를 그대로 보였다 — 설정 화면의 발송 채널은 「사이트내 알림」처럼
 * 이름으로 보이는데 이력만 내부 키라 운영자가 어느 수단인지 대조하기 어려웠다.
 */
class GdprNoticeLogResourceTest extends PluginTestCase
{
    /**
     * 기존 테스트 DB를 트랜잭션으로 격리하고 전체 DDL 초기화를 피합니다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }

    /**
     * 저장하지 않은 통지 이력 한 줄을 만듭니다.
     *
     * @param  array<int, string>  $channels  채널 식별자 목록
     * @return GdprPrivacyNoticeLog 통지 이력
     */
    private function log(array $channels): GdprPrivacyNoticeLog
    {
        return new GdprPrivacyNoticeLog([
            'user_id' => 1,
            'status' => PrivacyNoticeStatus::Sent,
            'channels' => $channels,
            'is_test' => true,
        ]);
    }

    /**
     * 코어 채널은 현재 언어의 이름으로 보인다.
     *
     * @scenario locale=ko, channel=core
     *
     * @effects notice_log_channels_show_channel_names
     *
     * @return void 반환값 없음
     */
    public function test_core_channels_show_names_in_current_locale(): void
    {
        app()->setLocale('ko');
        $ko = (new GdprNoticeLogResource($this->log(['database', 'mail'])))->toArray(Request::create('/'));

        app()->setLocale('en');
        $en = (new GdprNoticeLogResource($this->log(['database', 'mail'])))->toArray(Request::create('/'));

        $this->assertSame('사이트내 알림, 메일', $ko['channels_label']);
        $this->assertSame('Site Notification, Email', $en['channels_label']);
        $this->assertSame(['database', 'mail'], $ko['channels'], '식별자 목록은 그대로 둔다');
    }

    /**
     * 지금 없는 채널(제공 확장을 끈 경우)은 식별자로 남긴다 — 빈칸이 되면 기록이 사라진 것처럼 보인다.
     *
     * @scenario locale=ko, channel=unknown
     *
     * @effects notice_log_unknown_channel_keeps_identifier
     *
     * @return void 반환값 없음
     */
    public function test_unknown_channel_keeps_identifier(): void
    {
        $this->mock(NotificationChannelService::class, function ($mock) {
            $mock->shouldReceive('getAvailableChannels')->andReturn([['id' => 'mail', 'name' => '메일']]);
        });

        $result = (new GdprNoticeLogResource($this->log(['mail', 'retired_channel'])))->toArray(Request::create('/'));

        $this->assertSame('메일, retired_channel', $result['channels_label']);
    }

    /**
     * 채널 이름표는 요청 하나에 한 번만 만든다 — 이력 한 쪽(20줄)마다 채널 필터 훅을 다시 돌리지 않는다.
     *
     * @scenario locale=ko, channel=core
     *
     * @effects notice_log_channel_names_built_once_per_request
     *
     * @return void 반환값 없음
     */
    public function test_channel_names_are_built_once_per_request(): void
    {
        $this->mock(NotificationChannelService::class, function ($mock) {
            $mock->shouldReceive('getAvailableChannels')->once()->andReturn([['id' => 'database', 'name' => '사이트내 알림']]);
        });
        $request = Request::create('/');

        $first = (new GdprNoticeLogResource($this->log(['database'])))->toArray($request);
        $second = (new GdprNoticeLogResource($this->log(['database'])))->toArray($request);

        $this->assertSame('사이트내 알림', $first['channels_label']);
        $this->assertSame('사이트내 알림', $second['channels_label']);
    }
}
