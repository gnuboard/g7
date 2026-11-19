<?php

namespace Tests\Feature\Extension;

use App\Extension\Helpers\ExtensionBackupHelper;
use App\Extension\Helpers\ExtensionPendingHelper;
use App\Extension\Support\CarriedClassFileLedger;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 정리 대기 목록의 안전성 — 확실하지 않으면 지우지 않고, 목록 쓰기는 잠금·원자 교체로, 백업 복원은 목록도 되돌린다.
 *
 * @scenario-manifest tests/scenarios/carried-class-file-ledger-safety.yaml
 */
class CarriedClassFileLedgerSafetyTest extends TestCase
{
    /** @var string 시험 루트 */
    private string $base;

    /** @var CarriedClassFileLedger 시험 경로를 쓰는 대기 목록 */
    private CarriedClassFileLedger $ledger;

    /**
     * 시험 루트와 대기 목록을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->base = storage_path('app/test-ledger-safety-'.uniqid());
        File::ensureDirectoryExists($this->base.'/active/src/Listeners');
        File::put($this->base.'/active/src/Listeners/Old.php', '<?php // old');

        $ledgerPath = $this->base.'/ledger.json';
        $this->ledger = new class($ledgerPath) extends CarriedClassFileLedger
        {
            /**
             * 시험 경로를 쓰는 대기 목록을 만듭니다.
             *
             * @param  string  $file  대기 목록 파일 경로
             */
            public function __construct(private string $file)
            {
                $this->lockWaitSeconds = 1;
            }

