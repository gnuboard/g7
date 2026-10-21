<?php

namespace Tests\Feature\Layout;

use App\Extension\Traits\ValidatesLayoutFiles;
use App\Rules\ValidDataSourceMerge;
use App\Services\LayoutService;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * 같은 id 의 데이터 소스를 조건(if·conditions)으로 여러 판 선언하는 레이아웃 (7.1.1 출시 전)
 *
 * 공개 문서 `docs/frontend/data-sources-advanced.md` 「조건부 데이터 소스 로딩」과 엔진 `DataSourceManager.filterByCondition`
 * 은 같은 id 를 상호 배타 조건으로 여러 판 선언하는 것을 허용한다(조건을 만족하는 첫 판만 쓴다). 그런데 코어 설치 검증·
 * 편집기 저장 검증·상속 병합이 조건과 무관하게 「data_sources ID 중복」으로 거부해 그런 레이아웃을 설치할 수 없었다.
 *
 * 규칙: 같은 id 묶음의 **모든 판에 if 또는 conditions 가 있으면** 허용, 조건 없는 판이 섞이면 지금처럼 거부(문서 DON'T).
 * 부모↔자식 사이 id 중복 금지(상속 문서)는 그대로다.
 */
class ConditionalDataSourceVariantsTest extends TestCase
{
    /**
     * 문서 예시 그대로의 두 판(생성/수정 모드).
     *
     * @return array<int, array<string, mixed>> 같은 id 를 if 조건으로 나눈 두 판의 data_sources 목록
     */
    private function documentedVariants(): array
    {
        return [
            ['id' => 'user', 'type' => 'api', 'endpoint' => '/api/admin/users/{{route.id}}', 'if' => '{{route.id}}', 'initLocal' => 'form'],
            ['id' => 'user', 'type' => 'api', 'endpoint' => '/api/admin/users/template', 'if' => '{{!route.id}}', 'initLocal' => 'form'],
        ];
    }

    /**
     * 설치 검증(`validateDataSourceIds` — 설치·레이아웃 행 갱신 공용 관문)이 조건 판은 통과시키고 조건 없는 판이 섞이면 거부한다.
     *
     * @scenario consumer=install_validation
     *
     * @effects all_conditional_same_id_variants_accepted, conditions_key_counts_as_condition, unconditional_variant_mixed_in_rejected, unconditional_duplicate_still_rejected, empty_if_is_not_a_condition
     *
     * @return void 반환값 없음
     */
    public function test_install_validation_accepts_conditional_variants_only(): void
    {
        $validator = new class
        {
            use ValidatesLayoutFiles;

            /**
             * 보호 메서드를 시험에서 부릅니다.
             *
             * @param  array<int, array<string, mixed>>  $dataSources  검사할 data_sources 목록
             * @return string|null 오류 문구 (통과하면 null)
             */
            public function check(array $dataSources): ?string
            {
                return $this->validateDataSourceIds($dataSources, 'layouts/user_form.json');
            }
        };

        $this->assertNull($validator->check($this->documentedVariants()), '문서 예시(if 두 판)가 설치 검증에서 거부됐다');

        $this->assertNull($validator->check([
            ['id' => 'product', 'type' => 'api', 'endpoint' => '/a/{{route.id}}', 'conditions' => '{{!!route.id}}'],
            ['id' => 'product', 'type' => 'api', 'endpoint' => '/a/new', 'if' => '{{!route.id}}'],
        ]), 'conditions 판도 조건으로 친다');

        $mixed = [...$this->documentedVariants(), ['id' => 'user', 'type' => 'api', 'endpoint' => '/api/admin/users/me']];
        $this->assertNotNull($validator->check($mixed), '조건 없는 판이 섞이면 거부(문서 DON\'T)');
        $this->assertNotNull($validator->check([['id' => 'a', 'type' => 'static'], ['id' => 'a', 'type' => 'static']]), '조건 없는 중복은 종전대로 거부');
        $this->assertNotNull($validator->check([['id' => 'a', 'if' => ''], ['id' => 'a', 'if' => '{{x}}']]), '빈 if 는 조건이 아니다');
    }

    /**
     * 편집기 저장 검증(ValidDataSourceMerge)도 같은 규칙이다.
     *
     * @scenario consumer=editor_save_rule
     *
     * @effects all_conditional_same_id_variants_accepted, unconditional_variant_mixed_in_rejected
     *
     * @return void 반환값 없음
     */
    public function test_editor_save_rule_accepts_conditional_variants_only(): void
    {
        $passes = fn (array $dataSources): bool => Validator::make(
            ['content' => ['data_sources' => $dataSources]],
            ['content.data_sources' => ['nullable', 'array', new ValidDataSourceMerge]],
        )->passes();

        $this->assertTrue($passes($this->documentedVariants()), '편집기 저장이 문서 예시를 거부했다');
        $this->assertFalse($passes([...$this->documentedVariants(), ['id' => 'user', 'endpoint' => '/x']]));
    }

    /**
     * 상속 병합(부모 + 자식)이 자식 안의 조건 판을 둘 다 싣고, 부모와 겹치는 id 는 종전대로 막는다.
     *
     * @scenario consumer=inheritance_merge
     *
     * @effects inheritance_merge_keeps_every_child_variant, parent_child_duplicate_id_still_rejected
     *
     * @return void 반환값 없음
     */
    public function test_inheritance_merge_keeps_child_variants(): void
    {
        $merge = new \ReflectionMethod(LayoutService::class, 'mergeDataSources');
        $service = app(LayoutService::class);
        $parent = [['id' => 'site', 'type' => 'api', 'endpoint' => '/api/site']];

        $merged = $merge->invoke($service, $parent, $this->documentedVariants());
        $this->assertSame(['site', 'user', 'user'], array_column($merged, 'id'));
        $this->assertSame('/api/admin/users/template', $merged[2]['endpoint']);

        $this->expectException(\Throwable::class);
        $merge->invoke($service, $parent, [['id' => 'site', 'type' => 'api', 'endpoint' => '/x', 'if' => '{{a}}']]);
    }
}
