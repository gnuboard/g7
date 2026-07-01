/**
 * bindingCandidates.test.ts — 데이터 연결 후보 풀 빌더 단위
 *
 * 검증:
 *  - data_sources 샘플 shape walk → scalar leaf / array 후보
 *  - 상태 트리(_global) walk + stateLabels 카탈로그 친화 명칭 결선
 *  - shape 필터(scalar/array)
 *  - 키워드 검색(명칭·경로·미리보기값)
 *  - parseBindingExpression: 단일 경로 인지 / 복합식 디그레이드(null)
 *
 * @since engine-v1.50.0
 */

// layout-editor-data-binding.yaml axes cross product 마킹(82 surviving) + effects.
// 단위는 후보 풀/필터/파싱/디그레이드를 잠그고, 라이브(캔버스/저장/실사용자화면) 및 dnd 선택
// effects 는 Chrome MCP §공통 5단계 매트릭스가 검증한다(e2e:allow 정합). 본 파일이 매니페스트
// axes/effects 의 SSoT 마킹 보유처(scenario coverage 게이트).
// @scenario shape=scalar, source_kind=data_source, current_value=unconnected, template=sirsoft-basic
// @scenario shape=scalar, source_kind=data_source, current_value=unconnected, template=sirsoft-admin_basic
// @scenario shape=scalar, source_kind=data_source, current_value=single_path_binding, template=sirsoft-basic
// @scenario shape=scalar, source_kind=data_source, current_value=single_path_binding, template=sirsoft-admin_basic
// @scenario shape=scalar, source_kind=data_source, current_value=complex_binding, template=sirsoft-basic
// @scenario shape=scalar, source_kind=data_source, current_value=complex_binding, template=sirsoft-admin_basic
// @scenario shape=scalar, source_kind=data_source, current_value=static_literal, template=sirsoft-basic
// @scenario shape=scalar, source_kind=data_source, current_value=static_literal, template=sirsoft-admin_basic
// @scenario shape=scalar, source_kind=_global, current_value=unconnected, template=sirsoft-basic
// @scenario shape=scalar, source_kind=_global, current_value=unconnected, template=sirsoft-admin_basic
// @scenario shape=scalar, source_kind=_global, current_value=single_path_binding, template=sirsoft-basic
// @scenario shape=scalar, source_kind=_global, current_value=single_path_binding, template=sirsoft-admin_basic
// @scenario shape=scalar, source_kind=_global, current_value=complex_binding, template=sirsoft-basic
// @scenario shape=scalar, source_kind=_global, current_value=complex_binding, template=sirsoft-admin_basic
// @scenario shape=scalar, source_kind=_local, current_value=unconnected, template=sirsoft-basic
// @scenario shape=scalar, source_kind=_local, current_value=unconnected, template=sirsoft-admin_basic
// @scenario shape=scalar, source_kind=_local, current_value=single_path_binding, template=sirsoft-basic
// @scenario shape=scalar, source_kind=_local, current_value=single_path_binding, template=sirsoft-admin_basic
// @scenario shape=scalar, source_kind=_local, current_value=complex_binding, template=sirsoft-basic
// @scenario shape=scalar, source_kind=_local, current_value=complex_binding, template=sirsoft-admin_basic
// @scenario shape=scalar, source_kind=route, current_value=unconnected, template=sirsoft-basic
// @scenario shape=scalar, source_kind=route, current_value=unconnected, template=sirsoft-admin_basic
// @scenario shape=scalar, source_kind=route, current_value=single_path_binding, template=sirsoft-basic
// @scenario shape=scalar, source_kind=route, current_value=single_path_binding, template=sirsoft-admin_basic
// @scenario shape=scalar, source_kind=route, current_value=complex_binding, template=sirsoft-basic
// @scenario shape=scalar, source_kind=route, current_value=complex_binding, template=sirsoft-admin_basic
// @scenario shape=scalar, source_kind=query, current_value=unconnected, template=sirsoft-basic
// @scenario shape=scalar, source_kind=query, current_value=unconnected, template=sirsoft-admin_basic
// @scenario shape=scalar, source_kind=query, current_value=single_path_binding, template=sirsoft-basic
// @scenario shape=scalar, source_kind=query, current_value=single_path_binding, template=sirsoft-admin_basic
// @scenario shape=scalar, source_kind=query, current_value=complex_binding, template=sirsoft-basic
// @scenario shape=scalar, source_kind=query, current_value=complex_binding, template=sirsoft-admin_basic
// @scenario shape=scalar, source_kind=_computed, current_value=unconnected, template=sirsoft-basic
// @scenario shape=scalar, source_kind=_computed, current_value=unconnected, template=sirsoft-admin_basic
// @scenario shape=scalar, source_kind=_computed, current_value=single_path_binding, template=sirsoft-basic
// @scenario shape=scalar, source_kind=_computed, current_value=single_path_binding, template=sirsoft-admin_basic
// @scenario shape=scalar, source_kind=_computed, current_value=complex_binding, template=sirsoft-basic
// @scenario shape=scalar, source_kind=_computed, current_value=complex_binding, template=sirsoft-admin_basic
// @scenario shape=array, source_kind=data_source, current_value=unconnected, template=sirsoft-basic
// @scenario shape=array, source_kind=data_source, current_value=unconnected, template=sirsoft-admin_basic
// @scenario shape=array, source_kind=data_source, current_value=single_path_binding, template=sirsoft-basic
// @scenario shape=array, source_kind=data_source, current_value=single_path_binding, template=sirsoft-admin_basic
// @scenario shape=array, source_kind=data_source, current_value=static_literal, template=sirsoft-basic
// @scenario shape=array, source_kind=data_source, current_value=static_literal, template=sirsoft-admin_basic
// @scenario shape=array, source_kind=_global, current_value=unconnected, template=sirsoft-basic
// @scenario shape=array, source_kind=_global, current_value=unconnected, template=sirsoft-admin_basic
// @scenario shape=array, source_kind=_global, current_value=single_path_binding, template=sirsoft-basic
// @scenario shape=array, source_kind=_global, current_value=single_path_binding, template=sirsoft-admin_basic
// @scenario shape=array, source_kind=_local, current_value=unconnected, template=sirsoft-basic
// @scenario shape=array, source_kind=_local, current_value=unconnected, template=sirsoft-admin_basic
// @scenario shape=array, source_kind=_local, current_value=single_path_binding, template=sirsoft-basic
// @scenario shape=array, source_kind=_local, current_value=single_path_binding, template=sirsoft-admin_basic
// @scenario shape=array, source_kind=route, current_value=unconnected, template=sirsoft-basic
// @scenario shape=array, source_kind=route, current_value=unconnected, template=sirsoft-admin_basic
// @scenario shape=array, source_kind=route, current_value=single_path_binding, template=sirsoft-basic
// @scenario shape=array, source_kind=route, current_value=single_path_binding, template=sirsoft-admin_basic
// @scenario shape=array, source_kind=query, current_value=unconnected, template=sirsoft-basic
// @scenario shape=array, source_kind=query, current_value=unconnected, template=sirsoft-admin_basic
// @scenario shape=array, source_kind=query, current_value=single_path_binding, template=sirsoft-basic
// @scenario shape=array, source_kind=query, current_value=single_path_binding, template=sirsoft-admin_basic
// @scenario shape=array, source_kind=_computed, current_value=unconnected, template=sirsoft-basic
// @scenario shape=array, source_kind=_computed, current_value=unconnected, template=sirsoft-admin_basic
// @scenario shape=array, source_kind=_computed, current_value=single_path_binding, template=sirsoft-basic
// @scenario shape=array, source_kind=_computed, current_value=single_path_binding, template=sirsoft-admin_basic
// @scenario shape=object, source_kind=data_source, current_value=unconnected, template=sirsoft-basic
// @scenario shape=object, source_kind=data_source, current_value=unconnected, template=sirsoft-admin_basic
// @scenario shape=object, source_kind=data_source, current_value=single_path_binding, template=sirsoft-basic
// @scenario shape=object, source_kind=data_source, current_value=single_path_binding, template=sirsoft-admin_basic
// @scenario shape=object, source_kind=data_source, current_value=static_literal, template=sirsoft-basic
// @scenario shape=object, source_kind=data_source, current_value=static_literal, template=sirsoft-admin_basic
// @scenario shape=object, source_kind=_global, current_value=unconnected, template=sirsoft-basic
// @scenario shape=object, source_kind=_global, current_value=unconnected, template=sirsoft-admin_basic
// @scenario shape=object, source_kind=_global, current_value=single_path_binding, template=sirsoft-basic
// @scenario shape=object, source_kind=_global, current_value=single_path_binding, template=sirsoft-admin_basic
// @scenario shape=object, source_kind=_local, current_value=unconnected, template=sirsoft-basic
// @scenario shape=object, source_kind=_local, current_value=unconnected, template=sirsoft-admin_basic
// @scenario shape=object, source_kind=_local, current_value=single_path_binding, template=sirsoft-basic
// @scenario shape=object, source_kind=_local, current_value=single_path_binding, template=sirsoft-admin_basic
// @scenario shape=object, source_kind=_computed, current_value=unconnected, template=sirsoft-basic
// @scenario shape=object, source_kind=_computed, current_value=unconnected, template=sirsoft-admin_basic
// @scenario shape=object, source_kind=_computed, current_value=single_path_binding, template=sirsoft-basic
// @scenario shape=object, source_kind=_computed, current_value=single_path_binding, template=sirsoft-admin_basic
/**
 * @effects datapropspec_object_shape_added_to_schema_and_audit_enum,
 *   dataprops_declared_exhaustively_both_templates_admin18_basic10,
 *   dataprops_orthogonal_to_propcontrols_and_nodeeditor_no_overlap,
 *   structural_numeric_enum_boolean_props_have_zero_data_binding_ui,
 *   binding_candidates_include_data_source_sample_shape,
 *   binding_candidates_include_global_local_route_query_computed_state_trees,
 *   object_node_candidate_emitted_for_entity_objects_not_pure_containers,
 *   shape_filter_returns_only_matching_scalar_array_object_candidates,
 *   state_labels_catalog_declared_both_templates_with_t_keys,
 *   candidate_label_resolved_via_edit_template_dictionary_fallback_to_key,
 *   data_source_label_key_deferred_to_6c_with_exhaustive_coverage_mandate,
 *   select_candidate_writes_binding_expression_to_props_propkey,
 *   clear_removes_binding_back_to_unconnected,
 *   single_path_binding_prefilled_in_picker_via_parse,
 *   complex_binding_degrades_to_code_edit_readonly_notice,
 *   bound_array_prop_renders_sample_collection_live_on_canvas,
 *   bound_scalar_prop_renders_live_value_on_canvas,
 *   state_toggle_changes_bound_data_on_canvas,
 *   save_persists_binding_user_page_fetches_real_data_source
 */


