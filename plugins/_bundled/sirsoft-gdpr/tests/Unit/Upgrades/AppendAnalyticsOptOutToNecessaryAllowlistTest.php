<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use App\Upgrades\Data\Ext\Plugins\SirsoftGdpr\V1_1_0\Migrations\AppendAnalyticsOptOutToNecessaryAllowlist;
use Illuminate\Support\Facades\File;
use Plugins\Sirsoft\Gdpr\Plugin;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 1.1.0 업그레이드 스텝 — 방문자 통계 수집 거부 쿠키 추가 검증
 *
 * 이 항목이 허용목록에서 빠지면 동의 전 상태에서 응답의 Set-Cookie 가 제거되어,
 * 「내 방문은 세지 않기」를 눌러도 아무 일도 일어나지 않는다. 서버는 200 과
 * `opted_out: true` 를 돌려주므로 화면에도 로그에도 신호가 남지 않는다 —
 * **동의하지 않은 방문자일수록 제외가 안 되는 역설**이라 방향이 정반대다.
 * 반대로 목록을 통째로 덮어쓰면 운영자가 편집한 항목이 소리 없이 사라진다.
 * 둘 다 오류를 남기지 않으므로 여기서 잠근다.
 *
 * 검사 축:
 *   - 쿠키가 없으면 추가한다
 *   - 이미 있으면 아무것도 쓰지 않는다
 *   - 재실행해도 결과가 같다 (멱등)
 *   - 설정 파일이 없으면 skip
 *   - JSON 이 깨져 있으면 skip
 *   - 허용목록 키 자체가 없으면 skip (설치 시드·1.0.4 스텝의 몫)
 *   - 다른 스코프·다른 설정 키를 건드리지 않는다
 *   - 출하 카탈로그에도 등재돼 있다 (신규 설치는 스텝을 타지 않는다)
 *   - 잠금 집합에는 들어가지 않는다 (운영자가 뺄 수 있어야 한다)
 *
 * @scenario scope=cookie, notation=exact, settings_state=seeded, request=key_absent
 *
 * @effects upgrade_appends_analytics_opt_out_cookie_when_absent,
 *   upgrade_leaves_allowlist_untouched_when_opt_out_cookie_present
 */
class AppendAnalyticsOptOutToNecessaryAllowlistTest extends PluginTestCase
{
    /** @var string 추가되어야 하는 허용목록 항목 */
    private const PATTERN = 'g7_analytics_optout';

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
        require_once __DIR__.'/../../../upgrades/data/1.1.0/migrations/02_AppendAnalyticsOptOutToNecessaryAllowlist.php';

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
     * 항목이 없으면 cookie 절 끝에 추가합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=cookie, notation=exact, settings_state=seeded, request=key_absent
     *
     * @effects upgrade_appends_analytics_opt_out_cookie_when_absent
     */
    public function test_appends_cookie_when_absent(): void
    {
        $this->writeSettings([
            'banner_enabled' => true,
            'necessary_storage_allowlist' => [
                'localStorage' => ['g7_locale'],
                'cookie' => ['laravel_maintenance'],
            ],
        ]);

        $this->runStep();

        $allowlist = $this->readSettings()['necessary_storage_allowlist'];
        $this->assertContains(self::PATTERN, $allowlist['cookie']);
        $this->assertSame(
            ['laravel_maintenance', self::PATTERN],
            $allowlist['cookie'],
            '기존 항목 뒤에 덧붙여야 하고 순서를 흔들면 안 됩니다.'
        );
    }

