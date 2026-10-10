<?php

namespace App\Console\Commands\Analytics;

use App\Console\Commands\Traits\HasUnifiedConfirm;
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
 * 되돌릴 수 없으므로 --confirm 을 반드시 붙여야 하고, 대화형 실행에서는 한 번 더 묻습니다.
 *
 * @example php artisan analytics:purge-extension module sirsoft-ecommerce --confirm
 */
class PurgeExtensionCommand extends Command
{
    use HasUnifiedConfirm;

    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'analytics:purge-extension
        {type : 확장 종류 (module, plugin)}
        {identifier : 확장 식별자}
        {--confirm : 되돌릴 수 없음을 확인}
        {--force : 켜져 있는 확장의 통계도 지운다}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '확장 하나가 남긴 방문자 통계 전환·분류 기준 집계를 삭제합니다 (되돌릴 수 없음).';

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
            $this->error('확장 종류는 module 또는 plugin 이고 식별자가 필요합니다.');

            return self::FAILURE;
        }

        // 켜져 있는 확장의 전환을 지우면 그 확장이 계속 쌓는 수치와 어긋나고, 「방문자당 한 번」
        // 목표의 중복 판정 근거도 사라진다. 이 커맨드의 용도는 제거한(또는 끈) 확장의 뒷정리다.
        $info = $type === 'module' ? $modules->getModuleInfo($identifier) : $plugins->getPluginInfo($identifier);
        $installed = (bool) ($info['is_installed'] ?? false);
        $status = $info['status'] ?? null;
        $status = $status instanceof \BackedEnum ? $status->value : (string) $status;

        if ($installed && $status === ExtensionStatus::Active->value && ! $this->option('force')) {
            $this->error("{$type} {$identifier} 는 켜져 있습니다. 끄거나 제거한 뒤 실행하거나, 켜진 채로 지우려면 --force 를 붙이세요.");

            return self::FAILURE;
        }

        if (! $this->option('confirm')) {
            $this->error('확장 통계 정리는 --confirm 을 붙여야 실행됩니다. 되돌릴 수 없습니다.');

            return self::FAILURE;
        }

        if (! $installed) {
            // 「데이터 보존」으로 제거한 확장이 이 커맨드의 주 대상이라 막지는 않는다 — 다만 식별자
            // 오타는 「0건 삭제 성공」으로 끝나므로 확인 전에 알린다.
            $this->warn("{$type} {$identifier} 는 설치되어 있지 않습니다. 데이터를 남기고 제거한 확장이면 그대로 진행하고, 식별자를 잘못 적었다면 취소하세요.");
        }

        if (! $this->unifiedConfirm("{$type} {$identifier} 의 방문자 통계 전환·차원 집계를 지웁니다. 되돌릴 수 없습니다. 진행할까요?")) {
            $this->info('취소했습니다.');

            return self::SUCCESS;
        }

        $result = $service->purge($type, $identifier);

        if ($result['failed']) {
            // 서비스는 확장 제거 흐름을 막지 않으려고 예외를 삼킨다. 커맨드는 운영자가 직접
            // 부른 것이므로 실패를 종료 코드로 알린다 — 「0건 삭제」가 성공처럼 보이면 안 된다.
            $this->error('정리에 실패했습니다. 진단 화면의 실행 이력과 애플리케이션 로그에서 사유를 확인하세요.');

            return self::FAILURE;
        }

        $this->table(['대상', '삭제'], [
            ['conversions', $result['conversions']],
            ['dimensions', $result['dimensions']],
        ]);

        return self::SUCCESS;
    }
}