import { describe, it, expect } from 'vitest';
import {
  buildBindingCandidates,
  buildBindingExpression,
  filterCandidatesByShape,
  searchCandidates,
  parseBindingExpression,
  collectIterationVars,
  buildArrayItemFieldsLookup,
  buildActionContextCandidates,
} from '../../spec/bindingCandidates';
import type { EditorSpec, EditorActionChipCandidatesSpec } from '../../spec/specTypes';

describe('buildBindingCandidates — data_sources', () => {
  it('스칼라 leaf 와 배열 경로를 각각 scalar/array 후보로 만든다', () => {
    const out = buildBindingCandidates({
      dataSources: [
        {
          id: 'products',
          labelKey: '$t:editor.ds.products',
          sample: {
            data: {
              data: [{ name: '노트북', price: 1200 }],
              pagination: { total: 47 },
            },
          },
        },
      ],
    });
    const arr = out.find((c) => c.path === 'data.data');
    expect(arr).toBeTruthy();
    expect(arr!.shape).toBe('array');
    expect(arr!.expression).toBe('{{products.data.data}}');
    expect(arr!.preview).toBe('[1]');
    expect(arr!.itemFields).toEqual(['name', 'price']);
    expect(arr!.groupLabelKey).toBe('editor.ds.products');

    const total = out.find((c) => c.path === 'data.pagination.total');
    expect(total).toBeTruthy();
    expect(total!.shape).toBe('scalar');
    expect(total!.expression).toBe('{{products.data.pagination.total}}');
    expect(total!.preview).toBe('47');
  });
});

