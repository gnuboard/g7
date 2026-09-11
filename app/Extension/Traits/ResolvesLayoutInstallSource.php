<?php

namespace App\Extension\Traits;

use Illuminate\Support\Facades\Log;

/**
 * 레이아웃 설치 소스 전략(`auto` | `json`)을 해석하는 공통 트레이트.
 *
 * 기본 `auto` 는 소스 SSoT 인 `.g7.html` 을 우선해 설치 시점에 메모리로 컴파일한다.
 * `json` 은 진단·복구용 탈출구로, 짝 `.json` 이 실재하면 마크업을 무시하고 원본 JSON 을 등록한다
 * (컴파일러 회귀가 의심될 때 원본 기준으로 되돌려 설치하기 위한 것).
 *
 * 3개 매니저의 8개 호출처에 플래그를 배선하면 시그니처가 오염되므로, 값은 config 를 SSoT 로 두고
 * 커맨드(`--layout-source=json`)가 실행 시점에 런타임 오버라이드한다.
 */
trait ResolvesLayoutInstallSource
{
    /**
     * 설치 소스가 JSON 강제(`--layout-source=json`)인지 판정합니다.
     *
     * @return bool JSON 강제면 true
     */
    protected function layoutInstallSourceIsJsonForced(): bool
    {
        return self::normalizeLayoutInstallSource(config('template.layout_markup.install_source')) === 'json';
    }

    /**
     * 설치 소스 값을 정규화합니다 — 알 수 없는 값은 기본값 `auto` 로 떨어뜨립니다.
     *
     * @param  mixed  $value  원본 설정/옵션 값
     * @return string `auto` 또는 `json`
     */
    protected static function normalizeLayoutInstallSource(mixed $value): string
    {
        return is_string($value) && strtolower(trim($value)) === 'json' ? 'json' : 'auto';
    }

    /**
     * 참조 경로 하나에 대한 소스 후보를 우선순위대로 만듭니다 (`.g7.html` → `.json`, JSON 강제면 역순).
     *
     * 본 레이아웃·fragment·조각(`<include>`)이 **같은 우선순위 규약**을 쓰도록 규칙을 여기 한 곳에 둔다.
     * 소비 경로는 넷이고 전부 이 함수로 수렴한다 — 조각({@see ValidatesLayoutFiles::resolvePartial()})과
     * fragment 단일 파일({@see CollectsFragmentSources::resolveFragmentSource()})은 직접 호출하고,
     * 디렉토리 스캔 두 벌(`collectLayoutSources`·`collectFragmentSources`)은
     * {@see mergeLayoutSourcesByPriority()} 를 거쳐 호출한다.
     *
     * 종전에는 디렉토리 스캔 두 벌만 규약을 갖고 조각은 `.json` 전용이었으며, 그 둘조차 짝 판별을
     * 각자 복제하고 있었다. 규약을 복제해 배포하면 한쪽만 넓힐 때 나머지가 반쪽으로 남는다.
     *
     * 확장자가 `.json`/`.g7.html` 어느 쪽도 아니면 후보를 늘리지 않는다 (원본 하나만).
     *
     * @param  string  $path  참조 경로 (상대·절대 무관)
     * @return array<int, array{path: string, markup: bool}> 우선순위순 후보
     */
    protected function layoutSourceCandidates(string $path): array
    {
        $isMarkup = preg_match('/\.g7\.html$/i', $path) === 1;

        $markupPath = $isMarkup ? $path : preg_replace('/\.json$/i', '.g7.html', $path);
        $jsonPath = $isMarkup ? preg_replace('/\.g7\.html$/i', '.json', $path) : $path;

        if ($markupPath === $jsonPath) {
            return [['path' => $path, 'markup' => $isMarkup]];
        }

        $candidates = [
            ['path' => $markupPath, 'markup' => true],
            ['path' => $jsonPath, 'markup' => false],
        ];

        return $this->layoutInstallSourceIsJsonForced() ? array_reverse($candidates) : $candidates;
    }

