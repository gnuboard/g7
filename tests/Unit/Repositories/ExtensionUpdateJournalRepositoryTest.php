<?php

namespace Tests\Unit\Repositories;

use App\Contracts\Repositories\ExtensionUpdateJournalRepositoryInterface;
use App\Extension\Helpers\ExtensionUpdateJournal;
use App\Repositories\ExtensionUpdateJournalRepository;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 확장 업데이트 진행 기록 Repository 테스트 — 파일 저장·조회·삭제와 저장본 모양 정규화
 */
class ExtensionUpdateJournalRepositoryTest extends TestCase
{
    private const ID = 'g7test-journal-repo';

    private ExtensionUpdateJournalRepository $repository;

    /**
     * 저장소를 만들고 남은 기록을 지운다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new ExtensionUpdateJournalRepository;
        $this->repository->delete('module', self::ID);
    }

    /**
     * 기록을 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->repository->delete('module', self::ID);

        parent::tearDown();
    }

    /**
     * 테스트가 직접 쓰는 기록 파일 경로.
     *
     * @return string 기록 파일 절대 경로
     */
    private function journalPath(): string
    {
        return storage_path('app/extension_update_journal/module/'.self::ID.'.json');
    }

    /**
     * 컨테이너가 인터페이스를 파일 기반 구현으로 해석한다.
     *
     * @scenario operation=binding
     *
     * @effects journal_repository_bound_to_file_implementation
     *
     * @return void 반환값 없음
     */
    public function test_interface_is_bound_to_file_repository(): void
    {
        $this->assertInstanceOf(ExtensionUpdateJournalRepository::class, app(ExtensionUpdateJournalRepositoryInterface::class));
    }

    /**
     * 바인딩을 모르는 직전 판 프로바이더가 메모리에 남은 프로세스(코어 업그레이드 도중)에서도 기록 저장소를 얻는다.
     *
     * @scenario operation=unbound_fallback
     *
     * @effects unbound_interface_falls_back_to_file_implementation
     *
     * @return void 반환값 없음
     */
    public function test_repository_falls_back_to_file_implementation_when_interface_unbound(): void
    {
        unset($this->app[ExtensionUpdateJournalRepositoryInterface::class]);
        $this->assertFalse($this->app->bound(ExtensionUpdateJournalRepositoryInterface::class));

        $this->assertInstanceOf(ExtensionUpdateJournalRepository::class, ExtensionUpdateJournal::repository());
    }

    /**
     * 저장한 기록을 같은 내용으로 다시 읽고, 지우면 사라진다.
     *
     * @scenario operation=round_trip
     *
     * @effects saved_journal_read_back_unchanged, deleted_journal_not_found, delete_missing_journal_is_noop
     *
     * @return void 반환값 없음
     */
    public function test_save_find_delete_round_trip(): void
    {
        $data = [
            'from_version' => '1.1.0',
            'to_version' => '1.1.4',
            'source' => 'bundled',
            'files_touched' => true,
            'completed_steps' => ['1.1.1', '1.1.2'],
            'started_at' => '2026-10-19T00:00:00+00:00',
            'resumed' => false,
        ];

        $this->assertNull($this->repository->find('module', self::ID), '저장 전에는 기록이 없다');

        $this->repository->save(new ExtensionUpdateJournal('module', self::ID, $data));
        $this->assertFileExists($this->journalPath());

        $found = $this->repository->find('module', self::ID);
        $this->assertNotNull($found);
        $this->assertSame('module', $found->type());
        $this->assertSame(self::ID, $found->identifier());
        $this->assertSame($data, $found->toArray());

        $this->repository->delete('module', self::ID);
        $this->assertFileDoesNotExist($this->journalPath());
        $this->assertNull($this->repository->find('module', self::ID));

        // 없는 기록을 지워도 아무 일도 없다
        $this->repository->delete('module', self::ID);
        $this->assertNull($this->repository->find('module', self::ID));
    }

    /**
     * 읽을 수 없거나 판 정보가 없는 저장본은 기록 없음으로 본다 — 업데이트를 막지 않는다.
     *
     * @scenario operation=unreadable
     *
     * @effects unreadable_journal_file_treated_as_absent, journal_without_versions_treated_as_absent
     *
     * @return void 반환값 없음
     */
    public function test_unreadable_or_incomplete_file_is_treated_as_no_journal(): void
    {
        File::ensureDirectoryExists(dirname($this->journalPath()));

        File::put($this->journalPath(), '{not json');
        $this->assertNull($this->repository->find('module', self::ID), '깨진 JSON');

        File::put($this->journalPath(), json_encode(['from_version' => '1.0.0']));
        $this->assertNull($this->repository->find('module', self::ID), '목표 판 없음');
    }

    /**
     * 저장본의 빠진 칸·잘못된 칸은 기본값으로 정규화한다.
     *
     * @scenario operation=normalize
     *
     * @effects stored_fields_normalized_to_defaults
     *
     * @return void 반환값 없음
     */
    public function test_find_normalizes_stored_fields(): void
    {
        File::ensureDirectoryExists(dirname($this->journalPath()));
        File::put($this->journalPath(), json_encode([
            'from_version' => '1.0.0',
            'to_version' => '1.0.2',
            'source' => 7,
            'completed_steps' => ['1.0.1', 3, null],
        ]));

        $found = $this->repository->find('module', self::ID);

        $this->assertNotNull($found);
        $this->assertSame([
            'from_version' => '1.0.0',
            'to_version' => '1.0.2',
            'source' => null,
            'files_touched' => false,
            'completed_steps' => ['1.0.1'],
            'started_at' => '',
            'resumed' => false,
        ], $found->toArray());
    }
}