describe('buildBindingCandidates — 상태 트리 + stateLabels', () => {
  const spec: EditorSpec = {
    stateLabels: [
      { key: 'currentUser.data.name', scope: '_global', label_key: '$t:editor.state.current_user_name' },
    ],
  };

  it('_global walk + stateLabels 친화 명칭 결선, 미선언은 labelKey 부재', () => {
    const out = buildBindingCandidates({
      spec,
      states: [
        {
          scope: '_global',
          tree: { currentUser: { data: { name: '홍길동' } }, settings: { siteName: 'G7' } },
        },
      ],
    });
    const named = out.find((c) => c.path === 'currentUser.data.name');
    expect(named).toBeTruthy();
    expect(named!.source).toBe('_global');
    expect(named!.labelKey).toBe('editor.state.current_user_name');
    expect(named!.expression).toBe('{{_global.currentUser.data.name}}');

    const unnamed = out.find((c) => c.path === 'settings.siteName');
    expect(unnamed).toBeTruthy();
    expect(unnamed!.labelKey).toBeUndefined(); // 카탈로그 미선언 → 키 폴백(피커 책임)
  });
});

describe('buildBindingCandidates — object shape (단일 객체 바인딩)', () => {
  it('직접 스칼라 leaf 보유 객체는 object 후보로도 emit (product/author/user)', () => {
    const out = buildBindingCandidates({
      dataSources: [
        {
          id: 'profile',
          sample: {
            user: { id: 1, name: '홍길동', email: 'a@b.c', meta: { roles: ['admin'] } },
          },
        },
      ],
    });
    const obj = out.find((c) => c.path === 'user' && c.shape === 'object');
    expect(obj).toBeTruthy();
    expect(obj!.expression).toBe('{{profile.user}}');
    expect(obj!.preview).toBe('{4}');
    expect(obj!.itemFields).toEqual(['id', 'name', 'email', 'meta']);
    // 스칼라 leaf 후보도 그대로 공존(객체 후보 emit 이 재귀를 막지 않음).
    expect(out.find((c) => c.path === 'user.name' && c.shape === 'scalar')).toBeTruthy();
  });

  it('순수 컨테이너(직접 스칼라 leaf 없음)는 object 후보에서 제외 — 피커 범람 방지', () => {
    const out = buildBindingCandidates({
      dataSources: [
        { id: 'wrap', sample: { data: { list: [{ a: 1 }] } } },
      ],
    });
    // `data` 는 자식이 객체뿐(직접 스칼라 leaf 0) → object 후보 아님.
    expect(out.find((c) => c.path === 'data' && c.shape === 'object')).toBeUndefined();
    // 배열은 array 후보로 정상 노출.
    expect(out.find((c) => c.path === 'data.list' && c.shape === 'array')).toBeTruthy();
  });

  it('object shape 필터가 객체 후보만 가른다', () => {
    const out = buildBindingCandidates({
      dataSources: [{ id: 'p', sample: { author: { id: 1, name: 'x' }, count: 3 } }],
    });
    const objs = filterCandidatesByShape(out, 'object');
    expect(objs.length).toBe(1);
    expect(objs[0]!.path).toBe('author');
  });
});

