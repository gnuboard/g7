<?php

namespace App\Services;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;

/**
 * 시스템 정보 화면의 확장 카드 섹션.
 *
 * 관리자 환경설정 > 시스템 정보 탭은 코어가 모은 환경 정보(「시스템 정보」 카드)를 보인다. 확장은 필터 훅
 * `core.settings.filter_system_info_sections` 로 그 옆에 카드를 더할 수 있다 — 예: 서버 상태를 보여 주는 플러그인이
 * 큐 적체·디스크 경고를 한 카드로 낸다. 코어는 카드를 그리는 일반 렌더러만 갖고, 무엇을 싣는지는 확장이 정한다.
 *
 * 리스너는 섹션 배열을 받아 자기 섹션을 더한 배열을 돌려준다. 섹션 형식:
 *
 * ```php
 * [
 *     'id' => 'acme-server_status.overview',      // 확장 식별자로 시작하는 문자열 (필수)
 *     'title' => '서버 상태',                        // 다국어를 확장이 풀어서 넘긴다 (필수)
 *     'badge' => ['text' => '정상', 'tone' => 'success'],   // 선택 — tone: neutral|success|warning|danger
 *     'rows' => [['label' => '큐 대기', 'value' => '3건', 'mono' => false]],  // 필수 (빈 목록 가능)
 *     'list' => ['title' => '최근 경고', 'items' => ['...'], 'collapsible' => true],  // 선택
 *     'notice' => ['tone' => 'warning', 'text' => '...'],  // 선택
 * ]
 * ```
 *
 * 보장:
 *  - 매 요청 새로 계산한다 — 코어 정보의 1시간 캐시 밖에서 돈다(확장 정보는 캐시 수명과 무관하게 최신이어야 한다).
 *  - 형식이 맞지 않는 섹션은 통째로 버리고 경고 로그를 남긴다. 알 수 없는 키는 지운다. 값은 모두 문자열로 바꾼다.
 *  - 리스너가 던지면 `report()` 로 남기고 그 리스너의 섹션만 뺀다 — 확장 하나가 시스템 정보 화면 전체를 깨지 않는다.
 *  - 화면은 모든 값을 텍스트로 그린다(HTML 해석 없음). 응답 권한은 시스템 정보 API 그대로(`core.settings.read`)다.
 */
class SystemInfoSectionService
{
    /** 필터 훅 이름 */
    public const HOOK = 'core.settings.filter_system_info_sections';

    /** 배지·알림 색조 */
    public const TONES = ['neutral', 'success', 'warning', 'danger'];

    /** 섹션 id 형식 — 확장 식별자(`vendor-name`)로 시작하고, 뒤에 `.`/`:` 로 이은 이름을 붙일 수 있다 */
    private const ID_PATTERN = '/^[a-z0-9]+-[a-z0-9_]+(?:[.:][A-Za-z0-9_.:-]+)?$/D';

    /** 문자열 길이 상한 (제목·라벨·배지·목록 제목) */
    private const MAX_SHORT_TEXT = 200;

    /** 문자열 길이 상한 (값·목록 항목·알림 본문) */
    private const MAX_LONG_TEXT = 2000;

    /**
     * 확장 섹션을 모아 검증한 목록을 돌려줍니다.
     *
     * 평소에는 필터를 한 번 적용한다. 리스너 하나가 던지면 리스너마다 따로 다시 돌려(던진 리스너만 빼고) 나머지 섹션을 살린다 —
     * 이 훅의 리스너는 정보를 읽어 섹션을 만들 뿐이어야 하므로 앞선 리스너가 한 번 더 도는 것은 무해하다.
     *
     * @return array<int, array{id: string, title: string, badge: array{text: string, tone: string}|null, rows: array<int, array{label: string, value: string, mono: bool}>, list: array{title: string, items: array<int, string>, collapsible: bool}|null, notice: array{tone: string, text: string}|null}> 검증된 섹션 목록
     */
    public function collect(): array
    {
        try {
            $raw = HookManager::applyFilters(self::HOOK, []);
        } catch (\Throwable) {
            // 던진 리스너는 아래 격리 실행에서 리스너 단위로 report() 된다 — 여기서 또 남기면 같은 예외가 두 번 기록된다.
            $raw = $this->collectIsolated();
        }

        if (! is_array($raw)) {
            Log::warning('시스템 정보 확장 섹션 필터가 배열이 아닌 값을 돌려줘 섹션을 모두 버렸습니다', [
                'hook' => self::HOOK,
                'type' => get_debug_type($raw),
            ]);

            return [];
        }

        return $this->normalize($raw);
    }

    /**
     * 섹션 목록을 검증·정규화합니다. 형식이 틀린 섹션과 id 가 겹치는 뒤 섹션은 버리고 경고를 남깁니다.
     *
     * @param  array<int|string, mixed>  $raw  리스너가 돌려준 섹션 목록
     * @return array<int, array<string, mixed>> 검증된 섹션 목록 (입력 순서 유지)
     */
    public function normalize(array $raw): array
    {
        $sections = [];
        $seen = [];

        foreach (array_values($raw) as $index => $candidate) {
            $section = is_array($candidate) ? $this->normalizeSection($candidate) : null;

            if ($section === null) {
                Log::warning('시스템 정보 확장 섹션 형식이 맞지 않아 버렸습니다', [
                    'hook' => self::HOOK,
                    'index' => $index,
                    'id' => is_array($candidate) && is_string($candidate['id'] ?? null) ? $candidate['id'] : null,
                ]);

                continue;
            }

            if (isset($seen[$section['id']])) {
                Log::warning('시스템 정보 확장 섹션 id 가 겹쳐 뒤 섹션을 버렸습니다', [
                    'hook' => self::HOOK,
                    'index' => $index,
                    'id' => $section['id'],
                ]);

                continue;
            }

            $seen[$section['id']] = true;
            $sections[] = $section;
        }

        return $sections;
    }