            /**
             * 시험 경로를 돌려줍니다.
             *
             * @return string 경로
             */
            public function path(): string
            {
                return $this->file;
            }
        };
        $this->app->instance(CarriedClassFileLedger::class, $this->ledger);
    }

    /**
     * 시험 루트를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    /**
     * 실을 때 내용 지문을 뜨지 못한 항목(지문 없음)은 정리 때 지우지 않는다 — 내용을 확인할 수 없으면 남긴다.
     *
     * @scenario case=unknown_hash
     *
     * @effects ledger_never_deletes_unverified_file
     *
     * @return void 반환값 없음
     */
    public function test_entry_without_hash_is_not_deleted(): void
    {
        $active = $this->base.'/active';
        File::put($this->ledger->path(), (string) json_encode([
            str_replace('\\', '/', $active) => ['active' => $active, 'recorded_at' => time() - CarriedClassFileLedger::MIN_AGE_SECONDS - 1, 'files' => ['src/Listeners/Old.php' => null]],
        ]));

        $this->ledger->sweep();

        $this->assertFileExists($active.'/src/Listeners/Old.php', '지문이 없는(내용을 확인할 수 없는) 파일을 지웠다');
    }

    /**
     * 다른 프로세스가 대기 목록을 쓰는 중(잠금 보유)이면 겹쳐 쓰지 않는다 — 기다렸다가 넘기며 상대 기록을 덮지 않는다.
     *
     * @scenario case=concurrent_write
     *
     * @effects ledger_writes_are_locked_and_atomic
     *
     * @return void 반환값 없음
     */
    public function test_write_waits_for_lock_and_does_not_clobber(): void
    {
        $active = $this->base.'/active';
        File::put($this->ledger->path(), '{"other":{"active":"other","recorded_at":1,"files":{"src/X.php":"abc"}}}');

        $handle = fopen($this->ledger->path().'.lock', 'c+');
        $this->assertTrue(flock($handle, LOCK_EX), '시험 전제: 다른 프로세스의 잠금');

        try {
            $this->ledger->record($active, ['src/Listeners/Old.php']);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $this->assertSame(['other'], array_keys($this->ledger->read()), '잠금을 쥔 다른 프로세스가 있는데 대기 목록을 겹쳐 썼다');

        $this->ledger->record($active, ['src/Listeners/Old.php']);
        $this->assertArrayHasKey(str_replace('\\', '/', $active), $this->ledger->read());
        $this->assertArrayHasKey('other', $this->ledger->read(), '다른 확장의 기록을 잃었다');
        $this->assertFileDoesNotExist($this->ledger->path().'.tmp', '임시 파일이 남았다');
    }

    /**
     * 업데이트가 실패해 백업으로 되돌리면 대기 목록도 백업 시점으로 되돌린다 — 교체가 먼저 뺀 항목이 복원 폴더에 영구히 남지 않게.
     *
     * @scenario case=backup_restore
     *
     * @effects backup_restore_restores_ledger_entry
     *
     * @return void 반환값 없음
     */
    public function test_backup_restore_restores_ledger_entry(): void
    {
        $identifier = 'zz-ledger-probe-'.substr(md5(uniqid('', true)), 0, 6);
        $active = base_path('modules/'.$identifier);
        File::ensureDirectoryExists($active.'/src/Listeners');
        File::put($active.'/module.php', '<?php // v1');
        File::put($active.'/src/Listeners/Old.php', '<?php // carried');
        $backup = null;

        try {
            $this->ledger->record($active, ['src/Listeners/Old.php'], time() - 30);
            $backup = ExtensionBackupHelper::createBackup('modules', $identifier);

            // 실패할 업데이트: 새 트리(남긴 파일까지 실은 스테이징)로 교체 — 교체가 대기 목록에서 그 경로를 뺀다
            $staging = $this->base.'/staging';
            File::ensureDirectoryExists($staging.'/src/Listeners');
            File::put($staging.'/module.php', '<?php // v2');
            File::put($staging.'/src/Listeners/Old.php', '<?php // carried');
            ExtensionPendingHelper::copyToActive($staging, $active);
            $this->assertSame([], $this->ledger->read(), '시험 전제: 교체가 대기 항목을 뺐다');

            ExtensionBackupHelper::restoreFromBackup('modules', $identifier, $backup);

            $entries = $this->ledger->read();
            $key = str_replace('\\', '/', $active);
            $this->assertArrayHasKey($key, $entries, '백업 복원 뒤 대기 목록이 돌아오지 않았다 — 남긴 파일이 영구히 남는다');
            $this->assertArrayHasKey('src/Listeners/Old.php', $entries[$key]['files']);
        } finally {
            File::deleteDirectory($active);
            if ($backup !== null) {
                ExtensionBackupHelper::deleteBackup($backup);
            }
        }

        $this->assertSame([], array_keys(array_filter($this->ledger->snapshots(), fn ($k) => str_contains((string) $k, $identifier), ARRAY_FILTER_USE_KEY)), '백업을 지웠는데 대기 목록 사본이 남았다');
    }

    /**
     * 새 판이 같은 경로를 대소문자만 바꿔 출하하면 옛 파일을 싣지 않는다 — 같은 클래스가 두 파일에 있게 되지 않게.
     * 대소문자를 무시하는 파일 시스템(Windows·macOS 기본)에서는 경로가 같은 파일이라 이 경우가 생기지 않아 건너뛴다.
     *
     * @scenario case=case_only_rename
     *
     * @effects case_only_rename_not_carried
     *
     * @return void 반환값 없음
     */
    public function test_case_only_rename_is_not_carried(): void
    {
        $probe = $this->base.'/CaseProbe';
        File::put($probe, 'x');
        if (File::exists($this->base.'/caseprobe')) {
            $this->markTestSkipped('대소문자를 무시하는 파일 시스템 — 경로가 같은 파일이라 재현되지 않는다');
        }

        $staging = $this->base.'/staging-case';
        File::ensureDirectoryExists($staging.'/src/Listeners');
        File::put($staging.'/src/Listeners/old.php', '<?php // renamed case');

        $carried = ExtensionPendingHelper::carryOverRemovedClassFiles($this->base.'/active', $staging);

        $this->assertSame([], $carried, '대소문자만 바꾼 판에 옛 파일을 실어 같은 클래스가 두 파일에 있게 됐다');
    }
}
