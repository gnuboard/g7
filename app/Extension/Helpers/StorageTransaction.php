<?php

namespace App\Extension\Helpers;

use App\Contracts\Extension\StorageInterface;
use App\Contracts\Extension\StreamingStorageInterface;
use App\Extension\Storage\StorageTransactionJournal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 트랜잭션 파일 보상 — 「커밋되면 옮기고, 롤백되면 원래대로」
 *
 * 파일 저장소는 DB 트랜잭션에 참여하지 않는다. 글·상품 저장 도중 파일을 옮기고 그 뒤 단계가 실패해 DB 가 되돌아가면,
 * 파일만 새 자리에 남아 DB 와 어긋났다(#883 — 8경로). 이 도구는 트랜잭션 안에서는 되돌릴 수 있는 방식으로 작업하고
 * (옮기기 = 복사 후 커밋 때 원본 삭제), 롤백되면 보상하며, 트랜잭션 밖에서는 즉시 수행한다.
 *
 * | 메서드 | 트랜잭션 안 | 커밋되면 | 롤백되면 | 트랜잭션 밖 |
 * |---|---|---|---|---|
 * | `move` | 대상에 복사(스트림 우선) | 원본 삭제 | 대상 삭제 | 즉시 이동 |
 * | `put` | 기록 | — | 삭제 | 즉시 |
 * | `deleteAfterCommit` | 지우지 않음 | 삭제 | 그대로 | 즉시 삭제 |
 * | `afterCommit` | — | 실행(예외는 경고 로그) | — | 즉시 |
 *
 * 보상 실패는 경고 로그(「파일 보상 실패 — 수동 정리 필요」)만 남긴다 — 원래 예외를 덮지 않는다. 롤백 감지는 등록 깊이와
 * 롤백 뒤 깊이를 비교하므로 안쪽 트랜잭션이 먼저 커밋된 뒤 바깥이 롤백돼도 보상된다(`StorageTransactionJournal`).
 *
 * 주의: 복사 중에는 원본과 사본이 함께 있어 용량을 잠깐 두 배로 쓴다. 커밋 직후 프로세스가 죽으면 원본이 남을 수 있다.
 */
class StorageTransaction
{
    /**
     * 파일을 옮깁니다.
     *
     * @param  StorageInterface  $storage  저장소
     * @param  string  $category  카테고리
     * @param  string  $from  원본 상대 경로
     * @param  string  $to  대상 상대 경로
     * @param  string|null  $connection  DB 연결 이름 (null 이면 기본)
     * @return bool 복사(트랜잭션 밖이면 이동) 성공 여부 — 원본이 없으면 false
     */
    public static function move(StorageInterface $storage, string $category, string $from, string $to, ?string $connection = null): bool
    {
        if (! self::copy($storage, $category, $from, $to)) {
            return false;
        }

        if (! self::inTransaction($connection)) {
            $storage->delete($category, $from);

            return true;
        }

        self::journal()->record(
            $connection,
            fn () => $storage->delete($category, $to),
            fn () => $storage->delete($category, $from),
            "move {$category}:{$from} -> {$to}",
        );

        return true;
    }

    /**
     * 파일을 씁니다 — 트랜잭션이 롤백되면 지웁니다.
     *
     * @param  StorageInterface  $storage  저장소
     * @param  string  $category  카테고리
     * @param  string  $path  상대 경로
     * @param  mixed  $content  내용
     * @param  string|null  $connection  DB 연결 이름 (null 이면 기본)
     * @return bool 쓰기 성공 여부
     */
    public static function put(StorageInterface $storage, string $category, string $path, mixed $content, ?string $connection = null): bool
    {
        $written = $storage->put($category, $path, $content);

        if ($written && self::inTransaction($connection)) {
            self::journal()->record(
                $connection,
                fn () => $storage->delete($category, $path),
                null,
                "put {$category}:{$path}",
            );
        }

        return $written;
    }

    /**
     * 파일을 커밋된 뒤에 지웁니다 — 트랜잭션이 롤백되면 그대로 둡니다. 트랜잭션 밖이면 바로 지웁니다.
     *
     * @param  StorageInterface  $storage  저장소
     * @param  string  $category  카테고리
     * @param  string  $path  상대 경로
     * @param  string|null  $connection  DB 연결 이름 (null 이면 기본)
     * @return void 반환값 없음
     */
    public static function deleteAfterCommit(StorageInterface $storage, string $category, string $path, ?string $connection = null): void
    {
        if (! self::inTransaction($connection)) {
            $storage->delete($category, $path);

            return;
        }

        self::journal()->record(
            $connection,
            null,
            fn () => $storage->delete($category, $path),
            "delete {$category}:{$path}",
        );
    }

    /**
     * 커밋된 뒤에 할 일을 겁니다(임시 디렉토리 정리 등) — 예외는 경고 로그만 남깁니다. 트랜잭션 밖이면 바로 합니다.
     *
     * @param  callable  $callback  할 일
     * @param  string|null  $connection  DB 연결 이름 (null 이면 기본)
     * @return void 반환값 없음
     */
    public static function afterCommit(callable $callback, ?string $connection = null): void
    {
        if (! self::inTransaction($connection)) {
            try {
                $callback();
            } catch (Throwable $e) {
                Log::warning('파일 마무리 실패 — 수동 정리 필요', ['operation' => 'afterCommit', 'error' => $e->getMessage()]);
            }

            return;
        }

        self::journal()->record($connection, null, $callback, 'afterCommit');
    }

    /**
     * 원본을 대상에 복사합니다(스트림 저장소면 메모리에 통째로 올리지 않는다).
     *
     * @param  StorageInterface  $storage  저장소
     * @param  string  $category  카테고리
     * @param  string  $from  원본 상대 경로
     * @param  string  $to  대상 상대 경로
     * @return bool 복사 성공 여부
     */
    private static function copy(StorageInterface $storage, string $category, string $from, string $to): bool
    {
        if ($storage instanceof StreamingStorageInterface) {
            $stream = $storage->readStream($category, $from);
            if (! is_resource($stream)) {
                return false;
            }

            try {
                return $storage->writeStream($category, $to, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        $content = $storage->get($category, $from);
        if ($content === null) {
            return false;
        }

        return $storage->put($category, $to, $content);
    }

    /**
     * 연결이 트랜잭션 안인지 판정합니다.
     *
     * @param  string|null  $connection  DB 연결 이름
     * @return bool 트랜잭션 안이면 true
     */
    private static function inTransaction(?string $connection): bool
    {
        return DB::connection($connection)->transactionLevel() > 0;
    }

    /**
     * 보상 저널을 돌려줍니다.
     *
     * @return StorageTransactionJournal 저널
     */
    private static function journal(): StorageTransactionJournal
    {
        return app(StorageTransactionJournal::class);
    }
}