    /**
     * 리스너마다 따로 실행해, 던진 리스너의 섹션만 빼고 나머지를 모읍니다.
     *
     * @return array<int|string, mixed> 섹션 목록 (검증 전)
     */
    private function collectIsolated(): array
    {
        $filters = HookManager::getFilters()[self::HOOK] ?? [];
        ksort($filters);

        $sections = [];

        foreach ($filters as $callbacks) {
            foreach ($callbacks as $callback) {
                try {
                    $result = call_user_func($callback, $sections);
                } catch (\Throwable $e) {
                    report($e);

                    continue;
                }

                if (is_array($result)) {
                    $sections = $result;

                    continue;
                }

                Log::warning('시스템 정보 확장 섹션 리스너가 배열이 아닌 값을 돌려줘 그 결과를 버렸습니다', [
                    'hook' => self::HOOK,
                    'type' => get_debug_type($result),
                ]);
            }
        }

        return $sections;
    }

    /**
     * 섹션 하나를 검증·정규화합니다.
     *
     * @param  array<string, mixed>  $section  리스너가 넘긴 섹션
     * @return array<string, mixed>|null 정규화된 섹션 (형식이 틀리면 null)
     */
    private function normalizeSection(array $section): ?array
    {
        $id = $section['id'] ?? null;
        if (! is_string($id) || strlen($id) > 100 || preg_match(self::ID_PATTERN, $id) !== 1) {
            return null;
        }

        $title = $this->text($section['title'] ?? null, self::MAX_SHORT_TEXT);
        if ($title === null || $title === '') {
            return null;
        }

        $rows = $this->rows($section['rows'] ?? null);
        if ($rows === null) {
            return null;
        }

        $badge = null;
        if (array_key_exists('badge', $section) && $section['badge'] !== null) {
            $badge = $this->toned($section['badge'], self::MAX_SHORT_TEXT);
            if ($badge === null) {
                return null;
            }
        }

        $list = null;
        if (array_key_exists('list', $section) && $section['list'] !== null) {
            $list = $this->list($section['list']);
            if ($list === null) {
                return null;
            }
        }

        $notice = null;
        if (array_key_exists('notice', $section) && $section['notice'] !== null) {
            $notice = $this->toned($section['notice'], self::MAX_LONG_TEXT);
            if ($notice === null) {
                return null;
            }
        }

        return [
            'id' => $id,
            'title' => $title,
            'badge' => $badge,
            'rows' => $rows,
            'list' => $list,
            'notice' => $notice,
        ];
    }

    /**
     * 표 행 목록을 검증합니다 — `[{label, value, mono?}]`.
     *
     * @param  mixed  $rows  리스너가 넘긴 행 목록
     * @return array<int, array{label: string, value: string, mono: bool}>|null 정규화된 행 (형식이 틀리면 null)
     */
    private function rows(mixed $rows): ?array
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            return null;
        }

        $normalized = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                return null;
            }

            $label = $this->text($row['label'] ?? null, self::MAX_SHORT_TEXT);
            $value = $this->text($row['value'] ?? null, self::MAX_LONG_TEXT);
            $mono = $row['mono'] ?? false;

            if ($label === null || $label === '' || $value === null || ! is_bool($mono)) {
                return null;
            }

            $normalized[] = ['label' => $label, 'value' => $value, 'mono' => $mono];
        }

        return $normalized;
    }

    /**
     * 접는 목록을 검증합니다 — `{title, items: [string], collapsible: bool}`.
     *
     * @param  mixed  $list  리스너가 넘긴 목록
     * @return array{title: string, items: array<int, string>, collapsible: bool}|null 정규화된 목록 (형식이 틀리면 null)
     */
    private function list(mixed $list): ?array
    {
        if (! is_array($list) || ! is_array($list['items'] ?? null) || ! array_is_list($list['items'])) {
            return null;
        }

        $title = $this->text($list['title'] ?? null, self::MAX_SHORT_TEXT);
        $collapsible = $list['collapsible'] ?? false;

        if ($title === null || $title === '' || ! is_bool($collapsible)) {
            return null;
        }

        $items = [];
        foreach ($list['items'] as $item) {
            $text = $this->text($item, self::MAX_LONG_TEXT);
            if ($text === null) {
                return null;
            }
            $items[] = $text;
        }

        return ['title' => $title, 'items' => $items, 'collapsible' => $collapsible];
    }

    /**
     * 색조가 붙은 글(배지·알림)을 검증합니다 — `{text, tone}`.
     *
     * @param  mixed  $value  리스너가 넘긴 값
     * @param  int  $maxLength  글 길이 상한
     * @return array{text: string, tone: string}|null 정규화된 값 (형식이 틀리면 null)
     */
    private function toned(mixed $value, int $maxLength): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $text = $this->text($value['text'] ?? null, $maxLength);
        $tone = $value['tone'] ?? null;

        if ($text === null || $text === '' || ! is_string($tone) || ! in_array($tone, self::TONES, true)) {
            return null;
        }

        return ['text' => $text, 'tone' => $tone];
    }

    /**
     * 글 값을 문자열로 바꿉니다 — 문자열·정수·실수만 받고, 올바른 UTF-8 이며 길이 상한 안이어야 합니다.
     *
     * @param  mixed  $value  값
     * @param  int  $maxLength  글자 수 상한
     * @return string|null 문자열 (받을 수 없는 값이면 null)
     */
    private function text(mixed $value, int $maxLength): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $maxLength) {
            return null;
        }

        return trim($value);
    }
}
