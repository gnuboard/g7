<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use App\Upgrades\Data\Ext\Plugins\SirsoftGdpr\V1_1_0\Migrations\ConvertPrivacyPolicySlugToUrl;
use Illuminate\Support\Facades\File;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 1.1.0 업그레이드 스텝 — 처리방침 슬러그 설정을 처리방침 주소 설정으로 이관 검증
 *
 * 1.0.x 는 슬러그(`privacy_policy_slug`)를 저장하고 페이지 모듈 주소 규칙(`/page/{slug}`)으로 링크를
 * 조합했다. 1.1.0 은 운영자가 주소(`privacy_policy_url`)를 직접 적는다. 이관이 틀리면 기설치본의
 * 배너 링크가 업그레이드 직후 사라지거나 다른 곳을 가리키는데, 오류도 로그도 남지 않는다.
 *
 * 검사 축:
 *   - 슬러그 `x` → `/page/x`, 빈 슬러그 → 빈 주소, 슬러그 키 부재 → 1.0.x 기본값 경로
 *   - 주소가 이미 있으면 유지하고 슬러그 키만 지운다
 *   - 다른 설정 키는 보존한다
 *   - 재실행해도 결과가 같다 (멱등)
 *   - 설정 파일 없음 / JSON 깨짐은 skip
 */
class ConvertPrivacyPolicySlugToUrlTest extends PluginTestCase
{
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

        // 업그레이드 마이그레이션은 오토로드되지 않는다 — 실행 시점과 같은 방식으로 싣는다.
        require_once __DIR__.'/../../../upgrades/data/1.1.0/migrations/05_ConvertPrivacyPolicySlugToUrl.php';

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
     * 저장된 슬러그를 그 시절 링크 경로로 옮기고 슬러그 키를 지우며 다른 설정은 보존합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=upgrade
     *
     * @effects upgrade_converts_slug_to_page_path
     */
    public function test_converts_slug_to_page_path_and_removes_legacy_key(): void
    {
        $this->writeSettings([
            'privacy_policy_slug' => 'privacy-policy',
            'legal_entity_name' => '(주)예시',
            'banner_enabled' => true,
        ]);

        $this->runStep();

        $settings = $this->readSettings();
        $this->assertSame('/page/privacy-policy', $settings['privacy_policy_url']);
        $this->assertArrayNotHasKey('privacy_policy_slug', $settings);
        $this->assertSame('(주)예시', $settings['legal_entity_name']);
        $this->assertTrue($settings['banner_enabled']);
    }

    /**
     * 빈 슬러그(링크를 숨기던 상태)는 빈 주소로 옮겨 계속 숨깁니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=upgrade
     *
     * @effects upgrade_converts_slug_to_page_path
     */
    public function test_empty_slug_becomes_empty_url(): void
    {
        $this->writeSettings(['privacy_policy_slug' => '']);

        $this->runStep();

        $settings = $this->readSettings();
        $this->assertSame('', $settings['privacy_policy_url']);
        $this->assertArrayNotHasKey('privacy_policy_slug', $settings);
    }

    /**
     * 슬러그 키가 없던 설치본은 1.0.x 기본 슬러그가 적용되던 경로를 그대로 받습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=upgrade
     *
     * @effects upgrade_converts_slug_to_page_path
     */
    public function test_absent_slug_key_keeps_the_legacy_default_link(): void
    {
        $this->writeSettings(['banner_enabled' => true]);

        $this->runStep();

        $this->assertSame('/page/privacy', $this->readSettings()['privacy_policy_url']);
    }

    /**
     * 주소가 이미 있으면 그 값을 유지하고 남은 슬러그 키만 지웁니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=upgrade
     *
     * @effects upgrade_keeps_existing_privacy_policy_url
     */
    public function test_keeps_existing_url_and_drops_leftover_slug(): void
    {
        $this->writeSettings([
            'privacy_policy_url' => 'https://example.com/privacy',
            'privacy_policy_slug' => 'privacy',
        ]);

        $this->runStep();

        $settings = $this->readSettings();
        $this->assertSame('https://example.com/privacy', $settings['privacy_policy_url']);
        $this->assertArrayNotHasKey('privacy_policy_slug', $settings);
    }

    /**
     * 두 번 실행해도 결과가 같고, 두 번째 실행은 파일을 건드리지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=upgrade
     *
     * @effects upgrade_privacy_policy_url_is_idempotent
     */
    public function test_is_idempotent(): void
    {
        $this->writeSettings(['privacy_policy_slug' => 'privacy']);

        $this->runStep();
        $once = File::get($this->path);
        $this->runStep();

        $this->assertSame($once, File::get($this->path));
        $this->assertSame('/page/privacy', $this->readSettings()['privacy_policy_url']);
    }

    /**
     * 설정 파일이 없으면 만들지 않고 skip 합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=upgrade
     *
     * @effects upgrade_privacy_policy_url_is_idempotent
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
     * @scenario surface=upgrade
     *
     * @effects upgrade_privacy_policy_url_is_idempotent
     */
    public function test_skips_when_settings_json_malformed(): void
    {
        File::ensureDirectoryExists(dirname($this->path));
        File::put($this->path, '{ this is not json');

        $this->runStep();

        $this->assertSame('{ this is not json', File::get($this->path));
    }

    /**
     * 마이그레이션을 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runStep(): void
    {
        (new ConvertPrivacyPolicySlugToUrl)->run(
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
