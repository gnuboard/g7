<?php

namespace Plugins\Sirsoft\Gdpr\Console\Commands;

use Illuminate\Console\Command;
use Plugins\Sirsoft\Gdpr\Services\GdprAnnualNoticeService;

/** 설정에 따라 개인정보 연간 통지를 유계 접수합니다. */
class SendAnnualNotices extends Command
{
    protected $signature = 'sirsoft-gdpr:send-annual-notices {--scheduled : 스케줄러 실행}';

    protected $description = '활성 회원의 개인정보 연간 통지를 접수합니다';

    /**
     * 등록된 개별 처리 메서드에 작업을 위임합니다.
     *
     * @param  GdprAnnualNoticeService  $notices  통지 서비스 또는 이력 저장소
     * @return int 처리 결과
     */
    public function handle(GdprAnnualNoticeService $notices): int
    {
        $this->info(__('sirsoft-gdpr::messages.annual_notice.queued_count', ['count' => $notices->run()]));

        return self::SUCCESS;
    }
}
