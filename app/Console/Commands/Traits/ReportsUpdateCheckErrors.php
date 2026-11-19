<?php

namespace App\Console\Commands\Traits;

use Illuminate\Console\Command;

/**
 * 업데이트 확인의 GitHub 조회 실패(`check_error`)를 명령 출력·종료 코드로 드러내는 트레이트.
 *
 * 매니저의 업데이트 확인(check*Update)은 GitHub 조회에 실패해도 예외 없이 번들로 대체하거나 「업데이트 없음」으로 답하고,
 * 실패 사유를 `check_error` 로 싣는다. 이 트레이트는 그 사유를 운영자와 비대화형 실행(cron·스크립트)이 알 수 있게 한다:
 * `--source=github` 처럼 GitHub 를 명시했으면 실패(종료 코드 1)로 끝내고, 소스를 지정하지 않았으면 번들 판정은 그대로
 * 두고 경고 한 줄을 낸다. `*:check-updates` 는 실패 표를 그리고 종료 코드 1 로 끝낸다.
 *
 * @mixin Command
 */
trait ReportsUpdateCheckErrors
{
    /**
     * 업데이트 확인 결과의 GitHub 조회 실패를 알립니다.
     *
     * @param  array<string, mixed>  $checkResult  업데이트 확인 결과
     * @param  string|null  $source  소스 지정 (`github`·`bundled`, null 이면 자동)
     * @param  string  $identifier  확장 식별자
     * @return bool GitHub 를 명시했는데 조회에 실패해 명령을 실패로 끝내야 하면 true
     */
    protected function reportUpdateCheckError(array $checkResult, ?string $source, string $identifier): bool
    {
        $reason = $checkResult['check_error'] ?? null;

        if (! is_string($reason) || $reason === '') {
            return false;
        }

        if ($source === 'github') {
            $this->error('❌ '.__('extensions.commands.update.github_check_failed', ['identifier' => $identifier, 'reason' => $reason]));

            return true;
        }

        $this->warn('⚠️  '.__('extensions.commands.update.github_check_fallback', ['identifier' => $identifier, 'reason' => $reason]));

        return false;
    }

    /**
     * 업데이트 확인에 실패한 확장 표를 그리고 종료 코드를 돌려줍니다.
     *
     * @param  list<array{identifier: string, reason: string}>  $failures  조회 실패 목록
     * @return int 실패가 하나라도 있으면 `Command::FAILURE`, 없으면 `Command::SUCCESS`
     */
    protected function updateCheckFailuresExitCode(array $failures): int
    {
        if ($failures === []) {
            return Command::SUCCESS;
        }

        $this->newLine();
        $this->error('❌ '.trans_choice('extensions.commands.check_updates.failed_header', count($failures), ['count' => count($failures)]));
        $this->table(
            [__('extensions.commands.check_updates.failed_columns.identifier'), __('extensions.commands.check_updates.failed_columns.reason')],
            array_map(fn (array $failure) => [$failure['identifier'], $failure['reason']], $failures),
        );
        $this->line(__('extensions.commands.check_updates.failed_hint'));

        return Command::FAILURE;
    }
}
