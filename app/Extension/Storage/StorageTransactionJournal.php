<?php

namespace App\Extension\Storage;

use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 트랜잭션 파일 보상 저널 — `StorageTransaction` 의 내부 상태 (싱글톤)
 *
 * 트랜잭션 안에서 한 파일 작업마다 「롤백되면 되돌릴 일(compensate)」과 「커밋되면 마무리할 일(finalize)」을
 * 등록 깊이(`transactionLevel()`)와 함께 적어 둔다.
 *
 * - 커밋: 마무리는 `DB::connection()->afterCommit()` 로 건다 — 가장 바깥 커밋 때 실행되고, 바깥이 롤백되면 매니저가
 *   버린다(중첩·테스트 트랜잭션 의미가 프레임워크와 같다). 마무리가 실행되면 그 항목은 저널에서 빠진다.
 * - 롤백: `TransactionRolledBack` 이벤트에서, 롤백 뒤 깊이보다 깊은 곳에서 등록된 항목을 역순으로 되돌린다.
 *   `DB::afterRollBack()` 은 안쪽 트랜잭션(savepoint)이 먼저 커밋되면 바깥이 롤백돼도 실행되지 않으므로 쓰지 않는다
 *   (상품문의 — 바깥 트랜잭션 안에서 글 저장이 커밋되는 경로, #883 F1).
 * - 안쪽 커밋(`TransactionCommitted`, 커밋 뒤 깊이 > 0): 그보다 깊은 항목을 커밋 뒤 깊이로 내린다 — 같은 깊이의 다른
 *   savepoint 가 나중에 롤백돼도 이미 커밋된 안쪽 작업을 되돌리지 않게.
 * - 보상·마무리 실패는 경고 로그만 남기고 예외를 올리지 않는다(원래 예외를 덮지 않기 위해).
 */
class StorageTransactionJournal
{
    /**
     * 등록된 항목 — 키는 항목 번호
     *
     * @var array<int, array{connection: string, level: int, compensate: callable|null, label: string}>
     */
    private array $entries = [];

    /** 다음 항목 번호 */
    private int $sequence = 0;

    /**
     * 트랜잭션 안 파일 작업을 적어 둡니다.
     *
     * @param  string|null  $connection  DB 연결 이름 (null 이면 기본 연결)
     * @param  callable|null  $compensate  롤백되면 실행할 보상
     * @param  callable|null  $finalize  커밋되면 실행할 마무리
     * @param  string  $label  로그용 작업 설명
     * @return void 반환값 없음
     */
    public function record(?string $connection, ?callable $compensate, ?callable $finalize, string $label): void
    {
        $db = DB::connection($connection);
        $name = $db->getName();
        $id = ++$this->sequence;

        $this->entries[$id] = [
            'connection' => $name,
            'level' => $db->transactionLevel(),
            'compensate' => $compensate,
            'label' => $label,
        ];

        $db->afterCommit(function () use ($id, $finalize, $label): void {
            unset($this->entries[$id]);

            if ($finalize !== null) {
                $this->runSafely($finalize, '파일 마무리 실패 — 수동 정리 필요', $label);
            }
        });
    }

    /**
     * 롤백 이벤트 — 롤백 뒤 깊이보다 깊이 등록된 항목을 역순으로 되돌립니다.
     *
     * @param  TransactionRolledBack  $event  롤백 이벤트
     * @return void 반환값 없음
     */
    public function handleRolledBack(TransactionRolledBack $event): void
    {
        $name = $event->connectionName;
        $level = $event->connection->transactionLevel();

        foreach (array_reverse($this->entries, true) as $id => $entry) {
            if ($entry['connection'] !== $name || $entry['level'] <= $level) {
                continue;
            }

            unset($this->entries[$id]);

            if ($entry['compensate'] !== null) {
                $this->runSafely($entry['compensate'], '파일 보상 실패 — 수동 정리 필요', $entry['label']);
            }
        }
    }

    /**
     * 커밋 이벤트 — 안쪽 커밋이면 더 깊은 항목을 커밋 뒤 깊이로 내리고, 깊이 0 이면 남은 항목을 비웁니다.
     *
     * @param  TransactionCommitted  $event  커밋 이벤트
     * @return void 반환값 없음
     */
    public function handleCommitted(TransactionCommitted $event): void
    {
        $name = $event->connectionName;
        $level = $event->connection->transactionLevel();

        foreach ($this->entries as $id => $entry) {
            if ($entry['connection'] !== $name) {
                continue;
            }

            if ($level === 0) {
                // 마무리는 afterCommit 이 이미 돌렸다 — 남은 것은 누수(워커 장기 실행)라 비운다
                unset($this->entries[$id]);
            } elseif ($entry['level'] > $level) {
                $this->entries[$id]['level'] = $level;
            }
        }
    }

    /**
     * 아직 결말이 나지 않은 항목 수 (시험·진단용).
     *
     * @return int 항목 수
     */
    public function pendingCount(): int
    {
        return count($this->entries);
    }

    /**
     * 보상·마무리를 실행하고, 실패하면 경고만 남깁니다.
     *
     * @param  callable  $callback  실행할 일
     * @param  string  $message  실패 로그 문구
     * @param  string  $label  작업 설명
     * @return void 반환값 없음
     */
    private function runSafely(callable $callback, string $message, string $label): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Log::warning($message, ['operation' => $label, 'error' => $e->getMessage()]);
        }
    }
}
