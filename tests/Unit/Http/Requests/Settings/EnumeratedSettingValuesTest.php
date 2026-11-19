<?php

namespace Tests\Unit\Http\Requests\Settings;

use App\Execution\ExecutionMode;
use App\Http\Requests\Settings\EnumeratedSettingValues;
use App\Support\AssetUrl;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\In;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 허용값 표(EnumeratedSettingValues) 단위 테스트.
 *
 * 표는 벌크·단건 두 저장 경로가 공유하는 SSoT 다 — 표의 키가 설정 카탈로그에 실재하고,
 * 허용값이 그 값을 해석하는 쪽(Enum·상수)과 어긋나지 않는지를 고정한다.
 */
class EnumeratedSettingValuesTest extends TestCase
{
    /**
     * 허용값 표에 없는 키나 빈 키에는 규칙을 돌려주지 않는지(null) 확인한다.
     *
     * @scenario setting_key=non_enumerated
     *
     * @effects non_enumerated_key_has_no_allowed_value_rule
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 허용값이_닫힌_키가_아니면_null_을_돌려준다(): void
    {
        $this->assertNull(EnumeratedSettingValues::ruleFor('general.site_name'));
        $this->assertNull(EnumeratedSettingValues::ruleFor(''));
    }

    /**
     * 실행 모드 허용값이 ExecutionMode 열거형의 케이스 값 목록과 순서까지 같은지 확인한다.
     *
     * @scenario setting_key=execution_mode
     *
     * @effects execution_mode_allowed_values_match_the_enum
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 실행_모드_허용값은_열거형의_모든_케이스와_같다(): void
    {
        $this->assertSame(
            array_column(ExecutionMode::cases(), 'value'),
            EnumeratedSettingValues::allowed()['system.execution_mode'],
        );
        $this->assertSame(['auto', 'subprocess', 'inprocess'], ExecutionMode::values());
    }

    /**
     * 자산 URL 방식 허용값이 AssetUrl 의 두 모드 상수와 같은지 확인한다.
     *
     * @scenario setting_key=asset_url_mode
     *
     * @effects asset_url_mode_allowed_values_match_the_constants
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 자산_url_방식_허용값은_상수와_같다(): void
    {
        $this->assertSame(
            [AssetUrl::MODE_EXTENSION, AssetUrl::MODE_EXTENSIONLESS],
            EnumeratedSettingValues::allowed()['general.asset_url_mode'],
        );
    }

    /**
     * 허용값 표의 키마다 설정 카탈로그 기본값이 있고 그 기본값이 허용값 안에 드는지 확인한다.
     *
     * @effects enumerated_keys_exist_in_the_settings_catalog_with_allowed_defaults
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 표의_모든_키는_설정_카탈로그_기본값에_실재한다(): void
    {
        $defaults = json_decode((string) file_get_contents(config_path('settings/defaults.json')), true)['defaults'];

        foreach (array_keys(EnumeratedSettingValues::allowed()) as $key) {
            $this->assertTrue(Arr::has($defaults, $key), "허용값 표의 키가 카탈로그에 없다: {$key}");
            $this->assertContains(Arr::get($defaults, $key), EnumeratedSettingValues::allowed()[$key], "카탈로그 기본값이 허용값 밖이다: {$key}");
        }
    }

    /**
     * 실행 모드 규칙이 In 규칙이며 열거형 값만 통과시키고 엉뚱한 값과 대문자 값은 거부하는지 확인한다.
     *
     * @scenario setting_key=execution_mode
     *
     * @effects enumerated_rule_accepts_only_allowed_values
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 규칙은_허용값만_통과시킨다(): void
    {
        $rule = EnumeratedSettingValues::ruleFor('system.execution_mode');
        $this->assertInstanceOf(In::class, $rule);

        foreach (ExecutionMode::values() as $mode) {
            $this->assertTrue(Validator::make(['v' => $mode], ['v' => [$rule]])->passes(), $mode);
        }

        $this->assertTrue(Validator::make(['v' => 'garbage'], ['v' => [$rule]])->fails());
        $this->assertTrue(Validator::make(['v' => 'AUTO'], ['v' => [$rule]])->fails());
    }
}
