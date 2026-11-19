<?php

namespace Tests\Unit\Upgrades;

use App\Extension\Helpers\StorageLinkHelper;
use App\Extension\UpgradeContext;
use App\Upgrades\Data\V7_2_0\Migrations\MovePublicStorageBrokenBackupsOutOfDocroot;
use Illuminate\Support\Facades\Log;
use Psr\Log\AbstractLogger;
use ReflectionClass;
use Stringable;
use Tests\TestCase;

/**
 * 7.2.0 업그레이드 스텝 — 문서 루트 안의 `public/storage` 손상 백업 정리.
 *
 * 소스를 고쳐도 **이미 그 상태인 사이트는 낫지 않는다** — 링크 복구는 `public/storage` 가
 * 정상이면 즉시 반환하므로 잔해에 영영 닿지 않는다. 그 잔해는 공개 파일 전량의 사본이고,
 * 문서 루트 안에 있는 동안 제2 URL 로 계속 서빙된다(운영자가 지운 파일이 그 주소로 열린다).
 *
 * @scenario residue=none, destination=free, entry_kind=directory
 */
class MovePublicStorageBrokenBackupsOutOfDocrootTest extends TestCase
{
    private string $publicPath;

    private string $storageAppPath;

    /** @var array<int, string> */
    private array $created = [];

    /**
     * 이설 스텝 파일을 읽어 들이고 노출 경고 버퍼를 비운 뒤, 번역 그룹을 미리 올려 두고 public 과 storage/app 을 갖춘 가짜 기준 트리로 옮긴다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalBase = base_path();

        // 데이터 마이그레이션은 오토로드 대상이 아니다 — 실행기(`AbstractUpgradeStep`)가
        // `data/{version}/migrations/*.php` 를 `require_once` 로 읽는다. 테스트도 같은
        // 방식으로 읽어, 실제 실행 경로와 로딩 방식이 갈리지 않게 한다.
        require_once base_path('upgrades/data/7.2.0/migrations/04_MovePublicStorageBrokenBackupsOutOfDocroot.php');

        // 노출 통보 버퍼는 **정적**이라 테스트 사이로 샌다 — 이 클래스의 여러 케이스가
        // 「옮기지 못한 항목」을 만들므로, 진입·종료 양쪽에서 비워 격리를 지킨다.
        StorageLinkHelper::takeDocrootExposureWarnings();

        // 실 저장소의 `public/` 을 대상으로 돌지 않는다. 개발 체크아웃에 (링크 복구 폴백이
        // 남긴) `public/storage.broken.*` 가 하나라도 있으면 ① no-op 케이스가 red 가 되고
        // ② 그 잔해가 테스트에 의해 **실제로 이동**된다(tearDown 정리 목록에도 없어 돌아오지
        // 않는다). 형제 `StorageLinkHelperTest` 가 이미 격리 트리를 쓰는 선례다.
        $this->fakeBase = storage_path('app/g7-test-upgrade-'.bin2hex(random_bytes(4)));
        mkdir($this->fakeBase.'/public', 0755, true);
        mkdir($this->fakeBase.'/storage/app', 0755, true);
        $this->created[] = $this->fakeBase;

        // 번역기는 `base_path()` 기준으로 언어 파일을 읽는다 — 아래에서 베이스를 가짜
        // 트리로 바꾸면 그 뒤의 `__()` 는 파일을 찾지 못해 **키 문자열 그대로**를 돌려주고
        // 치환도 일어나지 않는다. 베이스를 바꾸기 **전**에 한 번 불러 그룹을 캐시에 올린다
        // (프로덕션에는 없는 상태이므로 테스트 격리 장치다).
        __('settings.core_update.storage_link_backup_inside_docroot', ['path' => '']);

        app()->setBasePath($this->fakeBase);

        $this->publicPath = public_path();
        $this->storageAppPath = storage_path('app');
    }

    private string $fakeBase = '';

    private string $originalBase = '';

    /**
     * 노출 경고 버퍼를 비우고 기준 경로를 되돌린 뒤 테스트가 만든 경로를 모두 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        StorageLinkHelper::takeDocrootExposureWarnings();

        app()->setBasePath($this->originalBase);

        foreach ($this->created as $path) {
            $this->removeTree($path);
        }
        $this->created = [];

        parent::tearDown();
    }

    /**
     * 이름 규칙에 **맞는** 링크·일반 파일은 옮기지 않는다 (가드 축).
     *
     * 종전 픽스처는 `g7-test-not-a-backup` 처럼 접두 검사에서 이미 걸러지는 이름이라,
     * `is_link($path) || ! is_dir($path)` 가드를 통째로 지워도 초록이었다 — 테스트 이름은
     * 그 축을 잰다고 말하고 있었다.
     *
     * @effects docroot_storage_backup_residue_is_moved_out_of_web_root
     *
     * @return void 반환값 없음
     */
    public function test_matching_links_and_plain_files_are_left_alone(): void
    {
        $plainFile = $this->publicPath.DIRECTORY_SEPARATOR.'storage.broken.20260303030303';
        file_put_contents($plainFile, 'not a directory');
        $this->created[] = $plainFile;

        $linkTarget = $this->makeResidue('storage.broken.linktarget', 'gamma.txt');
        $link = $this->publicPath.DIRECTORY_SEPARATOR.'storage.broken.20260404040404';
        $linked = @symlink($linkTarget, $link);

        if ($linked) {
            $this->created[] = $link;
        }

        $this->runMigration();

        $this->assertFileExists($plainFile, '디렉토리가 아닌 항목은 옮기지 않는다');

        // 링크를 만들지 못하는 환경에서는 **초록으로 조용히 넘어가지 않는다** — 종전에는
        // `if ($linked)` 로 건너뛰어 skip 통계에도 잡히지 않았고, 「링크는 옮기지 않는다」
        // 축이 그 환경에서 상시 미측정이었다. 형제 파일(`StorageLinkUsabilityTest`)의
        // 규약대로 명시적으로 skip 한다.
        if (! $linked) {
            $this->markTestSkipped('이 환경에서 symlink 를 만들 수 없다 — 「링크는 옮기지 않는다」 축을 측정할 수 없다');
        }

        $this->assertTrue(is_link($link), '링크를 옮기면 원본이 무엇을 가리켰는지 잃는다');
    }

