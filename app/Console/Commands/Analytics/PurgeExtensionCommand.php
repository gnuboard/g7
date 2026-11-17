<?php

namespace App\Console\Commands\Analytics;

use App\Console\Commands\Traits\HasUnifiedConfirm;
use App\Console\Commands\Traits\LocalizesDescription;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Enums\ExtensionStatus;
use App\Services\Analytics\ExtensionPurgeService;
use Illuminate\Console\Command;

/**
 * 확장 통계 정리 커맨드
 *
 * 확장이 남긴 전환·차원 집계를 지웁니다. 확장을 「데이터 보존」으로 제거한 뒤 나중에
 * 지우거나, 제거 때의 정리가 실패(진단 화면 실행 이력 「실패」)한 뒤 다시 돌릴 때 씁니다.
 * 되돌릴 수 없으므로 --confirm 을 반드시 붙여야 하고, 지우기 전에 터미널에서 한 번 더 묻습니다.
 * 그 확인에 답할 수 없는 실행(cron, CI, 원격 명령, --no-interaction)은 지우지 않고 실패로 끝납니다.
 * 확인 없이 지워야 하면 --yes 를 함께 붙입니다(7.1.6 과 같은 형태, #918).
 *
 * @example php artisan analytics:purge-extension module sirsoft-ecommerce --confirm
 */
class PurgeExtensionCommand extends Command
{
    use HasUnifiedConfirm;
    use LocalizesDescription;

    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'analytics:purge-extension
        {type : 확장 종류 (module, plugin)}
        {identifier : 확장 식별자}
        {--confirm : 되돌릴 수 없음을 확인}
        {--force : 켜져 있는 확장의 통계도 지운다}
        {--y|yes : 확인 질문에 「예」로 답하고 진행 (터미널 없는 실행의 비대화형 동의, --confirm 과 함께)}';

    /**
     * 커맨드 실행
     *
     * @param  ExtensionPurgeService  $service  확장 통계 정리기
     * @param  ModuleManagerInterface  $modules  모듈 관리자 — 대상의 설치·활성 여부
     * @param  PluginManagerInterface  $plugins  플러그인 관리자 — 대상의 설치·활성 여부
     * @return int 종료 코드
     */
    public function handle(ExtensionPurgeService $service, ModuleManagerInterface $modules, PluginManagerInterface $plugins): int
    {
        $type = (string) $this->argument('type');
        $identifier = (string) $this->argument('identifier');

        if (! in_array($type, ['module', 'plugin'], true) || $identifier === '') {
            $this->error(__('analytics.console.purge_extension.invalid_arguments'));

            return self::FAILURE;
        }

        // 켜져 있는 확장의 전환을 지우면 그 확장이 계속 쌓는 수치와 어긋나고, 「방문자당 한 번」
        // 목표의 중복 판정 근거도 사라진다. 이 커맨드의 용도는 제거한(또는 끈) 확장의 뒷정리다.
        $info = $type === 'module' ? $modules->getModuleInfo($identifier) : $plugins->getPluginInfo($identifier);
        $installed = (bool) ($info['is_installed'] ?? false);
        $status = $info['status'] ?? null;
        $status = $status instanceof \BackedEnum ? $status->value : (string) $status;

        if ($installed && $status === ExtensionStatus::Active->value && ! $this->option('force')) {
            $this->error(__('analytics.console.purge_extension.extension_active', ['type' => $type, 'identifier' => $identifier]));

            return self::FAILURE;
        }

        if (! $this->option('confirm')) {
            $this->error(__('analytics.console.purge_extension.confirm_option_required'));

            return self::FAILURE;
        }

        if (! $installed) {
            // 「데이터 보존」으로 제거한 확장이 이 커맨드의 주 대상이라 막지는 않는다 — 다만 식별자
            // 오타는 「0건 삭제 성공」으로 끝나므로 확인 전에 알린다.
            $this->warn(__('analytics.console.purge_extension.not_installed', ['type' => $type, 'identifier' => $identifier]));
        }

        // 확인에 답할 수 없는 실행(cron, CI, 원격 명령, --no-interaction)은 종전에 기본값 「아니오」로 취소 문구만 내고
        // 종료 코드 0 으로 끝나 자동화가 성공으로 읽었다. 그런 실행은 사유와 터미널 확인 안내를 남기고 실패로 끝낸다.
        // 대화형에서 운영자가 직접 「아니오」를 답한 취소는 의도이므로 종전대로 0 이다.
        // --confirm 은 필수 동의 표식일 뿐 비대화형 동의로 보지 않는다. 비대화형 동의는 --yes 하나다(7.1.6 과 같은 형태).
        if (! $this->canAnswerConfirmation()) {
            return $this->refuseUnanswerableConfirmation(
                __('analytics.console.purge_extension.confirm_unavailable', ['type' => $type, 'identifier' => $identifier]),
                __('analytics.console.purge_extension.confirm_unavailable_hint', ['type' => $type, 'identifier' => $identifier]),
            );
        }

        if (! $this->unifiedConfirm(__('analytics.console.purge_extension.confirm', ['type' => $type, 'identifier' => $identifier]))) {
            $this->info(__('analytics.console.purge_extension.cancelled'));

            return $this->confirmAbortExitCode();
        }

        $result = $service->purge($type, $identifier);

        if ($result['failed']) {
            // 서비스는 확장 제거 흐름을 막지 않으려고 예외를 삼킨다. 커맨드는 운영자가 직접
            // 부른 것이므로 실패를 종료 코드로 알린다 — 「0건 삭제」가 성공처럼 보이면 안 된다.
            $this->error(__('analytics.console.purge_extension.failed'));

            return self::FAILURE;
        }

        $this->table([__('analytics.console.purge_extension.headers.target'), __('analytics.console.purge_extension.headers.deleted')], [
            ['conversions', $result['conversions']],
            ['dimensions', $result['dimensions']],
        ]);

        return self::SUCCESS;
    }
}
