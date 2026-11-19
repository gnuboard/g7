<?php

namespace Tests\Unit\Extension;

use App\Extension\Support\PendingBundledExtensionClass;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 「새 판에만 있는 확장 클래스」로 인한 선언 재동기화 실패를 실패가 아니라 「확장 갱신이 이어서 한다」로 가른다.
 *
 * 실측(7.1.6 → 7.2.0): 코어 스텝 `SyncActiveExtensionSchedules` 가 환율 플러그인을 새 판 선언(`_bundled`)으로 다시
 * 동기화하다 `Class "Plugins\Sirsoft\Koreaexim\Services\UpdateScheduleResolver" not found` 로 실패해 업그레이드 로그에
 * 「sync 실패」 경고가 남았다. 그 클래스는 7.2.0 에 새로 생겨 활성 폴더에 아직 없었을 뿐이고, 바로 뒤 확장 갱신이
 * 스케줄을 정상으로 만들었다 — 운영자가 고칠 일이 아닌 오경보다.
 */
class PendingBundledExtensionClassTest extends TestCase
{
    private string $typeRoot;

    /**
     * 시험용 임시 트리와 대체 함수를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->typeRoot = storage_path('framework/testing/pending-class-'.uniqid());
        File::ensureDirectoryExists($this->typeRoot.'/_bundled/sirsoft-koreaexim/src/Services');
        File::ensureDirectoryExists($this->typeRoot.'/sirsoft-koreaexim/src/Services');
        File::put($this->typeRoot.'/_bundled/sirsoft-koreaexim/src/Services/UpdateScheduleResolver.php', '<?php');
    }

    /**
     * 임시 트리와 대체 함수를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->typeRoot);

        parent::tearDown();
    }

    /**
     * 새 판(`_bundled`)에만 있는 클래스면 그 이름을 돌려준다 — 실측 메시지 그대로.
     *
     * @scenario case=class_only_in_bundled
     *
     * @effects pending_bundled_class_detected
     *
     * @return void 반환값 없음
     */
    public function test_detects_class_present_only_in_bundled(): void
    {
        $class = PendingBundledExtensionClass::fromMessage(
            'Class "Plugins\\Sirsoft\\Koreaexim\\Services\\UpdateScheduleResolver" not found',
            'Plugins',
            $this->typeRoot.'/_bundled/sirsoft-koreaexim',
            $this->typeRoot.'/sirsoft-koreaexim',
        );

        $this->assertSame('Plugins\\Sirsoft\\Koreaexim\\Services\\UpdateScheduleResolver', $class);
    }

    /**
     * 활성 폴더에도 있는 클래스·다른 오류·다른 이름공간은 진짜 실패로 남긴다.
     *
     * @scenario case=genuine_failure
     *
     * @effects genuine_failures_kept
     *
     * @return void 반환값 없음
     */
    public function test_genuine_failures_are_not_deferred(): void
    {
        File::put($this->typeRoot.'/sirsoft-koreaexim/src/Services/UpdateScheduleResolver.php', '<?php');

        $this->assertNull(PendingBundledExtensionClass::fromMessage(
            'Class "Plugins\\Sirsoft\\Koreaexim\\Services\\UpdateScheduleResolver" not found',
            'Plugins',
            $this->typeRoot.'/_bundled/sirsoft-koreaexim',
            $this->typeRoot.'/sirsoft-koreaexim',
        ), '활성 폴더에도 있으면 이연 사유가 아니다');

        $this->assertNull(PendingBundledExtensionClass::fromMessage(
            'SQLSTATE[42S02]: Base table or view not found',
            'Plugins',
            $this->typeRoot.'/_bundled/sirsoft-koreaexim',
            $this->typeRoot.'/sirsoft-koreaexim',
        ));

        $this->assertNull(PendingBundledExtensionClass::fromMessage(
            'Class "Modules\\Sirsoft\\Board\\Fields\\GuestAuthorFields" not found',
            'Plugins',
            $this->typeRoot.'/_bundled/sirsoft-koreaexim',
            $this->typeRoot.'/sirsoft-koreaexim',
        ), '다른 확장 종류의 이름공간');

        $this->assertNull(PendingBundledExtensionClass::fromMessage(
            'Class "Plugins\\Sirsoft\\Koreaexim\\Services\\NoSuchClass" not found',
            'Plugins',
            $this->typeRoot.'/_bundled/sirsoft-koreaexim',
            $this->typeRoot.'/sirsoft-koreaexim',
        ), '새 판에도 없는 클래스');
    }

    /**
     * 모듈·플러그인 매니저의 일괄 재동기화가 이 판정으로 실패와 이연을 가른다.
     *
     * @scenario case=manager_wiring
     *
     * @effects managers_defer_pending_bundled_class_failures
     *
     * @return void 반환값 없음
     */
    public function test_managers_route_pending_class_failures_to_deferred(): void
    {
        foreach ([app_path('Extension/ModuleManager.php'), app_path('Extension/PluginManager.php')] as $file) {
            $source = (string) file_get_contents($file);
            $start = strpos($source, 'public function resyncAllActiveDeclarativeArtifacts(): array');
            $this->assertNotFalse($start, basename($file));
            $body = substr($source, $start, 3000);

            $this->assertStringContainsString('PendingBundledExtensionClass::fromMessage(', $body, basename($file));
            $this->assertStringContainsString("'deferred' => \$deferred", $body, basename($file));
        }
    }
}
