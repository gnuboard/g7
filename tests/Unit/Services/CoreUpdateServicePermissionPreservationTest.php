<?php

namespace Tests\Unit\Services;

use App\Services\CoreUpdateService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * CoreUpdateService 권한 정확 복원 회귀 테스트.
 *
 * sudo 코어 업데이트 후 storage/app 사용자 데이터 권한이 깨지는 회귀 차단:
 *
 *   - snapshotOwnershipDetailed: 좁힌 영역의 항목별 owner/group/perms 재귀 스냅샷
 *   - restoreOwnership: detailed snapshot 기반 항목별 정확 복원
 *   - storage/app/{modules,plugins,attachments,...} 는 detailed/snapshot 둘 다 비대상
 *     → snapshot 후 변형된 상태가 그대로 유지되어야 함 (사용자 데이터 비대상 보장)
 *
 * 본 테스트는 권한 비트(perms) 변경 검증에 집중. owner 변경은 root 가 아닌 일반 user
 * 환경에서 다른 user 로 chown 호출 자체가 실패하므로 best-effort 로 검증.
 */
class CoreUpdateServicePermissionPreservationTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (File::isDirectory($dir)) {
                @chmod($dir, 0775);
                $this->relaxTreePermissions($dir);
                File::deleteDirectory($dir);
            }
        }

        parent::tearDown();
    }

    /**
     * Tear-down 직전 트리의 모든 항목에 0775/0664 부여 — tearDown 의 deleteDirectory 가
     * 깨진 perms (예: 0700) 환경에서 traversal 실패하지 않도록 안전화.
     */
    private function relaxTreePermissions(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            @chmod($item->getPathname(), $item->isDir() ? 0775 : 0664);
        }
    }

    private function createTempDir(): string
    {
        $dir = storage_path('test_perm_preservation_'.uniqid());
        File::ensureDirectoryExists($dir);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function assertPosixOrSkip(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! function_exists('posix_getuid') || ! function_exists('chown')) {
            $this->markTestSkipped('chmod/chown 검증은 POSIX 환경 전용 (Windows 로컬 자동 스킵)');
        }
    }

    // ========================================================================
    // OS 무관 시그니처/메서드 존재 검증 (Windows 에서도 fail 가능)
    // ========================================================================

    /**
     * snapshotOwnershipDetailed 메서드 존재 검증.
     *
     * @return void
     */
    public function test_snapshot_ownership_detailed_method_exists(): void
    {
        $this->assertTrue(
            method_exists(CoreUpdateService::class, 'snapshotOwnershipDetailed'),
            'CoreUpdateService::snapshotOwnershipDetailed 메서드가 정의되어야 함 (좁힌 영역의 항목별 권한 스냅샷)',
        );
    }

    /**
     * restoreOwnership 시그니처 — detailedSnapshot 인자 지원 검증.
     *
     * 호환성 유지: 기존 ($snapshot, $onProgress) positional 호출이 그대로 작동하도록
     * detailedSnapshot 은 세 번째 인자로 default 값 빈 배열.
     *
     * @return void
     */
    public function test_restore_ownership_supports_detailed_snapshot_argument(): void
    {
        $reflection = new \ReflectionMethod(CoreUpdateService::class, 'restoreOwnership');
        $params = $reflection->getParameters();
        $names = array_map(fn ($p) => $p->getName(), $params);

        $this->assertContains(
            'detailedSnapshot',
            $names,
            'restoreOwnership 가 detailedSnapshot 인자를 받아야 함 (항목별 정확 복원 인프라)',
        );

        // 기존 호출 호환성: snapshot + onProgress 인자가 첫 두 자리에 유지되어야 함
        $this->assertSame('snapshot', $names[0] ?? null, '첫 인자는 snapshot');
        $this->assertSame('onProgress', $names[1] ?? null, '두 번째 인자는 onProgress (호환성 유지)');
    }

    // ========================================================================
    // POSIX 환경 전용 — 실제 권한 동작 검증
    // ========================================================================

    /**
     * snapshotOwnershipDetailed — 트리 항목별 owner/group/perms/is_dir/is_link 수집.
     *
     * 정확한 시그니처 검증 + 빈 디렉토리는 항목 1개 (루트 자체) 만 포함.
     *
     * @return void
     */
    public function test_snapshot_ownership_detailed_collects_recursive_metadata(): void
    {
        $this->assertPosixOrSkip();

        $root = $this->createTempDir();
        $logsDir = $root.'/logs';
        File::ensureDirectoryExists($logsDir);
        chmod($logsDir, 0775);

        $logFile = $logsDir.'/test.log';
        file_put_contents($logFile, 'log content');
        chmod($logFile, 0664);

        $service = app(CoreUpdateService::class);

        // base_path 상대가 아닌 절대 경로 직접 지정 가능하도록 메서드 설계 검증.
        // 본 테스트에서는 절대경로 인자도 처리되는지 확인.
        $snapshot = $service->snapshotOwnershipDetailed([$root]);

        $this->assertArrayHasKey($root, $snapshot, '루트 디렉토리 항목 포함');
        $this->assertArrayHasKey($logsDir, $snapshot, '하위 디렉토리 항목 포함');
        $this->assertArrayHasKey($logFile, $snapshot, '하위 파일 항목 포함');

        $this->assertSame(0775, $snapshot[$logsDir]['perms'], 'perms 정확 기록');
        $this->assertSame(0664, $snapshot[$logFile]['perms'], '파일 perms 정확 기록');
        $this->assertTrue($snapshot[$logsDir]['is_dir'], 'is_dir true');
        $this->assertFalse($snapshot[$logFile]['is_dir'], 'is_dir false for file');
        $this->assertFalse($snapshot[$logFile]['is_link'], 'is_link false for regular file');

        $this->assertIsInt($snapshot[$logsDir]['owner']);
        $this->assertIsInt($snapshot[$logsDir]['group']);
    }

    /**
     * snapshotOwnershipDetailed — 존재하지 않는 path 는 결과에서 제외.
     *
     * @return void
     */
    public function test_snapshot_ownership_detailed_skips_missing_paths(): void
    {
        $this->assertPosixOrSkip();

        $service = app(CoreUpdateService::class);
        $snapshot = $service->snapshotOwnershipDetailed(['/nonexistent_path_'.uniqid()]);

        $this->assertSame([], $snapshot);
    }

    /**
     * restoreOwnership(detailed) — 변형된 perms 가 원본으로 정확 복원.
     *
     * 시나리오: 0775 디렉토리 + 0664 파일을 snapshot → chmod 로 0700/0600 변형 → restore
     * → snapshot 기준으로 0775/0664 정확 복원 검증.
     *
     * @return void
     */
    public function test_restore_ownership_detailed_recovers_perms_to_snapshot_state(): void
    {
        $this->assertPosixOrSkip();

        $root = $this->createTempDir();
        $childDir = $root.'/child';
        File::ensureDirectoryExists($childDir);
        chmod($childDir, 0775);

        $childFile = $childDir.'/data.txt';
        file_put_contents($childFile, 'x');
        chmod($childFile, 0664);

        $service = app(CoreUpdateService::class);
        $detailedSnapshot = $service->snapshotOwnershipDetailed([$root]);

        // sudo 변형 모방 — 자식 디렉토리/파일을 0700/0600 으로 좁힘
        chmod($childDir, 0700);
        chmod($childFile, 0600);

        // restore — detailed snapshot 으로 정확 복원
        $service->restoreOwnership(snapshot: [], onProgress: null, detailedSnapshot: $detailedSnapshot);

        $this->assertSame(0775, fileperms($childDir) & 0777, '디렉토리 perms 원본 복원');
        $this->assertSame(0664, fileperms($childFile) & 0777, '파일 perms 원본 복원');
    }

    /**
     * restoreOwnership(detailed) — detailed 미포함 path 는 기존 chownRecursive 동작 유지.
     *
     * 호환성 회귀 차단: 기존 호출(`restoreOwnership($snapshot, $onProgress)`)이 그대로
     * 작동하며, detailed snapshot 인자가 default(빈 배열) 일 때 기존 로직만 실행.
     *
     * @return void
     */
    public function test_restore_ownership_backward_compatible_when_detailed_empty(): void
    {
        $this->assertPosixOrSkip();

        $service = app(CoreUpdateService::class);

        // 빈 snapshot + 빈 detailed → no-op. 예외 없이 종료해야 함.
        $service->restoreOwnership([], null);
        $service->restoreOwnership([], null, []);

        $this->addToAssertionCount(1); // 시그니처 호환성 자체가 검증 포인트
    }

    // ========================================================================
    // 트랙 1 — Upgrade_7_0_0_beta_4 의 normalizeStorageAppPermissionsForLegacyParent
    //
    // beta3 → beta4+ transition 한정 우회: spawn 자식 (sudo root) 이 storage/app 트리에
    // 직접 chmod 0755/0644 적용 → 부모(beta3) chown jjh + g+w 후 최종 0775/0664 →
    // PHP-FPM other 비트로 access OK (boot 트리거의 PHP-FPM 권한 제약 회피).
    // ========================================================================

    /**
     * normalizeStorageAppPermissionsForLegacyParent 메서드가 Upgrade_7_0_0_beta_4 에 존재.
     *
     * 작동 불가 메커니즘 (recordPermissionSnapshotForLegacyParent + boot 트리거) 대체.
     *
     * @return void
     */
    public function test_upgrade_beta_4_has_normalize_storage_app_permissions_method(): void
    {
        require_once base_path('upgrades/Upgrade_7_0_0_beta_4.php');
        $reflection = new \ReflectionClass(\App\Upgrades\Upgrade_7_0_0_beta_4::class);

        $this->assertTrue(
            $reflection->hasMethod('normalizeStorageAppPermissionsForLegacyParent'),
            'Upgrade_7_0_0_beta_4 에 normalizeStorageAppPermissionsForLegacyParent 메서드 정의 필요 '
                .'(spawn 자식 root 권한으로 storage/app chmod 0755/0644 적용)',
        );
    }

    /**
     * 작동 불가 메커니즘 제거 검증 — recordPermissionSnapshotForLegacyParent 부재.
     *
     * boot 트리거 (PermissionRestoreHelper) 가 PHP-FPM 권한 제약으로 작동 못 함이
     * 확인됨. 마커 작성 단계도 의미 없으므로 함께 제거.
     *
     * @return void
     */
    public function test_upgrade_beta_4_no_longer_has_record_permission_snapshot_method(): void
    {
        require_once base_path('upgrades/Upgrade_7_0_0_beta_4.php');
        $reflection = new \ReflectionClass(\App\Upgrades\Upgrade_7_0_0_beta_4::class);

        $this->assertFalse(
            $reflection->hasMethod('recordPermissionSnapshotForLegacyParent'),
            '작동 불가 메커니즘은 제거되어야 함 (PermissionRestoreHelper boot 트리거가 PHP-FPM 권한 제약으로 silent fail)',
        );
    }

    /**
     * PermissionRestoreHelper 클래스 자체 제거 — 작동 불가 메커니즘 cleanup.
     *
     * @return void
     */
    public function test_permission_restore_helper_class_removed(): void
    {
        $this->assertFalse(
            class_exists(\App\Extension\Helpers\PermissionRestoreHelper::class),
            'PermissionRestoreHelper 는 권한 모델상 작동 불가 — 제거되어야 함',
        );
    }

    /**
     * 사용자 데이터 비대상 보장 — detailed snapshot 에 미포함된 영역은 손대지 않음.
     *
     * 시나리오: storage/app/modules 모방 디렉토리를 0700 PHP-FPM owner 로 시드한 뒤
     * detailed snapshot 에 미포함 상태에서 restore 호출 → 변경 0건 검증.
     *
     * @return void
     */
    public function test_restore_ownership_does_not_touch_paths_outside_detailed_snapshot(): void
    {
        $this->assertPosixOrSkip();

        $tempRoot = $this->createTempDir();

        // detailed 대상 영역 (예: storage/logs) — snapshot 수집
        $detailedRoot = $tempRoot.'/logs';
        File::ensureDirectoryExists($detailedRoot);
        chmod($detailedRoot, 0775);

        // detailed 비대상 영역 (예: storage/app/modules) — 시드 owner 0700 으로 세팅
        $userDataDir = $tempRoot.'/app_modules';
        File::ensureDirectoryExists($userDataDir);
        chmod($userDataDir, 0700);
        $userDataFile = $userDataDir.'/image.jpg';
        file_put_contents($userDataFile, 'image');
        chmod($userDataFile, 0600);

        $userDataDirOwnerBefore = fileowner($userDataDir);
        $userDataDirPermsBefore = fileperms($userDataDir) & 0777;
        $userDataFilePermsBefore = fileperms($userDataFile) & 0777;

        $service = app(CoreUpdateService::class);
        $detailedSnapshot = $service->snapshotOwnershipDetailed([$detailedRoot]);

        // restore — userDataDir 는 detailed 미포함이라 손대지 않아야 함
        $service->restoreOwnership(snapshot: [], onProgress: null, detailedSnapshot: $detailedSnapshot);

        $this->assertSame($userDataDirOwnerBefore, fileowner($userDataDir), '사용자 데이터 owner 무변경');
        $this->assertSame($userDataDirPermsBefore, fileperms($userDataDir) & 0777, '사용자 데이터 디렉토리 perms 무변경');
        $this->assertSame($userDataFilePermsBefore, fileperms($userDataFile) & 0777, '사용자 데이터 파일 perms 무변경');
    }
}
