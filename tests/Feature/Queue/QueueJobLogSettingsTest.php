<?php

namespace Tests\Feature\Queue;

use App\Http\Requests\Settings\SaveSettingsRequest;
use App\Services\JobQueueService;
use Tests\TestCase;

/**
 * 큐 처리 기록 설정 키 테스트 (— drivers.queue_job_log_enabled · drivers.queue_record_retention_days)
 */
class QueueJobLogSettingsTest extends TestCase
{
    /**
     * 기본값 파일과 프론트 스키마에 두 키가 있다(기록 켬·7일).
     *
     * @scenario surface=defaults
     *
     * @effects settings_keys_have_defaults
     *
     * @return void 반환값 없음
     */
    public function test_기본값과_스키마에_두_키가_있다(): void
    {
        $defaults = json_decode((string) file_get_contents(config_path('settings/defaults.json')), true);

        $this->assertTrue($defaults['defaults']['drivers']['queue_job_log_enabled']);
        $this->assertSame(7, $defaults['defaults']['drivers']['queue_record_retention_days']);
        $this->assertSame('boolean', $defaults['frontend_schema']['drivers']['fields']['queue_job_log_enabled']['type']);
        $this->assertSame('integer', $defaults['frontend_schema']['drivers']['fields']['queue_record_retention_days']['type']);
    }

    /**
     * 저장 요청은 보관 기간을 1~90 정수로, 기록 켜기를 boolean 으로 검증하고 라벨을 준다.
     *
     * @scenario surface=save_request
     *
     * @effects retention_setting_bounds_enforced
     *
     * @return void 반환값 없음
     */
    public function test_저장_요청은_보관기간_범위를_검증한다(): void
    {
        $request = new SaveSettingsRequest;
        $rules = $request->rules();

        $this->assertContains('boolean', $rules['drivers.queue_job_log_enabled']);
        $this->assertContains('integer', $rules['drivers.queue_record_retention_days']);
        $this->assertContains('min:1', $rules['drivers.queue_record_retention_days']);
        $this->assertContains('max:90', $rules['drivers.queue_record_retention_days']);
        $this->assertSame(1, config('core.settings_limits.drivers_queue_record_retention_days_min'));
        $this->assertSame(90, config('core.settings_limits.drivers_queue_record_retention_days_max'));

        $attributes = $request->attributes();
        $this->assertSame(__('validation.attributes.queue_record_retention_days'), $attributes['drivers.queue_record_retention_days']);
        $this->assertSame(__('validation.attributes.queue_job_log_enabled'), $attributes['drivers.queue_job_log_enabled']);
    }

    /**
     * 서비스의 보관 기간은 설정값을 쓰되 비었거나 범위 밖이면 기본 7일이다.
     *
     * @scenario retention=null|0|1|30|90|91|non_numeric
     *
     * @effects retention_days_clamped_to_default
     *
     * @return void 반환값 없음
     */
    public function test_보관기간은_범위_밖이면_기본값이다(): void
    {
        $service = app(JobQueueService::class);
        $cases = [null => 7, 0 => 7, 1 => 1, 30 => 30, 90 => 90, 91 => 7, 'abc' => 7];

        foreach ($cases as $value => $expected) {
            config(['g7_settings.core.drivers.queue_record_retention_days' => $value === '' ? null : $value]);
            $this->assertSame($expected, $service->retentionDays(), 'value='.var_export($value, true));
        }
    }
}
