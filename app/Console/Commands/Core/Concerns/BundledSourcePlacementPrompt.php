<?php

namespace App\Console\Commands\Core\Concerns;

use App\Services\Core\BundledInventory;
use App\Services\Core\BundledPlacementPlan;
use App\Services\Core\BundledSourcePlanner;
use App\Support\LocalizedValue;

/**
 * 코어 업데이트가 이 프로젝트에 없는 공식 번들 소스를 배치할지 묻고 결과를 요약하는 트레이트 (공개 #146).
 *
 * 설치된 확장, 그 의존 번들, 이미 디스크에 있는 번들은 묻지 않고 항상 갱신한다. 나머지(디스크에도 없고
 * 설치되지도 않은 번들)는 [배치하지 않음 / 모두 배치 / 골라서 배치] 를 묻는다. 비대화형(`--force`,
 * `--no-interaction`, 응답을 받을 수 없는 실행)은 `--bundled-sources` 를 따르고, 없으면 배치하지 않는다.
 *
 * 이 트레이트를 쓰는 Command 는 HasUnifiedConfirm 도 함께 써야 한다(`canPromptForAnswer`).
 */
trait BundledSourcePlacementPrompt
{
    /**
     * 번들 배치 판정을 내립니다 — 옵션이 있으면 따르고, 대화형이면 묻고, 아니면 배치하지 않습니다.
     *
     * @param  BundledSourcePlanner  $planner  판정 서비스
     * @param  BundledInventory  $inventory  새 배포본의 릴리스 인벤토리
     * @param  string|null  $rawOption  `--bundled-sources` 값
     * @param  bool  $inMaintenance  유지보수 모드 중에 묻는지 (경고 문구 표시)
     * @param  \Closure  $log  업데이트 로그 수집 콜백
     * @return BundledPlacementPlan 판정 결과
     *
     * @throws \InvalidArgumentException 옵션에 배포본에 없거나 모호한 식별자가 있을 때
     */
    protected function decideBundledPlacement(
        BundledSourcePlanner $planner,
        BundledInventory $inventory,
        ?string $rawOption,
        bool $inMaintenance,
        \Closure $log,
    ): BundledPlacementPlan {
        $selection = $planner->resolveSelection($inventory, $rawOption);

        if ($selection !== null) {
            $plan = $planner->plan($inventory, $selection['mode'] === 'all' ? 'all' : $selection['keys']);
            $this->line(__('settings.bundled_sources.option_applied', [
                'value' => (string) $rawOption,
                'unplaced' => count($plan->unplaced),
                'selected' => count($plan->placedBy('selected')),
            ]));
            $this->logBundledPlan($plan, $log, 'option');

            return $plan;
        }

        $base = $planner->plan($inventory);
        // 확장 언어팩은 대상 확장을 따라가므로 선택지에 올리지 않는다(대상을 배치하면 함께 배치된다)
        $candidates = array_filter($base->unplaced, fn (array $entry) => $entry['type'] !== 'language_pack');

        if ($candidates === []) {
            $this->logBundledPlan($base, $log, 'nothing_to_ask');

            return $base;
        }

        if (! $this->canAskBundledPlacement()) {
            $this->line(__('settings.bundled_sources.noninteractive_default', ['count' => count($candidates)]));
            $this->logBundledPlan($base, $log, 'noninteractive_default');

            return $base;
        }

        $this->newLine();
        if ($inMaintenance) {
            $this->warn(__('settings.bundled_sources.maintenance_warning'));
        }
        $this->info(__('settings.bundled_sources.prompt_intro', ['count' => count($candidates)]));
        foreach ($candidates as $entry) {
            $this->line($this->bundledItemLine($entry));
        }

        $none = __('settings.bundled_sources.choice_none');
        $all = __('settings.bundled_sources.choice_all');
        $pick = __('settings.bundled_sources.choice_pick');
        $answer = $this->choice(__('settings.bundled_sources.choice_question'), [$none, $all, $pick], 0);

        if ($answer === $all) {
            $plan = $planner->plan($inventory, 'all');
            $this->logBundledPlan($plan, $log, 'interactive_all');

            return $plan;
        }

        if ($answer !== $pick) {
            $this->logBundledPlan($base, $log, 'interactive_none');

            return $base;
        }

        $labels = [];
        foreach ($candidates as $key => $entry) {
            $labels[$this->bundledLabel($entry)] = $key;
        }
        $chosen = (array) $this->choice(__('settings.bundled_sources.pick_question'), array_keys($labels), null, null, true);
        $keys = array_values(array_filter(array_map(fn ($label) => $labels[$label] ?? null, $chosen)));

        $plan = $planner->plan($inventory, $keys);

        $added = [];
        foreach ($plan->placedBy('dependency') as $key => $row) {
            if (! isset($base->placed[$key])) {
                $added[] = $this->bundledLabel($row['entry']);
            }
        }
        if ($added !== []) {
            $this->line(__('settings.bundled_sources.dependency_added', ['items' => implode(', ', $added)]));
        }

        $this->logBundledPlan($plan, $log, 'interactive_pick');

        return $plan;
    }

    /**
     * 이번 실행이 번들 배치를 물을 수 있는지 판정합니다(`--force`·`--no-interaction`·응답 불가면 false).
     *
     * @return bool 물을 수 있으면 true
     */
    protected function canAskBundledPlacement(): bool
    {
        if ($this->hasOption('force') && $this->option('force')) {
            return false;
        }

        return $this->input->isInteractive() && $this->canPromptForAnswer();
    }

