<?php

namespace App\Console\Commands\Traits;

use App\Extension\Support\LifecycleWarnings;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 생명주기 명령의 부분 실패 경고를 출력하고 종료코드 1 로 알립니다 (7.1.3).
 *
 * 여러 파일·항목을 처리하는 명령(레이아웃 등록, 언어팩 시더 재실행 등)은 항목 하나가 실패해도 작업을
 * 계속하고 성공으로 끝낸다. 그 실패를 로그에만 남기면 ✅ 만 보고 지나치므로, 명령이 실행되는 동안
 * `LifecycleWarnings` 에 쌓인 경고를 끝에서 표(단계·대상·사유)로 보이고 종료코드 1 을 돌려준다.
 *
 * 이 명령을 자식 프로세스로 부르는 쪽(설치 마법사·훅 캐시 새 프로세스)은 출력에 {@see LifecycleWarnings::CLI_MARKER}
 * 가 있으면 「완료했지만 경고 있음」으로, 없으면 실패로 판정한다({@see LifecycleWarnings::cliCompletedWithWarnings()}).
 */
trait ReportsLifecycleWarnings
{
    /** 이 명령이 시작할 때의 경고 수집기 위치 — 완료 표시(✅/⚠️) 판정에 쓴다 */
    private ?int $lifecycleWarningsMark = null;

    /**
     * 완료 줄 앞에 붙일 표시를 돌려줍니다 — 기준점 이후 실패 경고가 쌓였으면 ⚠️, 아니면 ✅.
     *
     * 안내(notice)만 있으면 실패가 아니므로 ✅ 를 유지한다. 기준점을 주지 않으면 명령 시작 시점이다.
     *
     * @param  int|null  $since  항목별 판정 기준점({@see lifecycleMark()}), null 이면 명령 시작 시점
     * @return string 완료 표시 이모지
     */
    protected function lifecycleDoneIcon(?int $since = null): string
    {
        $since ??= $this->lifecycleWarningsMark;
        if ($since === null) {
            return '✅';
        }

        return LifecycleWarnings::hasFailures(LifecycleWarnings::current()->since($since)) ? '⚠️' : '✅';
    }

    /**
     * 지금까지 쌓인 경고 위치를 돌려줍니다 — 여러 항목을 도는 명령이 항목별 완료 표시를 판정할 때 쓴다.
     *
     * @return int 경고 수집기 위치
     */
    protected function lifecycleMark(): int
    {
        return LifecycleWarnings::current()->mark();
    }

    /**
     * 실패 경고가 있을 때 종료코드를 1 로 바꿀지 돌려줍니다.
     *
     * 코어 업데이트 명령(`core:update` 와 그 자식 명령)은 경고 표만 보이고 성공(0)을 유지한다 — 부모 명령과 업데이트를
     * 자동화한 스크립트가 끝난 업데이트를 실패로 보고 되돌리지 않게 한다. 그 명령들은 false 로 덮는다.
     *
     * @return bool 종료코드를 1 로 바꾸면 true
     */
    protected function lifecycleWarningsChangeExitCode(): bool
    {
        return true;
    }

    /**
     * 명령을 실행하고, 실행 중 쌓인 경고가 있으면 출력한 뒤 종료코드 1 을 돌려줍니다.
     *
     * @param  InputInterface  $input  콘솔 입력
     * @param  OutputInterface  $output  콘솔 출력
     * @return int 종료코드 (본 명령이 0 이었고 경고가 있으면 1)
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $warnings = LifecycleWarnings::current();
        $mark = $warnings->mark();
        $this->lifecycleWarningsMark = $mark;

        $exitCode = parent::execute($input, $output);

        $added = $warnings->since($mark);
        if ($added === []) {
            return $exitCode;
        }

        // 안내만 있으면(압축 경로 정규화 등) 실패가 아니다 — 표만 보이고 종료코드·표지는 바꾸지 않는다
        $hasFailures = LifecycleWarnings::hasFailures($added);

        $this->newLine();
        $hasFailures
            ? $this->warn(__('extensions.warnings.completed_with_warnings', ['count' => count($added)]))
            : $this->info(__('extensions.warnings.completed_with_notices', ['count' => count($added)]));
        $this->table(
            [__('extensions.warnings.column_step'), __('extensions.warnings.column_target'), __('extensions.warnings.column_message')],
            array_map(fn (array $w) => [$w['step'], $w['target'], $w['message']], $added),
        );

        // 표지는 「작업은 끝났고 경고만 있다」는 뜻이다 — 본 명령이 실패로 끝났으면 찍지 않아야 부르는 쪽이 실패로 판정한다
        if (! $hasFailures || ! $this->lifecycleWarningsChangeExitCode() || $exitCode !== Command::SUCCESS) {
            return $exitCode;
        }

        $this->line(LifecycleWarnings::CLI_MARKER.' count='.count($added));

        return Command::FAILURE;
    }
}
