<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Installer\Concerns\RunsInstallerSubprocess;

/**
 * 설치 결과의 사이트 기본 언어가 실행 경로(세션 유무)와 무관하게 같은지 검증한다.
 *
 * 회귀: 설치 마법사를 영어로 진행해도 SSE 모드(기본)는 세션이 없는 워커가 설정 파일을 만들어
 * `general.language` 가 ko 로 기록되고, 브라우저 세션이 부르는 finalize 는 `.env APP_LOCALE=en`
 * 을 썼다 — 두 값이 어긋났다. 원인은 둘 다 세션의 UI 언어(`getCurrentLanguage()`)를 읽었기 때문이다.
 * 이제 둘 다 state 의 `config.site_language` → `g7_locale` → ko 순서로 읽는다(터미널 설치도 같은 키).
 */
class InstallerSiteLanguageTest extends TestCase
{
    use RunsInstallerSubprocess;

    /**
     * 임시 루트에 .env.example 과 설정 기본값 파일을 둔다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $base = $this->makeInstallerTempBase();
        $root = dirname(__DIR__, 3);

        copy($root.'/.env.example', $base.'/.env.example');
        @mkdir($base.'/config/settings', 0777, true);
        copy($root.'/config/settings/defaults.json', $base.'/config/settings/defaults.json');
    }

    /**
     * 임시 루트 제거.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->removeInstallerTempBase();

        parent::tearDown();
    }

    /**
     * 세션 없는 워커와 같은 조건(세션 언어 없음)에서 state 의 언어로 .env 를 만든다.
     *
     * @effects env_locale_follows_state_language_without_session
     *
     * @return void 반환값 없음
     */
    public function test_env_locale_uses_state_language_without_session(): void
    {
        $this->writeInstallerState(['g7_locale' => 'en', 'config' => ['app_name' => 'G7']]);

        $result = $this->runInstallerScript(<<<'PHP'
            unset($_SESSION['g7_locale']);
            $env = generateEnvContent();
            preg_match('/^APP_LOCALE=(.*)$/m', $env, $l);
            preg_match('/^APP_FAKER_LOCALE=(.*)$/m', $env, $f);
            echo json_encode(['locale' => $l[1] ?? null, 'faker' => $f[1] ?? null]);
            PHP, ['session' => []]);

        $this->assertSame(['locale' => 'en', 'faker' => 'en_US'], $result['body'], $result['raw']);
    }

    /**
     * 명시 입력(config.site_language)이 UI 언어보다 우선한다.
     *
     * @effects site_language_input_overrides_ui_language
     *
     * @return void 반환값 없음
     */
    public function test_explicit_site_language_overrides_ui_language(): void
    {
        $this->writeInstallerState(['g7_locale' => 'ko', 'config' => ['site_language' => 'en']]);

        $result = $this->runInstallerScript(<<<'PHP'
            echo json_encode(['site' => installer_site_language(getInstallationState())]);
            PHP, ['session' => ['g7_locale' => 'ko']]);

        $this->assertSame(['site' => 'en'], $result['body'], $result['raw']);
    }

    /**
     * 지원하지 않는 값·누락은 ko 로 떨어진다.
     *
     * @effects site_language_falls_back_to_ko
     *
     * @return void 반환값 없음
     */
    public function test_unsupported_language_falls_back_to_ko(): void
    {
        $this->writeInstallerState(['g7_locale' => 'xx', 'config' => ['site_language' => 'zz']]);

        $result = $this->runInstallerScript(<<<'PHP'
            echo json_encode(['site' => installer_site_language(getInstallationState()), 'empty' => installer_site_language([])]);
            PHP, ['session' => []]);

        $this->assertSame(['site' => 'ko', 'empty' => 'ko'], $result['body'], $result['raw']);
    }

    /**
     * 설정 파일 생성 작업(세션 없는 SSE 워커가 실행)도 같은 언어를 쓴다.
     *
     * @effects settings_general_language_follows_state_language
     *
     * @return void 반환값 없음
     */
    public function test_settings_json_language_matches_env_locale_without_session(): void
    {
        $this->writeInstallerState([
            'g7_locale' => 'en',
            'installation_status' => 'running',
            'completed_tasks' => [],
            'current_task' => null,
            'config' => ['app_name' => 'G7', 'app_url' => 'https://example.test', 'admin_email' => 'a@example.test'],
        ]);

        $result = $this->runInstallerScript(<<<'PHP'
            require_once $projectRoot.'/public/install/includes/task-runner.php';
            unset($_SESSION['g7_locale']);
            $GLOBALS['translations'] = loadTranslations('en');
            setProgressEmitter(new NullEmitter());
            $r = createSettingsJsonSSE();
            $general = json_decode((string) file_get_contents(BASE_PATH.'/storage/app/settings/general.json'), true);
            echo json_encode(['ok' => $r['success'], 'language' => $general['language'] ?? null]);
            PHP, ['session' => []]);

        $this->assertSame(['ok' => true, 'language' => 'en'], $result['body'], $result['raw']);
    }
}