    /**
     * 판정 결과를 업데이트 로그에 남깁니다.
     *
     * @param  BundledPlacementPlan  $plan  판정 결과
     * @param  \Closure  $log  업데이트 로그 수집 콜백
     * @param  string  $how  판정 경로 (option|nothing_to_ask|noninteractive_default|interactive_*)
     * @return void 반환값 없음
     */
    protected function logBundledPlan(BundledPlacementPlan $plan, \Closure $log, string $how): void
    {
        $reasons = [];
        foreach ($plan->placed as $row) {
            $reasons[$row['reason']] = ($reasons[$row['reason']] ?? 0) + 1;
        }
        ksort($reasons);
        $reasonText = implode(', ', array_map(fn ($r, $c) => "{$r}={$c}", array_keys($reasons), $reasons));

        $log(sprintf(
            '공식 번들 배치 판정(%s): 배치 %d (%s), 미배치 %d%s',
            $how,
            count($plan->placed),
            $reasonText,
            count($plan->unplaced),
            $plan->unplaced === [] ? '' : ' — '.implode(', ', array_keys($plan->unplaced)),
        ));
    }

    /**
     * 완료 요약에 공식 번들 처리 결과 4구분 블록을 출력합니다.
     *
     * @param  BundledPlacementPlan|null  $plan  판정 결과 (판정하지 않았으면 null)
     * @param  array{success?: int, failed?: int, skipped?: int, has_updates?: bool}  $promptResult  기존 설치 확장 일괄 업데이트 결과
     * @return void 반환값 없음
     */
    protected function summarizeBundledPlacement(?BundledPlacementPlan $plan, array $promptResult): void
    {
        if ($plan === null) {
            return;
        }

        $this->newLine();
        $this->info(__('settings.bundled_sources.summary_title'));

        if (! empty($promptResult['has_updates'])) {
            $this->line(__('settings.bundled_sources.summary_installed', [
                'success' => (int) ($promptResult['success'] ?? 0),
                'failed' => (int) ($promptResult['failed'] ?? 0),
                'skipped' => (int) ($promptResult['skipped'] ?? 0),
            ]));
        } else {
            $this->line(__('settings.bundled_sources.summary_installed_none'));
        }

        $dependency = $plan->placedBy('dependency');
        $this->line(__('settings.bundled_sources.summary_dependency', ['count' => count($dependency)]));
        foreach ($dependency as $row) {
            $this->line(__('settings.bundled_sources.summary_dependency_item', [
                'label' => $this->bundledLabel($row['entry']),
                'required_by' => implode(', ', $row['required_by']),
            ]));
        }

        $selected = $plan->placedBy('selected');
        $this->line(__('settings.bundled_sources.summary_selected', ['count' => count($selected)]));
        foreach ($selected as $row) {
            $this->line(__('settings.bundled_sources.summary_selected_item', ['label' => $this->bundledLabel($row['entry'])]));
        }

        $this->line(__('settings.bundled_sources.summary_unplaced', ['count' => count($plan->unplaced)]));
        foreach ($plan->unplaced as $entry) {
            $this->line(__('settings.bundled_sources.summary_unplaced_item', ['label' => $this->bundledLabel($entry)]));
        }
        if ($plan->unplaced !== []) {
            $this->line(__('settings.bundled_sources.summary_place_hint'));
        }

        $onDisk = count($plan->placedBy('on_disk'));
        if ($onDisk > 0) {
            $this->line(__('settings.bundled_sources.summary_on_disk', ['count' => $onDisk]));
        }
        $this->newLine();
    }

    /**
     * 대화형 목록의 한 줄을 만듭니다(`[플러그인] id — 이름 (버전) (샘플)`).
     *
     * @param  array<string, mixed>  $entry  인벤토리 항목
     * @return string 출력 줄
     */
    protected function bundledItemLine(array $entry): string
    {
        return __('settings.bundled_sources.item_line', [
            'type' => __('settings.bundled_sources.types.'.$entry['type']),
            'identifier' => $entry['identifier'],
            'name' => $this->bundledLocalizedName($entry['name'] ?? $entry['identifier']),
            'version' => $entry['version'] ?? '',
            'sample' => ! empty($entry['hidden']) ? __('settings.bundled_sources.sample_suffix') : '',
        ]);
    }

    /**
     * 요약·선택지에 쓰는 짧은 표기를 만듭니다(`plugin:sirsoft-totp`).
     *
     * @param  array<string, mixed>  $entry  인벤토리 항목
     * @return string 표기
     */
    protected function bundledLabel(array $entry): string
    {
        return $entry['type'].':'.$entry['identifier'];
    }

    /**
     * 다국어 이름에서 현재 로케일 값을 고릅니다(없으면 사이트 기본 언어 → 영어 → 첫 값).
     *
     * @param  mixed  $name  manifest name (array 또는 string)
     * @return string 이름
     */
    protected function bundledLocalizedName(mixed $name): string
    {
        if (is_string($name)) {
            return $name;
        }
        if (! is_array($name) || $name === []) {
            return '';
        }

        return LocalizedValue::pick($name) ?? '';
    }
}
