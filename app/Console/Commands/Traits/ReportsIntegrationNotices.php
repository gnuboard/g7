<?php

namespace App\Console\Commands\Traits;

use App\Extension\Helpers\IntegrationNotices;

/**
 * 생명주기 명령이 선택 연동(`integrations`) 안내를 표로 출력합니다 (7.1.4).
 *
 * 연동은 「있으면 연동, 없어도 동작」이므로 안내만 하고 종료 코드는 바꾸지 않는다 — 부분 실패 경고
 * ({@see ReportsLifecycleWarnings})와 달리 경고 수집기에 넣지 않는다.
 *
 * - 설치·활성화·업데이트: 연동 대상 중 미설치·비활성·버전 미달인 것
 * - 비활성화·제거: 이 확장을 연동으로 선언한 활성 확장 (그 확장의 연동 화면·기능이 사라짐)
 */
trait ReportsIntegrationNotices
{
    /**
     * 연동 안내 표를 출력합니다. 안내가 없으면 아무것도 출력하지 않습니다.
     *
     * @param  array<int, array{type: string, identifier: string, name: string, state: string, required_version: string, installed_version: string|null}>  $notices  {@see IntegrationNotices::fromIntegrations()} 결과
     * @return void 반환값 없음
     */
    protected function renderIntegrationNotices(array $notices): void
    {
        if ($notices === []) {
            return;
        }

        $this->newLine();
        $this->line('ℹ️  '.__('extensions.integrations.console.notices_title'));
        $this->table(
            [
                __('extensions.integrations.console.column_type'),
                __('extensions.integrations.console.column_extension'),
                __('extensions.integrations.console.column_state'),
                __('extensions.integrations.console.column_required'),
                __('extensions.integrations.console.column_installed'),
            ],
            array_map(fn (array $notice): array => [
                __('extensions.types.'.$notice['type']),
                $notice['name'] === $notice['identifier'] ? $notice['identifier'] : $notice['name'].' ('.$notice['identifier'].')',
                __('extensions.integrations.states.'.$notice['state']),
                $notice['required_version'],
                $notice['installed_version'] ?? '-',
            ], $notices),
        );
    }

    /**
     * 확장을 설치·활성화한 뒤 연동 안내 표를 출력합니다.
     *
     * @param  string  $type  확장 유형 (module|plugin|template)
     * @param  string  $identifier  확장 식별자
     * @return void 반환값 없음
     */
    protected function reportIntegrationNotices(string $type, string $identifier): void
    {
        $this->renderIntegrationNotices(IntegrationNotices::forExtension($type, $identifier));
    }

    /**
     * 확장을 비활성화·제거한 뒤 이 확장과 연동 중인 활성 확장 표를 출력합니다.
     *
     * @param  string  $type  확장 유형 (module|plugin|template)
     * @param  string  $identifier  확장 식별자
     * @return void 반환값 없음
     */
    protected function reportIntegratedBy(string $type, string $identifier): void
    {
        $integratedBy = IntegrationNotices::integratedBy($type, $identifier);
        if ($integratedBy === []) {
            return;
        }

        $this->newLine();
        $this->line('ℹ️  '.__('extensions.integrations.console.integrated_by_title'));
        $this->table(
            [
                __('extensions.integrations.console.column_type'),
                __('extensions.integrations.console.column_extension'),
            ],
            array_map(fn (array $item): array => [
                __('extensions.types.'.$item['type']),
                $item['name'] === $item['identifier'] ? $item['identifier'] : $item['name'].' ('.$item['identifier'].')',
            ], $integratedBy),
        );
    }
}
