<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use ReflectionClass;
use Tests\Support\UpgradeFiles;

/**
 * Board 모듈 1.0.0-beta.4 업그레이드 스텝의 user_overrides dot-path 변환 검증.
 */
class BoardUserOverridesSubKeyMigrationTest extends ModuleTestCase
{
    private object $upgrade;

    private UpgradeContext $context;

    /**
     * 1.0.0-beta.4 업그레이드 스텝 파일을 싣고 beta.3 → beta.4 업그레이드 컨텍스트와 스텝 인스턴스를 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        UpgradeFiles::requireOnce(dirname(__DIR__, 3).'/upgrades/Upgrade_1_0_0_beta_4.php');

        $class = 'Modules\\Sirsoft\\Board\\Upgrades\\Upgrade_1_0_0_beta_4';
        $this->upgrade = new $class;
        $this->context = new UpgradeContext(
            fromVersion: '1.0.0-beta.3',
            toVersion: '1.0.0-beta.4',
            currentStep: '1.0.0-beta.4',
        );
    }

    /**
     * 업그레이드 스텝의 run 메서드를 리플렉션으로 호출해 board_types 변환을 실제로 실행한다.
     *
     * @return void 반환값 없음
     */
    private function runUpgrade(): void
    {
        $reflection = new ReflectionClass($this->upgrade);
        $method = $reflection->getMethod('run');
        $method->invoke($this->upgrade, $this->context);
    }

    /**
     * user_overrides 의 최상위 키 `name` 이 지원 로케일 수만큼 `name.ko`·`name.en`·`name.ja` dot-path 로 펼쳐진다.
     *
     * @scenario case=top_level_key
     *
     * @effects top_level_override_expanded_to_locale_dot_paths
     *
     * @return void 반환값 없음
     */
    public function test_board_types_name_expands_to_dot_paths(): void
    {
        config(['app.supported_locales' => ['ko', 'en', 'ja']]);

        $id = DB::table('board_types')->insertGetId([
            'slug' => 'test-'.uniqid(),
            'name' => json_encode(['ko' => '기본형', 'en' => 'Basic', 'ja' => '基本型']),
            'user_overrides' => json_encode(['name']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runUpgrade();

        $row = DB::table('board_types')->where('id', $id)->first(['user_overrides']);
        $overrides = json_decode($row->user_overrides, true);
        $this->assertEqualsCanonicalizing(['name.ko', 'name.en', 'name.ja'], $overrides);
    }

    /**
     * 이미 dot-path(`name.ko`)로 저장된 user_overrides 는 다시 펼치거나 다른 로케일을 덧붙이지 않고 그대로 둔다.
     *
     * @scenario case=already_dot_path
     *
     * @effects existing_dot_path_override_left_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_already_dot_path_idempotent(): void
    {
        config(['app.supported_locales' => ['ko', 'en']]);

        $id = DB::table('board_types')->insertGetId([
            'slug' => 'test-'.uniqid(),
            'name' => json_encode(['ko' => '기본형', 'en' => 'Basic']),
            'user_overrides' => json_encode(['name.ko']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runUpgrade();

        $row = DB::table('board_types')->where('id', $id)->first(['user_overrides']);
        $this->assertEquals(['name.ko'], json_decode($row->user_overrides, true));
    }

    /**
     * user_overrides 가 null 인 행은 빈 배열 등으로 바뀌지 않고 null 로 남는다.
     *
     * @scenario case=null_overrides
     *
     * @effects null_override_left_null
     *
     * @return void 반환값 없음
     */
    public function test_null_user_overrides_unchanged(): void
    {
        $id = DB::table('board_types')->insertGetId([
            'slug' => 'test-'.uniqid(),
            'name' => json_encode(['ko' => '기본형', 'en' => 'Basic']),
            'user_overrides' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runUpgrade();

        $row = DB::table('board_types')->where('id', $id)->first(['user_overrides']);
        $this->assertNull($row->user_overrides);
    }
}
