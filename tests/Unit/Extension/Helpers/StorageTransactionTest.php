<?php

namespace Tests\Unit\Extension\Helpers;

use App\Contracts\Extension\StorageInterface;
use App\Extension\Helpers\StorageTransaction;
use App\Extension\Storage\ModuleStorageDriver;
use App\Extension\Storage\StorageTransactionJournal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 트랜잭션 파일 보상 도구 (#883 3단계)
 *
 * 「커밋되면 옮기고 롤백되면 원래대로」 — 파일 저장소는 DB 트랜잭션에 참여하지 않아, 저장이 실패해 DB 가 되돌아가도
 * 옮긴 파일이 새 자리에 남던 8경로를 이 도구로 맞춘다. 특히 안쪽 트랜잭션이 먼저 커밋된 뒤 바깥이 롤백되는 경우
 * (`DB::afterRollBack()` 으로는 잡히지 않는다 — 상품문의 경로)를 잠근다.
 *
 * @scenario-manifest tests/scenarios/storage-transaction-compensation.yaml
 */
class StorageTransactionTest extends TestCase
{
    private StorageInterface $storage;

    /**
     * 가짜 로컬 디스크 위 모듈 저장소를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->storage = new ModuleStorageDriver('st-test');
        $this->storage->put('files', 'temp/a.txt', 'A');
    }

    /**
     * 트랜잭션 안의 이동은 커밋되면 원본을 지우고 대상만 남긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=commit
     *
     * @effects move_finalized_on_commit
     */
    public function test_move_inside_transaction_finalizes_on_commit(): void
    {
        DB::beginTransaction();
        $this->assertTrue(StorageTransaction::move($this->storage, 'files', 'temp/a.txt', 'final/a.txt'));
        $this->assertTrue($this->storage->exists('files', 'temp/a.txt'), '커밋 전에는 원본이 남는다');
        $this->assertTrue($this->storage->exists('files', 'final/a.txt'));
        DB::commit();

        $this->assertFalse($this->storage->exists('files', 'temp/a.txt'));
        $this->assertSame('A', $this->storage->get('files', 'final/a.txt'));
        $this->assertSame(0, app(StorageTransactionJournal::class)->pendingCount());
    }

    /**
     * 롤백되면 대상을 지우고 원본을 그대로 둔다. 커밋 뒤 삭제(deleteAfterCommit)는 실행되지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=rollback
     *
     * @effects move_compensated_on_rollback
     */
    public function test_move_and_put_compensated_on_rollback(): void
    {
        $this->storage->put('files', 'keep.txt', 'K');

        DB::beginTransaction();
        StorageTransaction::move($this->storage, 'files', 'temp/a.txt', 'final/a.txt');
        StorageTransaction::put($this->storage, 'files', 'new.txt', 'N');
        StorageTransaction::deleteAfterCommit($this->storage, 'files', 'keep.txt');
        $ran = false;
        StorageTransaction::afterCommit(function () use (&$ran) {
            $ran = true;
        });
        DB::rollBack();

        $this->assertTrue($this->storage->exists('files', 'temp/a.txt'));
        $this->assertFalse($this->storage->exists('files', 'final/a.txt'));
        $this->assertFalse($this->storage->exists('files', 'new.txt'));
        $this->assertTrue($this->storage->exists('files', 'keep.txt'));
        $this->assertFalse($ran);
        $this->assertSame(0, app(StorageTransactionJournal::class)->pendingCount());
    }

    /**
     * 안쪽 트랜잭션(savepoint)이 먼저 커밋된 뒤 바깥이 롤백돼도 보상된다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=nested_commit_then_outer_rollback
     *
     * @effects nested_savepoint_commit_then_outer_rollback_compensated
     */
    public function test_nested_commit_then_outer_rollback_is_compensated(): void
    {
        DB::beginTransaction();
        DB::beginTransaction();
        StorageTransaction::move($this->storage, 'files', 'temp/a.txt', 'final/a.txt');
        DB::commit();

        $this->assertTrue($this->storage->exists('files', 'temp/a.txt'), '바깥 커밋 전이라 원본이 남는다');
        DB::rollBack();

        $this->assertTrue($this->storage->exists('files', 'temp/a.txt'));
        $this->assertFalse($this->storage->exists('files', 'final/a.txt'));
    }

    /**
     * 안쪽 트랜잭션이 커밋된 뒤 다른 안쪽 트랜잭션이 롤백돼도 앞의 작업은 되돌리지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=sibling_savepoint_rollback
     *
     * @effects committed_inner_work_survives_sibling_rollback
     */
    public function test_sibling_savepoint_rollback_does_not_undo_committed_inner_work(): void
    {
        DB::beginTransaction();
        DB::beginTransaction();
        StorageTransaction::move($this->storage, 'files', 'temp/a.txt', 'final/a.txt');
        DB::commit();

        DB::beginTransaction();
        StorageTransaction::put($this->storage, 'files', 'other.txt', 'O');
        DB::rollBack();

        $this->assertTrue($this->storage->exists('files', 'final/a.txt'));
        $this->assertFalse($this->storage->exists('files', 'other.txt'));
        DB::commit();

        $this->assertFalse($this->storage->exists('files', 'temp/a.txt'));
        $this->assertTrue($this->storage->exists('files', 'final/a.txt'));
    }

    /**
     * 트랜잭션 밖에서는 바로 수행한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=outside_transaction
     *
     * @effects immediate_outside_transaction
     */
    public function test_outside_transaction_runs_immediately(): void
    {
        $this->assertTrue(StorageTransaction::move($this->storage, 'files', 'temp/a.txt', 'final/a.txt'));
        $this->assertFalse($this->storage->exists('files', 'temp/a.txt'));

        StorageTransaction::deleteAfterCommit($this->storage, 'files', 'final/a.txt');
        $this->assertFalse($this->storage->exists('files', 'final/a.txt'));

        $this->assertFalse(StorageTransaction::move($this->storage, 'files', 'missing.txt', 'x.txt'), '원본이 없으면 false');
    }

    /**
     * 보상이 실패해도 예외를 올리지 않고 경고만 남긴다(원래 예외를 덮지 않는다).
     *
     * @return void 반환값 없음
     *
     * @scenario case=compensation_failure
     *
     * @effects compensation_failure_logged_not_thrown
     */
    public function test_compensation_failure_is_logged_not_thrown(): void
    {
        Log::spy();
        $failing = new class($this->storage) extends ModuleStorageDriver
        {
            /**
             * 감싼 저장소를 받습니다.
             *
             * @param  StorageInterface  $inner  원래 저장소
             */
            public function __construct(private StorageInterface $inner)
            {
                parent::__construct('st-test');
            }

            /**
             * 지우기는 언제나 실패한다(보상 실패 재현).
             *
             * @param  string  $category  카테고리
             * @param  string  $path  상대 경로
             * @return bool 돌려주지 않는다 — 예외
             */
            public function delete(string $category, string $path): bool
            {
                throw new \RuntimeException('disk gone');
            }
        };

        DB::beginTransaction();
        StorageTransaction::put($failing, 'files', 'fail.txt', 'F');
        DB::rollBack();

        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, '파일 보상 실패'))->once();
    }
}
