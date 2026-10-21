<?php

namespace App\Extension\Helpers;

use App\Enums\ExtensionStatus;
use App\Exceptions\ExtensionOperationInProgressException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 도중에 멈춘 확장 업데이트(`updating` 고착)를 판정하고 정리합니다.
 *
 * 업데이트는 시작할 때 행을 `updating` 으로 바꾸고, 실패하면 catch 가 직전 상태로 되돌린다. 프로세스가 죽으면(메모리 초과·
 * 시간 초과·강제 종료) catch 가 돌지 않아 `updating` 이 남고, 상태 가드({@see ExtensionStatusGuard})가 시간 조건 없이 막아
 * 업데이트·활성화로 빠져나올 수 없었다 — 사이트는 503, DB 를 직접 고쳐야 했다(7.1.1 출시 전 실측).
 *
 * 고착 판정: 업데이트 잠금({@see ExtensionOperationLock})이 비어 있고, 행의 마지막 갱신이 잠금 유지 시간보다 오래됐다. 잠금은
 * 프로세스가 죽어도 그 시간이 지나면 풀리므로, 둘 다 만족하면 그 업데이트를 하고 있는 프로세스는 없다.
 *
 * 되돌릴 상태: 업데이트 흐름이 직전 상태를 따로 남기지 않으므로 `inactive` 로 둔다 — 사이트 503 해소가 우선이고 운영자가 다시
 * 켤 수 있다(결정 — 새 컬럼·마이그레이션을 만들지 않는다).
 *
 * 자동으로 풀지 않는다 — 운영자가 명시한 `--force` 업데이트와 활성화 진입점에서만 정리한다.
 *
 * 끝나지 않은 판 전환: 업데이트 진행 기록({@see ExtensionUpdateJournal})이 남아 있고 파일을 바꾸기 시작했으면, 파일은 새 판일 수
 * 있는데 DB 판·업그레이드 스텝은 끝나지 않았다. 정리는 DB 판을 그 업데이트의 시작 판으로 되돌리고 「업데이트 있음」으로
 * 표시한다 — 다음 업데이트(`--force` 없이도)가 시작 판부터 못 끝낸 스텝만 순서대로 돌아 판 전환을 마친다. 파일을 옛 판으로 되돌리지는
 * 않는다(활성화 요청 안에서 파일을 갈아 끼우지 않는다). 정리 기록에 남겨 명령이 「업데이트를 마저 끝내라」고 안내한다.
 */
final class StuckUpdateRecovery
{
    /**
     * 고착으로 보는 경과 시간(초) — 업데이트 잠금 유지 시간과 같다.
     */
    public const STALE_AFTER_SECONDS = ExtensionOperationLock::LOCK_SECONDS;

    /**
     * 이번 프로세스에서 정리한 건 — 명령이 결과 출력에 싣는다({@see takeRecovered()}).
     *
     * @var list<array{type: string, identifier: string, status: string, unfinished: array{from: string, to: string}|null}>
     */
    private static array $recovered = [];

    /**
     * 행이 멈춘 업데이트로 보이는지(시간 조건만) 판정합니다.
     *
     * @param  object  $record  확장 행 (`status`, `updated_at`)
     * @return bool `updating` 이고 마지막 갱신이 고착 기준보다 오래됐으면 true
     */
    public static function looksStale(object $record): bool
    {
        if (($record->status ?? null) !== ExtensionStatus::Updating->value) {
            return false;
        }

        $updatedAt = $record->updated_at ?? null;
        if ($updatedAt === null) {
            return true;
        }

        return Carbon::parse($updatedAt)->lt(Carbon::now()->subSeconds(self::STALE_AFTER_SECONDS));
    }

    /**
     * 행이 멈춘 업데이트인지(시간 조건 + 그 업데이트를 하는 프로세스 없음) 판정합니다 — 관리자 목록이 카드에 정리 단추를 보일지 정한다.
     *
     * 시간 조건을 만족하는 행에만 잠금을 확인한다(잡았다가 바로 푼다) — `updating` 이 아닌 대부분의 행은 저장소를 건드리지 않는다.
     *
     * @param  string  $type  확장 종류 (module|plugin|template)
     * @param  string  $identifier  확장 식별자
     * @param  object  $record  확장 행 (`status`, `updated_at`)
     * @return bool 멈춘 업데이트면 true
     */
    public static function isStuck(string $type, string $identifier, object $record): bool
    {
        if (! self::looksStale($record)) {
            return false;
        }

        try {
            ExtensionOperationLock::acquire($type, $identifier)->release();
        } catch (ExtensionOperationInProgressException) {
            return false;
        }

        return true;
    }