describe('buildBindingCandidates — per-state 후보', () => {
  it('_local/route/query/_computed scope 트리를 각각 후보로 평탄화한다', () => {
    const out = buildBindingCandidates({
      states: [
        { scope: '_local', tree: { searchKeyword: '노트북', form: { name: 'x' } } },
        { scope: 'query', tree: { tab: 'general' } },
        { scope: 'route', tree: { id: 7 } },
        { scope: '_computed', tree: { isAdmin: '{{currentUser.is_admin}}' } },
      ],
    });
    expect(out.find((c) => c.expression === '{{_local.searchKeyword}}')?.source).toBe('_local');
    expect(out.find((c) => c.expression === '{{_local.form.name}}')).toBeTruthy();
    expect(out.find((c) => c.expression === '{{query.tab}}')?.source).toBe('query');
    expect(out.find((c) => c.expression === '{{route.id}}')?.source).toBe('route');
    expect(out.find((c) => c.expression === '{{_computed.isAdmin}}')?.source).toBe('_computed');
  });
});

describe('filterCandidatesByShape / searchCandidates', () => {
  const candidates = buildBindingCandidates({
    dataSources: [
      { id: 'products', sample: { data: { data: [{ name: 'x' }] }, count: 3 } },
    ],
  });

  it('shape 로 후보를 가른다', () => {
    expect(filterCandidatesByShape(candidates, 'array').every((c) => c.shape === 'array')).toBe(true);
    expect(filterCandidatesByShape(candidates, 'scalar').every((c) => c.shape === 'scalar')).toBe(true);
  });

  it('키워드로 경로/미리보기/명칭을 매칭한다', () => {
    const withLabel = candidates.map((c) => ({ ...c, resolvedLabel: c.path }));
    expect(searchCandidates(withLabel, 'count').length).toBe(1);
    expect(searchCandidates(withLabel, 'data.data').length).toBe(1);
    expect(searchCandidates(withLabel, '').length).toBe(candidates.length); // 빈 검색=전체
    expect(searchCandidates(withLabel, 'zzz없음').length).toBe(0);
  });
});

