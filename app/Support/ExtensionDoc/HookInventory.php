<?php

namespace App\Support\ExtensionDoc;

use Illuminate\Support\Facades\File;

/**
 * 확장 훅 인벤토리
 *
 * 확장이 **발행하는 훅**과 **구독하는 훅**(리스너의 `getSubscribedHooks()`)을 수집합니다.
 *
 * 발행 훅의 1차 출처는 확장이 `getHooks()` 로 **선언한 목록**입니다. 소스 스캔은 그 선언을
 * 보강할 뿐입니다 — 훅 이름을 `self::PLUGIN_ID.'.consent.granted'` 처럼 조립하는 확장이
 * 실재하고, 그런 호출은 리터럴 스캔이 원리상 읽을 수 없기 때문입니다. 스캔만 믿으면 훅을
 * 12곳에서 발행하는 확장이 "훅을 발행하지 않습니다" 로 문서화됩니다(실제 `sirsoft-gdpr`).
 * 선언은 유형과 ko/en 설명까지 갖고 있어 스캔이 만들 수 없는 정보를 준다는 이점도 있습니다.
 *
 * 구독 훅은 `_bundled` 소스 파일을 직접 파싱합니다. 리스너 클래스를 로드해 static 메서드를
 * 호출하면 활성 디렉토리에 이미 로드된 동일 FQCN 이 우선하므로, `_bundled` 를 고쳐도 그
 * 변경이 문서에 반영되지 않습니다. 문서 SSoT 는 `_bundled` 소스이므로 파싱으로 읽습니다.
 */
class HookInventory
{
    /**
     * 훅 발행 호출 형태 → 훅 유형.
     *
     * @var array<string, string>
     */
    private const EMIT_FORMS = [
        'doAction' => 'action',
        'applyFilters' => 'filter',
        'broadcast' => 'broadcast',
    ];

    /**
     * 훅 이름 리터럴을 갖는 발행 호출을 찾는 패턴.
     *
     * 첫 인자가 리터럴이 아닌(변수/보간) 호출은 이름을 정적으로 알 수 없으므로 별도 집계한다.
     */
    private const EMIT_LITERAL = "/HookManager::(doAction|applyFilters|broadcast)\s*\(\s*'([^']+)'/";

    /**
     * 발행 호출 전수(리터럴 여부 무관)를 세는 패턴.
     */
    private const EMIT_ANY = '/HookManager::(doAction|applyFilters|broadcast)\s*\(/';

    /**
     * 확장의 훅 표면을 수집합니다.
     *
     * @param  array<string, mixed>  $record  ExtensionInventory 레코드
     * @param  array<int, mixed>  $declared  확장이 `getHooks()` 로 선언한 발행 훅 목록
     * @return array{published: array<int, array<string, mixed>>, publishedSites: int, publishedDynamic: int, publishedUndeclared: int, subscribed: array<int, array<string, mixed>>, listeners: array<int, array<string, mixed>>}
     */
    public function collect(array $record, array $declared = []): array
    {
        $published = $this->collectPublished($record, $declared);
        $listeners = $this->collectListeners($record);

        $subscribed = [];
        foreach ($listeners as $listener) {
            foreach ($listener['hooks'] as $hook) {
                $subscribed[] = $hook + ['listener' => $listener['class'], 'listenerFile' => $listener['relFile']];
            }
        }

        usort($subscribed, static fn (array $a, array $b): int => [$a['name'], $a['listener']] <=> [$b['name'], $b['listener']]);

        return [
            'published' => $published['hooks'],
            'publishedSites' => $published['sites'],
            'publishedDynamic' => $published['dynamic'],
            'publishedUndeclared' => $published['undeclared'],
            'subscribed' => $subscribed,
            'listeners' => $listeners,
        ];
    }

