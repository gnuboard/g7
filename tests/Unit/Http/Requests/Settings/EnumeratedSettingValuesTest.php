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
    #[Test]
    public function 허용값이_닫힌_키가_아니면_null_을_돌려준다(): void
    {
        $this->assertNull(EnumeratedSettingValues::ruleFor('general.site_name'));
        $this->assertNull(EnumeratedSettingValues::ruleFor(''));
    }

    #[Test]
    public function 실행_모드_허용값은_열거형의_모든_케이스와_같다(): void
    {
        $this->assertSame(
            array_column(ExecutionMode::cases(), 'value'),
            EnumeratedSettingValues::allowed()['system.execution_mode'],
        );
        $this->assertSame(['auto', 'subprocess', 'inprocess'], ExecutionMode::values());
    }

    #[Test]
    public function 자산_url_방식_허용값은_상수와_같다(): void
    {
        $this->assertSame(
            [AssetUrl::MODE_EXTENSION, AssetUrl::MODE_EXTENSIONLESS],
            EnumeratedSettingValues::allowed()['general.asset_url_mode'],
        );
    }

    #[Test]
    public function 표의_모든_키는_설정_카탈로그_기본값에_실재한다(): void
    {
        $defaults = json_decode((string) file_get_contents(config_path('settings/defaults.json')), true)['defaults'];

        foreach (array_keys(EnumeratedSettingValues::allowed()) as $key) {
            $this->assertTrue(Arr::has($defaults, $key), "허용값 표의 키가 카탈로그에 없다: {$key}");
            $this->assertContains(Arr::get($defaults, $key), EnumeratedSettingValues::allowed()[$key], "카탈로그 기본값이 허용값 밖이다: {$key}");
        }
    }

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
