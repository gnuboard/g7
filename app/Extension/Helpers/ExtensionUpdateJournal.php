<?php

namespace App\Extension\Helpers;

use App\Contracts\Repositories\ExtensionUpdateJournalRepositoryInterface;
use App\Repositories\ExtensionUpdateJournalRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 확장 업데이트 하나가 어디까지 진행됐는지 남기는 기록 (7.1.1 출시 전).
 *
 * 업데이트는 DB 판(`version`)을 새 판으로 적은 **뒤** 업그레이드 스텝을 돈다. 프로세스가 스텝 도중 죽으면(메모리 초과·시간 초과·
 * 강제 종료) catch 가 돌지 않아 DB 판은 새 판인데 중간 판 스텝은 돌지 않은 채 남았고, 다음 업데이트는 `from == to` 로 마지막 판
 * 스텝만 다시 돌았다 — 여러 판을 건너뛴 업데이트(예 1.1.0 → 1.1.4)의 1.1.1~1.1.3 스텝은 다시 돌 길이 없었다. 파일을 바꾼 직후
 * 죽으면 반대로 파일은 새 판, DB 판은 옛 판으로 남았고 정리(활성화)는 그 사실을 알리지 않았다.
 *
 * 이 기록은 업데이트가 시작한 판(`from`)·목표 판(`to`)·파일을 건드렸는지(`files_touched`)·끝낸 스텝(`completed_steps`)을 파일 하나에
 * 적는다. 업데이트가 백업을 지울 때(파일·DB 판·스텝이 되돌릴 수 없게 끝났을 때) 지운다. 남아 있으면 그 업데이트는 끝나지 않은 것이다:
 * - 다음 업데이트는 기록의 `from` 부터 스텝을 고르고 끝낸 스텝은 건너뛴다 — 중간 판 스텝이 순서대로 정확히 한 번 돈다.
 * - 멈춘 업데이트 정리({@see StuckUpdateRecovery})는 DB 판을 `from` 으로 되돌려 「업데이트 있음」으로 만들고 끝나지 않았음을 알린다.
 *
 * DB 칸을 새로 만들지 않는다(결정 — 새 컬럼·마이그레이션 없음). 업데이트 백업과 같은 저장소(`storage/app`) 아래에 둔다.
 * 기록을 읽거나 쓰지 못해도 업데이트 자체는 막지 않는다 — 종전 동작으로 돌아갈 뿐이다(경고 로그).
 *
 * 저장·조회·삭제는 {@see ExtensionUpdateJournalRepositoryInterface} 가 맡는다 — 이 클래스는 이어받기·건너뛰기·정리 규칙만 갖는다.
 */
final class ExtensionUpdateJournal
{
    /**
     * 업데이트가 끝나 기록을 지웠는지 — 지운 뒤에는 다시 쓰지 않는다.
     */
    private bool $finished = false;

    /**
     * 기록을 만듭니다 — 새 기록은 {@see begin()}, 저장된 기록은 Repository 의 find 가 만든다.
     *
     * @param  string  $type  확장 종류 (module|plugin|template)
     * @param  string  $identifier  확장 식별자
     * @param  array{from_version: string, to_version: string, source: string|null, files_touched: bool, completed_steps: list<string>, started_at: string, resumed: bool}  $data  기록 내용
     */
    public function __construct(
        private readonly string $type,
        private readonly string $identifier,
        private array $data,
    ) {}

    /**
     * 남아 있는(끝나지 않은) 기록을 읽습니다.
     *
     * @param  string  $type  확장 종류 (module|plugin|template)
     * @param  string  $identifier  확장 식별자
     * @return self|null 기록 (없거나 읽을 수 없으면 null)
     */
    public static function find(string $type, string $identifier): ?self
    {
        return self::repository()->find($type, $identifier);
    }

    /**
     * 업데이트를 시작하며 기록합니다 — 끝나지 않은 앞선 기록이 있으면 이어받습니다.
     *
     * 이어받을 때 시작 판은 둘 중 낮은 판, 끝낸 스텝은 그대로 둔다 — 앞선 업데이트가 못 끝낸 중간 판 스텝을 이번 업데이트가 마저
     * 돌고, 이미 끝낸 스텝은 다시 돌지 않는다.
     *
     * @param  string  $type  확장 종류 (module|plugin|template)
     * @param  string  $identifier  확장 식별자
     * @param  string  $fromVersion  이번 업데이트의 시작 판 (DB 판)
     * @param  string  $toVersion  목표 판
     * @param  string|null  $source  업데이트 소스 (bundled|github|zip)
     * @return self 기록
     */
    public static function begin(string $type, string $identifier, string $fromVersion, string $toVersion, ?string $source): self
    {
        $previous = self::find($type, $identifier);

        $journal = new self($type, $identifier, [
            'from_version' => $previous !== null && version_compare($previous->fromVersion(), $fromVersion, '<')
                ? $previous->fromVersion()
                : $fromVersion,
            'to_version' => $toVersion,
            'source' => $source,
            'files_touched' => false,
            'completed_steps' => $previous?->completedSteps() ?? [],
            'started_at' => Carbon::now()->toIso8601String(),
            'resumed' => $previous !== null,
        ]);

        if ($previous !== null) {
            Log::info('끝나지 않은 확장 업데이트를 이어받습니다 — 끝낸 스텝은 다시 돌지 않습니다', [
                'type' => $type,
                'identifier' => $identifier,
                'from' => $journal->fromVersion(),
                'to' => $toVersion,
                'completed_steps' => $journal->completedSteps(),
            ]);
        }

        $journal->persist();

        return $journal;
    }