    /**
     * 디렉토리 스캔 결과 두 벌(`.g7.html` 목록 · `.json` 목록)을 우선순위 규약으로 병합합니다.
     *
     * 단일 경로용 {@see layoutSourceCandidates()} 와 **같은 규칙을 쓴다** — 이 메서드는 후보 생성을
     * 그 함수에 위임하고 "실재하는 첫 후보를 고른다" 만 수행한다. 종전에는 본 레이아웃
     * (`ValidatesLayoutFiles::collectLayoutSources`)과 fragment(`CollectsFragmentSources::collectFragmentSources`)가
     * 짝 판별 18줄을 각자 복제하고 있었고, 그것이 규약을 한쪽만 넓힐 때 나머지가 반쪽으로 남는 원인이다.
     *
     * 마크업은 스캔으로 실재가 이미 보장되므로 후보 실재 확인은 `.json` 쪽에만 필요하다.
     * JSON 강제인데 짝 `.json` 이 없으면 대안이 없으므로 마크업을 그대로 쓰되 플래그 미이행을 남긴다
     * — 조용히 누락시키면 등록 자체가 사라져 빈 화면이 되기 때문이다.
     *
     * @param  array<int, string>  $markupFiles  스캔된 `.g7.html` 경로 목록
     * @param  array<int, string>  $jsonFiles  스캔된 `.json` 경로 목록
     * @param  string  $sourceLabel  경고 로그에 쓸 소스 종류 라벨 (`레이아웃` · `fragment`)
     * @return array<int, array{path: string, markup: bool}> 소스 디스크립터 목록
     */
    protected function mergeLayoutSourcesByPriority(array $markupFiles, array $jsonFiles, string $sourceLabel): array
    {
        $jsonKeys = [];
        foreach ($jsonFiles as $jsonFile) {
            $jsonKeys[self::layoutSourcePathKey($jsonFile)] = true;
        }

        $jsonForced = $this->layoutInstallSourceIsJsonForced();
        $sources = [];
        $claimedJsonKeys = [];

        foreach ($markupFiles as $markupFile) {
            $candidates = $this->layoutSourceCandidates($markupFile);

            // 짝 `.json` 키는 승자와 무관하게 기록한다 — 마크업이 이겨도 그 짝은 아래 `.json`
            // 루프에서 빠져야 하므로, 승자 판정 루프 안에서 구하면 우선순위에 따라 누락된다.
            $siblingKey = null;
            foreach ($candidates as $candidate) {
                if (! $candidate['markup']) {
                    $siblingKey = self::layoutSourcePathKey($candidate['path']);
                }
            }

            // 실재하는 첫 후보가 승자다 (마크업은 스캔으로 실재가 이미 보장된다).
            $winner = null;
            foreach ($candidates as $candidate) {
                if ($candidate['markup'] || isset($jsonKeys[self::layoutSourcePathKey($candidate['path'])])) {
                    $winner = $candidate;
                    break;
                }
            }

            // JSON 강제 + 짝 `.json` 실재 → 아래 `.json` 루프가 그 짝을 등록한다.
            if ($winner === null || ! $winner['markup']) {
                continue;
            }

            if ($jsonForced) {
                Log::warning($sourceLabel.' 설치 소스 JSON 강제를 이행하지 못했습니다 (짝 .json 부재 — 마크업 컴파일로 대체)', [
                    'markup' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $markupFile),
                ]);
            }

            if ($siblingKey !== null) {
                $claimedJsonKeys[$siblingKey] = true;
            }

            $sources[] = $winner;
        }

        foreach ($jsonFiles as $jsonFile) {
            // 짝 `.g7.html` 이 이미 이겼으면 `.json` 은 건너뛴다.
            if (isset($claimedJsonKeys[self::layoutSourcePathKey($jsonFile)])) {
                continue;
            }

            $sources[] = ['path' => $jsonFile, 'markup' => false];
        }

        return $sources;
    }

    /**
     * 경로를 비교용 키로 정규화합니다 (슬래시 통일 + 소문자 — 대소문자 무시 파일시스템 대응).
     *
     * 짝 판별이 이 키에 의존하므로 규약과 같은 자리에 둔다. 종전에는 두 트레이트가 본문이 동일한
     * private 사본(`normalizePathKey` · `fragmentPathKey`)을 각자 갖고 있었다.
     *
     * @param  string  $path  경로
     * @return string 정규화 키
     */
    protected static function layoutSourcePathKey(string $path): string
    {
        return strtolower(str_replace('\\', '/', $path));
    }
}
