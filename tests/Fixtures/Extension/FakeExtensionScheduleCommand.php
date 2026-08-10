<?php

namespace Modules\G7Testing\Console;

use Illuminate\Console\Command;

/**
 * 스케줄 Artisan 허용목록의 "확장 소유 명령" 계층을 검증하기 위한 테스트 전용 명령.
 *
 * 확장 소유 판정은 등록된 명령 인스턴스의 클래스 네임스페이스(`Modules\` / `Plugins\`)로
 * 이뤄지므로, 판정 대상이 되려면 클래스가 그 네임스페이스에 있어야 한다. 설치된 확장에서
 * 아무 명령이나 골라 쓰면 확장 구성에 따라 테스트가 조용히 skip 되어 계층 자체가
 * 검증되지 않으므로, 이 픽스처를 런타임에 등록해 결과를 고정한다.
 *
 * `Tests\` PSR-4 네임스페이스가 아니므로 오토로드되지 않는다 — 사용처에서 require 한다.
 */
class FakeExtensionScheduleCommand extends Command
{
    /** @var string 명령 시그니처 ({확장}:{작업} 패턴을 따르지 않는 이름도 허용되는지 함께 확인) */
    protected $signature = 'g7-testing-fake-extension-command
        {--scope= : 확장 명령이 자기 정의로 선언한 옵션}';

    /** @var string 명령 설명 */
    protected $description = '스케줄 허용목록의 확장 계층 검증용 테스트 명령';

    /**
     * 실행되지 않는 테스트 픽스처입니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        return self::SUCCESS;
    }
}