    /**
     * 문서 루트에 잔해가 없으면 스텝이 아무것도 만들거나 옮기지 않는지 확인한다.
     *
     * @scenario residue=none, destination=free, entry_kind=directory
     *
     * @effects docroot_storage_backup_residue_is_moved_out_of_web_root
     *
     * @return void 반환값 없음
     */
    public function test_no_residue_is_a_no_op(): void
    {
        $before = $this->listResidue();

        $this->runMigration();

        $this->assertSame($before, $this->listResidue(), '잔해가 없으면 아무것도 만들지 않는다');
    }

    /**
     * public 안의 storage.broken 잔해 디렉토리들이 지워지지 않고 내용째 storage/app 아래 정해진 이름으로 옮겨지는지 확인한다.
     *
     * @scenario residue=present, destination=free, entry_kind=directory
     *
     * @effects docroot_storage_backup_residue_is_moved_out_of_web_root
     *
     * @return void 반환값 없음
     */
    public function test_residue_directories_are_moved_out_of_the_document_root(): void
    {
        $first = $this->makeResidue('storage.broken.20260101010101', 'alpha.txt');
        $second = $this->makeResidue('storage.broken.20260202020202', 'beta.txt');

        $this->runMigration();

        $this->assertDirectoryDoesNotExist($first, '문서 루트 안에 사본이 남으면 그 파일은 계속 웹에서 열린다');
        $this->assertDirectoryDoesNotExist($second);

        $movedFirst = $this->storageAppPath.'/public-storage-broken-20260101010101';
        $movedSecond = $this->storageAppPath.'/public-storage-broken-20260202020202';
        $this->created[] = $movedFirst;
        $this->created[] = $movedSecond;

        $this->assertFileExists($movedFirst.'/alpha.txt', '옮기기만 하고 지우지 않는다 — 그 사본이 유일하게 남은 파일일 수 있다');
        $this->assertFileExists($movedSecond.'/beta.txt');
    }

