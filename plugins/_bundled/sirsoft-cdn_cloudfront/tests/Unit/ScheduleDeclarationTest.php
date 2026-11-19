<?php

namespace Plugins\Sirsoft\CdnCloudfront\Tests\Unit;

use App\Enums\ExtensionOwnerType;
use Plugins\Sirsoft\CdnCloudfront\Plugin;
use Plugins\Sirsoft\CdnCloudfront\Tests\PluginTestCase;
use Tests\Support\Concerns\AssertsExtensionScheduleDeclarations;

/**
 * sirsoft-cdn_cloudfront 스케줄 선언 — 코어 스케줄 표로 동기화될 수 있는 모양인지
 *
 * 동기화는 검증 위반 항목을 건너뛰므로 선언이 틀리면 설치 뒤 재시도 작업이 조용히 빠진다.
 *
 * @scenario-manifest tests/scenarios/cloudfront-purge.yaml
 */
class ScheduleDeclarationTest extends PluginTestCase
{
    use AssertsExtensionScheduleDeclarations;

    /**
     * 선언이 정규화·검증·실행 조건 해석을 통과하고, 5분 주기·자동 무효화 설정 조건을 갖는다.
     *
     * @scenario case=retry_success
     *
     * @effects retry_schedule_declared
     *
     * @return void 반환값 없음
     */
    public function test_retry_schedule_is_syncable(): void
    {
        $schedules = (new Plugin)->getSchedules();

        $this->assertScheduleDeclarationsSyncable($schedules, ExtensionOwnerType::Plugin, self::IDENTIFIER);
        $this->assertSame([
            'retry-purges' => ['sirsoft-cdn_cloudfront:retry-purges', '*/5 * * * *', null, ['plugin_setting:purge_enabled']],
        ], $this->scheduleDeclarationTable($schedules));
    }
}
