<?php

namespace Tests\Unit\Extension;

use App\Extension\Helpers\ExtensionPendingHelper;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 확장 업데이트의 코어 버전 사전 검증이 읽는 값 (7.2.1 출시 전 실측 W5-001)
 *
 * 업데이트 「3.4 코어 버전 호환성 사전 검증」 은 스테이징한 새 판의 확장 manifest(module.json·plugin.json·template.json)의
 * `g7_version` 제약을 봐야 한다. 종전에는 vendor 번들 무결성 파일(vendor-bundle.json)의 `g7_version`(번들을 만든 코어 판,
 * 연산자 없음)을 제약으로 읽어, 코어를 7.2.1 로 올린 뒤 관리자 화면에서 이커머스·팝업·SSO 를 올리면
 * 「코어 7.2.0 이상 필요(현재 7.2.1)」 로 실패했다.
 */
class StagedCoreConstraintTest extends TestCase
{
    private string $dir;

    /**
     * 임시 스테이징 폴더를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/staged-core-constraint-'.uniqid());
        File::ensureDirectoryExists($this->dir);
    }

    /**
     * 임시 스테이징 폴더를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /**
     * 확장 manifest 의 g7_version 제약을 읽고 vendor-bundle.json 의 번들 생성 판은 쓰지 않습니다.
     *
     * @scenario case=staged_manifest_constraint
     *
     * @effects update_precheck_reads_extension_manifest
     *
     * @return void 반환값 없음
     */
    public function test_reads_extension_manifest_constraint_not_vendor_bundle(): void
    {
        File::put($this->dir.'/module.json', json_encode(['identifier' => 'vendor-x', 'version' => '1.4.1', 'g7_version' => '>=7.2.1']));
        File::put($this->dir.'/vendor-bundle.json', json_encode(['g7_version' => '7.2.0']));

        $this->assertSame('>=7.2.1', ExtensionPendingHelper::readStagedCoreConstraint($this->dir, 'module.json'));
    }

    /**
     * manifest 가 없거나 제약이 비었거나 JSON 이 깨졌으면 null(검사 건너뜀)입니다.
     *
     * @scenario case=staged_manifest_missing
     *
     * @effects update_precheck_reads_extension_manifest
     *
     * @return void 반환값 없음
     */
    public function test_missing_or_empty_constraint_is_null(): void
    {
        $this->assertNull(ExtensionPendingHelper::readStagedCoreConstraint($this->dir, 'plugin.json'));

        File::put($this->dir.'/plugin.json', json_encode(['identifier' => 'vendor-x']));
        $this->assertNull(ExtensionPendingHelper::readStagedCoreConstraint($this->dir, 'plugin.json'));

        File::put($this->dir.'/template.json', '{broken');
        $this->assertNull(ExtensionPendingHelper::readStagedCoreConstraint($this->dir, 'template.json'));
    }

    /**
     * 모듈·플러그인·템플릿 관리자의 업데이트 사전 검증이 이 헬퍼로 자기 manifest 를 읽습니다(vendor 무결성 파일을 읽지 않음).
     *
     * @scenario case=manager_precheck_wiring
     *
     * @effects update_precheck_reads_extension_manifest
     *
     * @return void 반환값 없음
     */
    public function test_managers_precheck_uses_extension_manifest(): void
    {
        foreach (['ModuleManager' => 'module.json', 'PluginManager' => 'plugin.json', 'TemplateManager' => 'template.json'] as $class => $manifest) {
            $src = File::get(app_path("Extension/{$class}.php"));
            $pos = strpos($src, '코어 버전 호환성 사전 검증 (staging');
            $this->assertNotFalse($pos, "{$class}: 사전 검증 절을 찾지 못했습니다");
            $block = substr($src, $pos, 1200);
            $this->assertStringContainsString("readStagedCoreConstraint(\$stagingPath, '{$manifest}')", $block, $class);
            $this->assertStringNotContainsString('VendorIntegrityChecker', $block, $class);
        }
    }
}