    /**
     * 옮길 자리에 이전 백업이 이미 있으면 덮어쓰지 않고, 형제 생산자와 같은 16진 접미사를 붙인 유일한 이름으로 옮기는지 확인한다.
     *
     * @scenario residue=present, destination=stale_file, entry_kind=directory
     *
     * @effects docroot_storage_backup_residue_is_moved_out_of_web_root
     *
     * @return void 반환값 없음
     */
    public function test_existing_destination_does_not_overwrite(): void
    {
        $source = $this->makeResidue('storage.broken.20260303030303', 'gamma.txt');

        $occupied = $this->storageAppPath.'/public-storage-broken-20260303030303';
        mkdir($occupied, 0755, true);
        file_put_contents($occupied.'/keep-me.txt', 'previous run');
        $this->created[] = $occupied;

        $this->runMigration();

        $this->assertDirectoryDoesNotExist($source);
        $this->assertFileExists($occupied.'/keep-me.txt', '먼저 있던 백업을 덮어쓰지 않는다');

        // 대체 이름의 **모양**을 못박지 않는다.
        //
        // 종전에는 `-1` 카운터를 리터럴로 적었는데, 그 방식은 상한이 없고 `file_exists`
        // 판정과 `rename` 사이가 벌어져 두 실행이 겹치면 같은 이름을 고른다. 형제 생산자와
        // 같은 난수 접미사로 바꾸면서, 이 단언도 「유일한 이름으로 옮겼다」는 **성질**로
        // 옮긴다.
        $alternates = glob($occupied.'-*') ?: [];

        $this->assertCount(1, $alternates, '이름이 겹치면 유일한 이름으로 옮긴다');
        $this->created[] = $alternates[0];
        $this->assertFileExists($alternates[0].'/gamma.txt');
        $this->assertMatchesRegularExpression(
            '/-[0-9a-f]{8}$/',
            $alternates[0],
            '대체 이름의 접미사가 형제 생산자와 같은 규칙(16진)이 아니다'
        );
    }

    /**
     * 이름 규칙에 맞지 않는 항목은 건드리지 않는다.
     *
     * 종전 이름은 `..._and_symlinks_...` 였지만 본문은 일반 디렉토리 하나뿐이라 symlink
     * 축을 재지 않았다 — 링크를 옮기는 변이를 이 케이스는 잡지 못한다. 그 축은 위
     * `test_matching_links_and_plain_files_are_left_alone` 이 소유하므로 이름을 사실에
     * 맞춘다.
     *
     * @scenario residue=present, entry_kind=directory
     *
     * @effects docroot_storage_backup_residue_is_moved_out_of_web_root
     *
     * @return void 반환값 없음
     */
    public function test_unrelated_entries_are_left_alone(): void
    {
        $unrelated = $this->publicPath.'/g7-test-not-a-backup';
        mkdir($unrelated, 0755, true);
        $this->created[] = $unrelated;

        $this->runMigration();

        $this->assertDirectoryExists($unrelated, '이름 규칙에 맞지 않는 디렉토리는 건드리지 않는다');
    }

