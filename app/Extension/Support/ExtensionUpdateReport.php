<?php

namespace App\Extension\Support;

use App\Exceptions\ExtensionUpdateFailedException;
use App\Extension\Helpers\ExtensionUpdateJournal;
use App\Extension\Helpers\StuckUpdateRecovery;

/**
 * 웹(관리자 화면) 확장 업데이트의 결과·실패 안내를 터미널과 같은 정보로 만드는 도우미 (7.2.1 출시 전).
 *
 * 터미널 `{type}:update` 는 판 변화·의존 경고·멈춘 업데이트 정리·실패 뒤 상태·재실행 명령을 보여 주지만, 웹 응답에는 그 값이
 * 빠져 있었다. 이 도우미는 **이미 있는 값**(매니저 결과·{@see ExtensionUpdateFailedException::$outcome}·
 * {@see StuckUpdateRecovery::takeRecovered()}·{@see ExtensionUpdateJournal})을 응답 모양으로 옮길 뿐, 업데이트 동작은 바꾸지 않는다.
 */
final class ExtensionUpdateReport
{
    /**
     * 성공 응답 `data` 에 더할 값을 만듭니다.
     *
     * @param  array<string, mixed>  $result  매니저(서비스) 업데이트 결과
     * @return array{from_version: string|null, to_version: string|null, dependency_warnings: array<int, mixed>, recovered: list<array<string, mixed>>|null, no_update: bool} 응답 값
     */
    public static function successFields(array $result): array
    {
        return [
            'from_version' => isset($result['from_version']) ? (string) $result['from_version'] : null,
            'to_version' => isset($result['to_version']) ? (string) $result['to_version'] : null,
            'dependency_warnings' => array_values((array) ($result['dependency_warnings'] ?? [])),
            'recovered' => self::recovered(),
            // 매니저는 업데이트할 것이 없으면 success=false 로 답한다 — 화면은 성공이 아니라 안내로 보인다
            'no_update' => ($result['success'] ?? true) === false,
        ];
    }

    /**
     * 이번 요청에서 정리한 멈춘 업데이트를 꺼냅니다 (터미널 「멈춘 업데이트를 정리했습니다」와 같은 정보).
     *
     * @return list<array{type: string, identifier: string, status: string, unfinished: array{from: string, to: string}|null, activate_command: string}>|null 정리한 건 (없으면 null)
     */
    public static function recovered(): ?array
    {
        $items = [];

        foreach (StuckUpdateRecovery::takeRecovered() as $item) {
            $items[] = [
                'type' => $item['type'],
                'identifier' => $item['identifier'],
                'status' => $item['status'],
                'unfinished' => $item['unfinished'] ?? null,
                // 정리는 확장을 비활성으로 되돌린다 — 다시 켜는 명령(터미널 안내와 같다)
                'activate_command' => "php artisan {$item['type']}:activate {$item['identifier']}",
            ];
        }

        return $items === [] ? null : $items;
    }

    /**
     * 실패 응답 `data` 에 실을 값을 만듭니다.
     *
     * @param  \Throwable  $e  업데이트 실패 예외
     * @param  string  $type  확장 종류 (module|plugin|template|lang_pack)
     * @param  string  $identifier  확장 식별자
     * @param  array{source?: string|null, vendor_mode?: string|null}  $options  요청 선택 (재실행 명령에 옮긴다)
     * @return array{outcome: string|null, warnings: array<int, mixed>, retry_command: string|null, recovered: list<array<string, mixed>>|null} 응답 값
     */
    public static function failureFields(\Throwable $e, string $type, string $identifier, array $options = []): array
    {
        $outcome = $e instanceof ExtensionUpdateFailedException ? $e->outcome : null;

        return [
            'outcome' => $outcome,
            'warnings' => LifecycleWarnings::current()->all(),
            'retry_command' => self::retryCommand($type, $identifier, $outcome, $options),
            'recovered' => self::recovered(),
        ];
    }

    /**
     * 터미널 재실행 명령을 만듭니다.
     *
     * 업데이트를 시작한 뒤의 실패(outcome 있음)에만 만든다 — 시작 전 거절(다른 작업 진행 중·소스 없음 등)은 바뀐 것이 없어
     * 사유만 보인다. 새 판이 남았거나(kept_new) 되돌리기가 실패했으면(restore_failed) 같은 판 재설치가 필요해 `--force` 를 붙인다.
     *
     * @param  string  $type  확장 종류 (module|plugin|template|lang_pack)
     * @param  string  $identifier  확장 식별자
     * @param  string|null  $outcome  실패 뒤 상태
     * @param  array{source?: string|null, vendor_mode?: string|null}  $options  요청 선택
     * @return string|null 명령 (만들지 않으면 null)
     */
    public static function retryCommand(string $type, string $identifier, ?string $outcome, array $options = []): ?string
    {
        if ($outcome === null) {
            return null;
        }

        $command = $type === 'lang_pack' ? 'language-pack' : $type;
        $parts = ["php artisan {$command}:update {$identifier}"];

        if (in_array($outcome, ['kept_new', 'restore_failed'], true)) {
            $parts[] = '--force';
        }

        $source = $options['source'] ?? null;
        if (is_string($source) && $source !== '' && $source !== 'auto') {
            $parts[] = "--source={$source}";
        }

        $vendorMode = $options['vendor_mode'] ?? null;
        if (is_string($vendorMode) && $vendorMode !== '' && $vendorMode !== 'auto') {
            $parts[] = "--vendor-mode={$vendorMode}";
        }

        return implode(' ', $parts);
    }

    /**
     * 목록 행의 「끝나지 않은 업데이트」 값을 만듭니다 — 이 서버의 진행 기록이 남아 있으면.
     *
     * @param  string  $type  확장 종류 (module|plugin|template)
     * @param  string  $identifier  확장 식별자
     * @return array{started_at: string|null, from_version: string, to_version: string}|null 진행 기록 (없으면 null)
     */
    public static function unfinishedUpdate(string $type, string $identifier): ?array
    {
        try {
            $journal = ExtensionUpdateJournal::find($type, $identifier);
        } catch (\Throwable) {
            // 기록을 읽지 못해도 목록은 보인다 — 진행 기록은 보조 정보다
            return null;
        }

        if ($journal === null) {
            return null;
        }

        return [
            'started_at' => $journal->toArray()['started_at'] ?? null,
            'from_version' => $journal->fromVersion(),
            'to_version' => $journal->toVersion(),
        ];
    }
}
