<?php

namespace Tests\Helpers;

use App\Enums\AttachmentSourceType;
use App\Enums\ExtensionOwnerType;
use App\Extension\Storage\CoreStorageDriver;
use App\Models\Attachment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\User;
use App\Services\FaviconService;
use App\Services\TemplateSettingsService;
use App\Support\ExtensionStoragePath;
use App\Support\Favicon\BundledFavicon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * 템플릿 환경설정·파비콘 테스트 공통 픽스처 (#434)
 *
 * 실제 번들 템플릿을 설치하지 않는다 — `templates/qa434-*` 임시 디렉토리에 선언 파일·설정 화면을 두고 DB 행만 만든다.
 * 테스트가 만든 디렉토리·설정 파일은 {@see cleanUpTemplateSettingsFixtures()} 가 지운다(사용하는 테스트의 tearDown 에서 부른다).
 * 첨부 디스크는 {@see setUpCreatesTemplateSettingsFixtures()} 가 가짜로 바꾼다 — DB 는 되감겨도 실제 디스크에 쓴 파일은 남기 때문이다.
 */
trait CreatesTemplateSettingsFixtures
{
    /**
     * 이 테스트가 만든 템플릿 식별자
     *
     * @var array<int, string>
     */
    private array $fixtureTemplates = [];

    /**
     * 첨부 디스크를 가짜로 바꿉니다(Laravel 이 트레이트 이름으로 setUp 때 부른다).
     *
     * 업로드 API·{@see makeFaviconAttachment()} 가 실제 `storage/app/attachments` 에 파일을 남기지 않게 한다.
     *
     * @return void 반환값 없음
     */
    protected function setUpCreatesTemplateSettingsFixtures(): void
    {
        Storage::fake(config('attachment.disk'));
    }

    /**
     * 선언 파일·설정 화면을 가진 임시 템플릿을 만들고 DB 행을 등록합니다.
     *
     * @param  string  $identifier  식별자 (qa434- 로 시작)
     * @param  string  $type  종류 (user|admin)
     * @param  string  $status  상태 (active|inactive)
     * @param  array<string, mixed>|null  $declaration  선언 전체 (null 이면 파비콘 1칸 기본 선언)
     * @param  bool  $withLayout  설정 화면 파일을 둘지
     * @return Template 템플릿 행
     */
    protected function makeSettingsTemplate(string $identifier, string $type = 'user', string $status = 'active', ?array $declaration = null, bool $withLayout = true): Template
    {
        $root = base_path('templates/'.$identifier);
        File::ensureDirectoryExists($root.'/config/settings');
        File::put($root.'/config/settings/defaults.json', json_encode($declaration ?? $this->defaultDeclaration(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        File::put($root.'/template.json', json_encode(['identifier' => $identifier, 'vendor' => 'qa434', 'name' => ['ko' => $identifier, 'en' => $identifier], 'version' => '1.0.0', 'type' => $type]));

        if ($withLayout) {
            File::ensureDirectoryExists($root.'/settings');
            File::put($root.'/settings/template_settings.json', json_encode([
                'version' => '1.0.0',
                'layout_name' => 'template_settings',
                'extends' => '_admin_base',
                'meta' => ['title' => 'qa434'],
                'slots' => ['content' => [['id' => 'qa434_root', 'type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'p-4']]]],
            ]));
        }

        $this->fixtureTemplates[] = $identifier;

        if ($status === 'active') {
            Template::where('type', $type)->where('status', 'active')->update(['status' => 'inactive']);
        }

        $template = Template::create([
            'identifier' => $identifier,
            'vendor' => 'qa434',
            'name' => ['ko' => $identifier, 'en' => $identifier],
            'version' => '1.0.0',
            'type' => $type,
            'status' => $status,
            'description' => ['ko' => '', 'en' => ''],
        ]);

        $this->resetFaviconResolution();

        return $template;
    }

    /**
     * 기본 선언(섹션 2개)을 돌려줍니다 — 브랜딩(파비콘·포인트 색·다크 로고) + 표시(색·토글).
     *
     * @return array<string, mixed> 선언
     */
    protected function defaultDeclaration(): array
    {
        return [
            '_meta' => ['version' => '2.0.0', 'categories' => ['branding', 'display']],
            'sections' => ['branding' => ['label' => ['ko' => '브랜딩', 'en' => 'Branding']], 'display' => ['label' => '표시']],
            'defaults' => [
                'branding' => ['favicon' => [], 'accent' => 'blue', 'logo_dark' => []],
                'display' => ['accent_color' => '#3b5bdb', 'show_banner' => true],
            ],
            'schema' => [
                'branding' => [
                    'favicon' => ['type' => 'attachments', 'max_files' => 1, 'accept' => ['ico', 'png', 'svg', 'gif', 'jpg', 'jpeg', 'webp'], 'label' => ['ko' => '파비콘', 'en' => 'Favicon']],
                    'accent' => ['type' => 'enum', 'options' => [['value' => 'blue', 'label' => '파랑'], ['value' => 'green', 'label' => '초록']], 'label' => '포인트 색'],
                    'logo_dark' => ['type' => 'image', 'max_files' => 1, 'max_size_kb' => 512, 'label' => '다크 로고'],
                ],
                'display' => [
                    'accent_color' => ['type' => 'color', 'label' => '강조 색'],
                    'show_banner' => ['type' => 'boolean', 'label' => '배너 표시'],
                ],
            ],
            'frontend_schema' => [
                'branding' => ['expose' => true, 'fields' => ['favicon_url' => ['expose' => true], 'accent' => ['expose' => true], 'logo_dark_url' => ['expose' => true]]],
                'display' => ['expose' => true, 'fields' => ['accent_color' => ['expose' => true]]],
            ],
        ];
    }

    /**
     * 첨부(파일 포함)를 만듭니다.
     *
     * @param  string  $collection  첨부 컬렉션 이름
     * @param  string  $mime  저장할 MIME 형식
     * @param  string  $bytes  파일 내용
     * @return Attachment 첨부
     */
    protected function makeFaviconAttachment(string $collection, string $mime = 'image/png', string $bytes = "\x89PNG\r\n\x1a\nqa434"): Attachment
    {
        $filename = uniqid('qa434-').'.bin';
        $path = '2026/11/04/'.$filename;
        $disk = config('attachment.disk');
        (new CoreStorageDriver($disk))->put('', $path, $bytes);

        return Attachment::create([
            'attachmentable_type' => null,
            'attachmentable_id' => null,
            'source_type' => AttachmentSourceType::Core,
            'source_identifier' => null,
            'original_filename' => $filename,
            'stored_filename' => $filename,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $mime,
            'size' => strlen($bytes),
            'collection' => $collection,
            'order' => 0,
        ])->refresh();
    }

    /**
     * 권한을 가진 관리자 회원을 만듭니다.
     *
     * @param  array<int, string>  $permissions  권한 식별자
     * @return User 회원
     */
    protected function makeAdmin(array $permissions = ['core.templates.read', 'core.templates.settings']): User
    {
        $user = User::factory()->create();
        $ids = [];

        foreach ($permissions as $identifier) {
            $ids[] = Permission::firstOrCreate(['identifier' => $identifier], [
                'name' => ['ko' => $identifier, 'en' => $identifier],
                'description' => ['ko' => $identifier, 'en' => $identifier],
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ])->id;
        }

        $role = Role::create(['identifier' => 'qa434_'.uniqid(), 'name' => ['ko' => 'qa', 'en' => 'qa'], 'description' => ['ko' => 'qa', 'en' => 'qa'], 'is_active' => true]);
        $role->permissions()->sync($ids);
        $admin = Role::firstOrCreate(['identifier' => 'admin'], ['name' => ['ko' => '관리자', 'en' => 'Admin'], 'description' => ['ko' => '', 'en' => ''], 'extension_type' => ExtensionOwnerType::Core, 'extension_identifier' => 'core', 'is_active' => true]);
        $user->roles()->attach($admin->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 요청 내 해석 캐시를 비웁니다(같은 테스트 안에서 계층을 바꿀 때).
     *
     * @return void 반환값 없음
     */
    protected function resetFaviconResolution(): void
    {
        app(TemplateSettingsService::class)->clearCache();
        app(FaviconService::class)->flush();
        BundledFavicon::flush();
        Config::set('g7_settings.templates', []);
    }

    /**
     * 테스트가 만든 템플릿 디렉토리·설정 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function cleanUpTemplateSettingsFixtures(): void
    {
        foreach ($this->fixtureTemplates as $identifier) {
            File::deleteDirectory(base_path('templates/'.$identifier));
            File::deleteDirectory(ExtensionStoragePath::template($identifier));
        }

        $this->fixtureTemplates = [];
        BundledFavicon::flush();
    }
}