    /**
     * **옮기지 못한 항목**은 로그 파일이 아니라 운영자에게 도달한다.
     *
     * 이 스텝이 존재하는 이유가 「문서 루트 안의 공개 파일 사본 제거」인데, 옮기지 못한
     * 항목은 그 상태가 **그대로 남았다**는 사실이다. 종전에는 그 사실이 `upgrade` 로그
     * 채널에만 남아, 형제 생산자(`StorageLinkHelper::ensurePublicStorageLink()`)가 같은
     * 사실을 콘솔·업데이트 기록까지 보내는 것과 등급이 갈렸다 — 운영자는 「정리 완료」만
     * 보고 노출을 알 수 없다.
     *
     * @scenario move_outcome=skipped, move_outcome=all_moved, warning_channel=console_buffer, warning_channel=log_only
     *
     * @effects docroot_storage_backup_residue_is_moved_out_of_web_root
     *
     * @return void 반환값 없음
     */
    public function test_entries_left_inside_the_document_root_reach_the_operator_channel(): void
    {
        // 이름 규칙에는 맞지만 디렉토리가 아니라 옮기지 않는 항목 — 그 경로는 문서 루트
        // 안에 그대로 남아 웹에서 열린다.
        $plainFile = $this->publicPath.DIRECTORY_SEPARATOR.'storage.broken.20260505050505';
        file_put_contents($plainFile, 'not a directory');
        $this->created[] = $plainFile;

        $this->runMigration();

        $warnings = StorageLinkHelper::takeDocrootExposureWarnings();

        $this->assertNotEmpty(
            $warnings,
            '옮기지 못한 항목이 운영자 통보 통로에 실리지 않는다 — 노출 사실이 로그 파일에만 남는다'
        );
        $this->assertStringContainsString(
            $plainFile,
            implode("\n", $warnings),
            '통보가 남은 경로를 싣지 않으면 운영자는 무엇을 확인해야 할지 알 수 없다'
        );
    }

    /**
     * 이동 대상 디렉토리(`storage/app`)가 없어도 **노출 사실은 통보된다.**
     *
     * 종전에는 `storage/app` 부재를 info 한 줄로 넘기고 반환했다 — 그런데 그 사이트에
     * 잔해가 있으면 그 사본은 여전히 문서 루트 안에서 열리고, 옮길 곳이 없다는 것은
     * 노출이 **계속된다**는 뜻이지 정리가 끝났다는 뜻이 아니다. 같은 상태를 옮기기 실패
     * 경로는 통보 버퍼에 싣는데 이 갈래만 로그 파일에서 끝났다.
     *
     * @scenario residue=present, destination=missing
     *
     * @effects docroot_storage_backup_residue_is_moved_out_of_web_root
     *
     * @return void 반환값 없음
     */
    public function test_missing_destination_directory_still_reports_docroot_exposure(): void
    {
        $residue = $this->makeResidue('storage.broken.20260707070707', 'epsilon.txt');
        $this->removeTree($this->storageAppPath);

        $this->runMigration();

        $this->assertDirectoryExists($residue, '옮길 곳이 없으면 잔해는 그 자리에 남는다');

        $warnings = StorageLinkHelper::takeDocrootExposureWarnings();
        $this->assertNotEmpty($warnings, '옮길 곳이 없어 남은 잔해가 통보되지 않으면 노출이 로그 파일에만 남는다');
        // 스텝은 `DIRECTORY_SEPARATOR` 로 경로를 조립하고 픽스처는 `/` 로 만든다 — 이름으로 대조한다.
        $this->assertStringContainsString(basename($residue), implode("\n", $warnings));
    }

    /**
     * 대조군 — 전부 옮겼으면 노출 통보는 없다.
     *
     * 이 대조군이 없으면 「언제나 경고하는」 구현으로도 위 테스트가 초록이 되고, 정상
     * 결과가 매 업그레이드마다 노출 경보로 뜨는 거짓 경보를 아무도 잡지 못한다.
     *
     * @scenario move_outcome=all_moved
     *
     * @effects docroot_storage_backup_residue_is_moved_out_of_web_root
     *
     * @return void 반환값 없음
     */
    public function test_a_clean_move_records_no_exposure_warning(): void
    {
        $residue = $this->makeResidue('storage.broken.20260606060606', 'delta.txt');

        $this->runMigration();

        $this->created[] = $this->storageAppPath.'/public-storage-broken-20260606060606';

        $this->assertDirectoryDoesNotExist($residue);
        $this->assertSame(
            [],
            StorageLinkHelper::takeDocrootExposureWarnings(),
            '정상 결과에 노출 경보가 붙으면 표준 배치에서 매 업그레이드마다 거짓 경보가 뜬다'
        );
    }

