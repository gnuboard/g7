<?php

namespace Tests\Unit\Support;

use App\Extension\Helpers\StorageLinkHelper;
use App\Extension\Storage\CoreStorageDriver;
use App\Support\AssetUrl;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 공개 스토리지 대체 서빙 URL 생성(B-3) 테스트.
 *
 * `public/storage` 링크 가용 여부에 따라 public 디스크 공개 URL 의 형태가 갈린다 —
 * 링크 있음: 정적 `/storage/...`, 링크 없음: 읽기 전용 서빙 라우트. 형태는 서버가
 * 정하고(SSoT) 자산 URL 이중 모드(R33)를 따른다.
 *
 * 커버 축 요약(마커 아님 — 평문): consumer=public_disk_url 은 기본·운영자 지정(custom) 두 값을 함께 잰다.
 *
 * @scenario link_state=usable, asset_url_mode=extension, consumer=storage_driver, consumer=public_disk_url
 */
class AssetUrlPublicStorageTest extends TestCase
{
    /**
     * 강제로 지정한 자산 URL 모드와 링크 가용 판정을 풀어 다른 테스트에 남지 않게 한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        AssetUrl::forceMode(null);
        StorageLinkHelper::forcePublicStorageLinkUsable(null);

        parent::tearDown();
    }

    /**
     * 운영자가 public 디스크 url 을 외부 호스트로 지정하면 링크가 없어도 로컬 서빙 라우트로 끌어오지 않고 그 주소를 쓰는지 확인한다.
     *
     * @effects configured_public_disk_url_outranks_link_detection
     *
     * @return void 반환값 없음
     */
    public function test_configured_public_disk_url_outranks_link_detection(): void
    {
        // 운영자가 public 디스크를 외부 호스트(CDN·별도 도메인)로 돌린 배치는 로컬
        // `public/storage` 링크가 애초에 필요 없고 파일도 그 호스트가 내준다. 그런데도
        // 링크 부재를 이유로 로컬 서빙 라우트로 끌어오면, 그 사이트의 공개 자산 URL 이
        // 통째로 앱 오리진으로 옮겨 가 전부 404 가 된다 — 형제 판정
        // `diskSupportsDirectUrl()` 이 다른 디스크에 대해 이 설정을 권위로 삼는 것과
        // 같은 규칙이어야 한다 (제품 결정 2026-08-25).
        AssetUrl::forceMode(AssetUrl::MODE_EXTENSION);
        config(['filesystems.disks.public.url' => 'https://cdn.example.test/assets']);

        // 디스크 인스턴스는 설정을 캐시하므로 새 설정으로 다시 만든다.
        app('filesystem')->forgetDisk('public');

        $driver = new CoreStorageDriver('public');

        StorageLinkHelper::forcePublicStorageLinkUsable(false);
        $url = $driver->url('images', 'a.png');

        $this->assertIsString($url);
        $this->assertStringStartsWith('https://cdn.example.test/assets/', $url, '설정된 공개 디스크 URL 이 링크 판정보다 우선한다');
        $this->assertStringNotContainsString('public-storage', $url, '설정을 무시하고 로컬 서빙 라우트로 끌어오면 그 사이트의 자산이 전부 어긋난다');
    }

    /**
     * 공개 스토리지 라우트 주소가 확장자 모드에서는 경로 형태로, 확장자 없는 모드에서는 file 쿼리 형태로 버전까지 실려 만들어지는지 확인한다.
     *
     * @scenario asset_url_mode=extension, asset_url_mode=extensionless
     *
     * @effects dual_mode_query_form_matches_path_form
     *
     * @return void 반환값 없음
     */
    public function test_public_storage_url_has_dual_mode_forms(): void
    {
        AssetUrl::forceMode(AssetUrl::MODE_EXTENSION);
        $this->assertSame('/api/system/public-storage/images/a.png', AssetUrl::publicStorage('images/a.png'));
        $this->assertSame('/api/system/public-storage/images/a.png?v=3', AssetUrl::publicStorage('images/a.png', 3));

        AssetUrl::forceMode(AssetUrl::MODE_EXTENSIONLESS);
        $this->assertSame('/api/system/public-storage?file=images%2Fa.png', AssetUrl::publicStorage('images/a.png'));
        $this->assertSame('/api/system/public-storage?file=images%2Fa.png&v=3', AssetUrl::publicStorage('images/a.png', 3));
    }

