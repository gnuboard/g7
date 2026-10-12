<?php

namespace App\Extension\Layout;

use App\Extension\Traits\ResolvesLayoutInstallSource;
use Closure;

/**
 * 웹 요청 한 번 동안만 레이아웃 설치 소스(`auto` | `json`)를 덮는 범위 헬퍼.
 *
 * 관리자 설치·업데이트·레이아웃 갱신 창의 '고급 설정' 선택을 CLI `--layout-source` 와 같은 자리
 * (`config('template.layout_markup.install_source')`)로 흘려 보낸다 — 매니저·서비스 시그니처는 건드리지 않는다
 * ({@see ResolvesLayoutInstallSource} 가 설정을 SSoT 로 읽는다). 설치·업데이트·갱신은 요청 안에서 동기로 실행되므로
 * (큐·별도 프로세스 없음) 설정 범위 오버라이드로 충분하다.
 *
 * CLI 는 프로세스가 끝나면 덮은 값이 사라지지만 웹(특히 같은 앱 인스턴스를 공유하는 Feature 테스트·상주 워커)은
 * 그렇지 않으므로, 실행 뒤 이전 값을 **반드시** 되돌린다.
 */
final class LayoutInstallSourceScope
{
    use ResolvesLayoutInstallSource;

    /**
     * 설치 소스를 덮은 채로 콜백을 실행하고 이전 값을 복원합니다.
     *
     * @param  string|null  $source  요청 값 (`auto` | `json`). null·빈 값이면 덮지 않고 설정을 따른다
     * @param  Closure  $callback  실행할 작업 (서비스 호출)
     * @return mixed 콜백 반환값
     */
    public static function run(?string $source, Closure $callback): mixed
    {
        if ($source === null || trim($source) === '') {
            return $callback();
        }

        $previous = config('template.layout_markup.install_source');
        config(['template.layout_markup.install_source' => self::normalizeLayoutInstallSource($source)]);

        try {
            return $callback();
        } finally {
            config(['template.layout_markup.install_source' => $previous]);
        }
    }
}