    /**
     * 확장 종류.
     *
     * @return string 확장 종류 (module|plugin|template)
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * 확장 식별자.
     *
     * @return string 확장 식별자
     */
    public function identifier(): string
    {
        return $this->identifier;
    }

    /**
     * 저장할 기록 내용 — Repository 가 이 모양 그대로 적는다.
     *
     * @return array{from_version: string, to_version: string, source: string|null, files_touched: bool, completed_steps: list<string>, started_at: string, resumed: bool} 기록 내용
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * 기록의 시작 판 — 끝나지 않은 앞선 업데이트를 이어받았으면 그 시작 판입니다.
     *
     * @return string 시작 판
     */
    public function fromVersion(): string
    {
        return $this->data['from_version'];
    }

    /**
     * 기록의 목표 판.
     *
     * @return string 목표 판
     */
    public function toVersion(): string
    {
        return $this->data['to_version'];
    }

    /**
     * 업데이트 소스.
     *
     * @return string|null 업데이트 소스 (bundled|github|zip)
     */
    public function source(): ?string
    {
        return $this->data['source'];
    }

    /**
     * 확장 디렉토리의 파일을 바꾸기 시작했는지.
     *
     * @return bool 파일 교체를 시작했으면 true
     */
    public function filesTouched(): bool
    {
        return $this->data['files_touched'];
    }

    /**
     * 끝낸 업그레이드 스텝 판 목록.
     *
     * @return list<string> 끝낸 스텝 판
     */
    public function completedSteps(): array
    {
        return $this->data['completed_steps'];
    }

    /**
     * 끝나지 않은 앞선 업데이트를 이어받은 기록인지.
     *
     * @return bool 이어받았으면 true
     */
    public function resumed(): bool
    {
        return $this->data['resumed'];
    }

    /**
     * 그 판의 스텝을 이미 끝냈는지.
     *
     * @param  string  $stepVersion  스텝 판
     * @return bool 끝냈으면 true
     */
    public function hasCompleted(string $stepVersion): bool
    {
        foreach ($this->data['completed_steps'] as $done) {
            if (version_compare($done, $stepVersion, '==')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 파일 교체를 시작한다고 적습니다 — 교체 도중 죽어도 파일이 바뀌었을 수 있음을 남긴다.
     *
     * @return void 반환값 없음
     */
    public function markFilesTouched(): void
    {
        if ($this->data['files_touched']) {
            return;
        }

        $this->data['files_touched'] = true;
        $this->persist();
    }

    /**
     * 스텝 하나를 끝냈다고 적습니다.
     *
     * @param  string  $stepVersion  끝낸 스텝 판
     * @return void 반환값 없음
     */
    public function markStepCompleted(string $stepVersion): void
    {
        if ($this->hasCompleted($stepVersion)) {
            return;
        }

        $this->data['completed_steps'][] = $stepVersion;
        $this->persist();
    }

    /**
     * 업데이트가 판 전환을 끝냈다(백업 삭제) — 기록을 지웁니다.
     *
     * @return void 반환값 없음
     */
    public function finish(): void
    {
        $this->finished = true;
        self::discard($this->type, $this->identifier);
    }

    /**
     * 업데이트가 예외로 실패했을 때 기록을 정리합니다.
     *
     * 파일을 백업으로 되돌렸고 끝낸 스텝이 없으면 아무 일도 없던 것이므로 지운다. 끝낸 스텝이 있으면 남긴다 — 다음 업데이트가 그
     * 스텝을 다시 돌지 않는다. 파일을 되돌리지 못했으면 업데이트가 끝나지 않은 채이므로 남긴다.
     *
     * @param  bool  $filesRestored  파일을 백업으로 되돌렸는지
     * @return void 반환값 없음
     */
    public function settleAfterFailure(bool $filesRestored): void
    {
        if ($this->finished) {
            return;
        }

        if ($filesRestored && $this->data['completed_steps'] === []) {
            $this->finish();

            return;
        }

        if ($filesRestored) {
            // 파일은 옛 판으로 돌아왔다 — 정리가 「파일이 새 판일 수 있음」을 알리지 않게 한다.
            $this->data['files_touched'] = false;
            $this->persist();
        }
    }

    /**
     * 기록을 지웁니다.
     *
     * @param  string  $type  확장 종류 (module|plugin|template)
     * @param  string  $identifier  확장 식별자
     * @return void 반환값 없음
     */
    public static function discard(string $type, string $identifier): void
    {
        self::repository()->delete($type, $identifier);
    }

    /**
     * 기록을 저장합니다 — 지운 뒤에는 다시 쓰지 않고, 실패해도 업데이트를 막지 않습니다(Repository 가 경고 로그).
     *
     * @return void 반환값 없음
     */
    private function persist(): void
    {
        if ($this->finished) {
            return;
        }

        self::repository()->save($this);
    }

    /**
     * 기록 Repository 를 해석합니다 — 정적 진입점(Manager 의 업데이트 흐름)에서 쓰므로 컨테이너에서 꺼낸다.
     *
     * 코어 업그레이드 도중 바인딩을 모르는 직전 판 프로바이더가 메모리에 남은 프로세스가 이 클래스를 처음 읽어도
     * 업데이트가 멈추지 않도록, 인터페이스가 바인딩되지 않았으면 구현 클래스를 직접 쓴다.
     *
     * @return ExtensionUpdateJournalRepositoryInterface 기록 Repository
     */
    public static function repository(): ExtensionUpdateJournalRepositoryInterface
    {
        return app()->bound(ExtensionUpdateJournalRepositoryInterface::class)
            ? app(ExtensionUpdateJournalRepositoryInterface::class)
            : app(ExtensionUpdateJournalRepository::class);
    }
}