    /**
     * public 디스크 주소가 링크가 쓸 수 있으면 정적 /storage 경로를, 링크가 없으면 공개 스토리지 라우트를 가리키는지 확인한다.
     *
     * @effects public_storage_url_switches_to_route_when_link_missing
     *
     * @return void 반환값 없음
     */
    public function test_public_disk_url_switches_to_route_when_link_missing(): void
    {
        AssetUrl::forceMode(AssetUrl::MODE_EXTENSION);
        $driver = new CoreStorageDriver('public');

        StorageLinkHelper::forcePublicStorageLinkUsable(true);
        $withLink = $driver->url('images', 'a.png');
        $this->assertIsString($withLink);
        $this->assertStringContainsString('/storage/', $withLink, '링크 가용 시 정적 /storage 경로를 유지한다');
        $this->assertStringNotContainsString('public-storage', $withLink);

        StorageLinkHelper::forcePublicStorageLinkUsable(false);
        $withoutLink = $driver->url('images', 'a.png');
        $this->assertIsString($withoutLink);
        $this->assertStringContainsString('/api/system/public-storage/', $withoutLink, '링크 부재 시 서빙 라우트로 전환한다 (B-3)');
    }

    /**
     * 링크가 없어 라우트로 바뀐 주소가 확장자 없는 모드에서는 file 쿼리 형태를 따르는지 확인한다.
     *
     * @effects public_storage_url_switches_to_route_when_link_missing
     *
     * @return void 반환값 없음
     */
    public function test_route_form_follows_asset_url_mode_when_link_missing(): void
    {
        $driver = new CoreStorageDriver('public');
        StorageLinkHelper::forcePublicStorageLinkUsable(false);

        AssetUrl::forceMode(AssetUrl::MODE_EXTENSIONLESS);
        $url = $driver->url('images', 'a.png');
        $this->assertIsString($url);
        $this->assertStringContainsString('/api/system/public-storage?file=', $url, '확장자 없는 모드에서는 ?file= 쿼리 형태여야 한다 (R33)');
    }

    /**
     * 사이트가 하위 폴더에 마운트된 배치에서 생성 URL 이 그 사이트 안을 가리킨다.
     *
     * 종전에는 루트 상대 `/api/…` 를 그대로 내보내, `https://host/g7/` 에 설치한
     * 사이트가 `https://host/api/…` 를 부르게 됐다 — 그 주소는 사이트 밖이다.
     * 형제 헬퍼(`asset()`·`url()`)는 이 접두사를 알고 붙이므로, 같은 화면 안에서
     * 코어 번들과 확장 번들이 서로 다른 규칙을 따르고 있었다.
     *
     * @effects asset_urls_follow_the_site_base_path
     *
     * @return void 반환값 없음
     */
    public function test_generated_urls_carry_the_site_base_path(): void
    {
        AssetUrl::forceBasePrefix('/g7');

        try {
            $this->assertSame(
                '/g7/api/system/public-storage/uploads/logo.png',
                AssetUrl::publicStorage('uploads/logo.png'),
                '하위 폴더 배치에서 루트 상대 주소를 내면 사이트 밖을 가리킨다'
            );
            $this->assertSame(
                '/g7/api/templates/assets/sirsoft-basic/dist/js/a.js',
                AssetUrl::templateAsset('sirsoft-basic', 'dist/js/a.js')
            );
            $this->assertSame(
                '/g7/api/modules/bundle.js',
                AssetUrl::extensionBundle('modules', 'js')
            );
        } finally {
            AssetUrl::forceBasePrefix(null);
        }
    }