describe('parseBindingExpression', () => {
  it('단일 경로 바인딩을 소스/경로로 분해한다 (data_source)', () => {
    expect(parseBindingExpression('{{products.data.data}}')).toMatchObject({
      source: 'data_source',
      sourceId: 'products',
      path: 'data.data',
    });
  });

  it('상태 scope 루트를 인지한다', () => {
    expect(parseBindingExpression('{{_global.currentUser.data.name}}')).toMatchObject({
      source: '_global',
      sourceId: '_global',
      path: 'currentUser.data.name',
    });
    expect(parseBindingExpression('{{query.q}}')).toMatchObject({
      source: 'query',
      sourceId: 'query',
      path: 'q',
    });
  });

  it('배열 인덱스 경로를 허용한다', () => {
    expect(parseBindingExpression('{{products.data[0].name}}')).toMatchObject({
      source: 'data_source',
      sourceId: 'products',
      path: 'data[0].name',
    });
  });

  // 의미 보존 정규화: G7 표준 안전 바인딩(`?.` 체이닝 + `?? []` 폴백)을
  // 인식해 소스/경로 추출 + optional/fallback 보존(재기입용). 이전엔 전부 null 디그레이드돼
  // 검색 피커가 가려졌던 결함을 메운다.
  it('옵셔널 체이닝(`?.`)을 흡수해 경로를 추출한다 + optional 보존', () => {
    expect(parseBindingExpression('{{products?.data?.data}}')).toMatchObject({
      source: 'data_source',
      sourceId: 'products',
      path: 'data.data',
      optional: true,
    });
  });

  it('널 병합 폴백(`?? []`/`?? \'\'`)을 분리해 경로 추출 + fallback 보존', () => {
    expect(parseBindingExpression('{{products?.data?.data ?? []}}')).toMatchObject({
      source: 'data_source',
      sourceId: 'products',
      path: 'data.data',
      optional: true,
      fallback: '[]',
    });
    expect(parseBindingExpression("{{query.q ?? ''}}")).toMatchObject({
      source: 'query',
      sourceId: 'query',
      path: 'q',
      fallback: "''",
    });
  });

  it('외곽 괄호 + 폴백 조합을 흡수한다', () => {
    expect(parseBindingExpression('{{(category?.data?.children ?? [])}}')).toMatchObject({
      source: 'data_source',
      sourceId: 'category',
      path: 'data.children',
      optional: true,
      fallback: '[]',
    });
  });

  it('진짜 복합식·보간 텍스트·비-문자열은 null(디그레이드)', () => {
    expect(parseBindingExpression('{{a ? b : c}}')).toBeNull();
    // 폴백이 단순 리터럴이 아니라 또 다른 표현식이면 복합 → null
    expect(parseBindingExpression('{{a ?? b.c}}')).toBeNull();
    expect(parseBindingExpression('{{items | filter}}')).toBeNull();
    expect(parseBindingExpression('{{a}} 텍스트 {{b}}')).toBeNull();
    expect(parseBindingExpression('앞 {{a.b}}')).toBeNull();
    expect(parseBindingExpression('정적 평문')).toBeNull();
    expect(parseBindingExpression(undefined)).toBeNull();
    expect(parseBindingExpression(42)).toBeNull();
  });

  // SEO 다국어 추출 함수 래핑 — `$localized(<단순경로>)` 는 인자 경로를 단일 바인딩으로 인지하되
  // 함수명(localeFn)을 보존한다. SEO 메타값(meta_title/name 등 다국어 객체)을 현재 로케일 문자열로
  // 추출하는 코어 SEO 표현식과 정합 — 편집기 검색엔진 탭이 그 인자 경로를 친화 데이터 칩으로 보인다.
  it('$localized(<경로>) 래핑을 인지해 인자 경로를 단일 바인딩으로 추출 + localeFn 보존', () => {
    expect(parseBindingExpression('{{$localized(product.data.meta_title)}}')).toMatchObject({
      source: 'data_source',
      sourceId: 'product',
      path: 'data.meta_title',
      localeFn: '$localized',
    });
    // 상태 scope 인자도 동일하게 인지.
    expect(parseBindingExpression('{{$localized(_global.currentUser.data.name)}}')).toMatchObject({
      source: '_global',
      sourceId: '_global',
      path: 'currentUser.data.name',
      localeFn: '$localized',
    });
  });

  it('$localized 인자가 다인자/연산/리터럴이거나 폴백 체인이면 null(복합 → 트리 분해 대상)', () => {
    // 다인자 호출 — 단일 경로 칩 대상 아님.
    expect(parseBindingExpression('{{$localized(a.b, 1)}}')).toBeNull();
    // 인자가 연산식.
    expect(parseBindingExpression('{{$localized(a.b + c)}}')).toBeNull();
    // 폴백 체인(`?? $localized(...)`)은 단일 칩이 아니라 표현식 트리(expressionValueTree) 영역.
    expect(parseBindingExpression('{{$localized(a.b) ?? $localized(c.d)}}')).toBeNull();
    // 미등록 함수는 종전대로 복합 → null.
    expect(parseBindingExpression('{{Math.max(a.b)}}')).toBeNull();
  });
});

