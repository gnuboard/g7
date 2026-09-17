<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use App\Upgrades\Data\Ext\Plugins\SirsoftGdpr\V1_1_0\Migrations\AppendViewModeToNecessaryAllowlist;
use Illuminate\Support\Facades\File;
use Plugins\Sirsoft\Gdpr\Plugin;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 1.1.0 업그레이드 스텝 — 확장 목록 보기 방식 키 추가 검증
 *
 * 이 스텝이 잘못되면 두 방향으로 조용히 깨진다: 추가에 실패하면 운영자가 고른 보기가
 * 새로고침마다 사라지고(#640 테마 전례), 목록을 통째로 덮어쓰면 운영자가 편집한 항목이
 * 소리 없이 사라진다. 둘 다 오류를 남기지 않으므로 여기서 잠근다.
 *
 * 검사 축:
 *   - 패턴이 없으면 추가한다
 *   - 패턴이 이미 있으면 아무것도 쓰지 않는다
 *   - 재실행해도 결과가 같다 (멱등)
 *   - 설정 파일이 없으면 skip
 *   - JSON 이 깨져 있으면 skip
 *   - 허용목록 키 자체가 없으면 skip (설치 시드·1.0.4 스텝의 몫)
 *   - 다른 스코프·다른 설정 키를 건드리지 않는다
 *   - 보기 방식 패턴이 잠금 집합에는 들어가지 않는다 (운영자가 지울 수 있어야 한다)
 *
 * @scenario scope=localStorage, notation=prefix, settings_state=seeded, request=key_absent
 *
 * @effects upgrade_appends_view_mode_pattern_when_absent, upgrade_leaves_allowlist_untouched_when_pattern_present
 */
class AppendViewModeToNecessaryAllowlistTest extends PluginTestCase
{
    /** @var string 추가되어야 하는 허용목록 패턴 */
    private const PATTERN = 'g7_view_mode_*';

    /** @var string 설정 파일 절대 경로 */
    private string $path;

    /**
     * 테스트 환경 준비 — 마이그레이션 적재 및 잔여 설정 파일 정리.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 업그레이드 마이그레이션은 오토로드되지 않는다 — AbstractUpgradeStep 이 실행 시점에
        // `data/{version}/migrations/*.php` 를 require_once 한다. 테스트도 같은 방식으로 싣는다.
        require_once __DIR__.'/../../../upgrades/data/1.1.0/migrations/01_AppendViewModeToNecessaryAllowlist.php';

        $this->path = ExtensionStoragePath::plugin('sirsoft-gdpr', 'settings').'/setting.json';

        if (File::exists($this->path)) {
            File::delete($this->path);
        }
    }

    /**
     * 테스트가 만든 설정 파일 정리.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (File::exists($this->path)) {
            File::delete($this->path);
        }

        parent::tearDown();
    }

    /**
     * 패턴이 없으면 localStorage 절 끝에 추가합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=localStorage, notation=prefix, settings_state=seeded, request=key_absent
     *
     * @effects upgrade_appends_view_mode_pattern_when_absent
     */
    public function test_appends_pattern_when_absent(): void
    {
        $this->writeSettings([
            'banner_enabled' => true,
            'necessary_storage_allowlist' => [
                'localStorage' => ['g7_locale', 'g7_admin_sidebar_collapsed'],
                'sessionStorage' => ['g7.identity.redirectStash'],
            ],
        ]);

        $this->runStep();

        $allowlist = $this->readSettings()['necessary_storage_allowlist'];
        $this->assertContains(self::PATTERN, $allowlist['localStorage']);
        $this->assertSame(
            ['g7_locale', 'g7_admin_sidebar_collapsed', self::PATTERN],
            $allowlist['localStorage'],
            '기존 항목 뒤에 덧붙여야 하고 순서를 흔들면 안 됩니다.'
        );
    }

    /**
     * 패턴이 이미 있으면 파일을 건드리지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=localStorage, notation=prefix, settings_state=seeded, request=key_present
     *
     * @effects upgrade_leaves_allowlist_untouched_when_pattern_present
     */
    public function test_leaves_file_untouched_when_pattern_present(): void
    {
        $this->writeSettings([
            'necessary_storage_allowlist' => [
                'localStorage' => ['g7_locale', self::PATTERN],
            ],
        ]);
        $before = File::get($this->path);

        $this->runStep();

        $this->assertSame($before, File::get($this->path));
    }

    /**
     * 재실행해도 항목이 중복되지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=localStorage, notation=prefix, settings_state=seeded, request=key_absent
     *
     * @effects upgrade_appends_view_mode_pattern_when_absent
     */
    public function test_is_idempotent(): void
    {
        $this->writeSettings([
            'necessary_storage_allowlist' => ['localStorage' => ['g7_locale']],
        ]);

        $this->runStep();
        $this->runStep();
        $this->runStep();

        $localStorage = $this->readSettings()['necessary_storage_allowlist']['localStorage'];
        $this->assertSame(1, count(array_keys($localStorage, self::PATTERN, true)));
    }

    /**
     * 설정 파일이 없으면 만들지 않고 skip 합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=localStorage, notation=prefix, settings_state=empty, request=key_absent
     *
     * @effects upgrade_leaves_allowlist_untouched_when_pattern_present
     */
    public function test_skips_when_settings_file_missing(): void
    {
        $this->runStep();

        $this->assertFalse(File::exists($this->path));
    }

    /**
     * JSON 이 깨져 있으면 덮어쓰지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=localStorage, notation=prefix, settings_state=empty, request=key_absent
     *
     * @effects upgrade_leaves_allowlist_untouched_when_pattern_present
     */
    public function test_skips_when_settings_json_malformed(): void
    {
        File::ensureDirectoryExists(dirname($this->path));
        File::put($this->path, '{ this is not json');

        $this->runStep();

        $this->assertSame('{ this is not json', File::get($this->path));
    }

    /**
     * 허용목록 키 자체가 없으면 새로 만들지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=localStorage, notation=prefix, settings_state=empty, request=key_absent
     *
     * @effects upgrade_leaves_allowlist_untouched_when_pattern_present
     */
    public function test_skips_when_allowlist_key_absent(): void
    {
        $this->writeSettings(['banner_enabled' => true]);

        $this->runStep();

        $this->assertArrayNotHasKey('necessary_storage_allowlist', $this->readSettings());
    }

    /**
     * 다른 스코프와 다른 설정 키는 그대로 둡니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=sessionStorage, notation=exact, settings_state=seeded, request=key_present
     *
     * @effects upgrade_leaves_allowlist_untouched_when_pattern_present
     */
    public function test_does_not_touch_other_scopes_or_settings(): void
    {
        $this->writeSettings([
            'banner_enabled' => true,
            'banner_position' => 'bottom',
            'necessary_storage_allowlist' => [
                'localStorage' => ['g7_locale'],
                'sessionStorage' => ['g7.identity.redirectStash'],
                'cookie' => ['laravel_maintenance'],
            ],
        ]);

        $this->runStep();

        $settings = $this->readSettings();
        $this->assertTrue($settings['banner_enabled']);
        $this->assertSame('bottom', $settings['banner_position']);
        $this->assertSame(['g7.identity.redirectStash'], $settings['necessary_storage_allowlist']['sessionStorage']);
        $this->assertSame(['laravel_maintenance'], $settings['necessary_storage_allowlist']['cookie']);
    }

    /**
     * 출하 카탈로그에 보기 방식 패턴이 등재돼 있습니다.
     *
     * 신규 설치는 업그레이드 스텝을 타지 않고 카탈로그를 그대로 시드하므로,
     * 이 축이 없으면 새 사이트만 보기 선택이 파기됩니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=localStorage, notation=prefix, settings_state=seeded, request=key_present
     *
     * @effects shipped_catalog_contains_view_mode_pattern
     */
    public function test_shipped_catalog_contains_view_mode_pattern(): void
    {
        $catalog = Plugin::DEFAULT_NECESSARY_ALLOWLIST_CATALOG;

        $this->assertContains(self::PATTERN, $catalog['localStorage']);
    }

    /**
     * 보기 방식 패턴은 잠금 집합에 들어가지 않습니다.
     *
     * 잠금 항목은 운영자가 화면에서 지울 수 없다 — 인증·세션처럼 지우면 서비스가
     * 망가지는 것만 거기 둔다. 보기 선택은 운영자가 빼도 그만인 표시 설정이므로
     * 카탈로그(기본값)에만 있어야 한다. 이 축이 없으면 「허용목록에 넣는다」를
     * 잠금 집합에 넣는 것으로 이행해도 아무도 잡지 못한다.
     *
     * 모집단은 손으로 적지 않는다 — 잠금 집합의 **전 스코프**를 돌고, 그 집합이
     * 비면 red 가 되도록 스코프 수를 먼저 잠근다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=localStorage, notation=prefix, settings_state=seeded, request=key_present
     *
     * @effects shipped_catalog_contains_view_mode_pattern
     */
    public function test_view_mode_pattern_is_not_seeded_into_locked_set(): void
    {
        $locked = Plugin::lockedNecessaryStorage();

        $this->assertNotEmpty($locked, '잠금 집합이 비어 있으면 이 축은 아무것도 재지 못합니다.');

        foreach ($locked as $scope => $items) {
            $this->assertNotContains(
                self::PATTERN,
                $items,
                $scope.' 잠금 집합에 보기 방식 패턴이 들어갔습니다 — 운영자가 지울 수 없게 됩니다.'
            );
        }

        // 잠금 집합이 지켜야 할 것(인증 토큰)은 그대로 있는지 대조군으로 확인한다.
        $this->assertContains('auth_token', $locked['localStorage']);
    }

    /**
     * 마이그레이션을 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runStep(): void
    {
        (new AppendViewModeToNecessaryAllowlist)->run(
            new UpgradeContext(
                fromVersion: '1.0.4',
                toVersion: '1.1.0',
                currentStep: '1.1.0',
                logChannel: 'extension-upgrade',
            )
        );
    }

    /**
     * 설정 파일을 씁니다.
     *
     * @param  array<string, mixed>  $settings  설정 내용
     * @return void 반환값 없음
     */
    private function writeSettings(array $settings): void
    {
        File::ensureDirectoryExists(dirname($this->path));
        File::put($this->path, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * 설정 파일을 읽습니다.
     *
     * @return array<string, mixed> 설정 내용
     */
    private function readSettings(): array
    {
        return json_decode(File::get($this->path), true);
    }
}
