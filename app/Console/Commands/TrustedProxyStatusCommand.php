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
        $this->line('<options=bold>'.__('common.console.trusted_proxy_status.title').'</>');
        $this->line('');

        $this->line('  '.__('common.console.trusted_proxy_status.config_file', ['path' => 'config/trustedproxy.php']));
        $this->line('  '.__('common.console.trusted_proxy_status.env_var', ['name' => 'TRUSTED_PROXIES']));

        if ($diagnostic['trusted_configured']) {
            $this->line('  '.__('common.console.trusted_proxy_status.current_value', ['value' => $diagnostic['configured_proxies']]));
        } else {
            $this->line('  '.__('common.console.trusted_proxy_status.current_value', ['value' => '<comment>'.__('common.console.trusted_proxy_status.unset').'</comment>']));
        }

        $this->line('');
        $this->line('  <options=bold>'.__('common.console.trusted_proxy_status.measured_title').'</>');
        $this->line('    '.__('common.console.trusted_proxy_status.forwarded_headers', ['value' => '<comment>'.__('common.console.trusted_proxy_status.undetermined_no_request').'</comment>']));
        $this->line('    '.__('common.console.trusted_proxy_status.https_detection', ['value' => '<comment>'.__('common.console.trusted_proxy_status.undetermined').'</comment>']));
        $this->line('    '.__('common.console.trusted_proxy_status.visitor_ip', ['value' => '<comment>'.__('common.console.trusted_proxy_status.undetermined').'</comment>']));
        $this->line('');
        $this->comment('  '.__('common.console.trusted_proxy_status.web_only_hint'));

        $this->line('');

        if ($diagnostic['trusted_configured']) {
            $this->info(__('common.console.trusted_proxy_status.configured'));
            $this->line('');

            return self::SUCCESS;
        }

        $this->warn('• '.__('common.console.trusted_proxy_status.not_configured'));
        $this->line('');
        $this->line('  '.__('common.console.trusted_proxy_status.not_configured_help'));
        $this->line('  '.__('common.console.trusted_proxy_status.details', ['url' => 'https://github.com/gnuboard/g7/blob/main/docs/backend/reverse-proxy.md']));
        $this->line('');

        return self::FAILURE;
    }
}
