<?php

namespace Plugins\Sirsoft\Koreaexim\Tests\Unit;

use Illuminate\Support\Facades\Validator;
use Plugins\Sirsoft\Koreaexim\Listeners\ValidatePluginSettingsListener;
use Plugins\Sirsoft\Koreaexim\Plugin;
use Plugins\Sirsoft\Koreaexim\Tests\PluginTestCase;

class PluginScheduleTest extends PluginTestCase
{
    /**
     * 스케줄과 설정 입력 조합별 등록 및 검증 결과를 확인합니다.
     *
     * @effects settings_validation, schedule_timezone_conversion
     *
     * @return void 반환값 없음
     */
    public function test_schedule_and_settings_input_matrix(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 14));
        $cases = require dirname(__DIR__).'/fixtures/ScheduleCases.php';
        foreach ($cases as [$time, $zone, $days, $auto, $key]) {
            config(['app.schedule_timezone' => $zone]);
            $this->configure(['auth_key' => 'fixture-key', 'auto_manage' => false, 'update_time' => '11:30']);
            $input = ['auto_manage' => $auto === 'on', 'auth_key' => ['set' => 'fixture-key', 'unset' => '', 'masked' => '••••••••'][$key],
                'run_days' => ['weekdays' => [1, 2, 3, 4, 5], 'all' => [1, 2, 3, 4, 5, 6, 7], 'empty' => []][$days]];
            if ($time !== 'missing') {
                $input['update_time'] = $time === 'valid' ? '12:45' : '25:99';
            }
            $rules = (new ValidatePluginSettingsListener)->rules([], 'sirsoft-koreaexim', $input);
            $validator = Validator::make($input, $rules);
            $context = implode('/', [$time, $zone, $days, $auto, $key]);
            $this->assertSame($time === 'invalid' || ($auto === 'on' && $key === 'unset'), $validator->fails(), $context);
            // 非HTTP 진입점의 잘못된 기존 설정에도 스케줄 등록은 안전한 기본값으로 복구합니다.
            if ($time !== 'missing') {
                $this->configure(['update_time' => $input['update_time']]);
            }
            $expected = $time === 'valid' ? ($zone === 'Asia/Seoul' ? '45 12 * * *' : '45 23 * * *') : ($zone === 'Asia/Seoul' ? '30 11 * * *' : '30 22 * * *');
            $this->assertSame($expected, (new Plugin)->getSchedules()[0]['schedule'], $context);
        }
    }

    /**
     * 스케줄 세 종류와 한국시간의 서버 시간대 변환을 검증합니다.
     *
     * @effects three_schedules_and_kst_timezone_conversion
     *
     * @return void 반환값 없음
     */
    public function test_three_schedules_and_kst_timezone_conversion(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 14));
        $this->configure(['update_time' => '11:30']);
        config(['app.schedule_timezone' => 'Asia/Seoul']);
        $plugin = new Plugin;
        $this->assertCount(3, $plugin->getSchedules());
        $this->assertSame('30 11 * * *', $plugin->getSchedules()[0]['schedule']);
        config(['app.schedule_timezone' => 'America/New_York']);
        $this->assertSame('30 22 * * *', $plugin->getSchedules()[0]['schedule']);
        $this->configure(['update_time' => '25:99']);
        $this->assertSame('30 22 * * *', $plugin->getSchedules()[0]['schedule']);
        $defaults = json_decode(file_get_contents(dirname(__DIR__, 2).'/config/settings/defaults.json'), true);
        $this->assertSame($defaults['defaults'], $plugin->getConfigValues());
        $this->assertFalse(method_exists($plugin, 'getAdminMenus'));
        $this->assertSame(['auto_manage'], array_keys(array_filter($defaults['frontend_schema'], fn ($field) => $field['expose'])));
    }

    /**
     * 세 스케줄의 커맨드·활성 설정 키와 설정 손상 시 기본 스케줄 복구를 검증합니다.
     *
     * @effects three_schedules_and_kst_timezone_conversion
     *
     * @return void 반환값 없음
     */
    public function test_schedule_commands_enabled_config_and_recovery_from_broken_settings(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 14));
        $this->configure(['update_time' => '11:30']);
        config(['app.schedule_timezone' => 'Asia/Seoul']);
        $schedules = (new Plugin)->getSchedules();
        $this->assertSame([
            'sirsoft-koreaexim:update --scheduled',
            'sirsoft-koreaexim:update --scheduled --retry',
            'sirsoft-koreaexim:prune-history',
        ], array_column($schedules, 'command'));
        $this->assertSame(['hourly', '57 4 * * *'], [$schedules[1]['schedule'], $schedules[2]['schedule']]);
        // 정기·재시도만 자동 관리 스위치를 따르고 이력 정리는 항상 돕니다.
        $this->assertSame('auto_manage', $schedules[0]['enabled_config']);
        $this->assertSame('auto_manage', $schedules[1]['enabled_config']);
        $this->assertArrayNotHasKey('enabled_config', $schedules[2]);
        // 시간대 설정이 손상돼도 예외를 던지면 다른 확장의 스케줄 등록까지 함께 사라집니다.
        config(['app.schedule_timezone' => 'Not/AZone']);
        $recovered = (new Plugin)->getSchedules();
        $this->assertCount(3, $recovered);
        $this->assertSame('30 11 * * *', $recovered[0]['schedule']);
    }
}
