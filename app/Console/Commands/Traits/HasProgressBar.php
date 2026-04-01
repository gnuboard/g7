<?php

namespace App\Console\Commands\Traits;

use Symfony\Component\Console\Helper\ProgressBar;

/**
 * 확장 커맨드용 프로그레스바 트레이트.
 *
 * 2단계 Closure 콜백 패턴으로 동작:
 * 1. 단계 전환 ($step 지정): bar advance + 메시지 갱신
 * 2. 상세 표시 ($step = null): 파일명 전광판 표시
 */
trait HasProgressBar
{
    protected ?ProgressBar $progressBar = null;

    /**
     * 프로그레스 콜백을 생성합니다.
     *
     * @param  int  $steps  총 단계 수
     * @return \Closure 콜백 (?string $step, string $message)
     */
    protected function createProgressCallback(int $steps): \Closure
    {
        $this->progressBar = $this->output->createProgressBar($steps);
        $this->progressBar->setFormat(" %current%/%max% [%bar%] %message%\n   %detail%");
        $this->progressBar->setMessage('시작 중...');
        $this->progressBar->setMessage('', 'detail');
        $this->progressBar->start();

        return function (?string $step, string $message): void {
            if ($step !== null) {
                // 단계 전환: bar advance + 상세 초기화
                $this->progressBar->setMessage($message);
                $this->progressBar->setMessage('', 'detail');
                $this->progressBar->advance();
            } else {
                // 상세 갱신: 현재 파일명 전광판 표시
                $this->progressBar->setMessage("\u{2192} ".$message, 'detail');
                $this->progressBar->display();
            }
        };
    }

    /**
     * 프로그레스바를 완료합니다.
     */
    protected function finishProgress(): void
    {
        if ($this->progressBar) {
            $this->progressBar->setMessage('', 'detail');
            $this->progressBar->finish();
            $this->newLine();
        }
    }
}
