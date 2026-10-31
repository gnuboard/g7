<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * 확장(모듈·플러그인·템플릿) 업데이트 실패 — 실패 뒤 실제로 어떤 상태가 남았는지를 함께 싣는다.
 *
 * 종전에는 명령이 실패 원인과 무관하게 「백업에서 이전 버전이 복원되었습니다」를 출력해, 백업을 지운 뒤(마무리 단계) 실패해 새 판이
 * 그대로 남은 경우에도 복원됐다고 안내했다(W3 리허설 — 안내와 실제 DB·파일 불일치).
 *
 * 결과(outcome):
 *  - `restored`       백업에서 이전 판 파일을 되돌렸고 버전 기록도 되돌렸다
 *  - `restore_failed` 되돌리려 했으나 실패했다 — 파일이 섞였을 수 있다
 *  - `kept_new`       백업을 지운 뒤(마무리 단계) 실패했다 — 새 판 파일·버전 기록이 그대로다
 *  - `unchanged`      파일을 바꾸기 전에 실패했다 — 바뀐 것이 없다
 */
class ExtensionUpdateFailedException extends RuntimeException
{
    /**
     * @param  string  $message  실패 안내 문구
     * @param  string  $outcome  실패 뒤 상태 (`restored`·`restore_failed`·`kept_new`·`unchanged`)
     * @param  Throwable|null  $previous  원래 예외
     */
    public function __construct(string $message, public readonly string $outcome, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * 매니저 실패 처리의 상태로 예외를 만듭니다.
     *
     * @param  string  $message  실패 안내 문구
     * @param  bool  $filesRestored  백업에서 파일을 되돌렸는지
     * @param  bool  $hadBackup  실패 시점에 되돌릴 백업이 있었는지 (백업 삭제 전)
     * @param  bool  $versionRecorded  새 버전 기록을 이미 저장했는지
     * @param  Throwable  $previous  원래 예외
     * @return self 예외
     */
    public static function fromFailure(string $message, bool $filesRestored, bool $hadBackup, bool $versionRecorded, Throwable $previous): self
    {
        return new self($message, self::outcomeFor($filesRestored, $hadBackup, $versionRecorded), $previous);
    }

    /**
     * 실패 뒤 상태를 판정합니다.
     *
     * @param  bool  $filesRestored  백업에서 파일을 되돌렸는지
     * @param  bool  $hadBackup  실패 시점에 되돌릴 백업이 있었는지
     * @param  bool  $versionRecorded  새 버전 기록을 이미 저장했는지
     * @return string 결과 (`restored`·`restore_failed`·`kept_new`·`unchanged`)
     */
    public static function outcomeFor(bool $filesRestored, bool $hadBackup, bool $versionRecorded): string
    {
        return match (true) {
            $filesRestored => 'restored',
            $hadBackup => 'restore_failed',
            $versionRecorded => 'kept_new',
            default => 'unchanged',
        };
    }
}