    /**
     * 항목이 이미 있으면 파일을 건드리지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=cookie, notation=exact, settings_state=seeded, request=key_present
     *
     * @effects upgrade_leaves_allowlist_untouched_when_opt_out_cookie_present
     */
    public function test_leaves_file_untouched_when_cookie_present(): void
    {
        $this->writeSettings([
            'necessary_storage_allowlist' => [
                'cookie' => ['laravel_maintenance', self::PATTERN],
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
     * @scenario scope=cookie, notation=exact, settings_state=seeded, request=key_absent
     *
     * @effects upgrade_appends_analytics_opt_out_cookie_when_absent
     */
    public function test_is_idempotent(): void
    {
        $this->writeSettings([
            'necessary_storage_allowlist' => ['cookie' => ['laravel_maintenance']],
        ]);

        $this->runStep();
        $this->runStep();
        $this->runStep();

        $cookies = $this->readSettings()['necessary_storage_allowlist']['cookie'];
        $this->assertSame(1, count(array_keys($cookies, self::PATTERN, true)));
    }

    /**
     * cookie 절이 아직 없어도 새로 만들어 추가합니다.
     *
     * 1.0.4 이전에 만들어진 파일에는 절 자체가 없을 수 있다. 그때 조용히 skip 하면
     * 그 사이트만 제외가 영영 동작하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=cookie, notation=exact, settings_state=seeded, request=key_absent
     *
     * @effects upgrade_appends_analytics_opt_out_cookie_when_absent
     */
    public function test_creates_cookie_scope_when_missing(): void
    {
        $this->writeSettings([
            'necessary_storage_allowlist' => ['localStorage' => ['g7_locale']],
        ]);

        $this->runStep();

        $allowlist = $this->readSettings()['necessary_storage_allowlist'];
        $this->assertSame([self::PATTERN], $allowlist['cookie']);
        $this->assertSame(['g7_locale'], $allowlist['localStorage']);
    }

    /**
     * 설정 파일이 없으면 만들지 않고 skip 합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=cookie, notation=exact, settings_state=empty, request=key_absent
     *
     * @effects upgrade_leaves_allowlist_untouched_when_opt_out_cookie_present
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
     * @scenario scope=cookie, notation=exact, settings_state=empty, request=key_absent
     *
     * @effects upgrade_leaves_allowlist_untouched_when_opt_out_cookie_present
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
     * @scenario scope=cookie, notation=exact, settings_state=empty, request=key_absent
     *
     * @effects upgrade_leaves_allowlist_untouched_when_opt_out_cookie_present
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
     * @scenario scope=localStorage, notation=prefix, settings_state=seeded, request=key_present
     *
     * @effects upgrade_leaves_allowlist_untouched_when_opt_out_cookie_present
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
        $this->assertSame(['g7_locale'], $settings['necessary_storage_allowlist']['localStorage']);
        $this->assertSame(['g7.identity.redirectStash'], $settings['necessary_storage_allowlist']['sessionStorage']);
    }

    /**
     * 출하 카탈로그에 수집 거부 쿠키가 등재돼 있습니다.
     *
     * 신규 설치는 업그레이드 스텝을 타지 않고 카탈로그를 그대로 시드하므로,
     * 이 축이 없으면 새 사이트에서만 제외가 동작하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=cookie, notation=exact, settings_state=seeded, request=key_present
     *
     * @effects shipped_catalog_contains_analytics_opt_out_cookie
     */
    public function test_shipped_catalog_contains_opt_out_cookie(): void
    {
        $catalog = Plugin::DEFAULT_NECESSARY_ALLOWLIST_CATALOG;

        $this->assertContains(self::PATTERN, $catalog['cookie']);
    }

    /**
     * 수집 거부 쿠키는 잠금 집합에 들어가지 않습니다.
     *
     * 잠금 항목은 운영자가 화면에서 지울 수 없다 — 인증·세션처럼 지우면 서비스가
     * 망가지는 것만 거기 둔다. 수집 제외는 운영자가 빼도 사이트가 서는 항목이므로
     * 카탈로그(기본값)에만 있어야 한다.
     *
     * 모집단은 손으로 적지 않는다 — 잠금 집합의 **전 스코프**를 돌고, 그 집합이
     * 비면 red 가 되도록 먼저 비어 있지 않음을 잠근다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=cookie, notation=exact, settings_state=seeded, request=key_present
     *
     * @effects shipped_catalog_contains_analytics_opt_out_cookie
     */
    public function test_opt_out_cookie_is_not_seeded_into_locked_set(): void
    {
        $locked = Plugin::lockedNecessaryStorage();

        $this->assertNotEmpty($locked, '잠금 집합이 비어 있으면 이 축은 아무것도 재지 못합니다.');

        foreach ($locked as $scope => $items) {
            $this->assertNotContains(
                self::PATTERN,
                $items,
                $scope.' 잠금 집합에 수집 거부 쿠키가 들어갔습니다 — 운영자가 지울 수 없게 됩니다.'
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
        (new AppendAnalyticsOptOutToNecessaryAllowlist)->run(
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
