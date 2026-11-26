<?php

namespace App\Extension\Support;

use Illuminate\Support\Str;

/**
 * 여러 확장을 한꺼번에 올리는 일괄 업데이트의 문맥 — 몇 개짜리 묶음인지, 누가 시작했는지.
 *
 * 확장 업데이트 하나는 끝에서 정적 게시·확장 오토로드·훅·config 캐시 재굽기·큐 워커 재시작을 한다. 확장 하나만 올릴 때는
 * 그것이 맞지만, 수십 개를 차례로 올리면 같은 일을 항목마다 되풀이한다(7.2.1 웹 코어 업데이트 실측 — 번들 31개, 항목당 약 22초).
 * 일괄(2개 이상)일 때만 그 일을 묶음 끝으로 모은다. 1개짜리 묶음은 일괄이 아니다 — 확장 하나만 올리는 업데이트와 같게 동작한다.
 *
 * 묶음을 시작한 쪽(웹 코어 업데이트의 번들 단계·명령줄 일괄 명령)이 {@see begin()} 으로 만들고, 항목을 새 프로세스로 넘길 때
 * 매니페스트 {@see MANIFEST_KEY} 에 {@see toManifest()} 를 싣는다. 항목 프로세스는 {@see fromManifest()} 로 같은 묶음의
 * 구성원이 된다. 한 프로세스 안에서는 {@see within()} 로 감싼 동안만 {@see current()} 가 그 묶음이다 — 리스너 등은
 * {@see defersPerExtensionWork()} 로 항목마다의 작업을 미룰지 판단한다.
 *
 * 나중에 「확장 여러 개를 골라 한꺼번에 업데이트」가 들어와도 같은 문맥을 쓴다.
 */
final class ExtensionBatchUpdateContext
{
    /** 매니페스트에 묶음을 싣는 키 */
    public const MANIFEST_KEY = 'batch';

    /** 일괄로 보는 최소 항목 수 */
    public const MIN_BATCH_SIZE = 2;

    /** 이 프로세스에서 지금 도는 묶음 */
    private static ?self $current = null;

    /**
     * 묶음 문맥을 만듭니다.
     *
     * @param  string  $id  묶음 식별자 (같은 묶음의 항목끼리 같다)
     * @param  int  $size  묶음의 항목 수
     * @param  int  $startedAt  묶음을 시작한 시각 (유닉스 초)
     * @param  bool  $ownedHere  이 프로세스가 묶음을 시작했는지 (끝 작업을 맡는 쪽)
     */
    private function __construct(
        public readonly string $id,
        public readonly int $size,
        public readonly int $startedAt,
        private readonly bool $ownedHere,
    ) {}

    /**
     * 이 프로세스가 시작하는 묶음을 만듭니다.
     *
     * @param  int  $size  묶음의 항목 수
     * @param  int|null  $startedAt  시작 시각 (생략하면 지금)
     * @return self 묶음 문맥
     */
    public static function begin(int $size, ?int $startedAt = null): self
    {
        return new self((string) Str::uuid(), max(0, $size), $startedAt ?? time(), true);
    }

    /**
     * 매니페스트에 실린 묶음을 읽어 그 구성원 문맥을 만듭니다.
     *
     * @param  array<string, mixed>  $manifest  매니페스트
     * @return self|null 구성원 문맥 (표시가 없거나 모양이 틀리면 null — 옛 부모·단독 실행)
     */
    public static function fromManifest(array $manifest): ?self
    {
        $batch = $manifest[self::MANIFEST_KEY] ?? null;
        if (! is_array($batch)) {
            return null;
        }

        $id = $batch['id'] ?? null;
        $size = $batch['size'] ?? null;
        $startedAt = $batch['started_at'] ?? null;
        if (! is_string($id) || $id === '' || ! is_int($size) || ! is_int($startedAt)) {
            return null;
        }

        return new self($id, max(0, $size), $startedAt, false);
    }

    /**
     * 매니페스트에 실을 모양으로 바꿉니다.
     *
     * @return array{id: string, size: int, started_at: int} 매니페스트 값
     */
    public function toManifest(): array
    {
        return ['id' => $this->id, 'size' => $this->size, 'started_at' => $this->startedAt];
    }

    /**
     * 일괄(2개 이상)인지 답합니다.
     *
     * @return bool 일괄이면 true
     */
    public function isBatch(): bool
    {
        return $this->size >= self::MIN_BATCH_SIZE;
    }

    /**
     * 이 프로세스가 묶음을 시작했는지 답합니다 — 묶음 끝 작업(큐 워커 재시작 등)은 시작한 쪽이 한 번 한다.
     *
     * @return bool 시작한 쪽이면 true, 매니페스트로 넘겨받은 구성원이면 false
     */
    public function isOwnedHere(): bool
    {
        return $this->ownedHere;
    }

    /**
     * 이 프로세스에서 지금 도는 묶음을 돌려줍니다.
     *
     * @return self|null 묶음 (없으면 null)
     */
    public static function current(): ?self
    {
        return self::$current;
    }

    /**
     * 콜백이 도는 동안 이 묶음을 현재 묶음으로 둡니다. 끝나면(예외로 끝나도) 바깥 묶음으로 돌아간다.
     *
     * @param  self  $batch  묶음
     * @param  callable  $callback  묶음 안에서 할 일
     * @return mixed 콜백 반환값
     */
    public static function within(self $batch, callable $callback): mixed
    {
        $previous = self::$current;
        self::$current = $batch;

        try {
            return $callback();
        } finally {
            self::$current = $previous;
        }
    }

    /**
     * 지금 확장마다의 부수 작업(큐 워커 재시작 등)을 묶음 끝으로 미뤄야 하는지 답합니다 — 일괄 묶음 안일 때만.
     *
     * @return bool 미루면 true
     */
    public static function defersPerExtensionWork(): bool
    {
        return self::$current?->isBatch() ?? false;
    }
}
