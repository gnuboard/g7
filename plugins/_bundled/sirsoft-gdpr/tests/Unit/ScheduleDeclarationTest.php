<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit;

use App\Enums\ExtensionOwnerType;
use Plugins\Sirsoft\Gdpr\Plugin;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;
use Tests\Support\Concerns\AssertsExtensionScheduleDeclarations;

/**
 * sirsoft-gdpr 스케줄 선언 (코어 7.2.0 DB 스케줄 등록부)
 *
 * 선언은 설치·갱신 때 코어가 스케줄 표로 동기화한다. 동기화는 검증 위반 항목을 건너뛰므로 선언이 틀리면
 * 설치 뒤 작업이 조용히 빠진다. 또 옛 등록(Laravel 주기 메서드·enabled_config)과 실행 시각·조건이 같아야
 * 업데이트한 사이트의 동작이 바뀌지 않는다.
 *
 * 참고: 연간 통지 조건의 기본값은 defaults.json 이 아니라 Plugin::getConfigValues() 에 있다 — 조건 해석기가 그 원천도 본다.
 */
class ScheduleDeclarationTest extends PluginTestCase
{
    use AssertsExtensionScheduleDeclarations;

    /**
     * 선언이 새 형식이고 정규화·검증·실행 조건 해석을 통과하는지 확인합니다.
     *
     * @scenario shape=new_schema
     *
     * @effects declarations_syncable
     *
     * @return void 반환값 없음
     */
    public function test_declarations_are_syncable(): void
    {
        $this->assertScheduleDeclarationsSyncable((new Plugin)->getSchedules(), ExtensionOwnerType::Plugin, 'sirsoft-gdpr');
    }

    /**
     * 옛 등록과 같은 명령·실행 시각(사이트 시간대)·실행 조건을 유지하는지 확인합니다.
     *
     * @scenario shape=new_schema
     *
     * @effects legacy_timing_and_conditions_preserved
     *
     * @return void 반환값 없음
     */
    public function test_legacy_timing_and_conditions_preserved(): void
    {
        $this->assertSame([
            'send-annual-notices' => ['sirsoft-gdpr:send-annual-notices --scheduled', '0 0 * * *', null, ['plugin_setting:annual_notice.enabled']],
        ], $this->scheduleDeclarationTable((new Plugin)->getSchedules()));
    }

    /**
     * 코어를 먼저 7.2.0 으로 올린 사이트에서 옛 형식이 자동 이름으로 만든 행을, 새 형식 업데이트가 지우지 않고 이어받는지 확인합니다.
     *
     * @scenario shape=new_schema
     *
     * @effects legacy_named_rows_adopted_on_format_upgrade
     *
     * @return void 반환값 없음
     */
    public function test_legacy_rows_adopted_on_format_upgrade(): void
    {
        $this->assertLegacyRowsAdoptedOnFormatUpgrade((new Plugin)->getSchedules(), ExtensionOwnerType::Plugin, 'sirsoft-gdpr');
    }
}
