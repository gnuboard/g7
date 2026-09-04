<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Support\LayoutMarkup\LayoutCorpus;
use Illuminate\Support\Facades\File;
use Tests\Feature\LayoutMarkup\LayoutSourceMatrixTest;

/**
 * S18.5 축 C 마크업 부채의 **기계 판본** — 고친 것이 다시 들어오지 못하게 잠근다.
 *
 * ## 왜 audit 룰이 있는데 또 만드는가
 *
 * 두 장치가 보는 자리가 다르다. audit 룰의 `appliesTo` 는 **`.json` 전용**이고, 코퍼스 `.json` 은
 * S18.5 굽기 이전까지 **어떤 변경셋에도 든 적이 없어** 구조적으로 미검출이었다 — 626건이 그렇게
 * 쌓였다. 이 테스트는 **소스 `.g7.html` 을 직접, 변경셋과 무관하게 전량** 본다. 그래서 audit 이
 * 다시 사각이 되더라도(글롭이 바뀌든, 파일이 변경셋에 안 들든) 이 축은 살아 있다.
 *
 * S16 이 결함 대장을 서술에서 {@see LayoutDefectLedgerTest} 로 옮긴 것과 같은 이유다 —
 * *"누락 방지는 서술이 아니라 테스트가 맡는다"*.
 *
 * ## 판정 대상
 *
 * - **아이콘 크기 표기**: Icon 은 `<i>` 글리프라 박스가 아니라 `font-size` 로 크기가 정해진다.
 *   `w-N h-N` 은 상자만 정하고 글리프는 부모 `font-size` 를 상속하므로 의도와 실제가 어긋난다.
 * - **반복 안 정적 `data-ref`**: `data-ref` 는 DOM `id` 가 되므로 `data-each` 안에서 정적이면
 *   행마다 같은 id 가 N벌 생긴다.
 * - **`{{$event}}` 단독 사용**: Toggle·Checkbox 류는 boolean 이 아니라 **이벤트 객체**를 넘긴다
 *   ({@see LayoutCorpus} 코퍼스 실측 — 이커머스 공통정보 폼이 그 상태였다).
 */
class AuthoringDebtLedgerTest extends LayoutMarkupTestCase
{
    /**
     * 코퍼스 하한 — 스캔이 죽으면 "위반 0" 이 아니라 "검사 대상 0" 이다. 그 둘을 가른다.
     */
    private const CORPUS_MINIMUM = 500;

    /**
     * 소스 전량을 `[상대경로 => 원문]` 으로 돌려준다.
     *
     * @return array<string, string>
     */
    private function corpus(): array
    {
        $out = [];

        foreach (LayoutCorpus::scan('.g7.html') as $file) {
            $out[ltrim(str_replace('\\', '/', str_replace(base_path(), '', $file)), '/')] = File::get($file);
        }

        $this->assertGreaterThanOrEqual(
            self::CORPUS_MINIMUM,
            count($out),
            '소스 스캔이 '.count($out).'건 — 모집단이 무너졌다. 아래 단언이 공허하게 참이 된다',
        );

        return $out;
    }

    /**
     * `<icon>` 이 박스 크기(`w-N h-N`)로 크기를 정하지 않는다.
     *
     * @scenario compile_unit=layout,source_health=valid
     *
     * @effects icon_size_uses_glyph_font_size_not_box_dimensions
     */
    public function test_icons_size_by_font_size_not_box_dimensions(): void
    {
        $violations = [];

        foreach ($this->corpus() as $relative => $source) {
            foreach (explode("\n", $source) as $number => $line) {
                if (! str_contains($line, '<icon') || preg_match('/\bw-[\d.]+\s+h-[\d.]+\b/', $line) !== 1) {
                    continue;
                }

                $violations[] = $relative.':'.($number + 1);
            }
        }

        $this->assertSame([], $violations,
            'Icon 에 박스 크기 표기가 남아 있다 — 글리프 크기(`text-*`)로 옮길 것 (AGENTS.md 등가표):'
            .PHP_EOL.implode(PHP_EOL, $violations));
    }

