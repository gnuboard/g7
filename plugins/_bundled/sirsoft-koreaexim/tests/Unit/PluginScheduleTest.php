<?php

namespace Plugins\Sirsoft\Koreaexim\Tests\Unit;

use App\Enums\ExtensionOwnerType;
use Illuminate\Support\Facades\Validator;
use Plugins\Sirsoft\Koreaexim\Listeners\ValidatePluginSettingsListener;
use Plugins\Sirsoft\Koreaexim\Plugin;
use Plugins\Sirsoft\Koreaexim\Tests\PluginTestCase;
use Tests\Support\Concerns\AssertsExtensionScheduleDeclarations;

/**
 * 스케줄 선언과 설정 검증 (1.1.0 — 갱신 시각은 스케줄 관리로 이동)
 *
 * 1.0.0 은 설정 `update_time`(한국시간)을 읽어 사이트 시간대 cron 으로 바꿔 선언했다. 1.1.0 부터 선언은
 * 한국시간 11:30 고정(cron + Asia/Seoul)이고 운영자는 관리자 > 스케줄 관리의 행에서 시각을 바꾼다.
 */
class PluginScheduleTest extends PluginTestCase
{
    use AssertsExtensionScheduleDeclarations;

    /**
     * 사이트 시간대·실행 요일·자동 관리·인증키 조합별 설정 검증과 선언 고정을 확인합니다.
     *
     * @effects settings_validation, declaration_fixed_to_kst_regardless_of_site_timezone
     *
     * @return void 반환값 없음
     */
    public function test_schedule_and_settings_input_matrix(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 14));
        $cases = require dirname(__DIR__).'/fixtures/ScheduleCases.php';
        foreach ($cases as [$zone, $days, $auto, $key]) {
            config(['app.schedule_timezone' => $zone]);
            $this->configure(['auth_key' => 'fixture-key', 'auto_manage' => false]);
            $input = ['auto_manage' => $auto === 'on', 'auth_key' => ['set' => 'fixture-key', 'unset' => '', 'masked' => '••••••••'][$key],
                'run_days' => ['weekdays' => [1, 2, 3, 4, 5], 'all' => [1, 2, 3, 4, 5, 6, 7], 'empty' => []][$days]];
            $rules = (new ValidatePluginSettingsListener)->rules([], 'sirsoft-koreaexim', $input);
            $context = implode('/', [$zone, $days, $auto, $key]);
            $this->assertSame($auto === 'on' && $key === 'unset', Validator::make($input, $rules)->fails(), $context);
            $this->assertArrayNotHasKey('update_time', $rules, $context);
            // 사이트 시간대가 바뀌어도 선언은 한국시간 고정 — 시간대 변환은 등록기가 행의 timezone 으로 한다.
            $update = (new Plugin)->getSchedules()[0];
            $this->assertSame(['30 11 * * *', 'Asia/Seoul'], [$update['expression'], $update['timezone']], $context);
        }
    }

    /**
     * 세 스케줄이 새 형식으로 선언되고 설정 스키마에서 갱신 시각이 빠졌는지 확인합니다.
     *
     * @effects three_schedules_declared_in_new_schema
     *
     * @return void 반환값 없음
     */
    public function test_three_schedules_declared_in_new_schema(): void
    {
        $plugin = new Plugin;
        $this->assertSame([
            'update-rates' => ['sirsoft-koreaexim:update --scheduled', '30 11 * * *', 'Asia/Seoul', ['plugin_setting:auto_manage']],
            'retry-rates' => ['sirsoft-koreaexim:update --scheduled --retry', '0 * * * *', null, ['plugin_setting:auto_manage']],
            'prune-history' => ['sirsoft-koreaexim:prune-history', '57 4 * * *', null, []],
        ], $this->scheduleDeclarationTable($plugin->getSchedules()));
        $this->assertSame('hourly', $plugin->getSchedules()[1]['frequency']);
        $this->assertArrayNotHasKey('update_time', $plugin->getSettingsSchema());
        $defaults = json_decode(file_get_contents(dirname(__DIR__, 2).'/config/settings/defaults.json'), true);
        $this->assertSame($defaults['defaults'], $plugin->getConfigValues());
        $this->assertArrayNotHasKey('update_time', $defaults['frontend_schema']);
        $this->assertFalse(method_exists($plugin, 'getAdminMenus'));
        $this->assertSame(['auto_manage'], array_keys(array_filter($defaults['frontend_schema'], fn ($field) => $field['expose'])));
    }

    /**
     * 선언이 코어 동기화의 정규화·검증·조건 해석을 통과하는지 확인합니다.
     *
     * @effects declarations_syncable
     *
     * @return void 반환값 없음
     */
    public function test_declarations_are_syncable(): void
    {
        $this->assertScheduleDeclarationsSyncable((new Plugin)->getSchedules(), ExtensionOwnerType::Plugin, 'sirsoft-koreaexim');
    }

    /**
     * 코어를 먼저 7.2.0 으로 올린 사이트에서 옛 형식이 자동 이름으로 만든 행을, 새 형식 업데이트가 지우지 않고 이어받는지 확인합니다.
     *
     * @effects legacy_named_rows_adopted_on_format_upgrade
     *
     * @return void 반환값 없음
     */
    public function test_legacy_rows_adopted_on_format_upgrade(): void
    {
        $this->assertLegacyRowsAdoptedOnFormatUpgrade((new Plugin)->getSchedules(), ExtensionOwnerType::Plugin, 'sirsoft-koreaexim');
    }
}
