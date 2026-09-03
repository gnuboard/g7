<?php

namespace App\Console\Commands;

use App\Support\TrustedProxyDiagnostic;
use Illuminate\Console\Command;

/**
 * 신뢰 프록시 설정 상태 점검 커맨드 (#124)
 *
 * 프록시 뒤에서 신뢰 프록시가 설정되지 않으면 관리자 화면 자체가 뜨지 않을 수 있다. 화면으로
 * 확인할 수 없는 바로 그 상태에서 쓰는 통로가 이 커맨드다.
 *
 * 콘솔에는 요청이 없으므로 `isSecure()`·`ip()` 같은 **실측 항목은 판정할 수 없다.** 그 항목은
 * `판정 불가` 로 구분해 표시하고, 값이 비어 있는 것과 뭉뚱그리지 않는다 — 둘을 같은 문구로
 * 내보내면 운영자가 "설정이 비었다" 로 오독한다.
 *
 * 종료 코드: 설정이 없으면 1. 비-0 은 "커맨드 실행 실패" 가 아니라 **점검 대상 신호**다.
 * 다만 프록시를 쓰지 않는 직접 노출 구성에서는 미설정이 정상이므로, 그 사실을 출력에 명시한다.
 *
 * @since 7.0.10
 */
class TrustedProxyStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'trusted-proxy:status';

    /**
     * The console command description.
     */
    protected $description = '리버스 프록시 신뢰 설정(TRUSTED_PROXIES) 상태를 점검합니다';

    /**
     * Execute the console command.
     *
     * @return int 설정되어 있으면 0, 미설정이면 1
     */
    public function handle(): int
    {
        // 콘솔에는 요청이 없다 — null 을 넘겨 실측 축을 not_applicable 로 받는다.
        $diagnostic = TrustedProxyDiagnostic::forRequest(null);

        $this->line('');
        $this->line('<options=bold>리버스 프록시 신뢰 설정 상태</>');
        $this->line('');

        $this->line('  설정 파일        : config/trustedproxy.php');
        $this->line('  환경변수         : TRUSTED_PROXIES');

        if ($diagnostic['trusted_configured']) {
            $this->line('  현재 값          : '.$diagnostic['configured_proxies']);
        } else {
            $this->line('  현재 값          : <comment>미설정 (아무 프록시도 신뢰하지 않음)</comment>');
        }

        $this->line('');
        $this->line('  <options=bold>요청 기반 실측</>');
        $this->line('    수신 전달 헤더 : <comment>판정 불가 (콘솔에는 요청이 없습니다)</comment>');
        $this->line('    HTTPS 인식     : <comment>판정 불가</comment>');
        $this->line('    방문자 IP      : <comment>판정 불가</comment>');
        $this->line('');
        $this->comment('  실측 축은 웹 요청에서만 판정됩니다 — 관리자 대시보드 알림 또는');
        $this->comment('  환경설정 > 고급 의 진단 블록에서 확인하세요.');

        $this->line('');

        if ($diagnostic['trusted_configured']) {
            $this->info('신뢰 프록시가 설정되어 있습니다. X-Forwarded-* 헤더를 신뢰합니다.');
            $this->line('');

            return self::SUCCESS;
        }

        $this->warn('• 신뢰 프록시가 설정되어 있지 않습니다.');
        $this->line('');
        $this->line('  프록시(AWS ALB, CloudFront, Cloudflare, nginx, ngrok) 뒤에서 구동 중이라면');
        $this->line('  .env 에 TRUSTED_PROXIES 를 지정하세요. 미설정 시 접속 주소·방문자 IP 가');
        $this->line('  프록시 기준으로 인식되어 화면 표시·IP 기록·결제 통보 수신이 어긋납니다.');
        $this->line('  프록시를 쓰지 않는 직접 노출 구성이라면 미설정이 정상입니다.');
        $this->line('  상세: https://github.com/gnuboard/g7/blob/main/docs/backend/reverse-proxy.md');
        $this->line('');

        return self::FAILURE;
    }
}
