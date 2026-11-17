<?php

namespace App\Console\Commands\Layout;

use App\Console\Commands\Traits\HasUnifiedConfirm;
use App\Console\Commands\Traits\LocalizesDescription;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Services\Layout\StageLayoutTableService;
use App\Support\RuntimeRole;
use Illuminate\Console\Command;

/**
 * 검수 서버의 레이아웃 테이블을 운영 레이아웃으로 덮어씁니다 (`stage-layouts:reset-from-live`).
 *
 * 방향은 늘 운영 → 검수 서버다. 검수 서버에서 고친 레이아웃을 운영으로 올리는 명령이 아니며, 그런 기능은 없다 — 이름에 방향
 * (`from-live`)과 파괴성(`reset`)을 넣고, `--yes` 로 확인을 건너뛰어도 실행 전에 방향과 지워질 검수 서버 편집본 수를 늘 출력한다.
 *
 * 검수 서버에서 돈 업그레이드 스텝은 운영 레이아웃 테이블을 고친다(이미 출시된 스텝은 역할을 모른다). 그래서 검수 서버의 갱신 순서는
 * 「업그레이드 스텝 → stage-layouts:reset-from-live → *:refresh-layout」 이다 — 운영 행을 다시 복사하므로 결과가 일관된다.
 * 검수 서버(stage 역할)에서만 실행한다. 운영 테이블에는 쓰지 않는다.
 *
 * @since 7.2.0
 */
class StageLayoutsResetFromLiveCommand extends Command
{
    use ClearsTemplateCaches;
    use HasUnifiedConfirm;
    use LocalizesDescription;

    /**
     * @var string 명령 시그니처
     */
    protected $signature = 'stage-layouts:reset-from-live
        {--yes : 확인 질문에 「예」로 답하고 진행 (운영 → 검수 서버 방향. 검수 서버의 레이아웃 편집본과 그 버전 기록이 지워진다)}';

    /**
     * @var string 명령 설명 (LocalizesDescription 이 다국어로 바꾼다)
     */
    protected $description = 'common.console.stage_layouts.description';

    /**
     * 운영 레이아웃을 스테이지 테이블에 복사합니다.
     *
     * @param  StageLayoutTableService  $service  스테이지 테이블 서비스
     * @return int 종료 코드
     */
    public function handle(StageLayoutTableService $service): int
    {
        if (! RuntimeRole::isStage()) {
            $this->error(__('common.console.stage_layouts.live_refused'));

            return self::FAILURE;
        }

        if (! $service->available()) {
            $this->error(__('common.console.stage_layouts.tables_missing'));

            return self::FAILURE;
        }

        // 방향과 지워질 편집본 수는 확인 질문보다 먼저, `--yes` 여도 출력한다 — 확인을 건너뛰는 실행에서 방향을 알려 줄 문구가 이것뿐이다
        $this->warn(__('common.console.stage_layouts.direction'));
        $this->warn(__('common.console.stage_layouts.discard_summary', $service->stageDivergence()));

        // `--yes` 는 확인에 미리 답한다 — canAnswerConfirmation()·unifiedConfirm() 이 그 옵션을 보고 묻지 않고 진행한다
        if (! $this->canAnswerConfirmation()) {
            return $this->refuseUnanswerableConfirmation(
                __('common.console.stage_layouts.confirm_unavailable'),
                __('common.console.stage_layouts.confirm_hint'),
            );
        }
        if (! $this->unifiedConfirm(__('common.console.stage_layouts.confirm_question'), false)) {
            $this->info(__('common.console.stage_layouts.aborted'));

            return self::SUCCESS;
        }

        $result = $service->resetFromLive();

        // 검수 서버의 레이아웃 캐시(코드 범위)를 버린다 — 운영 서버 캐시는 그대로다
        $this->incrementExtensionCacheVersion();

        $this->info(__('common.console.stage_layouts.copied', ['layouts' => $result['layouts'], 'extensions' => $result['extensions']]));
        if (! $result['checksum_match']) {
            $this->error(__('common.console.stage_layouts.checksum_mismatch'));

            return self::FAILURE;
        }
        $this->info(__('common.console.stage_layouts.checksum_ok'));

        return self::SUCCESS;
    }
}
