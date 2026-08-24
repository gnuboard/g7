<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Console;

use Illuminate\Console\Command;
use Plugins\Sirsoft\MessageBizppurio\Services\BizppurioTemplateService;

/**
 * 알림톡 템플릿 검수 상태 동기화 커맨드 (#597 §3.4).
 *
 * 검수중(requested) 행이 있을 때만 카카오 관리 API 를 호출해 상태를 일괄 대조한다
 * (senderKey 별 template/list 1회 — 행별 detail 호출 없음, 레이트리밋 보호). 반려로
 * 전이한 행만 사유(comments) 확보를 위해 detail 을 추가 호출한다.
 *
 * plugin.php::getSchedules() 가 30분 주기로 등록하며, 화면의 수동 [새로고침]이 동등
 * 기능을 제공하므로 cron 미가동 환경에서도 승인 확인이 가능하다.
 */
class SyncTemplateStatusCommand extends Command
{
    /**
     * 커맨드 시그니처
     *
     * @var string
     */
    protected $signature = 'bizppurio:sync-template-status';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '검수중인 비즈뿌리오 알림톡 템플릿의 카카오 검수 상태를 동기화합니다';

    /**
     * 커맨드를 실행합니다.
     *
     * @param  BizppurioTemplateService  $service  템플릿 라이프사이클 서비스
     * @return int 종료 코드 (항상 SUCCESS — 조회 실패는 서비스가 로그로 남기고 다음 주기에 재시도)
     */
    public function handle(BizppurioTemplateService $service): int
    {
        $result = $service->syncRequested();

        if ($result['checked'] === 0) {
            $this->info('검수중(requested) 템플릿이 없어 카카오 조회를 건너뜁니다.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '검수중 템플릿 %d건 점검, %d건 상태 전이.',
            $result['checked'],
            $result['transitioned'],
        ));

        return self::SUCCESS;
    }
}