    /**
     * 확장이 발행하는 훅을 수집합니다.
     *
     * 선언(`getHooks()`)이 1차 출처이고 소스 스캔이 보강합니다. 선언에만 있는 훅은 호출
     * 지점 없이 표에 남고(이름·유형·설명은 선언이 준다), 스캔에만 있는 훅은 `declared`
     * 를 false 로 실어 "선언되지 않음" 을 문서가 드러낼 수 있게 합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @param  array<int, mixed>  $declared  `getHooks()` 선언 목록
     * @return array{hooks: array<int, array<string, mixed>>, sites: int, dynamic: int, undeclared: int}
     *                                                                                                   sites 는 리터럴/동적을 합한 전체 호출 지점 수
     */
    private function collectPublished(array $record, array $declared = []): array
    {
        $byName = $this->declaredHooks($declared);
        $sites = 0;
        $literalSites = 0;

        foreach ($this->phpFiles($record) as $file) {
            $content = (string) file_get_contents($file);

            if (! str_contains($content, 'HookManager::')) {
                continue;
            }

            $sites += preg_match_all(self::EMIT_ANY, $content);

            if (! preg_match_all(self::EMIT_LITERAL, $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $m) {
                $literalSites++;
                $form = $m[1][0];
                $name = $m[2][0];
                $line = substr_count(substr($content, 0, (int) $m[0][1]), "\n") + 1;
                $rel = $this->relative($record, $file);

                if (! isset($byName[$name])) {
                    $byName[$name] = [
                        'name' => $name,
                        'type' => self::EMIT_FORMS[$form] ?? $form,
                        'description' => null,
                        'parameters' => [],
                        'declared' => false,
                        'sites' => [],
                    ];
                }

                $scannedType = self::EMIT_FORMS[$form] ?? $form;
                if (($byName[$name]['typeDeclared'] ?? true) === false
                    && $byName[$name]['type'] !== $scannedType) {
                    // 선언이 유형을 말하지 않았고 소스가 다른 유형으로 발행한다 —
                    // 기본값이 사실을 덮은 자리이므로 실측을 따르고 표에 사유를 남긴다.
                    $byName[$name]['type'] = $scannedType;
                    $byName[$name]['typeInferred'] = true;
                }

                $byName[$name]['sites'][] = ['file' => $rel, 'line' => $line];
            }
        }

        ksort($byName);

        $undeclared = 0;
        foreach ($byName as $hook) {
            if ($hook['declared'] === false) {
                $undeclared++;
            }
        }

        return [
            'hooks' => array_values($byName),
            'sites' => $sites,
            'dynamic' => max(0, $sites - $literalSites),
            'undeclared' => $undeclared,
        ];
    }

    /**
     * `getHooks()` 선언을 훅 이름 색인으로 정규화합니다.
     *
     * 선언 형식이 어긋난 항목(이름 없음 등)은 조용히 버리지 않고 건너뛰되, 이름만 있으면
     * 유형·설명이 없어도 표에 남깁니다 — 발행 사실 자체가 확장점 공개의 핵심입니다.
     *
     * @param  array<int, mixed>  $declared  선언 목록
     * @return array<string, array<string, mixed>> 훅 이름 → 항목
     */
    private function declaredHooks(array $declared): array
    {
        $byName = [];

        foreach ($declared as $hook) {
            if (! is_array($hook)) {
                continue;
            }

            $name = $hook['name'] ?? null;
            if (! is_string($name) || $name === '') {
                continue;
            }

            $description = $hook['description'] ?? null;
            if (is_array($description)) {
                // ko 우선 — 확장 문서는 한국어다. ko 가 없으면 첫 값을 쓴다.
                $description = $description['ko'] ?? (reset($description) ?: null);
            }

            $byName[$name] = [
                'name' => $name,
                // 유형 미기재를 조용히 `action` 으로 굳히면, 실제로 filter 인 훅이
                // action 으로 문서화되어 구독하는 쪽이 반환값 계약을 잘못 읽는다.
                // 미기재 사실을 남겨 스캔 결과와 어긋날 때 드러나게 한다.
                'type' => is_string($hook['type'] ?? null) ? $hook['type'] : 'action',
                'typeDeclared' => is_string($hook['type'] ?? null),
                'description' => is_string($description) && $description !== '' ? $description : null,
                'parameters' => is_array($hook['parameters'] ?? null) ? $hook['parameters'] : [],
                'declared' => true,
                'sites' => [],
            ];
        }

        return $byName;
    }

    /**
     * 확장의 훅 리스너와 각 리스너가 구독하는 훅을 수집합니다.
     *
     * `src/Listeners/` 를 스캔해 리스너와 그 구독 훅을 모읍니다. 명시 등록(`getHookListeners()`)
     * 여부는 여기서 판정하지 않습니다 — 스캐폴더가 선언형 표면의 `getHookListeners` 값과
     * 대조해 표기합니다. 이 배열에는 `registered` 키가 없습니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return array<int, array<string, mixed>> 리스너 목록
     */
    private function collectListeners(array $record): array
    {
        $listenerDir = $record['path'].DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Listeners';
        $listeners = [];

        if (! is_dir($listenerDir)) {
            return $listeners;
        }

        foreach (File::allFiles($listenerDir) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $content = (string) file_get_contents($path);
            $class = $this->fqcnOf($content);

            $listeners[] = [
                'class' => $class ?? $file->getFilenameWithoutExtension(),
                'shortClass' => $file->getFilenameWithoutExtension(),
                'relFile' => $this->relative($record, $path),
                'implementsContract' => str_contains($content, 'HookListenerInterface'),
                'hooks' => $this->parseSubscribedHooks($content),
            ];
        }

        usort($listeners, static fn (array $a, array $b): int => $a['shortClass'] <=> $b['shortClass']);

        return $listeners;
    }

    /**
     * `getSubscribedHooks()` 메서드 본문에서 구독 훅 선언을 파싱합니다.
     *
     * 반환 배열의 최상위 항목만 봅니다 — `'훅이름' => [ ... ]` 형태의 키가 훅 이름이고,
     * 값 슬라이스에서 method/priority/type 을 읽습니다. `type` 미선언은 action 으로 간주하되
     * 그 사실을 `typeDeclared` 로 남깁니다 (filter 훅의 type 마커 누락은 별도 룰이 검사).
     *
     * @param  string  $content  리스너 소스
     * @return array<int, array<string, mixed>> 구독 훅 목록
     */
    private function parseSubscribedHooks(string $content): array
    {
        $body = $this->extractReturnArray($content, 'getSubscribedHooks');
        if ($body === null) {
            return [];
        }

        $hooks = [];

        foreach ($this->splitTopLevel($body) as $item) {
            if (! preg_match("/^\s*'([^']+)'\s*=>\s*(.*)$/s", $item, $m)) {
                continue;
            }

            $value = $m[2];
            $type = null;
            if (preg_match("/'type'\s*=>\s*'([^']+)'/", $value, $tm)) {
                $type = $tm[1];
            }

            $method = null;
            if (preg_match("/'method'\s*=>\s*'([^']+)'/", $value, $mm)) {
                $method = $mm[1];
            } elseif (preg_match("/^\s*'([^']+)'\s*$/", $value, $sm)) {
                $method = $sm[1];
            }

            $priority = null;
            if (preg_match("/'priority'\s*=>\s*(-?\d+)/", $value, $pm)) {
                $priority = (int) $pm[1];
            }

            $hooks[] = [
                'name' => $m[1],
                'method' => $method,
                'priority' => $priority,
                'type' => $type ?? 'action',
                'typeDeclared' => $type !== null,
            ];
        }

        return $hooks;
    }

    /**
     * 지정 메서드의 `return [...]` 배열 본문을 대괄호 균형으로 잘라냅니다.
     *
     * 정규식 하나로 배열을 잡으려 하면 중첩 배열에서 끊기므로, 여는 대괄호부터
     * 문자열·주석을 건너뛰며 깊이를 세어 대응하는 닫는 위치를 찾습니다.
     *
     * @param  string  $content  소스
     * @param  string  $method  메서드명
     * @return string|null 배열 본문 (대괄호 제외, 없으면 null)
     */
    private function extractReturnArray(string $content, string $method): ?string
    {
        if (! preg_match('/function\s+'.preg_quote($method, '/').'\s*\(/', $content, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $from = (int) $m[0][1];
        $returnPos = strpos($content, 'return [', $from);
        if ($returnPos === false) {
            return null;
        }

        $open = $returnPos + strlen('return ');
        $close = $this->matchBracket($content, $open);

        return $close === null ? null : substr($content, $open + 1, $close - $open - 1);
    }

    /**
     * 여는 대괄호 위치에 대응하는 닫는 대괄호 위치를 찾습니다.
     *
     * @param  string  $s  소스
     * @param  int  $open  여는 대괄호 인덱스
     * @return int|null 닫는 대괄호 인덱스 (불균형이면 null)
     */
    private function matchBracket(string $s, int $open): ?int
    {
        $depth = 0;
        $len = strlen($s);

        for ($i = $open; $i < $len; $i++) {
            $ch = $s[$i];

            if ($ch === "'" || $ch === '"') {
                $i = $this->skipString($s, $i);

                continue;
            }

            if ($ch === '/' && $i + 1 < $len && ($s[$i + 1] === '/' || $s[$i + 1] === '*')) {
                $i = $this->skipComment($s, $i);

                continue;
            }

            if ($ch === '[') {
                $depth++;
            } elseif ($ch === ']') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * 배열 본문을 최상위 쉼표 기준으로 분할합니다.
     *
     * @param  string  $body  배열 본문
     * @return array<int, string> 항목 목록 (빈 항목 제외)
     */
    private function splitTopLevel(string $body): array
    {
        $items = [];
        $buf = '';
        $depth = 0;
        $len = strlen($body);

        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];

            if ($ch === "'" || $ch === '"') {
                $end = $this->skipString($body, $i);
                $buf .= substr($body, $i, $end - $i + 1);
                $i = $end;

                continue;
            }

            if ($ch === '/' && $i + 1 < $len && ($body[$i + 1] === '/' || $body[$i + 1] === '*')) {
                $i = $this->skipComment($body, $i);

                continue;
            }

            if ($ch === '[' || $ch === '(') {
                $depth++;
            } elseif ($ch === ']' || $ch === ')') {
                $depth--;
            }

            if ($ch === ',' && $depth === 0) {
                if (trim($buf) !== '') {
                    $items[] = $buf;
                }
                $buf = '';

                continue;
            }

            $buf .= $ch;
        }

        if (trim($buf) !== '') {
            $items[] = $buf;
        }

        return $items;
    }

    /**
     * 문자열 리터럴의 끝 인덱스를 찾습니다 (이스케이프 인지).
     *
     * @param  string  $s  소스
     * @param  int  $start  따옴표 인덱스
     * @return int 닫는 따옴표 인덱스 (미종료 시 문자열 끝)
     */
    private function skipString(string $s, int $start): int
    {
        $quote = $s[$start];
        $len = strlen($s);

        for ($i = $start + 1; $i < $len; $i++) {
            if ($s[$i] === '\\') {
                $i++;

                continue;
            }
            if ($s[$i] === $quote) {
                return $i;
            }
        }

        return $len - 1;
    }

    /**
     * 주석의 끝 인덱스를 찾습니다.
     *
     * @param  string  $s  소스
     * @param  int  $start  `/` 인덱스
     * @return int 주석 마지막 문자 인덱스
     */
    private function skipComment(string $s, int $start): int
    {
        if ($s[$start + 1] === '/') {
            $end = strpos($s, "\n", $start);

            return $end === false ? strlen($s) - 1 : $end;
        }

        $end = strpos($s, '*/', $start);

        return $end === false ? strlen($s) - 1 : $end + 1;
    }

    /**
     * 소스에서 FQCN 을 추출합니다.
     *
     * @param  string  $content  소스
     * @return string|null FQCN (없으면 null)
     */
    private function fqcnOf(string $content): ?string
    {
        if (! preg_match('/^\s*namespace\s+([^;]+);/m', $content, $nm)) {
            return null;
        }

        if (! preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)/m', $content, $cm)) {
            return null;
        }

        return trim($nm[1]).'\\'.$cm[1];
    }

    /**
     * 훅 발행 스캔 대상 PHP 파일을 열거합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return \Generator<string> PHP 파일 절대 경로
     */
    private function phpFiles(array $record): \Generator
    {
        foreach (['src', 'database', 'upgrades', 'resources', 'config'] as $sub) {
            $dir = $record['path'].DIRECTORY_SEPARATOR.$sub;
            if (! is_dir($dir)) {
                continue;
            }

            foreach (File::allFiles($dir) as $file) {
                if ($file->getExtension() === 'php') {
                    yield $file->getPathname();
                }
            }
        }

        if ($record['entryFile'] !== null) {
            yield $record['entryFile'];
        }
    }

    /**
     * 확장 루트 기준 상대 경로로 변환합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @param  string  $absolute  절대 경로
     * @return string 상대 경로 (POSIX 구분자)
     */
    private function relative(array $record, string $absolute): string
    {
        $base = rtrim((string) $record['path'], '/\\').DIRECTORY_SEPARATOR;
        $rel = str_starts_with($absolute, $base) ? substr($absolute, strlen($base)) : $absolute;

        return str_replace('\\', '/', $rel);
    }
}