    /**
     * 반복(`data-each`) 안의 `data-ref` 가 행마다 유일하다.
     *
     * 같은 줄에 `data-each` 와 정적 `data-ref` 가 함께 있는 형태만 본다 — 감싸는 반복까지 추적하는
     * 것은 audit 룰(`layout-iteration-static-id`)이 컴파일된 트리에서 하고, 여기서는 **소스에서
     * 눈으로 보이는 형태**를 잠근다. 둘은 같은 명제의 서로 다른 관측면이다.
     *
     * @scenario compile_unit=layout,source_health=valid
     *
     * @effects iteration_rows_get_unique_dom_ids
     */
    public function test_iteration_rows_get_unique_dom_ids(): void
    {
        $violations = [];

        foreach ($this->corpus() as $relative => $source) {
            foreach (explode("\n", $source) as $number => $line) {
                if (! str_contains($line, 'data-each=')) {
                    continue;
                }

                // `{{` 가 없는 `data-ref` = 정적 id
                if (preg_match('/data-ref="[^"{}]+"/', $line) !== 1) {
                    continue;
                }

                $violations[] = $relative.':'.($number + 1);
            }
        }

        $this->assertSame([], $violations,
            '반복 안에 정적 data-ref 가 남아 있다 — 행마다 같은 DOM id 가 생긴다. 인덱스 변수로 유일화할 것:'
            .PHP_EOL.implode(PHP_EOL, $violations));
    }

    // `{{$event}}` 축은 **여기서 지지 않는다** — 소스 한 줄로는 판정할 수 없다.
    //
    // 착수 시 이 축을 넣었다가 코퍼스 실측이 반례 3건을 냈다: ⓐ DataGrid `columnVisibilityChange`
    // 처럼 **커스텀 컴포넌트 이벤트**는 `$event` 가 payload 자체라 `{{$event}}` 단독이 정상이고
    // ⓑ `{{$event?.target?.value ?? ''}}` 는 옵셔널 체이닝일 뿐 값 사용이 아니다. 둘을 가르려면
    // **native DOM 이벤트인지 커스텀 이벤트인지**를 알아야 하고, 그 정보는 컴파일된 액션 구조에만
    // 있다 — 그래서 그 판정은 audit 룰 `layout-binding-event-value` 가 지고, 특정 결함의 회귀는
    // 파일 단위 테스트가 진다({@see LayoutSourceMatrixTest} 와 같은
    // 분담: 일반형은 룰, 실물 결함은 테스트).
    //
    // 이 주석을 남기는 이유는 다음 저자가 같은 축을 다시 넣지 않게 하기 위해서다 — 넣으면
    // 정상 표기 3건이 red 가 된다.

    /**
     * 판정식이 살아 있다 — 합성 표본을 모집단 입구에 넣어 세 축이 실제로 검출하는지 본다.
     *
     * 세 단언 모두 "0건" 을 주장하므로, 판정식이 죽어도(정규식 오타·문법 변경) 초록이다.
     * 그 상태를 막으려면 **검출되는 것을 보여야** 한다(근본 H — 표본을 판정식이 아니라 입구에).
     */
    public function test_predicates_detect_the_forms_they_forbid(): void
    {
        $samples = [
            'icon' => '<icon name="x" class="w-4 h-4 text-red-500"/>',
            'static_id' => '<div data-ref="row" data-each="item in {{list}}"></div>',
            'event_value' => '<set-state target="local" flag="{{$event}}"/>',
            'event_expr' => '<switch on="{{$event &amp;&amp; !x}}">',
        ];

        $this->assertSame(1, preg_match('/\bw-[\d.]+\s+h-[\d.]+\b/', $samples['icon']),
            '아이콘 판정식이 죽었다');
        $this->assertSame(1, preg_match('/data-ref="[^"{}]+"/', $samples['static_id']),
            '정적 id 판정식이 죽었다');
        $this->assertSame(1, preg_match('/\{\{\s*\$event\s*\}\}/', $samples['event_value']),
            '$event 단독 판정식이 죽었다');
        $this->assertSame(1, preg_match('/\$event\s*(&amp;&amp;|&&|\|\||\?)/', $samples['event_expr']),
            '$event 표현식 판정식이 죽었다');

        // 정상 표기는 걸리지 않는다 (과탐 반례)
        $this->assertSame(0, preg_match('/\bw-[\d.]+\s+h-[\d.]+\b/', '<icon class="text-base"/>'));
        $this->assertSame(0, preg_match('/\{\{\s*\$event\s*\}\}/', '{{$event.target.checked}}'));
    }
}