describe('buildBindingExpression — 재기입 안전 형태', () => {
  it('array shape → `?.` 체이닝 + `?? []` 폴백', () => {
    expect(buildBindingExpression('products', 'data.data', 'array')).toBe('{{products?.data?.data ?? []}}');
  });

  it('scalar shape → `?? \'\'` 폴백', () => {
    expect(buildBindingExpression('query', 'q', 'scalar')).toBe("{{query?.q ?? ''}}");
  });

  it('object shape → `?? {}` 폴백', () => {
    expect(buildBindingExpression('product_detail', 'data', 'object')).toBe('{{product_detail?.data ?? {}}}');
  });

  it('경로 없는 소스 루트도 안전 형태', () => {
    expect(buildBindingExpression('categories', '', 'array')).toBe('{{categories ?? []}}');
  });

  it('round-trip: build → parse 가 소스/경로를 복원한다', () => {
    const expr = buildBindingExpression('products', 'data.data', 'array');
    expect(parseBindingExpression(expr)).toMatchObject({
      source: 'data_source',
      sourceId: 'products',
      path: 'data.data',
      optional: true,
      fallback: '[]',
    });
  });

  // SEO 추출 함수 래핑 재기입 — localeFn 지정 시 `$localized(<경로>)` 형태(옵셔널 체이닝/폴백 없음).
  // 인자에 `?.` 를 넣으면 코어 SEO ExpressionEvaluator 가 인자 경로를 다국어 객체로 받지 못한다.
  it('localeFn 지정 → `$localized(<멤버경로>)` 래핑(체이닝/폴백 없음)', () => {
    expect(buildBindingExpression('product', 'data.meta_title', 'scalar', '$localized')).toBe(
      '{{$localized(product.data.meta_title)}}',
    );
  });

  it('round-trip: build(localeFn) → parse 가 경로 + localeFn 복원', () => {
    const expr = buildBindingExpression('product', 'data.meta_title', 'scalar', '$localized');
    expect(parseBindingExpression(expr)).toMatchObject({
      source: 'data_source',
      sourceId: 'product',
      path: 'data.meta_title',
      localeFn: '$localized',
    });
  });

  it('미등록 localeFn 은 무시하고 일반 안전 형태로 폴백', () => {
    expect(buildBindingExpression('product', 'data.x', 'scalar', 'Math.max')).toBe(
      "{{product?.data?.x ?? ''}}",
    );
  });
});

