<?php

namespace Plugins\Sirsoft\Koreaexim\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\Koreaexim\Enums\RunStatus;
use Plugins\Sirsoft\Koreaexim\Listeners\UpdateFailedNotificationDataListener;
use Plugins\Sirsoft\Koreaexim\Models\KoreaeximRateRun;
use Plugins\Sirsoft\Koreaexim\Tests\PluginTestCase;
use Tests\Concerns\ForgesRequestHost;

/**
 * 환율 갱신 실패 관리자 알림의 설정 링크가 요청 Host 를 따르지 않는지 검증합니다.
 *
 * 알림은 동기 큐에서는 그 실행을 일으킨 요청 안에서, 큐 워커에서는 복원된 컨텍스트 안에서 만들어진다 —
 * 어느 쪽이든 링크는 사이트 주소여야 관리자 메일·알림이 올바른 곳을 가리킨다.
 */
class UpdateFailedNotificationHostTrustTest extends PluginTestCase
{
    use ForgesRequestHost;

    /**
     * 사이트 주소를 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->pinForgedHostSite();
    }

    /**
     * 신뢰 프록시 상태를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->resetForgedHostProxies();
        parent::tearDown();
    }

    /**
     * 실패 알림의 settings_url 은 사이트 주소다.
     *
     * @scenario host_header=forged, sink=notification_link
     *
     * @effects notification_link_uses_site_address
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_failed_notification_settings_url_uses_site_address(string $root, array $server): void
    {
        $this->bindForgedRequest($root, $server, '/api/plugins/sirsoft-koreaexim/admin/runs');

        $run = new KoreaeximRateRun([
            'status' => RunStatus::NoData,
            'message' => 'probe',
            'search_date' => '2026-10-03',
        ]);

        $data = app(UpdateFailedNotificationDataListener::class)->extract([], 'koreaexim_update_failed', [$run]);

        $this->assertSame(self::forgedHostSite().'/admin/plugins/sirsoft-koreaexim/settings', $data['data']['settings_url'] ?? null);
    }
}