    /**
     * 마이그레이션을 실행합니다.
     */
    /**
     * 재실행해도 아무 일도 일어나지 않는다 (멱등).
     *
     * 클래스 독블록이 「idempotent」를 선언하는데 그것을 재는 케이스가 없었다 —
     * 업그레이드 스텝은 중단·재시도 경로에서 두 번 도는 것이 정상이고, 두 번째 실행이
     * 무언가를 옮기면 그 자체가 결함이다. 형제 정리 서비스는 같은 축을 갖고 있다
     * (`test_second_run_prunes_nothing`).
     *
     * @scenario upgrade_step=move_broken_backups, run=second
     *
     * @effects upgrade_step_is_idempotent_on_rerun
     *
     * @return void 반환값 없음
     */
    public function test_second_run_moves_nothing(): void
    {
        $this->makeResidue('storage.broken.20260404040404', 'delta.txt');

        $this->runMigration();

        $moved = $this->storageAppPath.'/public-storage-broken-20260404040404';
        $this->created[] = $moved;
        $this->assertFileExists($moved.'/delta.txt');

        $before = glob($this->storageAppPath.'/public-storage-broken-*') ?: [];

        // 두 번째 실행 — 옮길 것이 없다.
        $this->runMigration();

        $after = glob($this->storageAppPath.'/public-storage-broken-*') ?: [];

        $this->assertSame(
            $before,
            $after,
            '재실행이 무언가를 더 만들었다 — 중단·재시도 경로에서 사본이 늘어난다'
        );
        $this->assertFileExists($moved.'/delta.txt', '재실행이 이미 옮긴 것을 건드렸다');
    }

    /**
     * 이름 형식이 다른 항목은 옮기지 않고 **건너뛴 것으로 보고**한다.
     *
     * 접두사만 보면 운영자가 손으로 둔 `storage.broken.메모` 같은 디렉토리까지 말없이
     * 옮긴다 — 클래스 독블록은 형식을 받는다고 적었는데 코드는 접두사만 봤다.
     *
     * @scenario move_outcome=skipped
     *
     * @effects docroot_storage_backup_residue_is_moved_out_of_web_root
     *
     * @return void 반환값 없음
     */
    public function test_foreign_name_shapes_are_skipped_not_moved(): void
    {
        $operator = $this->makeResidue('storage.broken.memo', 'note.txt');

        $this->runMigration();

        $this->assertFileExists(
            $operator.'/note.txt',
            '이름 형식이 다른 항목을 옮겼다 — 운영자가 손으로 둔 디렉토리가 말없이 사라진다'
        );
        $this->assertFileDoesNotExist($this->storageAppPath.'/public-storage-broken-memo/note.txt');
    }

    /**
     * 스텝의 비공개 이름 규칙 상수가 `StorageLinkHelper` 상수와 같은 값이다.
     *
     * V-1 안전 때문에 스텝은 헬퍼 상수(7.2.0 신설)를 읽지 않고 같은 값을 자기 상수로 들고 있다.
     * 두 값이 갈리면 스텝과 정리 주체의 모집단이 조용히 갈리므로 값 자체를 대조한다.
     *
     * @scenario upgrade_step=move_broken_backups, process_shape=in_process_stale_helper
     *
     * @effects docroot_storage_backup_residue_is_moved_out_of_web_root
     *
     * @return void 반환값 없음
     */
    public function test_step_literals_match_the_helper_constants(): void
    {
        $step = new ReflectionClass(MovePublicStorageBrokenBackupsOutOfDocroot::class);

        $this->assertSame(StorageLinkHelper::DOCROOT_BROKEN_PREFIX, $step->getConstant('DOCROOT_BROKEN_PREFIX'));
        $this->assertSame(StorageLinkHelper::PUBLIC_STORAGE_BROKEN_PREFIX, $step->getConstant('PUBLIC_STORAGE_BROKEN_PREFIX'));
        $this->assertSame(StorageLinkHelper::BROKEN_STAMP, $step->getConstant('BROKEN_STAMP'));
    }

