<?php

namespace App\Console\Commands\Concerns;

use App\Extension\Traits\ResolvesLayoutInstallSource;
use Illuminate\Console\Command;

/**
 * `--layout-source=auto|json` 옵션으로 레이아웃 설치 소스를 선택하는 커맨드 공통 트레이트.
 *
 * 설치/업데이트/레이아웃 갱신 커맨드가 이 트레이트를 써서 값을 검증하고 실행 시점에
 * `config('template.layout_markup.install_source')` 를 오버라이드한다. 매니저 시그니처는 건드리지
 * 않으므로 3개 매니저 8개 호출처가 무수정으로 따라온다.
 *
 * 옵션명이 `--source` 가 아닌 `--layout-source` 인 이유: `{type}:update` 는 이미
 * `--source=auto|bundled|github`(업데이트 패키지 출처)를 쓰고 있어 충돌한다. 기존
 * `--layout-strategy` 와 접두어를 맞춰 레이아웃 계열 옵션으로 묶는다.
 *
 * @mixin Command
 */
trait SelectsLayoutInstallSource
{
    use ResolvesLayoutInstallSource;

    /**
     * `--layout-source` 옵션을 검증하고 런타임 설정에 반영합니다.
     *
     * @return bool 유효한 값이면 true, 알 수 없는 값이면 false (커맨드는 실패로 종료해야 함)
     */
    protected function applyLayoutInstallSource(): bool
    {
        $raw = $this->option('layout-source');

        if ($raw === null) {
            return true;
        }

        $normalized = strtolower(trim((string) $raw));

        if (! in_array($normalized, ['auto', 'json'], true)) {
            $this->error(__('templates.layout_install_source.invalid', ['value' => (string) $raw]));

            return false;
        }

        config(['template.layout_markup.install_source' => $normalized]);

        if ($normalized === 'json') {
            $this->warn(__('templates.layout_install_source.json_notice'));
        }

        return true;
    }
}
