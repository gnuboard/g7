<?php

namespace Tests\Feature\Api\Admin;

use App\Contracts\Extension\CacheInterface;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomAssetService;
use App\Services\ExtensionStaticCacheService;
use App\Support\CustomAssets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * 사용자 추가 에셋(`custom/`) 관리 API 테스트
 *
 * 운영자가 화면에서 자기 CSS·JS·폰트·이미지를 넣고 고치는 경로를 잠근다. 특히
 * **권한 경계**와 **경로 탈출** 두 축은 실패해도 오류가 남지 않는 종류라(정상 200 이
 * 나가는 것이 유일한 증상) 테스트가 유일한 방어선이다.
 */
class AdminExtensionCustomAssetControllerTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPLATE = 'sirsoft-admin_basic';

    private User $manager;

    private string $managerToken;

    private User $outsider;

    private string $outsiderToken;

    private string $customDir;

    protected function setUp(): void
    {
        parent::setUp();

        $managePermission = Permission::firstOrCreate([
            'identifier' => 'core.extensions.custom_assets.manage',
        ], [
            'name' => '커스텀 자산 관리',
            'display_name' => '커스텀 자산 관리',
            'type' => 'admin',
        ]);

        // 레이아웃 편집 권한만 가진 역할 — "레이아웃을 고칠 수 있다" 가 곧 "사이트 전역
        // 스크립트를 올릴 수 있다" 가 되지 않는지 확인하는 대조군이다.
        $layoutPermission = Permission::firstOrCreate([
            'identifier' => 'core.templates.layouts.edit',
        ], [
            'name' => '레이아웃 편집',
            'display_name' => '레이아웃 편집',
            'type' => 'admin',
        ]);

        $managerRole = Role::firstOrCreate(['identifier' => 'custom-asset-manager'], [
            'name' => 'Custom Asset Manager',
            'display_name' => 'Custom Asset Manager',
            'is_default' => false,
        ]);
        $managerRole->permissions()->syncWithoutDetaching([$managePermission->id]);

        $layoutRole = Role::firstOrCreate(['identifier' => 'layout-only'], [
            'name' => 'Layout Only',
            'display_name' => 'Layout Only',
            'is_default' => false,
        ]);
        $layoutRole->permissions()->syncWithoutDetaching([$layoutPermission->id]);

        $this->manager = User::factory()->create();
        $this->manager->roles()->syncWithoutDetaching([$managerRole->id]);
        $this->managerToken = $this->manager->createToken('manager')->plainTextToken;

        $this->outsider = User::factory()->create();
        $this->outsider->roles()->syncWithoutDetaching([$layoutRole->id]);
        $this->outsiderToken = $this->outsider->createToken('outsider')->plainTextToken;

        $this->customDir = (string) CustomAssets::directory('templates', self::TEMPLATE);
        $this->cleanupCustomDir();
    }

    protected function tearDown(): void
    {
        $this->cleanupCustomDir();

        parent::tearDown();
    }

    /**
     * 테스트가 만든 파일만 지웁니다 (운영자 파일 보존).
     */
    private function cleanupCustomDir(): void
    {
        foreach (glob($this->customDir.DIRECTORY_SEPARATOR.'__t123-*') ?: [] as $path) {
            @unlink($path);
        }

        @rmdir($this->customDir.DIRECTORY_SEPARATOR.'__t123-dir');
    }

    /**
     * 관리 API URL 을 만듭니다.
     *
     * 세 타입이 한 엔드포인트를 공유하므로 테스트도 타입을 인자로 받는다 — URL 을 케이스마다
     * 손으로 조립하면 한 타입만 고쳐 놓고 나머지는 옛 경로를 두드리게 된다.
     *
     * @param  string  $suffix  `/custom-assets…` 이후 경로
     * @param  string  $type  확장 타입 (`template` | `module` | `plugin`)
     * @param  string|null  $identifier  확장 식별자 (기본: 대상 템플릿)
     * @return string URL
     */
    private function url(string $suffix, string $type = 'template', ?string $identifier = null): string
    {
        return '/api/admin/extensions/'.$type.'/'.($identifier ?? self::TEMPLATE).$suffix;
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    public function test_저장_조회_목록_삭제_왕복(): void
    {
        // @scenario manage_actor=with_permission, manage_action=save
        // @effects custom_asset_editor_save_invalidates_published_copy
        // @scenario manage_actor=with_permission, manage_action=read
        // @effects custom_asset_manage_requires_dedicated_permission
        // @scenario manage_actor=with_permission, manage_action=delete
        // @effects custom_asset_manage_requires_dedicated_permission
        $path = '__t123-overrides.css';

        $save = $this->putJson(
            $this->url('/custom-assets/content'),
            ['path' => $path, 'content' => 'body { color: red; }'],
            $this->headers($this->managerToken)
        );
        $save->assertStatus(200);
        $this->assertFileExists($this->customDir.DIRECTORY_SEPARATOR.$path);

        $index = $this->getJson(
            $this->url('/custom-assets'),
            $this->headers($this->managerToken)
        );
        $index->assertStatus(200);
        $files = $index->json('data.files');
        $paths = array_column($files, 'path');
        $this->assertContains($path, $paths);

        // `loaded` 는 그 파일이 실제로 페이지에 실리는지다. 서술자에 상대 경로 필드가
        // 없어 한때 언제나 false 였고, 그러면 화면이 모든 파일을 "실리지 않음" 으로
        // 표시해 운영자가 규약 스캔이 고장났다고 읽는다 (E2E 가 먼저 잡았다).
        $row = collect($files)->firstWhere('path', $path);
        $this->assertTrue($row['loaded'], '규약 스캔이 자동으로 싣는 css 인데 loaded 가 false 입니다.');

        $show = $this->getJson(
            $this->url('/custom-assets/content?path='.urlencode($path)),
            $this->headers($this->managerToken)
        );
        $show->assertStatus(200);
        $this->assertSame('body { color: red; }', $show->json('data.content'));

        $delete = $this->deleteJson(
            $this->url('/custom-assets?path='.urlencode($path)),
            [],
            $this->headers($this->managerToken)
        );
        $delete->assertStatus(200);
        $this->assertFileDoesNotExist($this->customDir.DIRECTORY_SEPARATOR.$path);
    }

    public function test_레이아웃_편집_권한만으로는_접근할_수_없다(): void
    {
        // 여기서 올린 스크립트는 그 레이아웃 한 장이 아니라 사이트 전 화면에서 실행된다.
        // 레이아웃 편집 권한이 곧 이 권한이 되면 그 확대는 조용히 일어난다.
        // @scenario manage_actor=layout_edit_only, manage_action=list
        // @effects custom_asset_manage_requires_dedicated_permission
        // @scenario manage_actor=layout_edit_only, manage_action=read
        // @effects custom_asset_manage_requires_dedicated_permission
        // @scenario manage_actor=layout_edit_only, manage_action=save
        // @effects custom_asset_manage_requires_dedicated_permission
        foreach ([
            ['getJson', $this->url('/custom-assets'), []],
            ['getJson', $this->url('/custom-assets/content?path=a.css'), []],
        ] as [$method, $url, $payload]) {
            $response = $this->{$method}($url, $this->headers($this->outsiderToken));
            $response->assertStatus(403);
        }

        $this->putJson(
            $this->url('/custom-assets/content'),
            ['path' => '__t123-evil.css', 'content' => 'x'],
            $this->headers($this->outsiderToken)
        )->assertStatus(403);

        $this->assertFileDoesNotExist($this->customDir.DIRECTORY_SEPARATOR.'__t123-evil.css');
    }

    public function test_비인증_요청은_거부된다(): void
    {
        // @scenario manage_actor=unauthenticated, manage_action=list
        // @effects custom_asset_manage_requires_dedicated_permission
        $this->getJson($this->url('/custom-assets'), ['Accept' => 'application/json'])
            ->assertStatus(401);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function 탈출_경로_제공자(): array
    {
        return [
            '상위 디렉토리' => ['../evil.css'],
            '중간 상위' => ['a/../../evil.css'],
            '절대 경로' => ['/etc/passwd.css'],
            '역슬래시 상위' => ['..\\evil.css'],
            '빈 세그먼트' => ['a//b.css'],
        ];
    }

    /**
     * @dataProvider 탈출_경로_제공자
     */
    public function test_경로_탈출은_422_로_차단된다(string $path): void
    {
        // @scenario manage_actor=with_permission, manage_action=save
        // @effects custom_asset_manage_path_traversal_blocked
        $this->putJson(
            $this->url('/custom-assets/content'),
            ['path' => $path, 'content' => 'x'],
            $this->headers($this->managerToken)
        )->assertStatus(422);
    }

    public function test_편집_불가_확장자는_본문_저장이_거부된다(): void
    {
        // @scenario manage_actor=with_permission, manage_action=save
        // @effects custom_asset_manage_binary_rejects_text_save
        // 바이너리를 텍스트 편집기에 열어 저장하면 내용이 손상된다 — 그 경로를 막는다.
        $this->putJson(
            $this->url('/custom-assets/content'),
            ['path' => '__t123-font.woff2', 'content' => 'x'],
            $this->headers($this->managerToken)
        )->assertStatus(422);
    }

    public function test_업로드는_허용_확장자만_받는다(): void
    {
        // @scenario manage_actor=with_permission, manage_action=upload
        // @effects custom_asset_manage_upload_extension_whitelist
        $rejected = $this->post(
            $this->url('/custom-assets/upload'),
            ['file' => UploadedFile::fake()->create('__t123-shell.php', 4)],
            $this->headers($this->managerToken)
        );
        $rejected->assertStatus(422);
        $this->assertFileDoesNotExist($this->customDir.DIRECTORY_SEPARATOR.'__t123-shell.php');

        $accepted = $this->post(
            $this->url('/custom-assets/upload'),
            ['file' => UploadedFile::fake()->image('__t123-logo.png')],
            $this->headers($this->managerToken)
        );
        $accepted->assertStatus(200);
        $this->assertFileExists($this->customDir.DIRECTORY_SEPARATOR.'__t123-logo.png');
    }

    public function test_목록_응답이_편집기_메타를_함께_싣는다(): void
    {
        // @scenario manage_actor=with_permission, manage_action=list
        // @effects custom_asset_manage_requires_dedicated_permission
        $response = $this->getJson(
            $this->url('/custom-assets'),
            $this->headers($this->managerToken)
        );

        $response->assertStatus(200);
        $this->assertSame(CustomAssetService::EDITABLE_EXTENSIONS, $response->json('data.editable_extensions'));
        $this->assertSame(CustomAssetService::MAX_TEXT_BYTES, $response->json('data.max_text_bytes'));
        $this->assertSame(CustomAssetService::MAX_UPLOAD_BYTES, $response->json('data.max_upload_bytes'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function 확장_타입_제공자(): array
    {
        return [
            '템플릿' => ['template', self::TEMPLATE],
            '모듈' => ['module', 'sirsoft-page'],
            '플러그인' => ['plugin', 'sirsoft-gdpr'],
        ];
    }

    /**
     * 세 타입이 같은 강도로 동작해야 한다.
     *
     * 타입별로 경로·검증이 갈리면 그중 약한 쪽이 조용한 우회로가 되고, 반대로 한 타입만
     * 동작하면 운영자는 "모듈에서는 왜 안 되는지" 를 알 수 없다.
     *
     * @dataProvider 확장_타입_제공자
     */
    public function test_세_타입_모두_같은_왕복을_지원한다(string $type, string $identifier): void
    {
        // @scenario manage_actor=with_permission, manage_action=save
        // @effects custom_asset_manage_all_extension_types_parity
        $dir = (string) CustomAssets::directory($type.'s', $identifier);
        $path = '__t123-parity.css';

        try {
            $this->putJson(
                $this->url('/custom-assets/content', $type, $identifier),
                ['path' => $path, 'content' => 'body { color: blue; }'],
                $this->headers($this->managerToken)
            )->assertStatus(200);

            $this->assertFileExists($dir.DIRECTORY_SEPARATOR.$path);

            $index = $this->getJson(
                $this->url('/custom-assets', $type, $identifier),
                $this->headers($this->managerToken)
            );
            $index->assertStatus(200);
            $this->assertContains($path, array_column($index->json('data.files'), 'path'));

            // 권한 경계도 타입과 무관하게 같아야 한다.
            //
            // 한 테스트 안에서 토큰을 바꿔 요청하려면 가드를 먼저 잊어야 한다 — 앱이
            // 요청 사이에 재부팅되지 않아 `auth:sanctum` 가드가 앞 요청에서 해석한
            // 사용자를 그대로 재사용하고, 그러면 권한이 없는 토큰이 200 을 받는다
            // (제품 결함이 아니라 테스트 하네스의 성질이다).
            $this->app['auth']->forgetGuards();

            $this->getJson(
                $this->url('/custom-assets', $type, $identifier),
                $this->headers($this->outsiderToken)
            )->assertStatus(403);

            $this->app['auth']->forgetGuards();

            $this->deleteJson(
                $this->url('/custom-assets?path='.urlencode($path), $type, $identifier),
                [],
                $this->headers($this->managerToken)
            )->assertStatus(200);

            $this->assertFileDoesNotExist($dir.DIRECTORY_SEPARATOR.$path);
        } finally {
            @unlink($dir.DIRECTORY_SEPARATOR.$path);
            @rmdir($dir);
        }
    }

    public function test_알_수_없는_확장_타입은_라우트가_받지_않는다(): void
    {
        // 정규식이 세 값으로 제한하므로 404 다 — 통과시켜 빈 목록으로 응답하면 오타가
        // "그 확장에는 파일이 없다" 로 보인다.
        $this->getJson(
            '/api/admin/extensions/theme/'.self::TEMPLATE.'/custom-assets',
            $this->headers($this->managerToken)
        )->assertStatus(404);
    }

    public function test_저장이_확장_캐시_버전을_올린다(): void
    {
        // 올리지 않으면 편집한 파일이 게시본에 반영되지 않아, 운영자에게는
        // "고쳤는데 화면이 그대로" 로만 나타난다.
        $before = ExtensionStaticCacheService::getExtensionCacheVersion();

        // 버전은 초 단위 timestamp 라 같은 초에 저장하면 값이 같을 수 있다 — 값 비교가
        // 아니라 "덮어써졌는지" 를 보기 위해 과거 값을 심어 둔다.
        app(CacheInterface::class)->put('ext.cache_version', 1);

        $this->putJson(
            $this->url('/custom-assets/content'),
            ['path' => '__t123-bump.css', 'content' => 'body{}'],
            $this->headers($this->managerToken)
        )->assertStatus(200);

        $after = (int) app(CacheInterface::class)->get('ext.cache_version');
        $this->assertGreaterThan(1, $after);
        $this->assertGreaterThan(0, $before);
    }
}
