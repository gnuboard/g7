<?php

namespace Tests\Feature\Services;

use App\Extension\HookManager;
use App\Extension\TemplateManager;
use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Services\TemplateSettingsService;
use App\Support\ExtensionStoragePath;
use App\Support\SensitiveSettingMask;
use App\Support\SettingsVersion;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\CreatesTemplateSettingsFixtures;
use Tests\TestCase;

/**
 * 템플릿 환경설정 서비스 테스트
 *
 * 모듈 설정과 같은 섹션 구조 — 섹션별 저장 파일, 섹션·키 2단 노출, 비밀값 암호화, 기본값 되돌리기.
 */
class TemplateSettingsServiceTest extends TestCase
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
     * 새 서비스 인스턴스(요청 내 캐시 없음)를 돌려줍니다.
     *
     * @return TemplateSettingsService 서비스
     */
    private function service(): TemplateSettingsService
    {
        return app()->make(TemplateSettingsService::class);
    }

    /**
     * 섹션 저장 파일을 읽습니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  string  $section  섹션
     * @return array<string, mixed>|null 저장값 (파일 없으면 null)
     */
    private function storedSection(string $identifier, string $section): ?array
    {
        $path = ExtensionStoragePath::template($identifier, 'settings').'/'.$section.'.json';

        return File::exists($path) ? json_decode(File::get($path), true) : null;
    }

    /**
     * 저장 파일이 없으면 기본값이 읽히고, 저장 뒤에는 섹션별 파일에 기본값 위 병합으로 쓰인다. 선언 밖 섹션·키는 저장하지 않는다.
     *
     * @scenario case=service
     *
     * @effects settings_read_merges_defaults
     *
     * @return void 반환값 없음
     */
    public function test_reads_defaults_and_writes_section_files(): void
    {
        $this->makeSettingsTemplate('qa434-svc');

        $this->assertSame('#3b5bdb', $this->service()->get('qa434-svc', 'display.accent_color'));
        $this->assertNull($this->service()->get('qa434-svc', 'branding.favicon_url'));

        $this->assertTrue($this->service()->save('qa434-svc', [
            'display' => ['accent_color' => '#ff0000', 'unknown_key' => 'x'],
            'ghost' => ['a' => 1],
        ]));

        $settings = $this->service()->get('qa434-svc');
        $this->assertSame('#ff0000', $settings['display']['accent_color']);
        $this->assertTrue($settings['display']['show_banner']);
        $this->assertArrayNotHasKey('unknown_key', $settings['display']);
        $this->assertArrayNotHasKey('ghost', $settings);

        $this->assertSame(['accent_color' => '#ff0000'], $this->storedSection('qa434-svc', 'display'));
        $this->assertNull($this->storedSection('qa434-svc', 'branding'), '건드리지 않은 섹션은 파일을 만들지 않는다');
        $this->assertNull($this->storedSection('qa434-svc', 'ghost'));
    }

    /**
     * 숫자·불리언 문자열은 칸 종류로 정규화해 저장한다(HTML 입력·명령줄 값).
     *
     * @scenario case=service
     *
     * @effects settings_read_merges_defaults
     *
     * @return void 반환값 없음
     */
    public function test_values_are_normalized_by_field_type(): void
    {
        $declaration = $this->defaultDeclaration();
        $declaration['schema']['display']['count'] = ['type' => 'integer'];
        $this->makeSettingsTemplate('qa434-svc', declaration: $declaration);

        $this->service()->save('qa434-svc', ['display' => ['count' => '7', 'show_banner' => 'false']]);

        $this->assertSame(['show_banner' => false, 'count' => 7], $this->storedSection('qa434-svc', 'display'), '선언 순서로 저장');
    }

    /**
     * 첨부 칸은 그 칸 컬렉션에 실재하는 ID 만 받고, 계산 키는 파비콘 전용 경로·이미지 공개 경로다.
     *
     * @scenario case=service
     *
     * @effects attachments_scoped_to_template_collection
     *
     * @return void 반환값 없음
     */
    public function test_attachments_are_scoped_and_urls_computed(): void
    {
        $this->makeSettingsTemplate('qa434-svc');
        $own = $this->makeFaviconAttachment('template_settings.qa434-svc.branding.favicon');
        $foreign = $this->makeFaviconAttachment('site_logo');
        $logo = $this->makeFaviconAttachment('template_settings.qa434-svc.branding.logo_dark', 'image/webp');

        $this->service()->save('qa434-svc', ['branding' => ['favicon' => [$foreign->id, $own->id], 'logo_dark' => [$logo->id, $foreign->id]]]);

        $this->assertSame([$own->id], $this->service()->get('qa434-svc', 'branding.favicon'));
        $this->assertSame([$logo->id], $this->service()->get('qa434-svc', 'branding.logo_dark'));
        $this->assertStringEndsWith('/api/favicon/'.$own->hash, (string) $this->service()->get('qa434-svc', 'branding.favicon_url'));
        $this->assertStringEndsWith('/api/attachment/'.$logo->hash, (string) $this->service()->get('qa434-svc', 'branding.logo_dark_url'));
    }

    /**
     * 교체하면 빠진 첨부는 저장 성공 뒤 파기되고, 첨부 칸을 제출하지 않은 저장은 첨부를 건드리지 않는다.
     *
     * @scenario case=service
     *
     * @effects removed_attachment_purged_after_commit, omitted_attachment_key_untouched
     *
     * @return void 반환값 없음
     */
    public function test_replaced_attachment_is_purged_and_omitted_key_kept(): void
    {
        $this->makeSettingsTemplate('qa434-svc');
        $first = $this->makeFaviconAttachment('template_settings.qa434-svc.branding.favicon');
        $second = $this->makeFaviconAttachment('template_settings.qa434-svc.branding.favicon');

        $this->service()->save('qa434-svc', ['branding' => ['favicon' => [$first->id]]]);
        $this->service()->save('qa434-svc', ['branding' => ['accent' => 'green']]);

        $this->assertSame([$first->id], $this->service()->get('qa434-svc', 'branding.favicon'), '첨부 칸을 제출하지 않은 저장은 기존 값을 유지한다');
        $this->assertNotNull(Attachment::find($first->id));

        $this->service()->save('qa434-svc', ['branding' => ['favicon' => [$second->id]]]);

        $this->assertSame([$second->id], $this->service()->get('qa434-svc', 'branding.favicon'));
        $this->assertNull(Attachment::withTrashed()->find($first->id), '교체된 첨부는 저장 뒤 파기된다');

        $this->service()->save('qa434-svc', ['branding' => ['favicon' => []]]);
        $this->assertSame([], $this->service()->get('qa434-svc', 'branding.favicon'));
        $this->assertNull(Attachment::withTrashed()->find($second->id), '비운 첨부도 파기된다');
    }

    /**
     * 비밀값 칸은 파일에 암호화돼 저장되고, 관리자 응답은 마스크, 마스크 그대로 저장하면 기존 값 유지, 노출·미러에는 없다.
     *
     * @scenario case=service
     *
     * @effects sensitive_masked_and_hidden
     *
     * @return void 반환값 없음
     */
    public function test_sensitive_field_is_encrypted_masked_and_hidden(): void
    {
        $declaration = $this->defaultDeclaration();
        $declaration['schema']['display']['api_token'] = ['type' => 'string', 'sensitive' => true];
        $declaration['frontend_schema']['display'] = ['expose' => true, 'fields' => ['accent_color' => ['expose' => true]]];
        $this->makeSettingsTemplate('qa434-svc', declaration: $declaration);

        $this->service()->save('qa434-svc', ['display' => ['api_token' => 'secret-1']]);

        $stored = $this->storedSection('qa434-svc', 'display')['api_token'];
        $this->assertNotSame('secret-1', $stored);
        $this->assertSame('secret-1', Crypt::decryptString($stored));
        $this->assertSame('secret-1', $this->service()->get('qa434-svc', 'display.api_token'));
        $this->assertSame(SensitiveSettingMask::MASK, $this->service()->getForAdmin('qa434-svc')['display']['api_token']);

        $this->service()->save('qa434-svc', ['display' => ['api_token' => SensitiveSettingMask::MASK, 'accent_color' => '#111111']]);
        $this->assertSame('secret-1', $this->service()->get('qa434-svc', 'display.api_token'), '마스크로 돌아오면 기존 값을 유지한다');

        $this->assertArrayNotHasKey('api_token', $this->service()->frontendSettings('qa434-svc')['display']);
        $this->assertArrayNotHasKey('api_token', g7_template_settings('qa434-svc', 'display'));
    }

    /**
     * 칸 이름을 바꾼 선언(`renamed_from`)은 옛 자리 값을 읽고, 다음 저장 때 새 자리에 쓴다.
     *
     * @scenario case=service
     *
     * @effects renamed_key_migrated
     *
     * @return void 반환값 없음
     */
    public function test_renamed_field_reads_old_value_and_moves_on_save(): void
    {
        $this->makeSettingsTemplate('qa434-svc');
        $this->service()->save('qa434-svc', ['display' => ['accent_color' => '#222222']]);

        $declaration = $this->defaultDeclaration();
        $declaration['_meta']['categories'] = ['branding', 'look'];
        $declaration['schema']['look'] = ['tint' => ['type' => 'color', 'renamed_from' => ['display.accent_color']]];
        unset($declaration['schema']['display'], $declaration['defaults']['display'], $declaration['frontend_schema']['display']);
        File::put(base_path('templates/qa434-svc/config/settings/defaults.json'), json_encode($declaration));

        $this->assertSame('#222222', $this->service()->get('qa434-svc', 'look.tint'));

        $this->service()->save('qa434-svc', ['branding' => ['accent' => 'green']]);

        $this->assertSame(['tint' => '#222222'], $this->storedSection('qa434-svc', 'look'));
        $this->assertNull($this->storedSection('qa434-svc', 'display'), '옛 섹션 파일은 옮긴 뒤 지운다');
    }

    /**
     * 노출은 섹션·키 2단이다 — 노출 안 한 칸은 프론트 설정에 없다. 표준 키는 선택지 안 값만 `<html>` 속성으로 쓴다.
     *
     * @scenario case=service
     *
     * @effects standard_attributes_rendered
     *
     * @return void 반환값 없음
     */
    public function test_frontend_exposure_and_standard_attributes(): void
    {
        $this->makeSettingsTemplate('qa434-svc');
        $this->service()->save('qa434-svc', ['branding' => ['accent' => 'green'], 'display' => ['show_banner' => false]]);

        $frontend = $this->service()->getActiveFrontendSettings('user');
        $this->assertSame('green', $frontend['branding']['accent']);
        $this->assertSame('#3b5bdb', $frontend['display']['accent_color']);
        $this->assertArrayNotHasKey('show_banner', $frontend['display'], '노출하지 않은 칸');
        $this->assertArrayNotHasKey('favicon', $frontend['branding'], '첨부 ID 는 노출하지 않는다');

        $this->assertSame(['accent' => 'green'], $this->service()->standardAttributes('user'));
        $this->assertSame(' data-g7-accent="green"', g7_template_html_attributes('user'));

        // 저장 파일을 손으로 고쳐 선택지 밖 값을 넣어도 속성으로 나가지 않는다
        File::put(ExtensionStoragePath::template('qa434-svc', 'settings').'/branding.json', json_encode(['accent' => 'red"><script>']));
        $this->assertSame([], $this->service()->standardAttributes('user'));
    }

    /**
     * 기본값 되돌리기 — 섹션 하나 또는 전체, 그 범위 첨부 파기, 훅·활동 로그·설정 버전.
     *
     * @scenario case=service
     *
     * @effects settings_reset_to_defaults
     *
     * @return void 반환값 없음
     */
    public function test_reset_section_and_all(): void
    {
        $this->makeSettingsTemplate('qa434-svc');
        $this->actingAs($this->makeAdmin());
        $favicon = $this->makeFaviconAttachment('template_settings.qa434-svc.branding.favicon');
        $this->service()->save('qa434-svc', ['branding' => ['favicon' => [$favicon->id], 'accent' => 'green'], 'display' => ['show_banner' => false]]);
        $version = SettingsVersion::current();

        $this->assertTrue($this->service()->reset('qa434-svc', 'display'));
        $this->assertTrue($this->service()->get('qa434-svc', 'display.show_banner'));
        $this->assertSame('green', $this->service()->get('qa434-svc', 'branding.accent'), '다른 섹션은 그대로');
        $this->assertNotNull(Attachment::find($favicon->id));

        $this->assertTrue($this->service()->reset('qa434-svc'));
        $this->assertSame('blue', $this->service()->get('qa434-svc', 'branding.accent'));
        $this->assertNull(Attachment::withTrashed()->find($favicon->id), '되돌린 범위의 첨부는 파기된다');
        $this->assertNull($this->storedSection('qa434-svc', 'branding'));
        $this->assertNotSame($version, SettingsVersion::current());
        $this->assertSame(2, ActivityLog::where('action', 'template_settings.reset')->count());

        $this->assertFalse($this->service()->reset('qa434-svc', 'nope'), '선언 밖 섹션은 거부');
    }

    /**
     * 저장하면 활동 로그(template_settings.save)가 남는다.
     *
     * @scenario case=activity_log
     *
     * @effects activity_logged
     *
     * @return void 반환값 없음
     */
    public function test_save_writes_activity_log(): void
    {
        $this->makeSettingsTemplate('qa434-svc');
        $this->actingAs($this->makeAdmin());

        $this->service()->save('qa434-svc', ['display' => ['show_banner' => false]]);

        $this->assertTrue(ActivityLog::where('action', 'template_settings.save')->exists());
    }

    /**
     * 「데이터도 삭제」 처리는 섹션 파일 전부와 그 템플릿 설정 첨부를 지우고 설정 버전을 올린다.
     *
     * @scenario case=uninstall
     *
     * @effects uninstall_delete_data_purges_settings
     *
     * @return void 반환값 없음
     */
    public function test_delete_settings_removes_files_and_attachments(): void
    {
        $this->makeSettingsTemplate('qa434-svc');
        $favicon = $this->makeFaviconAttachment('template_settings.qa434-svc.branding.favicon');
        $other = $this->makeFaviconAttachment('template_settings.qa434-other.branding.favicon');
        $this->service()->save('qa434-svc', ['branding' => ['favicon' => [$favicon->id]], 'display' => ['show_banner' => false]]);
        $version = SettingsVersion::current();

        $this->service()->deleteSettings('qa434-svc');

        $this->assertNull($this->storedSection('qa434-svc', 'branding'));
        $this->assertNull($this->storedSection('qa434-svc', 'display'));
        $this->assertNull(Attachment::withTrashed()->find($favicon->id));
        $this->assertNotNull(Attachment::find($other->id), '다른 템플릿 첨부는 남는다');
        $this->assertNotSame($version, SettingsVersion::current());
    }

    /**
     * 꺼진 템플릿도 섹션·칸 라벨(`$t:` 키)을 그 템플릿 언어 파일로 푼다 — 관리자는 켜기 전에 설정을 본다.
     * 지금 로케일 번역이 없으면(언어팩 미설치) 키 대신 대체 로케일 문구를 쓴다.
     *
     * @scenario case=service
     *
     * @effects labels_resolved_for_locale
     *
     * @return void 반환값 없음
     */
    public function test_inactive_template_labels_resolve_with_fallback_locale(): void
    {
        $declaration = $this->defaultDeclaration();
        $declaration['sections']['branding']['label'] = '$t:settings.sections.branding.label';
        $declaration['schema']['branding']['favicon']['label'] = '$t:settings.favicon.label';
        $this->makeSettingsTemplate('qa434-off', status: 'inactive', declaration: $declaration);
        File::ensureDirectoryExists(base_path('templates/qa434-off/lang'));
        File::put(base_path('templates/qa434-off/lang/ko.json'), json_encode(['settings' => ['sections' => ['branding' => ['label' => '브랜딩']], 'favicon' => ['label' => '파비콘']]], JSON_UNESCAPED_UNICODE));
        app(TemplateManager::class)->loadTemplates(); // 부팅 뒤 만든 픽스처 템플릿을 매니저가 알게 한다

        app()->setLocale('ko');
        $branding = $this->service()->sectionDescriptors('qa434-off')[0];
        $this->assertSame(['브랜딩', '파비콘'], [$branding['label'], $branding['fields'][0]['label']]);

        // en 파일이 없다 — 키(`branding`·`favicon`)가 아니라 대체 로케일(ko) 문구
        app()->setLocale('en');
        $branding = $this->service()->sectionDescriptors('qa434-off')[0];
        $this->assertSame(['브랜딩', '파비콘'], [$branding['label'], $branding['fields'][0]['label']]);
    }

    /**
     * 저장 파일 쓰기가 실패하면 교체로 빠진 첫 첨부도, 새로 고른 첨부도 파기하지 않는다 — 파기는 저장 성공 뒤에만.
     *
     * @scenario case=service
     *
     * @effects removed_attachment_purged_after_commit
     *
     * @return void 반환값 없음
     */
    public function test_failed_save_keeps_replaced_attachment(): void
    {
        $this->makeSettingsTemplate('qa434-svc');
        $first = $this->makeFaviconAttachment('template_settings.qa434-svc.branding.favicon');
        $second = $this->makeFaviconAttachment('template_settings.qa434-svc.branding.favicon');
        $disk = $this->failableTemplatesDisk();

        $this->assertTrue($this->service()->save('qa434-svc', ['branding' => ['favicon' => [$first->id]]]));

        $disk->failPut = true;
        $reason = null;
        $this->assertFalse($this->service()->save('qa434-svc', ['branding' => ['favicon' => [$second->id]]], $reason));
        $this->assertNotNull($reason, '실패 사유가 채워진다');

        $this->assertNotNull(Attachment::find($first->id), '저장이 실패하면 교체로 빠진 첨부를 파기하지 않는다');
        $this->assertNotNull(Attachment::find($second->id));

        $disk->failPut = false;
        $this->assertSame([$first->id], $this->service()->get('qa434-svc', 'branding.favicon'), '설정은 직전 첨부를 그대로 가리킨다');
    }

    /**
     * 저장은 before_save·filter_save_data·after_save 훅을 차례로 부르고, 필터가 바꾼 값이 저장된다.
     *
     * @scenario case=service
     *
     * @effects save_hooks_fired
     *
     * @return void 반환값 없음
     */
    public function test_save_fires_hooks_and_filter_changes_saved_data(): void
    {
        $this->makeSettingsTemplate('qa434-svc');
        $calls = [];

        HookManager::addAction('core.template_settings.before_save', function (string $identifier, array $settings) use (&$calls) {
            $calls[] = ['before_save', $identifier, $settings['display']['accent_color'] ?? null];
        });
        HookManager::addFilter('core.template_settings.filter_save_data', function (array $settings, string $identifier) use (&$calls) {
            $calls[] = ['filter_save_data', $identifier, $settings['display']['accent_color'] ?? null];
            $settings['display']['accent_color'] = '#00ff00';

            return $settings;
        });
        HookManager::addAction('core.template_settings.after_save', function (string $identifier, array $merged, bool $result) use (&$calls) {
            $calls[] = ['after_save', $identifier, $merged['display']['accent_color'] ?? null, $result];
        });

        $this->assertTrue($this->service()->save('qa434-svc', ['display' => ['accent_color' => '#ff0000']]));

        $this->assertSame([
            ['before_save', 'qa434-svc', '#ff0000'],
            ['filter_save_data', 'qa434-svc', '#ff0000'],
            ['after_save', 'qa434-svc', '#00ff00', true],
        ], $calls);
        $this->assertSame('#00ff00', $this->service()->get('qa434-svc', 'display.accent_color'), '필터가 바꾼 값이 저장된다');
    }

    /**
     * 보통 저장(되돌리기·삭제가 아닌)도 설정 버전을 올린다 — 프론트가 새 설정을 다시 받는다.
     *
     * @scenario case=service
     *
     * @effects settings_version_bumped
     *
     * @return void 반환값 없음
     */
    public function test_save_bumps_settings_version(): void
    {
        $this->makeSettingsTemplate('qa434-svc');
        $version = SettingsVersion::current();

        $this->assertTrue($this->service()->save('qa434-svc', ['display' => ['show_banner' => false]]));

        $this->assertNotSame($version, SettingsVersion::current());
    }

    /**
     * 기본값 되돌리기는 before_reset 훅을 그 템플릿·섹션 인자로 부른다.
     *
     * @scenario case=service
     *
     * @effects settings_reset_to_defaults
     *
     * @return void 반환값 없음
     */
    public function test_reset_fires_before_reset_hook(): void
    {
        $this->makeSettingsTemplate('qa434-svc');
        $calls = [];

        HookManager::addAction('core.template_settings.before_reset', function (string $identifier, ?string $section) use (&$calls) {
            $calls[] = [$identifier, $section];
        });

        $this->assertTrue($this->service()->reset('qa434-svc', 'display'));
        $this->assertTrue($this->service()->reset('qa434-svc'));

        $this->assertSame([['qa434-svc', 'display'], ['qa434-svc', null]], $calls);
    }

    /**
     * 다른 템플릿 설정 컬렉션의 첨부 ID 를 내 칸에 제출하면 버려진다 — 컬렉션은 템플릿·섹션·키 단위로 격리.
     *
     * @scenario case=service
     *
     * @effects attachments_scoped_to_template_collection
     *
     * @return void 반환값 없음
     */
    public function test_other_template_collection_attachment_is_dropped(): void
    {
        $this->makeSettingsTemplate('qa434-svc');
        $own = $this->makeFaviconAttachment('template_settings.qa434-svc.branding.favicon');
        $foreign = $this->makeFaviconAttachment('template_settings.qa434-other.branding.favicon');

        $this->service()->save('qa434-svc', ['branding' => ['favicon' => [$foreign->id]]]);
        $this->assertSame([], $this->service()->get('qa434-svc', 'branding.favicon'), '다른 템플릿 컬렉션 첨부만 낸 저장은 빈 값');

        $this->service()->save('qa434-svc', ['branding' => ['favicon' => [$foreign->id, $own->id]]]);
        $this->assertSame([$own->id], $this->service()->get('qa434-svc', 'branding.favicon'));
        $this->assertNotNull(Attachment::find($foreign->id), '버린 남의 첨부를 파기하지도 않는다');
    }

    /**
     * 이미지 칸(다크 로고)을 바꾸면 빠진 옛 첨부는 저장 뒤 파기되고 계산 키 `_url` 은 새 첨부를 가리킨다.
     *
     * @scenario case=service
     *
     * @effects removed_attachment_purged_after_commit
     *
     * @return void 반환값 없음
     */
    public function test_image_field_replacement_purges_old_attachment(): void
    {
        $this->makeSettingsTemplate('qa434-svc');
        $old = $this->makeFaviconAttachment('template_settings.qa434-svc.branding.logo_dark', 'image/png');
        $new = $this->makeFaviconAttachment('template_settings.qa434-svc.branding.logo_dark', 'image/webp');

        $this->service()->save('qa434-svc', ['branding' => ['logo_dark' => [$old->id]]]);
        $this->service()->save('qa434-svc', ['branding' => ['logo_dark' => [$new->id]]]);

        $this->assertNull(Attachment::withTrashed()->find($old->id), '교체된 이미지 첨부는 저장 뒤 파기된다');
        $this->assertNotNull(Attachment::find($new->id));
        $this->assertStringEndsWith('/api/attachment/'.$new->hash, (string) $this->service()->get('qa434-svc', 'branding.logo_dark_url'));
    }

    /**
     * 설정 미러(P-5) — 첫 `g7_settings('templates.{id}…')` 조회가 미러를 채우고, 저장 뒤 같은 서비스의 요청 캐시와
     * 미러가 새 값을 돌려준다.
     *
     * @scenario case=service
     *
     * @effects settings_mirror_refreshed
     *
     * @return void 반환값 없음
     */
    public function test_save_refreshes_request_cache_and_mirror(): void
    {
        $this->makeSettingsTemplate('qa434-svc');
        $this->assertFalse(Config::has('g7_settings.templates.qa434-svc'));

        $this->assertSame('#3b5bdb', g7_settings('templates.qa434-svc.display.accent_color'), '처음 조회가 미러를 채운다');

        $service = $this->service();
        $this->assertSame('#3b5bdb', $service->get('qa434-svc', 'display.accent_color'));

        $this->assertTrue($service->save('qa434-svc', ['display' => ['accent_color' => '#ff0000']]));

        $this->assertSame('#ff0000', $service->get('qa434-svc', 'display.accent_color'), '같은 요청의 캐시도 새 값');
        $this->assertSame('#ff0000', g7_settings('templates.qa434-svc.display.accent_color'), '미러도 새 값');
        $this->assertSame('#ff0000', g7_template_settings('qa434-svc', 'display.accent_color'));
    }

    /**
     * 쓰기를 실패시킬 수 있는 `templates` 디스크로 바꿉니다(가짜 디스크 위).
     *
     * @return FilesystemAdapter `failPut` 속성을 가진 디스크
     */
    private function failableTemplatesDisk(): FilesystemAdapter
    {
        $fake = Storage::fake('templates');
        $disk = new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
        {
            /**
             * 참이면 쓰기를 실패로 돌려준다
             */
            public bool $failPut = false;

            /**
             * 파일을 씁니다 — `failPut` 이 참이면 쓰지 않고 실패를 돌려준다.
             *
             * @param  string  $path  경로
             * @param  mixed  $contents  내용
             * @param  mixed  $options  옵션
             * @return string|bool 쓰기 결과 (실패면 false)
             */
            public function put($path, $contents, $options = [])
            {
                return $this->failPut ? false : parent::put($path, $contents, $options);
            }
        };
        Storage::set('templates', $disk);

        return $disk;
    }

    /**
     * 라벨 해석 언어 캐시 — 한 서비스 인스턴스 안에서는 언어 파일을 바꿔도 캐시된 라벨을 쓰고, `clearCache(템플릿)` 은 그 템플릿의
     * 언어 캐시만, `clearCache()` 는 전부 비운다. (저장은 언어 캐시를 비우지 않는다 — 설정 캐시·미러만 갱신한다.)
     *
     * @scenario case=service
     *
     * @effects labels_resolved_for_locale
     *
     * @return void 반환값 없음
     */
    public function test_clear_cache_drops_language_cache_per_template(): void
    {
        foreach (['qa434-la', 'qa434-lb'] as $identifier) {
            $declaration = $this->defaultDeclaration();
            $declaration['sections']['branding']['label'] = '$t:settings.sections.branding';
            $this->makeSettingsTemplate($identifier, status: 'inactive', declaration: $declaration);
            File::ensureDirectoryExists(base_path("templates/{$identifier}/lang"));
            File::put(base_path("templates/{$identifier}/lang/ko.json"), json_encode(['settings' => ['sections' => ['branding' => '옛 '.$identifier]]], JSON_UNESCAPED_UNICODE));
        }
        app(TemplateManager::class)->loadTemplates();
        app()->setLocale('ko');

        $service = $this->service();
        $label = fn (string $identifier) => $service->sectionDescriptors($identifier)[0]['label'];
        $this->assertSame(['옛 qa434-la', '옛 qa434-lb'], [$label('qa434-la'), $label('qa434-lb')]);

        foreach (['qa434-la', 'qa434-lb'] as $identifier) {
            File::put(base_path("templates/{$identifier}/lang/ko.json"), json_encode(['settings' => ['sections' => ['branding' => '새 '.$identifier]]], JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame('옛 qa434-la', $label('qa434-la'), '같은 인스턴스는 캐시된 언어 데이터를 쓴다(전제)');

        $service->clearCache('qa434-la');
        $this->assertSame('새 qa434-la', $label('qa434-la'), 'clearCache(템플릿) 은 그 템플릿 언어 캐시를 비운다');
        $this->assertSame('옛 qa434-lb', $label('qa434-lb'), '다른 템플릿 언어 캐시는 남는다');

        $service->clearCache();
        $this->assertSame('새 qa434-lb', $label('qa434-lb'), 'clearCache() 는 전부 비운다');
    }
}