    /**
     * 루트 설치에서는 종전과 같은 형태다 (회귀 축).
     *
     * @scenario base_path=root
     *
     * @effects asset_urls_follow_the_site_base_path
     *
     * @return void 반환값 없음
     */
    public function test_root_installs_keep_the_previous_url_shape(): void
    {
        AssetUrl::forceBasePrefix('');

        try {
            $this->assertSame('/api/system/public-storage/uploads/logo.png', AssetUrl::publicStorage('uploads/logo.png'));
            $this->assertSame('/api/modules/bundle.js', AssetUrl::extensionBundle('modules', 'js'));
        } finally {
            AssetUrl::forceBasePrefix(null);
        }
    }

    /**
     * 절대화는 경로 접두사를 **두 번** 붙이지 않는다.
     *
     * `url()` 은 요청 루트(접두사 포함)를 앞에 붙이므로, 이미 접두사가 든
     * 경로에 쓰면 `/g7/g7/api/…` 가 된다. 그 조합은 404 로만 나타난다.
     *
     * @effects asset_urls_follow_the_site_base_path
     *
     * @return void 반환값 없음
     */
    public function test_absolute_does_not_double_the_base_path(): void
    {
        AssetUrl::forceBasePrefix('/g7');

        try {
            $path = AssetUrl::publicStorage('uploads/logo.png');
            $absolute = AssetUrl::absolute($path);

            $this->assertStringEndsWith('/g7/api/system/public-storage/uploads/logo.png', $absolute);
            $this->assertSame(
                1,
                substr_count($absolute, '/g7/'),
                '접두사가 두 번 붙으면 그 자산은 언제나 404 다'
            );
        } finally {
            AssetUrl::forceBasePrefix(null);
        }
    }

    /**
     * 접두사의 **실도출식**이 요청을 따라간다.
     *
     * 종전 케이스는 전부 `forceBasePrefix()` 오버라이드를 걸고 돌았다. 그래서
     * `basePrefix()` 안의 `rtrim($request->getBaseUrl(), '/')` 를 `return ''` 로
     * 바꿔도 전 스위트가 초록이었다 — 하위 폴더 배치에서 모든 자산 URL 이 사이트 밖을
     * 가리키게 되는 변이를 어떤 게이트도 잡지 못한다.
     *
     * @scenario base_path=subpath, navigation_direction=request
     *
     * @effects asset_urls_follow_the_site_base_path
     *
     * @return void 반환값 없음
     */
    public function test_base_prefix_is_derived_from_the_request_without_an_override(): void
    {
        AssetUrl::forceBasePrefix(null);

        $request = Request::create('https://example.test/g7/admin/users');
        $request->server->set('SCRIPT_NAME', '/g7/index.php');
        $request->server->set('SCRIPT_FILENAME', '/var/www/g7/public/index.php');
        $request->server->set('PHP_SELF', '/g7/index.php');
        app()->instance('request', $request);

        $this->assertSame('/g7', AssetUrl::basePrefix());
        $this->assertStringStartsWith('/g7/api/', AssetUrl::templateAsset('t', 'js/app.js'));
    }

    /**
     * 요청 base URL 이 비면 `app.url` 의 경로로 내려간다.
     *
     * 두 배치가 이 폴백에 걸린다 — 앞단 프록시가 접두사를 벗겨 전달하는 구성과,
     * 요청 자체가 없는 문맥(큐가 만드는 메일 본문·콘솔이 렌더하는 사이트맵).
     * 후자는 종전에 빈 문자열이라 그 주소가 사이트 밖을 가리켰다.
     *
     * @scenario base_path=subpath
     *
     * @effects asset_urls_follow_the_site_base_path
     *
     * @return void 반환값 없음
     */
    public function test_base_prefix_falls_back_to_the_configured_path(): void
    {
        AssetUrl::forceBasePrefix(null);
        config(['app.url' => 'https://example.test/g7']);

        app()->forgetInstance('request');

        $this->assertSame('/g7', AssetUrl::basePrefix());
    }

