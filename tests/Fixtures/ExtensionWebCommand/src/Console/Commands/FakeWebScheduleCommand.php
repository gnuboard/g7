<?php

namespace Modules\G7TestingWeb\Console\Commands;

use Illuminate\Console\Command;

/**
 * 웹 요청에서 확장 명령을 찾아 검사·실행하는 경로를 검증하기 위한 테스트 전용 명령.
 *
 * 프로바이더가 등록·선언하지 않은 확장 명령(실제 번들 확장의 일반 형태)이 관리자 화면
 * 「즉시 실행」에서 허용·실행되는지 확인한다.
 *
 * `Tests\` PSR-4 네임스페이스가 아니므로 오토로드되지 않는다 — 사용처에서 require 한다.
 */
class FakeWebScheduleCommand extends Command
{
    /** @var string 명령 시그니처 */
    protected $signature = 'g7-testing-web-command
        {--scope= : 확장 명령이 자기 정의로 선언한 옵션}';

    /** @var string 명령 설명 */
    protected $description = '웹 요청 확장 명령 해석 검증용 테스트 명령';

    /**
     * 실행되었음을 출력으로 남깁니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        $this->line('g7-testing-web-command ran');

        return self::SUCCESS;
    }
}