    /**
     * 옛 헬퍼(통보 메서드 부재)가 메모리에 있어도 치명 종료하지 않고 로그 경고로 대신한다.
     *
     * 7.1.3 이하 부모 프로세스가 in-process 로 이 스텝을 돌리면 `recordDocrootExposureWarning()`·
     * `warnOnAccumulatedBackups()` 가 없다. 스텝은 그 둘을 부르지 않고, 옮기지 못한 경로와 옮긴
     * 규모를 `$context->logger` 경고로 남겨야 한다.
     *
     * @scenario upgrade_step=move_broken_backups, process_shape=in_process_stale_helper, move_outcome=skipped
     *
     * @effects docroot_storage_backup_residue_is_moved_out_of_web_root
     *
     * @return void 반환값 없음
     */
    public function test_stale_helper_without_buffer_methods_falls_back_to_logger(): void
    {
        $plainFile = $this->publicPath.DIRECTORY_SEPARATOR.'storage.broken.20260808080808';
        file_put_contents($plainFile, 'not a directory');
        $this->created[] = $plainFile;
        $residue = $this->makeResidue('storage.broken.20260909090909', 'zeta.txt');
        $this->created[] = $this->storageAppPath.'/public-storage-broken-20260909090909';

        $logger = new class extends AbstractLogger
        {
            /** @var array<int, array{level: string, message: string}> */
            public array $records = [];

            /**
             * 기록을 모읍니다.
             *
             * @param  mixed  $level  로그 등급
             * @param  string|Stringable  $message  메시지
             * @param  array<string, mixed>  $context  문맥
             * @return void 반환값 없음
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
            }
        };
        Log::shouldReceive('channel')->with('upgrade')->andReturn($logger);

        $step = new class extends MovePublicStorageBrokenBackupsOutOfDocroot
        {
            /**
             * 옛 헬퍼를 흉내 낸다 — 7.2.0 신설 메서드가 없다.
             *
             * @param  string  $method  메서드 이름
             * @return bool 언제나 false
             */
            protected function storageLinkHelperHas(string $method): bool
            {
                return false;
            }
        };

        $step->run(new UpgradeContext(fromVersion: '7.1.3', toVersion: '7.2.0', currentStep: '7.2.0'));

        $this->assertDirectoryDoesNotExist($residue, '옛 헬퍼 상태에서도 이동 자체는 이뤄져야 한다');
        $this->assertSame([], StorageLinkHelper::takeDocrootExposureWarnings(), '부재로 판정한 메서드를 불렀다');

        $warnings = implode("\n", array_column(array_filter(
            $logger->records,
            fn (array $record): bool => $record['level'] === 'warning'
        ), 'message'));
        $this->assertStringContainsString(basename($plainFile), $warnings, '옮기지 못한 경로가 로그 경고로도 남지 않았다');
        $this->assertStringContainsString('1건', $warnings, '옮긴 규모 통보가 로그 경고로 대신 남지 않았다');
    }

    /**
     * 7.0.8 에서 7.2.0 으로 가는 문맥으로 이설 스텝을 한 번 실행한다.
     *
     * @return void 반환값 없음
     */
    private function runMigration(): void
    {
        $context = new UpgradeContext(
            fromVersion: '7.0.8',
            toVersion: '7.2.0',
            currentStep: '7.2.0',
        );

        (new MovePublicStorageBrokenBackupsOutOfDocroot)->run($context);
    }

    /**
     * 문서 루트 안에 손상 백업 잔해를 만듭니다.
     *
     * @param  string  $name  디렉토리 이름
     * @param  string  $file  안에 둘 파일 이름
     * @return string 만들어진 절대 경로
     */
    private function makeResidue(string $name, string $file): string
    {
        $path = $this->publicPath.'/'.$name;
        mkdir($path, 0755, true);
        file_put_contents($path.'/'.$file, 'residue');
        $this->created[] = $path;

        return $path;
    }

    /**
     * 문서 루트 안의 잔해 목록을 돌려줍니다.
     *
     * @return array<int, string> 문서 루트 안 잔해 이름 목록
     */
    private function listResidue(): array
    {
        $found = glob($this->publicPath.'/storage.broken.*') ?: [];
        sort($found);

        return $found;
    }

    /**
     * 테스트가 만든 트리를 지웁니다.
     *
     * @param  string  $path  절대 경로
     * @return void 반환값 없음
     */
    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (glob($path.'/*') ?: [] as $child) {
            $this->removeTree($child);
        }

        @rmdir($path);
    }
}
