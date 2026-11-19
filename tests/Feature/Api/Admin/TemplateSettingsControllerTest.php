<?php

namespace Tests\Feature\Api\Admin;

use App\Extension\TemplateManager;
use App\Services\TemplateSettingsService;
use App\Support\ExtensionStoragePath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\Helpers\CreatesTemplateSettingsFixtures;
use Tests\TestCase;

/**
 * 템플릿 환경설정 API 테스트 (#434)
 *
 * 보기는 core.templates.read, 저장·되돌리기는 core.templates.settings. 미설치·선언 없는 템플릿은 404, 꺼진 템플릿도
 * 조회·저장된다. 입력·응답은 섹션 중첩(`{섹션: {키: 값}}`)이다.
 */
class TemplateSettingsControllerTest extends TestCase
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
     * 칸 종류를 넓힌 선언 — 언어별 문구·링크·목록·긴 글.
     *
     * @return array<string, mixed> 선언
     */
    private function richDeclaration(): array
    {
        $declaration = $this->defaultDeclaration();
        $declaration['_meta']['categories'][] = 'header';
        $declaration['schema']['header'] = [
            'max_boards' => ['type' => 'integer', 'min' => 1, 'max' => 10, 'step' => 1, 'required' => true],
            'notice' => ['type' => 'multilingual', 'max_length' => 20],
            'about' => ['type' => 'text', 'max_length' => 50],
            'links' => ['type' => 'list', 'max_items' => 2, 'item_fields' => [
                'label' => ['type' => 'multilingual', 'required' => true, 'max_length' => 10, 'label' => '이름'],
                'url' => ['type' => 'link', 'required' => true, 'label' => '주소'],
                'new_tab' => ['type' => 'boolean'],
            ], 'label' => '바로가기'],
        ];
        $declaration['defaults']['header'] = ['max_boards' => 5];

        return $declaration;
    }

    /**
     * 조회 응답은 섹션별 설정·섹션/칸 서술·언어·실효 파비콘·권한 값을 싣고, 꺼진 템플릿도 조회된다.
     *
     * @scenario case=api
     *
     * @effects settings_read_merges_defaults, labels_resolved_for_locale
     *
     * @return void 반환값 없음
     */
    public function test_show_returns_sections_settings_and_abilities(): void
    {
        $this->makeSettingsTemplate('qa434-api', 'user', 'inactive');

        $admin = $this->makeAdmin();
        $admin->forceFill(['language' => 'ko'])->save();
        $response = $this->actingAs($admin)->getJson('/api/admin/templates/qa434-api/settings')->assertOk();

        $response->assertJsonPath('data.identifier', 'qa434-api')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.settings.display.accent_color', '#3b5bdb')
            ->assertJsonPath('data.settings.branding.favicon', [])
            ->assertJsonPath('data.sections.0.key', 'branding')
            ->assertJsonPath('data.sections.0.label', '브랜딩')
            ->assertJsonPath('data.sections.0.fields.0.key', 'favicon')
            ->assertJsonPath('data.sections.0.fields.0.collection', 'template_settings.qa434-api.branding.favicon')
            ->assertJsonPath('data.sections.0.fields.0.max_files', 1)
            ->assertJsonPath('data.sections.0.fields.0.accept', '.ico,.png,.svg,.gif,.jpg,.jpeg,.webp')
            ->assertJsonPath('data.sections.0.fields.0.label', '파비콘')
            ->assertJsonPath('data.sections.0.fields.1.options.1', ['value' => 'green', 'label' => '초록'])
            ->assertJsonPath('data.sections.0.fields.2.type', 'image')
            ->assertJsonPath('data.sections.0.fields.2.max_size_kb', 512)
            ->assertJsonPath('data.sections.1.key', 'display')
            ->assertJsonPath('data.effective_favicon.source', 'bundled')
            ->assertJsonPath('data.abilities.can_update', true)
            ->assertJsonPath('data.abilities.can_reset', true);
        $this->assertNotEmpty($response->json('data.locales.available'));
        $this->assertArrayNotHasKey('fields', $response->json('data'), '옛 평면 서술은 없다');

        // 라벨은 요청 로케일(회원 언어 설정)로 풀린다
        $admin->forceFill(['language' => 'en'])->save();
        $this->actingAs($admin->fresh())->getJson('/api/admin/templates/qa434-api/settings')
            ->assertJsonPath('data.sections.0.label', 'Branding')
            ->assertJsonPath('data.sections.0.fields.0.label', 'Favicon');
    }

    /**
     * 보기 권한만 있으면 조회는 되지만 can_update·can_reset 은 false 이고 저장·되돌리기는 403. 권한이 없으면 조회도 403.
     *
     * @scenario case=api
     *
     * @effects read_only_without_settings_permission
     *
     * @return void 반환값 없음
     */
    public function test_read_only_admin_cannot_save_or_reset(): void
    {
        $this->makeSettingsTemplate('qa434-api');
        $reader = $this->makeAdmin(['core.templates.read']);

        $this->actingAs($reader)->getJson('/api/admin/templates/qa434-api/settings')
            ->assertOk()
            ->assertJsonPath('data.abilities.can_update', false)
            ->assertJsonPath('data.abilities.can_reset', false);

        $this->actingAs($reader)->putJson('/api/admin/templates/qa434-api/settings', ['display' => ['accent_color' => '#000000']])->assertForbidden();
        $this->actingAs($reader)->postJson('/api/admin/templates/qa434-api/settings/reset')->assertForbidden();

        $this->actingAs($this->makeAdmin(['core.settings.read']))->getJson('/api/admin/templates/qa434-api/settings')->assertForbidden();
    }

    /**
     * 미설치·선언 없는 템플릿은 404(조회·저장·되돌리기·레이아웃), 비로그인은 401. 직접 쓴 화면이 없으면 레이아웃 조회만 404.
     *
     * @scenario case=api
     *
     * @effects settings_api_not_found_for_undeclared
     *
     * @return void 반환값 없음
     */
    public function test_not_found_and_unauthenticated(): void
    {
        $this->getJson('/api/admin/templates/qa434-api/settings')->assertUnauthorized();
        $this->putJson('/api/admin/templates/qa434-api/settings', [])->assertUnauthorized();
        $this->postJson('/api/admin/templates/qa434-api/settings/reset')->assertUnauthorized();
        $this->getJson('/api/admin/templates/qa434-api/settings/layout')->assertUnauthorized();

        $admin = $this->makeAdmin();
        $this->actingAs($admin)->getJson('/api/admin/templates/qa434-none/settings')->assertNotFound();
        $this->actingAs($admin)->putJson('/api/admin/templates/qa434-none/settings', [])->assertNotFound();
        $this->actingAs($admin)->postJson('/api/admin/templates/qa434-none/settings/reset')->assertNotFound();

        // 직접 쓴 화면 없이 선언만 있는 템플릿 — 조회는 되고(공용 설정 화면이 그린다) 레이아웃 조회는 404
        $this->makeSettingsTemplate('qa434-auto', 'user', 'active', null, false);
        $this->actingAs($admin)->getJson('/api/admin/templates/qa434-auto/settings')->assertOk();
        $this->actingAs($admin)->getJson('/api/admin/templates/qa434-auto/settings/layout')->assertNotFound();

        // 선언 파일이 없는 설치 템플릿
        $this->makeSettingsTemplate('qa434-plain');
        File::delete(base_path('templates/qa434-plain/config/settings/defaults.json'));
        app(TemplateSettingsService::class)->clearCache();
        $this->actingAs($admin)->getJson('/api/admin/templates/qa434-plain/settings')->assertNotFound();
    }

    /**
     * 저장은 칸 선언으로 검증하고(422), 통과한 값만 섹션 파일에 저장한다. 다른 컬렉션 첨부·이미지 칸의 비이미지는 422.
     *
     * @scenario case=api
     *
     * @effects attachments_scoped_to_template_collection
     *
     * @return void 반환값 없음
     */
    public function test_update_validates_by_declared_schema(): void
    {
        $this->makeSettingsTemplate('qa434-api');
        $admin = $this->makeAdmin();
        $foreign = $this->makeFaviconAttachment('site_favicon');
        $own = $this->makeFaviconAttachment('template_settings.qa434-api.branding.favicon');
        $pdf = $this->makeFaviconAttachment('template_settings.qa434-api.branding.logo_dark', 'application/pdf');
        $put = fn (array $body) => $this->actingAs($admin)->putJson('/api/admin/templates/qa434-api/settings', $body);

        $put(['display' => ['accent_color' => 'red']])->assertStatus(422)->assertJsonValidationErrors(['display.accent_color'], 'errors');
        $put(['display' => ['show_banner' => 'yes-please']])->assertStatus(422);
        $put(['branding' => ['accent' => 'purple']])->assertStatus(422)->assertJsonValidationErrors(['branding.accent'], 'errors');
        $put(['branding' => ['favicon' => [$foreign->id]]])->assertStatus(422);
        $put(['branding' => ['favicon' => [$own->id, $own->id]]])->assertStatus(422);
        $put(['branding' => ['logo_dark' => [$pdf->id]]])->assertStatus(422)->assertJsonValidationErrors(['branding.logo_dark.0'], 'errors');

        $put([
            'display' => ['accent_color' => '#123abc'],
            'branding' => ['favicon' => [['id' => $own->id, 'hash' => $own->hash]], 'favicon_url' => 'https://evil.example/x.ico'],
            'abilities' => ['can_update' => true],
            'ghost' => ['x' => 1],
        ])->assertOk()
            ->assertJsonPath('data.settings.display.accent_color', '#123abc')
            ->assertJsonPath('data.settings.branding.favicon.0.id', $own->id)
            ->assertJsonPath('data.effective_favicon.source', 'template');

        $dir = ExtensionStoragePath::template('qa434-api', 'settings');
        $this->assertStringNotContainsString('evil.example', (string) File::get($dir.'/branding.json'));
        $this->assertFileDoesNotExist($dir.'/ghost.json');
    }

    /**
     * 언어별 문구·링크·목록·긴 글·정수 단계 칸 검증 — `javascript:` 링크·목록 최대 개수·길이 초과는 422, 사이트 경로·https 는 통과.
     *
     * @scenario case=api
     *
     * @effects attachments_scoped_to_template_collection
     *
     * @return void 반환값 없음
     */
    public function test_update_validates_rich_field_types(): void
    {
        $this->makeSettingsTemplate('qa434-api', declaration: $this->richDeclaration());
        $admin = $this->makeAdmin();
        $put = fn (array $header) => $this->actingAs($admin)->putJson('/api/admin/templates/qa434-api/settings', ['header' => $header]);
        $link = fn (string $url) => ['label' => ['ko' => '이동'], 'url' => $url, 'new_tab' => false];

        $put(['links' => [$link('javascript:alert(1)')]])->assertStatus(422)->assertJsonValidationErrors(['header.links.0.url'], 'errors');
        $put(['links' => [$link('//evil.example')]])->assertStatus(422);
        $put(['links' => [$link('/a'), $link('/b'), $link('/c')]])->assertStatus(422)->assertJsonValidationErrors(['header.links'], 'errors');
        $put(['links' => [['label' => [], 'url' => '/a']]])->assertStatus(422);
        $put(['notice' => ['ko' => str_repeat('가', 21)]])->assertStatus(422);
        $put(['notice' => 'not-a-map'])->assertStatus(422);
        $put(['about' => str_repeat('a', 51)])->assertStatus(422);
        $put(['max_boards' => 11])->assertStatus(422);
        $put(['max_boards' => null])->assertStatus(422)->assertJsonValidationErrors(['header.max_boards'], 'errors');

        $put([
            'max_boards' => '3',
            'notice' => ['ko' => '공지', 'en' => 'Notice'],
            'links' => [$link('/boards/free'), ['label' => ['ko' => '외부'], 'url' => 'https://example.com/a?b=1', 'new_tab' => true]],
        ])->assertOk()
            ->assertJsonPath('data.settings.header.max_boards', 3)
            ->assertJsonPath('data.settings.header.links.1.url', 'https://example.com/a?b=1')
            ->assertJsonPath('data.settings.header.notice.en', 'Notice');
    }

    /**
     * 기본값 되돌리기 — 섹션 하나·전체, 모르는 섹션 422, 저장 실패 500.
     *
     * @scenario case=api
     *
     * @effects settings_reset_to_defaults
     *
     * @return void 반환값 없음
     */
    public function test_reset_endpoint(): void
    {
        $this->makeSettingsTemplate('qa434-api');
        $admin = $this->makeAdmin();
        $this->actingAs($admin)->putJson('/api/admin/templates/qa434-api/settings', ['display' => ['accent_color' => '#000000'], 'branding' => ['accent' => 'green']])->assertOk();

        $this->actingAs($admin)->postJson('/api/admin/templates/qa434-api/settings/reset', ['section' => 'nope'])->assertStatus(422);

        $this->actingAs($admin)->postJson('/api/admin/templates/qa434-api/settings/reset', ['section' => 'display'])
            ->assertOk()
            ->assertJsonPath('data.settings.display.accent_color', '#3b5bdb')
            ->assertJsonPath('data.settings.branding.accent', 'green');

        $this->actingAs($admin)->postJson('/api/admin/templates/qa434-api/settings/reset')
            ->assertOk()
            ->assertJsonPath('data.settings.branding.accent', 'blue');
    }

    /**
     * 저장 파일을 쓰지 못하면 500 과 사유를 돌려준다.
     *
     * @scenario case=api
     *
     * @effects settings_save_failure_reported
     *
     * @return void 반환값 없음
     */
    public function test_save_failure_returns_500(): void
    {
        $this->makeSettingsTemplate('qa434-api');
        $admin = $this->makeAdmin();

        // 실제 서비스를 감싼 모의 객체 — 저장만 실패로 바꾸고 나머지(선언·조회)는 그대로 쓴다
        $mock = \Mockery::mock(app(TemplateSettingsService::class))->makePartial();
        $mock->shouldReceive('save')->andReturnUsing(function (string $identifier, array $settings, ?string &$failureReason = null) {
            $failureReason = 'disk full';

            return false;
        });
        $this->instance(TemplateSettingsService::class, $mock);

        $this->actingAs($admin)->putJson('/api/admin/templates/qa434-api/settings', ['display' => ['accent_color' => '#000000']])
            ->assertStatus(500)
            ->assertJsonPath('message', __('templates.settings.update_failed', ['error' => 'disk full']));
    }

    /**
     * 레이아웃 조회는 템플릿이 직접 쓴 설정 화면 원본을 돌려준다.
     *
     * @scenario case=api
     *
     * @effects settings_layout_registered_into_active_admin
     *
     * @return void 반환값 없음
     */
    public function test_layout_returns_own_settings_screen(): void
    {
        $this->makeSettingsTemplate('qa434-api');

        $this->actingAs($this->makeAdmin(['core.templates.read']))->getJson('/api/admin/templates/qa434-api/settings/layout')
            ->assertOk()
            ->assertJsonPath('data.layout_name', 'template_settings');
    }

    /**
     * 기본 칸 7종(문자열·URL·이메일·색·불리언·선택지·정수)을 넣은 선언.
     *
     * @return array<string, mixed> 선언
     */
    private function scalarDeclaration(): array
    {
        $declaration = $this->defaultDeclaration();
        $declaration['_meta']['categories'][] = 'contact';
        $declaration['schema']['contact'] = [
            'name' => ['type' => 'string', 'max_length' => 10],
            'site' => ['type' => 'url'],
            'mail' => ['type' => 'email'],
            'count' => ['type' => 'integer', 'min' => 1, 'max' => 10],
        ];

        return $declaration;
    }

    /**
     * 칸 종류별 잘못된 값 — 종류마다 2건 이상.
     *
     * @return array<string, array{0: string, 1: mixed}> 사례 이름 => [칸 경로, 값]
     */
    public static function invalidScalarValueProvider(): array
    {
        return [
            'string 숫자' => ['contact.name', 123],
            'string 배열' => ['contact.name', ['x']],
            'string 길이 초과' => ['contact.name', str_repeat('a', 11)],
            'url 형식 아님' => ['contact.site', 'not a url'],
            'url ftp 스킴' => ['contact.site', 'ftp://example.com/a'],
            'url javascript 스킴' => ['contact.site', 'javascript:alert(1)'],
            'email 골뱅이 없음' => ['contact.mail', 'nope'],
            'email 도메인 없음' => ['contact.mail', 'a@'],
            'color 다섯 자리' => ['display.accent_color', '#12345'],
            'color 16진수 밖 문자' => ['display.accent_color', '#GGGGGG'],
            'color 이름' => ['display.accent_color', 'red'],
            'boolean 문자열' => ['display.show_banner', 'maybe'],
            'boolean 숫자 2' => ['display.show_banner', 2],
            'enum 선택지 밖' => ['branding.accent', 'purple'],
            'enum 대소문자 다름' => ['branding.accent', 'BLUE'],
            'integer 문자' => ['contact.count', 'abc'],
            'integer 소수' => ['contact.count', 3.5],
            'integer 최소 미만' => ['contact.count', 0],
            'integer 최대 초과' => ['contact.count', 11],
        ];
    }

    /**
     * 기본 칸 종류마다 잘못된 값은 그 칸 경로로 422 를 돌려준다.
     *
     * @scenario case=api
     *
     * @effects settings_read_merges_defaults
     *
     * @param  string  $path  칸 경로 (`섹션.키`)
     * @param  mixed  $value  잘못된 값
     * @return void 반환값 없음
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidScalarValueProvider')]
    public function test_update_rejects_invalid_scalar_value(string $path, mixed $value): void
    {
        $this->makeSettingsTemplate('qa434-api', declaration: $this->scalarDeclaration());
        [$section, $key] = explode('.', $path, 2);

        $this->actingAs($this->makeAdmin())
            ->putJson('/api/admin/templates/qa434-api/settings', [$section => [$key => $value]])
            ->assertStatus(422)
            ->assertJsonValidationErrors([$path], 'errors');
    }

    /**
     * 같은 기본 칸 7종의 올바른 값은 통과해 저장된다 — 위 422 표가 선언 오류로 생긴 것이 아님을 확인한다.
     *
     * @scenario case=api
     *
     * @effects settings_read_merges_defaults
     *
     * @return void 반환값 없음
     */
    public function test_update_accepts_valid_scalar_values(): void
    {
        $this->makeSettingsTemplate('qa434-api', declaration: $this->scalarDeclaration());

        $this->actingAs($this->makeAdmin())->putJson('/api/admin/templates/qa434-api/settings', [
            'contact' => ['name' => 'G7', 'site' => 'https://example.com/a', 'mail' => 'a@example.com', 'count' => 10],
            'display' => ['accent_color' => '#AbCdEf', 'show_banner' => '0'],
            'branding' => ['accent' => 'green'],
        ])->assertOk()
            ->assertJsonPath('data.settings.contact.name', 'G7')
            ->assertJsonPath('data.settings.contact.count', 10)
            ->assertJsonPath('data.settings.display.show_banner', false)
            ->assertJsonPath('data.settings.branding.accent', 'green');
    }

    /**
     * 라벨 `$t:` 키는 관리자 언어(ko·en·ja)마다 그 템플릿 언어 파일 문구로 풀린다.
     *
     * @scenario case=api
     *
     * @effects labels_resolved_for_locale
     *
     * @return void 반환값 없음
     */
    public function test_labels_resolve_per_locale_ko_en_ja(): void
    {
        Config::set('app.supported_locales', ['ko', 'en', 'ja']);
        $declaration = $this->defaultDeclaration();
        $declaration['sections']['branding']['label'] = '$t:settings.sections.branding';
        $declaration['schema']['branding']['favicon']['label'] = '$t:settings.favicon';
        $this->makeSettingsTemplate('qa434-api', declaration: $declaration);
        File::ensureDirectoryExists(base_path('templates/qa434-api/lang'));
        foreach (['ko' => ['브랜딩', '파비콘'], 'en' => ['Branding', 'Favicon'], 'ja' => ['ブランディング', 'ファビコン']] as $locale => [$section, $favicon]) {
            File::put(base_path("templates/qa434-api/lang/{$locale}.json"), json_encode(['settings' => ['sections' => ['branding' => $section], 'favicon' => $favicon]], JSON_UNESCAPED_UNICODE));
        }
        app(TemplateManager::class)->loadTemplates();
        $admin = $this->makeAdmin();

        foreach (['ko' => ['브랜딩', '파비콘'], 'en' => ['Branding', 'Favicon'], 'ja' => ['ブランディング', 'ファビコン']] as $locale => [$section, $favicon]) {
            $admin->forceFill(['language' => $locale])->save();
            $this->actingAs($admin->fresh())->getJson('/api/admin/templates/qa434-api/settings')
                ->assertOk()
                ->assertJsonPath('data.sections.0.label', $section)
                ->assertJsonPath('data.sections.0.fields.0.label', $favicon);
        }
    }
}