describe('iteration 변수 후보', () => {
  it('iterationVars: index 는 {{$idx}} scalar 후보, item 은 필드별 후보로 펼친다', () => {
    const out = buildBindingCandidates({
      iterationVars: [
        { name: '$idx', kind: 'index' },
        { name: 'row', kind: 'item', itemFields: ['id', 'name'] },
      ],
    });
    const exprs = out.map((c) => c.expression);
    expect(exprs).toContain('{{$idx}}');
    expect(exprs).toContain('{{row.id}}');
    expect(exprs).toContain('{{row.name}}');
    // 모두 iteration source + scalar shape
    expect(out.every((c) => c.source === 'iteration' && c.shape === 'scalar')).toBe(true);
  });

  it('collectIterationVars: 경로상 iteration 조상의 index_var/item_var 를 바깥→안 순서로 수집', () => {
    const root = {
      children: [
        {
          // [0] iteration 노드
          iteration: { source: '{{list?.data?.data}}', item_var: 'row', index_var: '$idx' },
          children: [
            { children: [] }, // [0,0] 자식(편집 대상)
          ],
        },
      ],
    };
    const lookup = new Map<string, string[]>([['list?.data?.data', ['id', 'name']]]);
    const vars = collectIterationVars(root, [0, 0], lookup);
    expect(vars).toEqual([
      { name: '$idx', kind: 'index' },
      { name: 'row', kind: 'item', itemFields: ['id', 'name'] },
    ]);
  });

  it('collectIterationVars: iteration 조상이 없으면 빈 배열', () => {
    const root = { children: [{ children: [{ children: [] }] }] };
    expect(collectIterationVars(root, [0, 0], new Map())).toEqual([]);
  });

  it('buildArrayItemFieldsLookup: array 후보의 표현식→itemFields 룩업을 만든다', () => {
    const lookup = buildArrayItemFieldsLookup([
      {
        expression: '{{list.data.data}}',
        source: 'data_source',
        sourceId: 'list',
        path: 'data.data',
        shape: 'array',
        preview: '[2]',
        itemFields: ['id', 'name'],
      },
      {
        expression: '{{list.data.total}}',
        source: 'data_source',
        sourceId: 'list',
        path: 'data.total',
        shape: 'scalar',
        preview: '2',
      },
    ]);
    expect(lookup.get('list.data.data')).toEqual(['id', 'name']);
    expect(lookup.has('list.data.total')).toBe(false); // scalar 는 제외
  });
});

