<?php

namespace Tests\Unit\Extension\Layout;

use App\Extension\Layout\LayoutContentComparator;
use App\Extension\Layout\LayoutThreeWayMerger;
use App\Extension\Traits\ValidatesLayoutFiles;
use App\Services\LayoutMarkupService;
use App\Support\LayoutMarkup\LayoutPartialOrigin;
use Tests\TestCase;

/**
 * 편집기 왕복(W4) — 실제 번들 레이아웃을 편집기처럼 마크업으로 풀었다 다시 컴파일해 저장한 판은 "운영자 수정" 이 아니다 (#727 §6.2).
 *
 * 운영자가 코드 편집기로 화면을 열고 아무것도 바꾸지 않은 채 저장하면 DB `content` 는 역변환 → 프로덕션 컴파일을 거친 판이
 * 된다. 비교기가 이 판을 원본과 다르다고 보면 모든 저장본이 "수정됨" 으로 보여 keep 업데이트가 새 버전 변경을 통째로 놓친다
 * (계획 §9 R2 — 이 테스트가 유일한 가드). 관리자 템플릿의 「모듈 관리」 화면(`admin_module_list` — 모달 조각 10여 개)을 쓴다.
 */
class LayoutEditorRoundTripMergeTest extends TestCase
{
    /** 번들 관리자 템플릿 식별자 */
    private const TEMPLATE = 'sirsoft-admin_basic';

    /** 대상 화면 */
    private const LAYOUT = 'admin_module_list';

    /**
     * 컴파일 결과 캐시 (번들 레이아웃 전체 검증은 한 번만).
     *
     * @var array{data: array<string, mixed>, original: array<string, mixed>}|null
     */
    private static ?array $compiled = null;

    /**
     * 번들 `admin_module_list` 를 설치 경로와 같이 컴파일합니다 — `data`(서비스 본문)와 `original`(조각 출처 표식 포함).
     *
     * @return array{data: array<string, mixed>, original: array<string, mixed>} 컴파일 결과
     */
    private function compiled(): array
    {
        if (self::$compiled !== null) {
            return self::$compiled;
        }

        $reader = new class
        {
            use ValidatesLayoutFiles;

            /**
             * 레이아웃 디렉토리(하위 제외)를 설치 경로와 같이 검증·컴파일합니다.
             *
             * @param  string  $path  layouts 경로
             * @param  string  $identifier  템플릿 식별자
             * @return array<int, array<string, mixed>> 검증 결과
             */
            public function read(string $path, string $identifier): array
            {
                return $this->validateLayoutFiles($path, $identifier, 'template', false);
            }
        };

        foreach ($reader->read(base_path('templates/_bundled/'.self::TEMPLATE.'/layouts'), self::TEMPLATE) as $layout) {
            if ($layout['layout_name'] === self::LAYOUT) {
                return self::$compiled = ['data' => $layout['data'], 'original' => $layout['original']];
            }
        }

        $this->fail(self::LAYOUT.' 를 컴파일하지 못했다');
    }

    /**
     * 편집기 왕복 — 코드 편집기가 여는 마크업으로 되돌린 뒤 저장 경로(프로덕션 컴파일)로 다시 JSON 을 만듭니다.
     *
     * @param  array<string, mixed>  $content  저장된 본문 (DB `content`)
     * @return array<string, mixed> 저장 뒤 `content`
     */
    private function editorRoundTrip(array $content): array
    {
        $service = app(LayoutMarkupService::class);
        $markup = $service->toMarkup($content, self::TEMPLATE)['markup'];

        return $service->toJson($markup, self::TEMPLATE, 'template', self::TEMPLATE, self::LAYOUT.'.g7.html')->json;
    }

    /**
     * 조각 출처 표식이 붙은 첫 노드(origin 이 접미어로 끝나는)에 값을 덧붙입니다 — 새 버전이 그 조각만 고친 판.
     *
     * @param  mixed  $node  문서 또는 노드
     * @param  string  $originSuffix  조각 경로 접미어
     * @param  bool  $done  이미 고쳤는지 (참조)
     * @return mixed 고친 문서
     */
    private function touchPartial(mixed $node, string $originSuffix, bool &$done = false): mixed
    {
        if (! is_array($node) || $done) {
            return $node;
        }

        if (is_string($node[LayoutPartialOrigin::KEY] ?? null) && str_ends_with($node[LayoutPartialOrigin::KEY], $originSuffix)) {
            $node['props']['data-probe'] = 'THEIRS-PROBE';
            $done = true;

            return $node;
        }

        foreach ($node as $key => $child) {
            $node[$key] = $this->touchPartial($child, $originSuffix, $done);
        }

        return $node;
    }

    /**
     * 아무것도 고치지 않은 편집기 왕복 판은 비교기가 원본과 같다고 보고, 새 버전이 조각 하나를 고치면 병합기는 새 판을
     * 그대로 받는다(운영자 판 = 원본 지름길).
     *
     * @effects editor_round_trip_is_not_a_modification, representation_only_differences_are_not_modifications, untouched_unit_takes_new_version
     *
     * @return void 반환값 없음
     */
    public function test_editor_round_trip_of_real_bundle_is_not_a_modification(): void
    {
        ['data' => $data, 'original' => $original] = $this->compiled();
        $this->assertTrue(LayoutPartialOrigin::has($original), '원본에는 조각 출처 표식이 있어야 한다');

        $ours = $this->editorRoundTrip($data);

        $this->assertTrue(LayoutContentComparator::sameDocument($ours, LayoutPartialOrigin::strip($original)), '편집기 왕복만 한 판을 운영자 수정으로 보면 안 된다');

        $done = false;
        $theirs = $this->touchPartial($original, '/_modal_update', $done);
        $this->assertTrue($done, '업데이트 모달 조각을 찾아 고쳐야 한다');

        $result = (new LayoutThreeWayMerger)->merge($original, $ours, $theirs);

        $this->assertSame('took_theirs', $result->verdict);
        $this->assertStringContainsString('THEIRS-PROBE', json_encode($result->merged));
    }

    /**
     * 편집기로 제목만 고쳐 저장한 판(왕복 + 운영자 수정)에 새 버전이 조각 하나를 고치면, 조각 단위 투영이 왕복 판에서도 맞아
     * 새 판 조각이 반영되고 운영자 제목은 남는다 — 충돌 0.
     *
     * @effects editor_round_trip_is_not_a_modification, untouched_unit_takes_new_version, operator_modified_unit_is_kept
     *
     * @return void 반환값 없음
     */
    public function test_editor_saved_operator_edit_merges_new_partial_change(): void
    {
        ['data' => $data, 'original' => $original] = $this->compiled();

        $edited = $data;
        $edited['meta']['title'] = '운영자 제목';
        $ours = $this->editorRoundTrip($edited);

        $done = false;
        $theirs = $this->touchPartial($original, '/_modal_update', $done);
        $result = (new LayoutThreeWayMerger)->merge($original, $ours, $theirs);

        $this->assertSame('merged', $result->verdict);
        $this->assertSame([], $result->conflicts, '왕복 판의 조각 투영이 어긋나면 충돌이 난다');
        $this->assertSame('운영자 제목', $result->merged['meta']['title']);
        $this->assertStringContainsString('THEIRS-PROBE', json_encode($result->merged));
        $this->assertContains('partial', array_column($result->applied, 'kind'));
    }
}