    /**
     * 멈춘 업데이트면 `inactive` 로 되돌리고 경고 로그를 남깁니다.
     *
     * @param  string  $type  확장 종류 (module|plugin|template)
     * @param  string  $identifier  확장 식별자
     * @param  object  $record  확장 행 (`status`, `updated_at`)
     * @param  callable(array<string, mixed>): mixed  $update  행 갱신 함수 (Repository 의 updateByIdentifier 위임)
     * @param  bool  $lockHeld  호출한 쪽이 이미 업데이트 잠금을 잡았는지 (업데이트 진입점)
     * @return string|null 되돌린 상태 (고착이 아니면 null)
     */
    public static function recover(string $type, string $identifier, object $record, callable $update, bool $lockHeld = false): ?string
    {
        if (! self::looksStale($record)) {
            return null;
        }

        // 잠금을 잡을 수 있어야 그 업데이트를 하는 프로세스가 없다 — 판정 뒤 바로 풀어 이어지는 작업이 다시 잡게 한다.
        if (! $lockHeld) {
            try {
                ExtensionOperationLock::acquire($type, $identifier)->release();
            } catch (ExtensionOperationInProgressException) {
                return null;
            }
        }

        $restored = ExtensionStatus::Inactive->value;
        $attributes = ['status' => $restored, 'updated_at' => Carbon::now()];
        $unfinished = self::unfinishedVersionChange($type, $identifier, $record);
        if ($unfinished !== null) {
            $attributes += self::revertVersionAttributes($unfinished);
        }
        $update($attributes);

        Log::warning('멈춘 확장 업데이트를 정리했습니다 — 업데이트 프로세스가 도중에 끝나 updating 상태로 남아 있었습니다', [
            'type' => $type,
            'identifier' => $identifier,
            'stuck_since' => (string) ($record->updated_at ?? ''),
            'restored_status' => $restored,
            'recorded_version' => (string) ($record->version ?? ''),
            'unfinished_from' => $unfinished?->fromVersion(),
            'unfinished_to' => $unfinished?->toVersion(),
            'completed_steps' => $unfinished?->completedSteps() ?? [],
        ]);

        self::$recovered[] = [
            'type' => $type,
            'identifier' => $identifier,
            'status' => $restored,
            'unfinished' => $unfinished !== null ? ['from' => $unfinished->fromVersion(), 'to' => $unfinished->toVersion()] : null,
        ];

        return $restored;
    }

    /**
     * 멈춘 업데이트가 판 전환을 끝내지 못했는지 진행 기록으로 판정합니다.
     *
     * 파일을 건드리기 전에 멈췄고 끝낸 스텝도 없으면 아무 일도 없던 것이므로 기록을 지우고 null 을 돌려준다.
     *
     * @param  string  $type  확장 종류 (module|plugin|template)
     * @param  string  $identifier  확장 식별자
     * @param  object  $record  확장 행 (`version`)
     * @return ExtensionUpdateJournal|null 끝나지 않은 판 전환의 기록 (없으면 null)
     */
    private static function unfinishedVersionChange(string $type, string $identifier, object $record): ?ExtensionUpdateJournal
    {
        $journals = ExtensionUpdateJournal::repository();
        $journal = $journals->find($type, $identifier);
        if ($journal === null) {
            return null;
        }

        $recordedVersion = (string) ($record->version ?? '');
        $untouched = ! $journal->filesTouched()
            && $journal->completedSteps() === []
            && version_compare($recordedVersion, $journal->fromVersion(), '==');

        if ($untouched) {
            $journals->delete($type, $identifier);

            return null;
        }

        return $journal;
    }

    /**
     * 끝나지 않은 판 전환을 「업데이트 있음」으로 되돌리는 칸 값을 만듭니다.
     *
     * DB 판을 시작 판으로 되돌려야 다음 업데이트가 `--force` 없이도 「업데이트 있음」으로 판정하고, 시작 판부터 못 끝낸 스텝을 돈다.
     * 관리자 카드가 업데이트 단추를 보이도록 `update_available`·`latest_version` 도 함께 맞춘다.
     *
     * @param  ExtensionUpdateJournal  $journal  끝나지 않은 판 전환의 기록
     * @return array<string, mixed> 행에 쓸 칸 값
     */
    private static function revertVersionAttributes(ExtensionUpdateJournal $journal): array
    {
        return [
            'version' => $journal->fromVersion(),
            'latest_version' => $journal->toVersion(),
            'update_available' => version_compare($journal->toVersion(), $journal->fromVersion(), '>'),
            ...(in_array($journal->source(), ['bundled', 'github'], true) ? ['update_source' => $journal->source()] : []),
        ];
    }

    /**
     * 정리한 건을 꺼내고 비웁니다 — 명령이 「멈춘 업데이트를 정리했습니다」 안내를 출력할 때 쓴다.
     *
     * @return list<array{type: string, identifier: string, status: string, unfinished: array{from: string, to: string}|null}> 정리한 건 (`unfinished` = 끝나지 않은 판 전환)
     */
    public static function takeRecovered(): array
    {
        $taken = self::$recovered;
        self::$recovered = [];

        return $taken;
    }

    /**
     * 진행 중 상태면 막습니다 — 멈춘 업데이트로 보이면 해결 방법(--force·활성화)을 알려 줍니다.
     *
     * @param  string  $identifier  확장 식별자 (문구용)
     * @param  object  $record  확장 행 (`status`, `updated_at`)
     * @return void 반환값 없음
     *
     * @throws \RuntimeException 진행 중인 작업이 있을 때
     */
    public static function assertNotInProgress(string $identifier, object $record): void
    {
        if (self::looksStale($record)) {
            throw new \RuntimeException(__('extensions.errors.operation_stuck', [
                'name' => $identifier,
                'minutes' => intdiv(self::STALE_AFTER_SECONDS, 60),
            ]));
        }

        ExtensionStatusGuard::assertNotInProgress(ExtensionStatus::from((string) $record->status), $identifier);
    }
}
