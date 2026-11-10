<?php

namespace Modules\Sirsoft\Page\Tests\Feature;

use Modules\Sirsoft\Page\Services\PageContentMediaService;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 페이지 편집기 본문 미디어 업로드 계약 — 표준 계약 엔드포인트 `GET admin/content-media/target` (#887 G10)
 *
 * 편집기 계약을 화면에 전하는 길을 확장마다 `…/content-media/target` 한 가지로 맞췄다. 권한은 업로드와 같고(등록·수정 어느 쪽이든),
 * 값은 페이지 모듈 설정이다. 옛 길(`GET admin/attachments/settings` 의 `content_media`)은 같은 값을 계속 싣는다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-page/tests/scenarios/page-content-media-target.yaml
 */
class PageContentMediaTargetTest extends ModuleTestCase
{
    /** 표준 계약 엔드포인트 */
    private const TARGET_URL = '/api/modules/sirsoft-page/admin/content-media/target';

    /**
     * 모듈 라우트를 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerModuleRoutes();
    }

    /**
     * 등록 권한이 있으면 계약을 받는다 — 업로드 주소·서빙 주소 정규식·종류별 정책.
     *
     * @return void 반환값 없음
     *
     * @scenario permission=create
     *
     * @effects target_returns_contract_shape
     */
    public function test_create_permission_receives_contract(): void
    {
        $this->assertContractFor('sirsoft-page.pages.create');
    }

    /**
     * 수정 권한만 있어도 계약을 받는다 — 수정 화면도 같은 편집기를 쓴다.
     *
     * @return void 반환값 없음
     *
     * @scenario permission=update
     *
     * @effects target_returns_contract_shape
     */
    public function test_update_permission_receives_contract(): void
    {
        $this->assertContractFor('sirsoft-page.pages.update');
    }

    /**
     * 열람권만 있는 관리자는 403 이다(화면은 null 로 받아 편집기를 종전대로 띄운다).
     *
     * @return void 반환값 없음
     *
     * @scenario permission=read_only
     *
     * @effects target_denied_without_upload_permission
     */
    public function test_read_only_admin_is_denied(): void
    {
        $this->actingAs($this->createAdminUser(['sirsoft-page.pages.read']))->getJson(self::TARGET_URL)->assertStatus(403);
    }

    /**
     * 로그인하지 않으면 401 이다.
     *
     * @return void 반환값 없음
     *
     * @scenario permission=guest
     *
     * @effects target_denied_without_upload_permission
     */
    public function test_guest_is_rejected(): void
    {
        $this->getJson(self::TARGET_URL)->assertStatus(401);
    }

    /**
     * 받는 종류가 하나도 없으면 계약은 `null` 이다(편집기는 종전대로).
     *
     * @return void 반환값 없음
     *
     * @scenario permission=create
     *
     * @effects target_null_when_nothing_accepted
     */
    public function test_contract_is_null_when_nothing_is_accepted(): void
    {
        $off = ['enabled' => false, 'max_size_mb' => 10, 'extensions' => []];
        config(['g7_settings.modules.sirsoft-page.content_media' => [
            'image' => $off,
            'video' => $off,
            'audio' => $off,
            'max_per_document' => 30,
        ]]);

        $response = $this->actingAs($this->createAdminUser(['sirsoft-page.pages.create']))->getJson(self::TARGET_URL);

        $response->assertOk();
        $this->assertNull($response->json('data'));
    }

    /**
     * 옛 길(첨부 설정 응답의 `content_media`)은 표준 엔드포인트와 같은 값을 계속 싣는다(호환 별칭).
     *
     * @return void 반환값 없음
     *
     * @scenario permission=create
     *
     * @effects legacy_settings_alias_same_value
     */
    public function test_legacy_attachment_settings_alias_carries_same_contract(): void
    {
        $admin = $this->createAdminUser(['sirsoft-page.pages.read', 'sirsoft-page.pages.create']);

        $target = $this->actingAs($admin)->getJson(self::TARGET_URL)->assertOk()->json('data');
        $legacy = $this->actingAs($admin)->getJson('/api/modules/sirsoft-page/admin/attachments/settings')->assertOk()->json('data.content_media');

        $this->assertNotNull($target);
        $this->assertSame($target, $legacy);
    }

    /**
     * 그 권한 하나를 가진 관리자가 계약을 받는지 단언합니다.
     *
     * @param  string  $permission  관리자에게 줄 권한 식별자
     * @return void 반환값 없음
     */
    private function assertContractFor(string $permission): void
    {
        $response = $this->actingAs($this->createAdminUser([$permission]))->getJson(self::TARGET_URL);

        $response->assertOk();
        $response->assertJsonPath('data.version', 1);
        $response->assertJsonPath('data.endpoint', '/api/modules/sirsoft-page/admin/content-media');
        $response->assertJsonPath('data.fileField', 'file');
        $response->assertJsonPath('data.response.urlPath', 'data.url');
        $this->assertSame(PageContentMediaService::SERVE_PATTERN, $response->json('data.mediaUrlPattern'));
        $this->assertTrue($response->json('data.policy.image.enabled'), '이미지는 늘 받는다');
        $this->assertIsInt($response->json('data.policy.maxPerDocument'));
    }
}
