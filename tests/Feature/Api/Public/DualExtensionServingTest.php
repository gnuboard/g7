<?php

namespace Tests\Feature\Api\Public;

use App\Enums\ExtensionStatus;
use App\Http\Controllers\Api\Public\AssetProbeController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 자산 URL 이중 모드 서빙 계약 테스트 (이슈 #486 단위 A).
 *
 * 라우트가 두 형태로 등록되었다는 것만으로는 부족하다 — 확장자 없는 형태가
 * 확장자 형태와 **같은 응답**을 주고 **같은 권한 가드**를 받아야 실제로 대체재가 된다.
 * 여기서는 그 등가성을 실제 HTTP 요청으로 검증한다.
 */
class DualExtensionServingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 감지 프로브의 확장자 형태가 매직 토큰을 반환해야 한다.
     */
    public function test_프로브_확장자_형태가_매직_토큰을_반환한다(): void
    {
        $response = $this->get('/api/system/asset-probe.js');

        $response->assertOk();
        $this->assertStringContainsString(AssetProbeController::PROBE_TOKEN, $response->getContent());
        $this->assertStringContainsString('javascript', $response->headers->get('Content-Type'));
    }

    /**
     * 감지 프로브의 대조군(확장자 없는 형태)도 동일한 토큰을 반환해야 한다.
     */
    public function test_프로브_대조군이_동일한_토큰을_반환한다(): void
    {
        $response = $this->get('/api/system/asset-probe');

        $response->assertOk();
        $this->assertStringContainsString(AssetProbeController::PROBE_TOKEN, $response->getContent());
    }

    /**
     * 프로브는 캐시되지 않아야 한다.
     *
     * 캐시되면 관리자가 서버 설정을 고친 뒤 재감지해도 옛 판정이 반복된다.
     */
    public function test_프로브_응답이_캐시되지_않는다(): void
    {
        foreach (['/api/system/asset-probe.js', '/api/system/asset-probe'] as $uri) {
            $cacheControl = $this->get($uri)->headers->get('Cache-Control');

            $this->assertStringContainsString('no-store', $cacheControl, "{$uri} 가 no-store 아님");
        }
    }

    /**
     * 템플릿 자산은 경로 세그먼트 형태와 `?file=` 형태가 동일한 내용을 반환해야 한다.
     */
    public function test_템플릿_자산이_두_형태에서_동일한_내용을_반환한다(): void
    {
        $assetPath = 'js/components.iife.js';

        if (! file_exists(base_path("templates/sirsoft-basic/dist/{$assetPath}"))) {
            $this->markTestSkipped('sirsoft-basic 빌드 산출물이 없어 자산 서빙 등가성을 검증할 수 없습니다.');
        }

        // 자산 서빙은 활성 상태의 템플릿 레코드를 요구한다 (TemplateService::getAssetFilePath)
        $this->activateTemplate('sirsoft-basic');

        $viaSegment = $this->get("/api/templates/assets/sirsoft-basic/{$assetPath}");
        $viaQuery = $this->get('/api/templates/assets/sirsoft-basic?file='.urlencode($assetPath));

        $viaSegment->assertOk();
        $viaQuery->assertOk();

        $this->assertSame(
            $viaSegment->streamedContent(),
            $viaQuery->streamedContent(),
            '경로 세그먼트 형태와 ?file= 형태의 응답 본문이 다르다'
        );
    }

    /**
     * `?file=` 형태도 경로 검증을 그대로 통과시켜서는 안 된다.
     *
     * 확장자 없는 모드가 보안 검증 우회 통로가 되면 안 된다.
     */
    public function test_쿼리_형태에도_경로_탈출_방어가_적용된다(): void
    {
        $response = $this->get('/api/templates/assets/sirsoft-basic?file='.urlencode('../../../.env'));

        $this->assertNotSame(200, $response->getStatusCode(), '경로 탈출이 차단되지 않았다');
    }

    /**
     * 허용되지 않은 확장자는 `?file=` 형태에서도 거부되어야 한다.
     */
    public function test_쿼리_형태에도_확장자_화이트리스트가_적용된다(): void
    {
        $response = $this->get('/api/templates/assets/sirsoft-basic?file='.urlencode('config.php'));

        $this->assertNotSame(200, $response->getStatusCode(), '허용되지 않은 확장자가 서빙되었다');
    }

    /**
     * 관리자 편집기 엔드포인트는 두 형태 모두 권한 가드를 받아야 한다.
     *
     * 확장자 없는 형태에 미들웨어가 빠지면 무인증 우회 통로가 열린다.
     */
    public function test_편집기_엔드포인트가_두_형태_모두_무인증을_거부한다(): void
    {
        $uris = [
            '/api/admin/templates/sirsoft-basic/editor/components.json',
            '/api/admin/templates/sirsoft-basic/editor/components',
            '/api/admin/templates/sirsoft-basic/editor/component-styles.css',
            '/api/admin/templates/sirsoft-basic/editor/component-styles',
        ];

        foreach ($uris as $uri) {
            $status = $this->getJson($uri)->getStatusCode();

            $this->assertContains(
                $status,
                [401, 403],
                "{$uri} 가 무인증 요청을 거부하지 않았다 (상태코드 {$status})"
            );
        }
    }

    /**
     * 권한을 가진 관리자는 두 형태 모두에서 동일한 응답을 받아야 한다.
     */
    public function test_편집기_엔드포인트가_두_형태에서_동일한_응답을_반환한다(): void
    {
        $token = $this->createEditorAdminToken();

        $viaExtension = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/admin/templates/sirsoft-basic/editor/components.json');
        $viaExtensionless = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/admin/templates/sirsoft-basic/editor/components');

        $this->assertSame(
            $viaExtension->getStatusCode(),
            $viaExtensionless->getStatusCode(),
            '두 형태의 상태코드가 다르다'
        );
        $this->assertSame(
            $viaExtension->getContent(),
            $viaExtensionless->getContent(),
            '두 형태의 응답 본문이 다르다'
        );
    }

    /**
     * 자산 서빙을 위해 활성 상태의 템플릿 레코드를 만듭니다.
     *
     * @param  string  $identifier  템플릿 식별자
     */
    private function activateTemplate(string $identifier): void
    {
        Template::updateOrCreate(['identifier' => $identifier], [
            'vendor' => 'sirsoft',
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
        ]);
    }

    /**
     * 레이아웃 편집 권한을 가진 관리자 토큰을 생성합니다.
     *
     * @return string Sanctum 평문 토큰
     */
    private function createEditorAdminToken(): string
    {
        $permission = Permission::firstOrCreate([
            'identifier' => 'core.templates.layouts.edit',
        ], [
            'name' => '레이아웃 편집',
            'display_name' => '레이아웃 편집',
            'type' => 'admin',
        ]);

        $role = Role::firstOrCreate(['identifier' => 'super-admin'], [
            'name' => 'Super Admin',
            'display_name' => 'Super Admin',
            'is_default' => false,
        ]);
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $user = User::factory()->create();
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->createToken('admin')->plainTextToken;
    }
}
