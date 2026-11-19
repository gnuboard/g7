<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 인스톨러 스타일시트의 캐스케이드·가시성 계약.
 *
 * 세 결함 모두 **텍스트는 정상 표시되므로** 스크린샷을 사람이 봐도 지나간다:
 *
 *  - #B30 펼친 아코디언에 높이 상한이 있으면 넘친 단계가 스크롤바도 없이 사라진다
 *         (13차 제품 결정 — 그 잘림을 막으면서 접기 전환도 유지한다: `max-height: none` 은
 *          `0` 과 보간되지 않아 애니메이션을 함께 없앴다. 그리드 행 크기 `1fr ↔ 0fr` 로 바꾼다)
 *  - #B31 같은 선택자를 미디어 쿼리 **뒤**에서 다시 선언하면 그 보정이 한 번도 적용되지 않는다
 *         (미디어 쿼리는 명시도를 올리지 않는다 — 같은 명시도면 뒤가 이긴다)
 *  - #B33 `opacity` 는 색 토큰이 기준을 지켜도 실효 대비를 깎는다. 토큰을 보는 검사는 이
 *         채널을 원리상 볼 수 없어, 이 지점들은 어느 라운드에서도 드러나지 않았다
 *
 * 커버 축: cascade_conflict media_then_top·none / overflow_cap capped·uncapped / dim_channel exempt·text /
 *          collapse_transition interpolable·non_interpolable / collapse_child single·missing
 */
class InstallerCssCascadeContractTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /**
     * `opacity` 로 흐려도 되는 지점과 그 근거.
     *
     * 접근성 기준이 면제하는 것은 **비활성 컨트롤**과 **장식 아이콘**이다. 본문 텍스트는
     * 여기에 들어올 수 없다 — 넣어야 할 것 같으면 색 토큰이나 굵기로 강조를 낮춘다.
     *
     * @var array<string, string>
     */
    private const OPACITY_ALLOWED = [
        '.form-input:disabled' => '비활성 컨트롤',
        '.form-select:disabled' => '비활성 컨트롤',
        '.checkbox-input:disabled' => '비활성 컨트롤',
        '.btn:disabled' => '비활성 컨트롤',
        '.btn-danger:disabled' => '비활성 컨트롤',
        '.bulk-toggle-checkbox:disabled' => '비활성 컨트롤',
        '.dep-tag i' => '장식 아이콘 — 뜻은 옆 텍스트가 전한다',
        '.integration-tag i' => '종류 아이콘 — 확장 이름은 옆 텍스트가 전한다 (의존 태그 .dep-tag i 와 같은 마크업·규칙)',
        '.db-existing-tables-badge:hover' => 'hover 피드백 (기본 상태는 흐리지 않다)',
        '.vendor-mode-card.disabled .vendor-mode-card-icon' => '장식 아이콘만 — 글자는 흐리지 않는다 (10차 결정)',
        '.vendor-mode-card.disabled .vendor-mode-card-check' => '같음',
        '.extension-card.disabled .extension-card-select' => '같음 (같은 규칙으로 맞췄다)',
    ];

    /** @var array<int, array{sel: string, props: array<string, string>, line: int, media: bool}> */
    private array $rules = [];

    /**
     * 설치기 스타일시트를 읽어 규칙 목록으로 풀어 두고, 규칙을 하나도 읽지 못하면 실패시킨다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->rules = $this->parse(
            (string) file_get_contents(dirname(__DIR__, 3).'/public/install/assets/css/installer.css'),
        );

        $this->assertNotEmpty($this->rules, 'CSS 규칙을 하나도 읽지 못했다 — 이 검사가 통째로 사각이 된다');
    }

    /**
     * 스타일시트를 규칙 목록으로 훑는다 (중괄호 깊이 추적 + 주석 건너뛰기).
     *
     * @param  string  $css  훑을 스타일시트 원문
     * @return array<int, array{sel: string, props: array<string, string>, line: int, media: bool}> 선택자 하나마다 선언, 시작 줄, 미디어 쿼리 안인지를 담은 규칙 목록
     */
    private function parse(string $css): array
    {
        $rules = [];
        $buffer = '';
        $atDepth = 0;
        $length = strlen($css);

        for ($i = 0; $i < $length;) {
            if (substr($css, $i, 2) === '/*') {
                $end = strpos($css, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;

                continue;
            }

            $char = $css[$i];

            if ($char === '}') {
                if ($atDepth > 0) {
                    $atDepth--;
                }
                $buffer = '';
                $i++;

                continue;
            }

            if ($char !== '{') {
                $buffer .= $char;
                $i++;

                continue;
            }

            $selector = trim($buffer);
            $buffer = '';
            $start = $i;

            if (str_starts_with($selector, '@')) {
                // @media / @keyframes 등은 본문을 다시 훑는다
                $atDepth++;
                $i++;

                continue;
            }

            // 규칙 본문 수집
            $depth = 1;
            $j = $i + 1;
            $body = '';
            while ($j < $length && $depth > 0) {
                if (substr($css, $j, 2) === '/*') {
                    $end = strpos($css, '*/', $j + 2);
                    $j = $end === false ? $length : $end + 2;

                    continue;
                }
                if ($css[$j] === '{') {
                    $depth++;
                }
                if ($css[$j] === '}') {
                    $depth--;
                }
                if ($depth > 0) {
                    $body .= $css[$j];
                }
                $j++;
            }

            $props = [];
            foreach (explode(';', $body) as $declaration) {
                $parts = explode(':', $declaration, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                $name = trim($parts[0]);
                if ($name === '' || str_starts_with($name, '--') || str_contains($name, '{')) {
                    continue;
                }
                $props[$name] = trim($parts[1]);
            }

            $line = substr_count($css, "\n", 0, $start) + 1;
            foreach (array_filter(array_map('trim', explode(',', $selector))) as $single) {
                $rules[] = ['sel' => $single, 'props' => $props, 'line' => $line, 'media' => $atDepth > 0];
            }

            $i = $j;
        }

        return $rules;
    }

    /**
     * 미디어 쿼리 밖의 펼친 작업 그룹 규칙에 높이 상한이 없어 넘친 단계가 잘려 사라지지 않는지 확인한다.
     *
     * @effects installer_expanded_accordion_has_no_height_cap
     *
     * @return void 반환값 없음
     */
    public function test_펼친_작업_그룹에_높이_상한이_없다(): void
    {
        foreach ($this->rules as $rule) {
            if ($rule['sel'] !== '.task-group-items' || $rule['media']) {
                continue;
            }

            $this->assertSame(
                'none',
                $rule['props']['max-height'] ?? 'none',
                '펼친 아코디언에 높이 상한이 있다 (줄 '.$rule['line'].")\n"
                .'항목 하나가 약 40px 이고 좁은 화면에서는 두 줄로 접힌다 — 상한을 넘긴 단계는 '
                .'`overflow: hidden` 때문에 스크롤바도 없이 화면에서 사라진다.',
            );
        }
    }

    /**
     * 선택자 하나의 최상위 선언들을 소스 순서대로 병합해 돌려준다.
     *
     * @param  string  $selector  선언을 모을 선택자
     * @return array<string, string> 실효 선언 (뒤에 온 선언이 이긴다)
     */
    private function topLevelProps(string $selector): array
    {
        $merged = [];

        foreach ($this->rules as $rule) {
            if ($rule['media'] || $rule['sel'] !== $selector) {
                continue;
            }

            $merged = array_merge($merged, $rule['props']);
        }

        return $merged;
    }

    /**
     * 접기 애니메이션은 보간 가능한 속성으로 구현되어야 한다 (제품 결정).
     *
     * 앞서 잘림을 `max-height: none` 으로 막으면서 접기 전환이 함께 사라졌다 —
     * `none` 은 `0` 과 보간되지 않아 패딩 전환만 남는다. 잘림 금지와 전환 유지는 함께
     * 성립해야 하므로, 높이 상한이 아니라 그리드 행 크기(`1fr ↔ 0fr`)를 전환한다.
     *
     * 이 방식은 **단일 자식**을 요구한다 — 자식이 여럿이면 두 번째 행부터 `auto` 라
     * 접히지 않는다. 그래서 클리핑을 맡는 자식 규칙까지 함께 고정한다.
     *
     * @effects installer_accordion_collapse_uses_interpolable_transition
     *
     * @return void 반환값 없음
     */
    public function test_접힘_전환은_보간되는_속성으로_구현된다(): void
    {
        $expanded = $this->topLevelProps('.task-group-items');
        $collapsed = $this->topLevelProps('.task-group.collapsed .task-group-items');
        $inner = $this->topLevelProps('.task-group-items-inner');

        $this->assertNotSame([], $expanded, '.task-group-items 규칙을 찾지 못했다 — 이 검사가 통째로 사각이 된다');
        $this->assertNotSame([], $collapsed, '.task-group.collapsed .task-group-items 규칙을 찾지 못했다');

        $this->assertSame('grid', $expanded['display'] ?? null, '펼친 아코디언이 그리드가 아니면 행 크기 전환이 성립하지 않는다');
        $this->assertSame('1fr', $expanded['grid-template-rows'] ?? null, '펼친 상태의 행 크기는 내용 높이를 따라야 한다 (상한 없음)');
        $this->assertStringContainsString(
            'grid-template-rows',
            $expanded['transition'] ?? '',
            "접기 전환 대상에 grid-template-rows 가 없다 — 패딩만 전환되면 목록이 즉시 사라진다.\n"
            .'`max-height: none ↔ 0` 은 보간되지 않으므로 그 조합으로 되돌리지 말 것.',
        );

        $this->assertSame('0fr', $collapsed['grid-template-rows'] ?? null, '접힌 상태의 행 크기가 0fr 이 아니면 접히지 않는다');
        $this->assertArrayNotHasKey(
            'max-height',
            $collapsed,
            '접힌 상태가 max-height 로 되돌아갔다 — 펼친 상태의 상한 없음과 함께 쓰면 전환이 사라진다',
        );

        $this->assertSame(
            'hidden',
            $inner['overflow'] ?? null,
            "그리드 자식이 넘치는 내용을 잘라내지 않으면 접힌 뒤에도 항목이 그대로 보인다.\n"
            .'클리핑은 부모가 아니라 이 단일 자식이 맡는다 (부모가 자르면 행 크기 전환이 상쇄된다).',
        );
        $this->assertSame(
            '0',
            $inner['min-height'] ?? null,
            '그리드 항목의 기본 min-height: auto 가 0fr 을 무시한다 — min-height: 0 이 함께 필요하다',
        );
    }

    /**
     * 접기 전환이 요구하는 단일 자식이 실제 마크업에 있어야 한다.
     *
     * CSS 만 고치면 그 규칙은 아무 요소에도 붙지 않는다 — 접힘이 조용히 동작하지 않는데
     * 스타일시트만 보면 정상으로 읽힌다.
     *
     * @effects installer_accordion_collapse_child_wrapper_exists
     *
     * @return void 반환값 없음
     */
    public function test_접힘_전환이_요구하는_단일_자식이_마크업에_있다(): void
    {
        $js = $this->stripJsComments((string) file_get_contents(dirname(__DIR__, 3).'/public/install/assets/js/installer.js'));

        $this->assertMatchesRegularExpression(
            '/class="task-group-items"[^>]*>\s*<div class="task-group-items-inner">/',
            $js,
            '.task-group-items 의 유일한 자식이 .task-group-items-inner 가 아니다 — '
            .'그리드 행 크기 전환은 자식이 하나일 때만 접힌다.',
        );
    }

    /**
     * 미디어 쿼리 안의 보정 선언을 소스 순서상 뒤에 오는 같은 선택자의 최상위 선언이 같은 속성으로 덮지 않는지 확인한다.
     *
     * @effects installer_media_query_corrections_are_not_overridden
     *
     * @return void 반환값 없음
     */
    public function test_미디어_쿼리_보정을_뒤의_중복_선언이_덮지_않는다(): void
    {
        $conflicts = [];

        foreach ($this->rules as $inMedia) {
            if (! $inMedia['media']) {
                continue;
            }

            foreach ($this->rules as $topLevel) {
                if ($topLevel['media'] || $topLevel['sel'] !== $inMedia['sel'] || $topLevel['line'] <= $inMedia['line']) {
                    continue;
                }

                $overlap = array_intersect(array_keys($inMedia['props']), array_keys($topLevel['props']));
                if ($overlap === []) {
                    continue;
                }

                $conflicts[] = $inMedia['sel'].' — 미디어 규칙 @'.$inMedia['line']
                    .' 를 최상위 @'.$topLevel['line'].' 이 덮음: '.implode(', ', $overlap);
            }
        }

        $this->assertSame(
            [],
            $conflicts,
            "미디어 쿼리 보정이 뒤의 중복 선언에 무력화된다:\n  ".implode("\n  ", $conflicts)
            ."\n미디어 쿼리는 명시도를 올리지 않는다 — 같은 명시도면 소스 순서가 뒤인 쪽이 이긴다."
            ."\n보정은 원 선언 **뒤**에 두거나, 속성을 원 선언으로 합친다.",
        );
    }

    /**
     * 0 과 1 사이의 불투명도를 쓰는 규칙이 면제 목록에 근거와 함께 등록된 선택자뿐인지 확인한다.
     *
     * @effects installer_opacity_is_limited_to_documented_cases
     *
     * @return void 반환값 없음
     */
    public function test_불투명도_감소는_문서화된_지점에만_있다(): void
    {
        $offenders = [];

        foreach ($this->rules as $rule) {
            $value = $rule['props']['opacity'] ?? null;
            if ($value === null) {
                continue;
            }
            // 0 (완전 숨김: 시각적으로 감춘 네이티브 input, 애니메이션 시작 프레임) 과 1 은 대비와 무관
            $numeric = (float) $value;
            if ($numeric <= 0.0 || $numeric >= 1.0) {
                continue;
            }
            if (array_key_exists($rule['sel'], self::OPACITY_ALLOWED)) {
                continue;
            }

            $offenders[] = $rule['sel'].' @'.$rule['line'].' → opacity: '.$value;
        }

        $this->assertSame(
            [],
            $offenders,
            "문서화되지 않은 불투명도 감소:\n  ".implode("\n  ", $offenders)
            ."\n색 토큰이 기준을 지켜도 투명도가 실효 대비를 깎는다 — 토큰을 보는 검사는 이 채널을 볼 수 없다."
            ."\n본문 텍스트라면 보조 텍스트 토큰이나 굵기로 강조를 낮춘다. 비활성 컨트롤·장식 아이콘이면"
            ."\nOPACITY_ALLOWED 에 근거와 함께 등록한다.",
        );
    }

    /**
     * 불투명도 면제 목록의 선택자가 모두 실제로 흐린 불투명도를 선언하고 있어 죽은 면제 항목이 남지 않았는지 확인한다.
     *
     * @effects installer_opacity_is_limited_to_documented_cases
     *
     * @return void 반환값 없음
     */
    public function test_면제_목록에_이미_해소된_항목이_남아_있지_않다(): void
    {
        $declared = [];
        foreach ($this->rules as $rule) {
            $value = $rule['props']['opacity'] ?? null;
            if ($value === null) {
                continue;
            }
            $numeric = (float) $value;
            if ($numeric > 0.0 && $numeric < 1.0) {
                $declared[$rule['sel']] = true;
            }
        }

        $stale = array_values(array_diff(array_keys(self::OPACITY_ALLOWED), array_keys($declared)));

        $this->assertSame(
            [],
            $stale,
            '이미 흐리지 않는데 면제 목록에 남은 항목: '.implode(', ', $stale)
            ."\n남겨 두면 다음 사람이 「면제된 것」으로 읽고 다시 흐리게 만든다.",
        );
    }
}
