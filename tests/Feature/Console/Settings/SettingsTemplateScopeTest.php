<?php

namespace Tests\Feature\Console\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\CreatesTemplateSettingsFixtures;
use Tests\TestCase;

/**
 * settings:get / settings:set --template 테스트
 *
 * 화면 저장과 같은 FormRequest·같은 서비스로 저장한다. 첨부 키·계산 키는 지정할 수 없다(조회만).
 */
class SettingsTemplateScopeTest extends TestCase
{
    use CreatesTemplateSettingsFixtures;
    use RefreshDatabase;

    /**
     * 테스트가 만든 픽스처를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanUpTemplateSettingsFixtures();

        parent::tearDown();
    }

    /**
     * 조회·지정·검증 실패·첨부/계산 키 거부·범위 충돌을 확인한다.
     *
     * @scenario case=cli
     *
     * @effects cli_get_set
     *
     * @return void 반환값 없음
     */
    public function test_get_and_set_template_settings(): void
    {
        $declaration = $this->defaultDeclaration();
        $declaration['schema']['display']['notice'] = ['type' => 'multilingual', 'max_length' => 20];
        $declaration['schema']['display']['api_token'] = ['type' => 'string', 'sensitive' => true];
        $this->makeSettingsTemplate('qa434-cli', declaration: $declaration);
        $service = fn () => app()->make(\App\Services\TemplateSettingsService::class);

        $this->artisan('settings:get', ['key' => 'display.accent_color', '--template' => 'qa434-cli'])
            ->expectsOutputToContain('#3b5bdb')
            ->assertSuccessful();

        $this->artisan('settings:set', ['key' => 'display.accent_color', 'value' => '#00ff00', '--template' => 'qa434-cli'])->assertSuccessful();
        $this->assertSame('#00ff00', $service()->get('qa434-cli', 'display.accent_color'));

        // 언어별 문구는 JSON 통째로, 또는 언어 하나만 바꾼다(나머지 언어 유지)
        $this->artisan('settings:set', ['key' => 'display.notice', 'value' => '{"ko":"공지","en":"Notice"}', '--json-value' => true, '--template' => 'qa434-cli'])->assertSuccessful();
        $this->artisan('settings:set', ['key' => 'display.notice.en', 'value' => 'News', '--template' => 'qa434-cli'])->assertSuccessful();
        $this->assertSame(['ko' => '공지', 'en' => 'News'], $service()->get('qa434-cli', 'display.notice'));

        // 비밀값은 인자로 받지 않고(셸 기록 노출 방지) 파일로만 받으며, 조회 때 가린다
        $this->artisan('settings:set', ['key' => 'display.api_token', 'value' => 'tok-123', '--template' => 'qa434-cli'])->assertFailed();
        $valueFile = tempnam(sys_get_temp_dir(), 'qa434');
        file_put_contents($valueFile, 'tok-123'.PHP_EOL);
        $this->artisan('settings:set', ['key' => 'display.api_token', '--value-file' => $valueFile, '--template' => 'qa434-cli'])->assertSuccessful();
        @unlink($valueFile);
        $this->assertSame('tok-123', $service()->get('qa434-cli', 'display.api_token'));
        $this->artisan('settings:get', ['key' => 'display.api_token', '--template' => 'qa434-cli'])
            ->doesntExpectOutputToContain('tok-123')
            ->assertSuccessful();

        $this->artisan('settings:set', ['key' => 'display.accent_color', 'value' => 'green', '--template' => 'qa434-cli'])->assertFailed();
        $this->artisan('settings:set', ['key' => 'branding.favicon', 'value' => '1', '--template' => 'qa434-cli'])->assertFailed();
        $this->artisan('settings:set', ['key' => 'branding.favicon_url', 'value' => 'x', '--template' => 'qa434-cli'])->assertFailed();
        $this->artisan('settings:set', ['key' => 'display.nope', 'value' => 'x', '--template' => 'qa434-cli'])->assertFailed();
        $this->artisan('settings:set', ['key' => 'accent_color', 'value' => '#000000', '--template' => 'qa434-cli'])->assertFailed();
        $this->artisan('settings:get', ['--template' => 'qa434-none'])->assertFailed();
        $this->artisan('settings:get', ['--template' => 'qa434-cli', '--plugin' => 'sirsoft-x'])->assertFailed();
    }

    /**
     * 목록 칸은 `--json-value` 로 행 배열 통째로 바꾼다 — 화면 저장과 같은 검증(`javascript:` 링크 거부)을 거친다.
     *
     * @scenario case=cli
     *
     * @effects cli_get_set
     *
     * @return void 반환값 없음
     */
    public function test_set_list_field_with_json_value(): void
    {
        $declaration = $this->defaultDeclaration();
        $declaration['schema']['display']['links'] = ['type' => 'list', 'max_items' => 3, 'item_fields' => [
            'label' => ['type' => 'string', 'required' => true],
            'url' => ['type' => 'link', 'required' => true],
        ]];
        $declaration['defaults']['display']['links'] = [];
        $this->makeSettingsTemplate('qa434-cli', declaration: $declaration);
        $service = fn () => app()->make(\App\Services\TemplateSettingsService::class);
        $set = fn (string $json) => $this->artisan('settings:set', ['key' => 'display.links', 'value' => $json, '--json-value' => true, '--template' => 'qa434-cli']);

        $set('[{"label":"자유게시판","url":"/boards/free"},{"label":"외부","url":"https://example.com/a"}]')->assertSuccessful();
        $this->assertSame([
            ['label' => '자유게시판', 'url' => '/boards/free'],
            ['label' => '외부', 'url' => 'https://example.com/a'],
        ], $service()->get('qa434-cli', 'display.links'));

        $set('[{"label":"하나","url":"/one"}]')->assertSuccessful();
        $this->assertSame([['label' => '하나', 'url' => '/one']], $service()->get('qa434-cli', 'display.links'), '행 배열은 통째로 바뀐다');

        $set('[{"label":"x","url":"javascript:alert(1)"}]')->assertFailed();
        $set('not-json')->assertFailed();
        $this->assertSame([['label' => '하나', 'url' => '/one']], $service()->get('qa434-cli', 'display.links'), '실패한 지정은 값을 바꾸지 않는다');
    }
}