    /**
     * 오리진은 설정이 권위다 — 링크 유무로 스킴이 갈리지 않는다.
     *
     * 형제 갈래(링크 있음)는 `filesystems.disks.public.url` 을 쓰고 그 기본값이
     * `APP_URL` 파생이다. 여기서 요청을 우선하면 TLS 를 앞단에서 끝내는 프록시 뒤에서
     * 같은 자산이 한쪽은 `https://`, 한쪽은 `http://` 로 나가고 브라우저가 후자를
     * 혼합 콘텐츠로 조용히 차단한다.
     *
     * @effects absolute_asset_urls_take_the_configured_origin
     *
     * @return void 반환값 없음
     */
    public function test_absolute_prefers_the_configured_origin_over_the_request_scheme(): void
    {
        config(['app.url' => 'https://example.test']);

        $request = Request::create('http://example.test/admin');
        app()->instance('request', $request);

        $this->assertSame('https://example.test/api/x.png', AssetUrl::absolute('/api/x.png'));
    }

    /**
     * 요청이 없어도 절대 URL 이다 (큐·콘솔).
     *
     * @effects absolute_asset_urls_take_the_configured_origin
     *
     * @return void 반환값 없음
     */
    public function test_absolute_yields_an_origin_without_a_request(): void
    {
        config(['app.url' => 'https://example.test']);
        app()->forgetInstance('request');

        $this->assertSame('https://example.test/api/x.png', AssetUrl::absolute('/api/x.png'));
    }

    /**
     * blade 세 곳이 서버 접두사를 **호출로** 얻는다.
     *
     * vitest 하네스는 그 값을 인자로 주입하므로 blade 의 실제 배선을 보지 않는다 —
     * `@json(AssetUrl::basePrefix())` 를 `''` 로 바꿔도 프론트 테스트가 전부 초록이다.
     *
     * 자가 복구 실행 코드는 strict CSP 대응으로 정적 파일(`g7-shell-head.js`)로 옮겨졌고,
     * 서버 값은 `partials/template-externals-head` 의 비실행 JSON 데이터 블록으로 넘긴다.
     * 그래서 세 번째 배선 지점은 그 파샬이고, 정적 파일이 그 블록의 `basePath` 를 읽는지도 함께 본다.
     *
     * @effects blade_entrypoints_wire_the_server_base_prefix
     *
     * @return void 반환값 없음
     */
    public function test_blade_entrypoints_wire_the_server_base_prefix(): void
    {
        foreach ([
            'resources/views/admin.blade.php',
            'resources/views/app.blade.php',
            'resources/views/partials/template-externals-head.blade.php',
        ] as $relative) {
            $this->assertStringContainsString(
                'AssetUrl::basePrefix()',
                (string) file_get_contents(base_path($relative)),
                $relative.' 가 서버 접두사를 호출로 얻지 않는다 — 하위 폴더 배치에서 프론트가 접두사를 모른다'
            );
        }

        $this->assertStringContainsString(
            "'basePath' => \\App\\Support\\AssetUrl::basePrefix()",
            (string) file_get_contents(base_path('resources/views/partials/template-externals-head.blade.php')),
            'template-externals-head 의 셸 헤드 데이터 블록이 basePath 를 싣지 않는다'
        );
        $this->assertMatchesRegularExpression(
            '/BASE_PATH\s*=\s*headData\.basePath/',
            (string) file_get_contents(base_path('public/assets/core/js/g7-shell-head.js')),
            'g7-shell-head.js 가 데이터 블록의 basePath 를 읽지 않는다 — 자가 복구가 하위 폴더 접두사를 모른다'
        );
    }
}