describe('buildActionContextCandidates — 컨텍스트 칩 + 확장(actionChipCandidates) 병합', () => {
  const t = (k: string) => k;

  it('response 컨텍스트 — 코어 기본 칩(response.data/message)만, 확장 미주입', () => {
    const out = buildActionContextCandidates('response', t);
    const exprs = out.map((c) => c.expression);
    expect(exprs).toEqual(['{{response.data}}', '{{response.message}}']);
  });

  it('error 컨텍스트 — 코어 기본 5종(status/message/errors/data/statusText)', () => {
    const out = buildActionContextCandidates('error', t);
    expect(out.map((c) => c.path)).toEqual(['status', 'message', 'errors', 'data', 'statusText']);
    expect(out.every((c) => c.expression.startsWith('{{error.'))).toBe(true);
  });

  it('payload 컨텍스트 루트는 message — onReceive 컨텍스트 변수명', () => {
    const out = buildActionContextCandidates('payload', t);
    expect(out.map((c) => c.expression)).toEqual(['{{message.data}}']);
  });

  it('확장 actionChipCandidates 를 코어 기본 칩 뒤에 병합한다(PG 결제 응답 필드)', () => {
    const extra: EditorActionChipCandidatesSpec = {
      response: [
        { path: 'data.pg_payment_handler', labelKey: 'sirsoft-ecommerce.editor.action_chip.response_pg_handler', shape: 'scalar' },
        { path: 'data.pg_payment_data', labelKey: 'sirsoft-ecommerce.editor.action_chip.response_pg_data', shape: 'object' },
      ],
    };
    const out = buildActionContextCandidates('response', t, extra);
    const exprs = out.map((c) => c.expression);
    // 코어 기본 2종 뒤에 확장 2종 — 순서 보존.
    expect(exprs).toEqual([
      '{{response.data}}',
      '{{response.message}}',
      '{{response.data.pg_payment_handler}}',
      '{{response.data.pg_payment_data}}',
    ]);
    // 확장 후보의 shape/labelKey 보존.
    const pgHandler = out.find((c) => c.path === 'data.pg_payment_handler')!;
    expect(pgHandler.shape).toBe('scalar');
    expect(pgHandler.labelKey).toBe('sirsoft-ecommerce.editor.action_chip.response_pg_handler');
  });

  it('확장 후보가 코어 path 와 겹치면 코어 우선 — 1회만 노출(확장이 코어를 덮지 않음)', () => {
    const extra: EditorActionChipCandidatesSpec = {
      response: [{ path: 'data', labelKey: 'x.override', shape: 'object' }],
    };
    const out = buildActionContextCandidates('response', t, extra);
    const dataCands = out.filter((c) => c.path === 'data');
    expect(dataCands).toHaveLength(1);
    // 코어 라벨 유지(확장 override 무시).
    expect(dataCands[0].labelKey).toBe('layout_editor.action_chip.response_data');
  });

  it('다른 컨텍스트(error)의 확장 후보는 response 호출에 새지 않는다', () => {
    const extra: EditorActionChipCandidatesSpec = {
      error: [{ path: 'data.domain_error', labelKey: 'x.err', shape: 'scalar' }],
    };
    const out = buildActionContextCandidates('response', t, extra);
    expect(out.map((c) => c.expression)).toEqual(['{{response.data}}', '{{response.message}}']);
  });

  it('shape 미지정 확장 후보는 scalar 로 기본 처리', () => {
    const extra: EditorActionChipCandidatesSpec = {
      response: [{ path: 'data.token', labelKey: 'x.t' } as { path: string; labelKey: string }],
    };
    const out = buildActionContextCandidates('response', t, extra);
    expect(out.find((c) => c.path === 'data.token')!.shape).toBe('scalar');
  });
});
