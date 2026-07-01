// e2e:allow 부록7 7-a label_key I18nTextField 승격 반영(단위). 브라우저 검증은 7-b 세션 귀속.
/**
 * DataSourcesPanel.test.tsx —
 *
 * 페이지 설정 · 데이터 소스 CRUD 모달 검증:
 * - splitSources: 자체(__editor.original.data_sources) / 상속(merged 중 자체 외) 분리
 * - 추가/편집/삭제 → onChange(merged, own) 정확 호출
 * - label_key 보존/설정/해제
 * - 검증: id 필수/형식/중복, params/fallback JSON 파싱 오류
 * - 상속 소스 읽기전용 표시(편집/삭제 버튼 없음)
 *
 * 시나리오 매니페스트: tests/scenarios/layout-editor-data-sources.yaml
 *
 * @effects split_sources_own_from_editor_original_inherited_from_merged,
 *   add_source_appends_to_own_and_merged_preserves_inherited,
 *   edit_source_preserves_unedited_fields_like_source_meta,
 *   remove_source_drops_from_own_and_merged,
 *   inherited_sources_render_readonly_no_edit_remove_buttons,
 *   id_required_invalid_format_duplicate_rejected_with_error,
 *   params_fallback_invalid_json_rejected_with_error,
 *   params_binding_expression_preserved_as_object,
 *   label_key_saved_and_removed_on_empty
 */

// 시나리오 cross product (operation × source_origin × ds_type × template) 마킹 —
// 라인 주석 형태(전역 매칭). DataSourcesPanel 단위가 add/edit/remove × 자체 소스를
// 커버하고, ds_type 별 add 폼 필드 노출/저장(api·static·route_params·query_params·
// websocket)을 동일 폼 경로로 검증한다.
// @scenario operation=add, source_origin=own, ds_type=api, template=sirsoft-basic
// @scenario operation=add, source_origin=own, ds_type=api, template=sirsoft-admin_basic
// @scenario operation=add, source_origin=own, ds_type=static, template=sirsoft-basic
// @scenario operation=add, source_origin=own, ds_type=static, template=sirsoft-admin_basic
// @scenario operation=add, source_origin=own, ds_type=route_params, template=sirsoft-basic
// @scenario operation=add, source_origin=own, ds_type=route_params, template=sirsoft-admin_basic
// @scenario operation=add, source_origin=own, ds_type=query_params, template=sirsoft-basic
// @scenario operation=add, source_origin=own, ds_type=query_params, template=sirsoft-admin_basic
// @scenario operation=add, source_origin=own, ds_type=websocket, template=sirsoft-basic
// @scenario operation=add, source_origin=own, ds_type=websocket, template=sirsoft-admin_basic
// @scenario operation=edit, source_origin=own, ds_type=api, template=sirsoft-basic
// @scenario operation=edit, source_origin=own, ds_type=api, template=sirsoft-admin_basic
// @scenario operation=remove, source_origin=own, ds_type=api, template=sirsoft-basic
// @scenario operation=remove, source_origin=own, ds_type=api, template=sirsoft-admin_basic

import React from 'react';
import { describe, expect, it, vi } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';

// useLayoutEditor — label_key 입력의 I18nTextField(부록7 7-a)가 공통 hook 으로 읽는 컨텍스트 모킹.
vi.mock('../../LayoutEditorContext', () => ({
  useLayoutEditor: () => ({
    state: {
      templateIdentifier: 'sirsoft-basic',
      locale: 'ko',
      selectedRoute: { path: '/home', layoutName: 'home' },
    },
  }),
}));
// TranslationEngine — label_key 토큰 해석(미리보기 시작값). raw 키/평문은 빈 해석.
vi.mock('../../../TranslationEngine', () => ({
  TranslationEngine: { getInstance: () => ({ translate: (k: string) => k }) },
}));

import { DataSourcesPanel } from '../../components/property-controls/DataSourcesPanel';

// 테스트용 t — 키 그대로 반환(파라미터는 무시). UI 텍스트 검증이 아닌 동작 검증이므로 충분.
const t = (key: string): string => key;

function rawWithOwn(own: Array<Record<string, unknown>>, merged?: Array<Record<string, unknown>>) {
  return {
    components: [],
    data_sources: merged ?? own,
    __editor: { original: { data_sources: own } },
  };
}

describe('DataSourcesPanel — splitSources', () => {
  it('__editor.original.data_sources 를 자체로 분리한다', () => {
    const raw = rawWithOwn([{ id: 'products', endpoint: '/api/p' }]);
    render(<DataSourcesPanel raw={raw} onChange={vi.fn()} t={t} onClose={vi.fn()} />);
    const items = screen.getAllByTestId('g7le-data-sources-item');
    expect(items).toHaveLength(1);
    expect(items[0].getAttribute('data-ds-id')).toBe('products');
  });

  it('merged 중 자체에 없는 id 는 상속으로 분리(읽기전용)', () => {
    const raw = rawWithOwn(
      [{ id: 'products' }],
      [{ id: 'parent_user' }, { id: 'products' }], // merged = 상속 + 자체
    );
    render(<DataSourcesPanel raw={raw} onChange={vi.fn()} t={t} onClose={vi.fn()} />);
    expect(screen.getAllByTestId('g7le-data-sources-item')).toHaveLength(1);
    const inh = screen.getAllByTestId('g7le-data-sources-inherited-item');
    expect(inh).toHaveLength(1);
    expect(inh[0].getAttribute('data-ds-id')).toBe('parent_user');
  });

  it('__editor 부재(레거시) 시 최상위 data_sources 로 폴백', () => {
    const raw = { components: [], data_sources: [{ id: 'legacy' }] };
    render(<DataSourcesPanel raw={raw} onChange={vi.fn()} t={t} onClose={vi.fn()} />);
    const items = screen.getAllByTestId('g7le-data-sources-item');
    expect(items).toHaveLength(1);
    expect(items[0].getAttribute('data-ds-id')).toBe('legacy');
  });
});

describe('DataSourcesPanel — 추가', () => {
  it('신규 소스 추가 → onChange(merged=상속∪자체, own=자체)', () => {
    const onChange = vi.fn();
    const raw = rawWithOwn([{ id: 'existing' }], [{ id: 'inh' }, { id: 'existing' }]);
    render(<DataSourcesPanel raw={raw} onChange={onChange} t={t} onClose={vi.fn()} />);

    fireEvent.click(screen.getByTestId('g7le-data-sources-add'));
    fireEvent.change(screen.getByTestId('g7le-data-sources-field-id'), { target: { value: 'newSrc' } });
    fireEvent.change(screen.getByTestId('g7le-ds-endpoint-input'), { target: { value: '/api/new' } });
    fireEvent.click(screen.getByTestId('g7le-data-sources-form-submit'));

    expect(onChange).toHaveBeenCalledTimes(1);
    const [merged, own] = onChange.mock.calls[0];
    expect(own.map((d: any) => d.id)).toEqual(['existing', 'newSrc']);
    // merged 에 상속(inh) 이 보존되어야 함
    expect(merged.map((d: any) => d.id)).toEqual(['inh', 'existing', 'newSrc']);
    const added = own.find((d: any) => d.id === 'newSrc');
    expect(added.endpoint).toBe('/api/new');
    expect(added.type).toBe('api');
    expect(added.auto_fetch).toBe(true);
  });

  it('label_key 입력은 동적 다국어 필드(부록7 7-a) — 기존 토큰 보존, 빈 값이면 제거', () => {
    // 부록7 7-a: label_key 입력은 raw `$t:` 직접 입력칸이 아니라 I18nTextField(평문→키 자동생성).
    // 기존 `$t:custom.*` 토큰을 가진 소스를 편집 → 라벨 미수정 submit 시 토큰이 보존된다.
    const onChange = vi.fn();
    render(
      <DataSourcesPanel
        raw={rawWithOwn([{ id: 'products', label_key: '$t:custom.home.3' }])}
        onChange={onChange}
        t={t}
        onClose={vi.fn()}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-data-sources-edit'));
    // I18nTextField 가 라벨 입력 자리에 렌더된다(평문 input 직접 노출 아님).
    expect(screen.getByTestId('g7le-data-sources-field-label-preview')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-data-sources-form-submit'));
    const own = onChange.mock.calls[0][1];
    expect(own[0].label_key).toBe('$t:custom.home.3');
  });

  it('label_key 빈 값(평문/토큰 모두 없음) → 저장 시 제거', () => {
    const onChange = vi.fn();
    render(<DataSourcesPanel raw={rawWithOwn([])} onChange={onChange} t={t} onClose={vi.fn()} />);
    fireEvent.click(screen.getByTestId('g7le-data-sources-add'));
    fireEvent.change(screen.getByTestId('g7le-data-sources-field-id'), { target: { value: 'products' } });
    fireEvent.click(screen.getByTestId('g7le-data-sources-form-submit'));
    const own = onChange.mock.calls[0][1];
    expect(own[0].label_key).toBeUndefined();
  });
});

describe('DataSourcesPanel — 편집/삭제', () => {
  it('편집 시 기존 필드 보존(__source 등) + 변경 필드만 갱신', () => {
    const onChange = vi.fn();
    const raw = rawWithOwn([
      { id: 'products', endpoint: '/api/old', __source: { kind: 'route' }, method: 'GET' },
    ]);
    render(<DataSourcesPanel raw={raw} onChange={onChange} t={t} onClose={vi.fn()} />);
    fireEvent.click(screen.getByTestId('g7le-data-sources-edit'));
    fireEvent.change(screen.getByTestId('g7le-ds-endpoint-input'), { target: { value: '/api/new' } });
    fireEvent.click(screen.getByTestId('g7le-data-sources-form-submit'));
    const own = onChange.mock.calls[0][1];
    expect(own[0].endpoint).toBe('/api/new');
    // __source 보존
    expect(own[0].__source).toEqual({ kind: 'route' });
  });

  it('삭제 → 해당 소스 제거, merged 에서도 제거', () => {
    const onChange = vi.fn();
    const raw = rawWithOwn([{ id: 'a' }, { id: 'b' }]);
    render(<DataSourcesPanel raw={raw} onChange={onChange} t={t} onClose={vi.fn()} />);
    const items = screen.getAllByTestId('g7le-data-sources-item');
    fireEvent.click(within(items[0]).getByTestId('g7le-data-sources-remove'));
    const own = onChange.mock.calls[0][1];
    expect(own.map((d: any) => d.id)).toEqual(['b']);
  });
});

describe('DataSourcesPanel — 검증', () => {
  function openAddForm() {
    fireEvent.click(screen.getByTestId('g7le-data-sources-add'));
  }

  it('id 빈 값 → 에러, onChange 미호출', () => {
    const onChange = vi.fn();
    render(<DataSourcesPanel raw={rawWithOwn([])} onChange={onChange} t={t} onClose={vi.fn()} />);
    openAddForm();
    fireEvent.click(screen.getByTestId('g7le-data-sources-form-submit'));
    expect(screen.getByTestId('g7le-data-sources-form-error')).toBeInTheDocument();
    expect(onChange).not.toHaveBeenCalled();
  });

  it('id 형식 오류(숫자 시작) → 에러', () => {
    const onChange = vi.fn();
    render(<DataSourcesPanel raw={rawWithOwn([])} onChange={onChange} t={t} onClose={vi.fn()} />);
    openAddForm();
    fireEvent.change(screen.getByTestId('g7le-data-sources-field-id'), { target: { value: '1bad' } });
    fireEvent.click(screen.getByTestId('g7le-data-sources-form-submit'));
    expect(screen.getByTestId('g7le-data-sources-form-error')).toBeInTheDocument();
    expect(onChange).not.toHaveBeenCalled();
  });

  it('id 중복(자체) → 에러', () => {
    const onChange = vi.fn();
    render(<DataSourcesPanel raw={rawWithOwn([{ id: 'dup' }])} onChange={onChange} t={t} onClose={vi.fn()} />);
    openAddForm();
    fireEvent.change(screen.getByTestId('g7le-data-sources-field-id'), { target: { value: 'dup' } });
    fireEvent.click(screen.getByTestId('g7le-data-sources-form-submit'));
    expect(screen.getByTestId('g7le-data-sources-form-error')).toBeInTheDocument();
    expect(onChange).not.toHaveBeenCalled();
  });

  it('id 중복(상속) → 에러 (ValidDataSourceMerge 사전 차단)', () => {
    const onChange = vi.fn();
    const raw = rawWithOwn([{ id: 'own' }], [{ id: 'parent' }, { id: 'own' }]);
    render(<DataSourcesPanel raw={raw} onChange={onChange} t={t} onClose={vi.fn()} />);
    openAddForm();
    fireEvent.change(screen.getByTestId('g7le-data-sources-field-id'), { target: { value: 'parent' } });
    fireEvent.click(screen.getByTestId('g7le-data-sources-form-submit'));
    expect(screen.getByTestId('g7le-data-sources-form-error')).toBeInTheDocument();
    expect(onChange).not.toHaveBeenCalled();
  });

  it('params raw 모드(코드 편집) JSON 파싱 오류 → 에러 (①)', () => {
    const onChange = vi.fn();
    render(<DataSourcesPanel raw={rawWithOwn([])} onChange={onChange} t={t} onClose={vi.fn()} />);
    openAddForm();
    fireEvent.change(screen.getByTestId('g7le-data-sources-field-id'), { target: { value: 'src' } });
    // 블럭 → 코드(raw JSON) 모드 전환 후 잘못된 JSON 입력.
    fireEvent.click(screen.getByTestId('g7le-ds-params-mode-toggle'));
    fireEvent.change(screen.getByTestId('g7le-data-sources-field-params'), { target: { value: '{ not json' } });
    fireEvent.click(screen.getByTestId('g7le-data-sources-form-submit'));
    expect(screen.getByTestId('g7le-data-sources-form-error')).toBeInTheDocument();
    expect(onChange).not.toHaveBeenCalled();
  });

  it('params raw 모드 유효 JSON(바인딩 표현식 포함) → 객체로 저장 (①)', () => {
    const onChange = vi.fn();
    render(<DataSourcesPanel raw={rawWithOwn([])} onChange={onChange} t={t} onClose={vi.fn()} />);
    openAddForm();
    fireEvent.change(screen.getByTestId('g7le-data-sources-field-id'), { target: { value: 'src' } });
    fireEvent.click(screen.getByTestId('g7le-ds-params-mode-toggle'));
    fireEvent.change(screen.getByTestId('g7le-data-sources-field-params'), {
      target: { value: '{ "page": "{{query.page ?? 1}}" }' },
    });
    fireEvent.click(screen.getByTestId('g7le-data-sources-form-submit'));
    const own = onChange.mock.calls[0][1];
    expect(own[0].params).toEqual({ page: '{{query.page ?? 1}}' });
  });
});

describe('DataSourcesPanel — 상속 소스 읽기전용', () => {
  it('상속 소스에는 편집/삭제 버튼이 없다', () => {
    const raw = rawWithOwn([{ id: 'own' }], [{ id: 'inh' }, { id: 'own' }]);
    render(<DataSourcesPanel raw={raw} onChange={vi.fn()} t={t} onClose={vi.fn()} />);
    const inh = screen.getByTestId('g7le-data-sources-inherited-item');
    expect(within(inh).queryByTestId('g7le-data-sources-edit')).toBeNull();
    expect(within(inh).queryByTestId('g7le-data-sources-remove')).toBeNull();
  });

  it('상속 소스 토글 클릭 시 정보(endpoint/method 등) 펼침/접힘', () => {
    const raw = rawWithOwn(
      [{ id: 'own' }],
      [
        { id: 'inh', type: 'api', endpoint: '/api/inh', method: 'GET', __source: { kind: 'base' } },
        { id: 'own' },
      ],
    );
    render(<DataSourcesPanel raw={raw} onChange={vi.fn()} t={t} onClose={vi.fn()} />);
    const inh = screen.getByTestId('g7le-data-sources-inherited-item');
    // 초기엔 접힘 — 정보 미노출
    expect(inh.getAttribute('data-expanded')).toBe('false');
    expect(within(inh).queryByTestId('g7le-data-sources-inherited-info')).toBeNull();
    // 토글 펼침
    fireEvent.click(within(inh).getByTestId('g7le-data-sources-inherited-toggle'));
    expect(inh.getAttribute('data-expanded')).toBe('true');
    const info = within(inh).getByTestId('g7le-data-sources-inherited-info');
    expect(info.textContent).toContain('/api/inh');
    expect(info.textContent).toContain('GET');
    // 다시 접힘
    fireEvent.click(within(inh).getByTestId('g7le-data-sources-inherited-toggle'));
    expect(inh.getAttribute('data-expanded')).toBe('false');
  });
});

describe('DataSourcesPanel — label_key 친화 명칭 표시', () => {
  // resolveLabel 가 친화 명칭을 돌려주는 가짜 사전
  const resolveLabel = (key: string): string => {
    const map: Record<string, string> = {
      'editor.data_source.products': '상품 목록',
      'editor.data_source.categories': '카테고리',
    };
    return map[key] ?? key;
  };

  it('label_key 해석 성공 시 친화 명칭이 제목, id 는 보조 표시', () => {
    const raw = rawWithOwn([{ id: 'products', label_key: '$t:editor.data_source.products' }]);
    render(<DataSourcesPanel raw={raw} onChange={vi.fn()} t={t} resolveLabel={resolveLabel} onClose={vi.fn()} />);
    const title = screen.getByTestId('g7le-data-sources-item-title');
    expect(title.textContent).toContain('상품 목록');
    expect(within(title).getByTestId('g7le-data-sources-item-id').textContent).toBe('products');
  });

  it('label_key 미해석/미지정 시 id 가 제목(보조 id 없음)', () => {
    const raw = rawWithOwn([{ id: 'unknownSrc' }]); // label_key 없음
    render(<DataSourcesPanel raw={raw} onChange={vi.fn()} t={t} resolveLabel={resolveLabel} onClose={vi.fn()} />);
    const title = screen.getByTestId('g7le-data-sources-item-title');
    expect(title.textContent).toBe('unknownSrc');
    expect(within(title).queryByTestId('g7le-data-sources-item-id')).toBeNull();
  });

  it('resolveLabel 미전달 시 t 폴백(키 그대로면 id 표시)', () => {
    const raw = rawWithOwn([{ id: 'products', label_key: '$t:editor.data_source.products' }]);
    // t 는 키 그대로 반환 → 해석 실패 → id 폴백
    render(<DataSourcesPanel raw={raw} onChange={vi.fn()} t={t} onClose={vi.fn()} />);
    const title = screen.getByTestId('g7le-data-sources-item-title');
    expect(title.textContent).toBe('products');
  });
});

describe('DataSourcesPanel — 확장 출처 배지', () => {
  it('확장 주입 소스(__source.kind=extension)는 모듈/플러그인 + 식별자 배지 표시', () => {
    const raw = rawWithOwn(
      [{ id: 'own' }],
      [
        {
          id: 'gdprMyConsent',
          __source: { kind: 'extension', extensionSourceType: 'plugin', extensionIdentifier: 'sirsoft-gdpr', extensionName: 'GDPR' },
        },
        { id: 'own' },
      ],
    );
    render(<DataSourcesPanel raw={raw} onChange={vi.fn()} t={t} onClose={vi.fn()} />);
    const inh = screen.getByTestId('g7le-data-sources-inherited-item');
    const badge = within(inh).getByTestId('g7le-data-sources-ext-badge');
    // t 는 키 그대로 반환 → "layout_editor.data_sources.source.plugin: GDPR (sirsoft-gdpr)"
    expect(badge.textContent).toContain('plugin');
    expect(badge.textContent).toContain('GDPR');
    expect(badge.textContent).toContain('sirsoft-gdpr');
  });

  it('모듈 출처는 module 배지, 비-확장(route)은 배지 없음', () => {
    const raw = rawWithOwn(
      [{ id: 'own' }],
      [
        { id: 'products', __source: { kind: 'extension', extensionSourceType: 'module', extensionIdentifier: 'sirsoft-ecommerce' } },
        { id: 'boards', __source: { kind: 'route', identifier: null } },
        { id: 'own' },
      ],
    );
    render(<DataSourcesPanel raw={raw} onChange={vi.fn()} t={t} onClose={vi.fn()} />);
    const items = screen.getAllByTestId('g7le-data-sources-inherited-item');
    const prod = items.find((e) => e.getAttribute('data-ds-id') === 'products')!;
    const board = items.find((e) => e.getAttribute('data-ds-id') === 'boards')!;
    expect(within(prod).getByTestId('g7le-data-sources-ext-badge').textContent).toContain('module');
    expect(within(prod).getByTestId('g7le-data-sources-ext-badge').textContent).toContain('sirsoft-ecommerce');
    expect(within(board).queryByTestId('g7le-data-sources-ext-badge')).toBeNull();
  });
});

describe('DataSourcesPanel — 헤더/푸터 고정 구조', () => {
  it('헤더(타이틀/설명)는 본문과 분리, 폼 진입 시 푸터(취소/저장) 분리', () => {
    const { container } = render(
      <DataSourcesPanel raw={rawWithOwn([])} onChange={vi.fn()} t={t} onClose={vi.fn()} />,
    );
    // 헤더 존재
    expect(container.querySelector('.g7le-data-sources__header')).toBeTruthy();
    // 본문(스크롤 영역) 존재
    expect(container.querySelector('.g7le-data-sources__body')).toBeTruthy();
    // 폼 미진입 시 푸터 없음
    expect(container.querySelector('.g7le-data-sources__footer')).toBeNull();
    // 추가 폼 진입 → 푸터(취소/저장)가 본문 밖에 렌더
    fireEvent.click(screen.getByTestId('g7le-data-sources-add'));
    const footer = container.querySelector('.g7le-data-sources__footer');
    expect(footer).toBeTruthy();
    expect(within(footer as HTMLElement).getByTestId('g7le-data-sources-form-submit')).toBeTruthy();
    expect(within(footer as HTMLElement).getByTestId('g7le-data-sources-form-cancel')).toBeTruthy();
    // 폼 필드(id)는 본문에 렌더
    const body = container.querySelector('.g7le-data-sources__body') as HTMLElement;
    expect(within(body).getByTestId('g7le-data-sources-field-id')).toBeTruthy();
  });
});

describe('DataSourcesPanel — 불러오기 조건 conditionSpec 전달', () => {
  // 최소 conditionRecipes — operators 1건이면 ConditionBuilder 가 빌더를 렌더한다.
  const conditionSpec = {
    conditionRecipes: {
      operators: [
        { value: 'logged_in', label: '로그인 상태', expr: '{{currentUser?.uuid}}', params: [] },
      ],
    },
  } as never;

  it('conditionSpec 미전달 시 불러오기 조건이 "표시 조건 없음" 안내로 가린다(종전 결함 재현)', () => {
    render(
      <DataSourcesPanel
        raw={rawWithOwn([{ id: 'products', endpoint: '/api/p' }])}
        onChange={vi.fn()}
        t={t}
        onClose={vi.fn()}
      />,
    );
    // 편집 진입 — 불러오기 조건 섹션이 폼 하단에 노출.
    fireEvent.click(screen.getByTestId('g7le-data-sources-edit'));
    expect(screen.getByTestId('g7le-ds-loadcondition')).toBeInTheDocument();
    // conditionSpec 미전달 → operators 0 → no_recipes 안내(빌더 미렌더).
    expect(screen.getByTestId('g7le-condition-no-recipes')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-condition-builder')).toBeNull();
  });

  it('conditionSpec 전달 시 불러오기 조건이 조건 빌더로 렌더된다(③ 수정)', () => {
    render(
      <DataSourcesPanel
        raw={rawWithOwn([{ id: 'products', endpoint: '/api/p' }])}
        onChange={vi.fn()}
        t={t}
        onClose={vi.fn()}
        conditionSpec={conditionSpec}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-data-sources-edit'));
    expect(screen.getByTestId('g7le-ds-loadcondition')).toBeInTheDocument();
    // operators 1건 → 빌더 렌더(no_recipes 안내가 사라짐).
    expect(screen.getByTestId('g7le-condition-builder')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-condition-no-recipes')).toBeNull();
  });
});

describe('DataSourcesPanel — 요청 파라미터 블럭(키-값)', () => {
  /** 블럭 모드 첫 행의 키/값 input 을 testid prefix 로 찾는다(rowId 동적). */
  function firstParamKeyInput(): HTMLElement {
    return document.querySelector('[data-testid^="g7le-ds-params-kv-key-"]') as HTMLElement;
  }
  function firstParamValueInput(): HTMLElement {
    return document.querySelector('[data-testid^="g7le-ds-params-kv-value-"][data-testid$="-input"]') as HTMLElement;
  }

  it('편집 시 기존 params 객체가 키-값 행으로 노출(raw textarea 아님)', () => {
    render(
      <DataSourcesPanel
        raw={rawWithOwn([{ id: 'memos', params: { page: '{{query.page}}', per_page: 10 } }])}
        onChange={vi.fn()}
        t={t}
        onClose={vi.fn()}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-data-sources-edit'));
    // 블럭 모드(키-값 칩 에디터)가 기본 — raw textarea 가 아니다.
    expect(document.querySelector('[data-testid="g7le-ds-params-kv"]')).toBeTruthy();
    const keys = Array.from(document.querySelectorAll('[data-testid^="g7le-ds-params-kv-key-"]')).map(
      (el) => (el as HTMLInputElement).value,
    );
    expect(keys).toEqual(['page', 'per_page']);
  });

  it('블럭 모드 키-값 입력 → params 객체로 저장(숫자 리터럴 복원)', () => {
    const onChange = vi.fn();
    render(<DataSourcesPanel raw={rawWithOwn([])} onChange={onChange} t={t} onClose={vi.fn()} />);
    fireEvent.click(screen.getByTestId('g7le-data-sources-add'));
    fireEvent.change(screen.getByTestId('g7le-data-sources-field-id'), { target: { value: 'src' } });
    // 행 추가 → 키/값 입력.
    fireEvent.click(screen.getByTestId('g7le-ds-params-kv-add'));
    fireEvent.change(firstParamKeyInput(), { target: { value: 'per_page' } });
    fireEvent.change(firstParamValueInput(), { target: { value: '10' } });
    fireEvent.click(screen.getByTestId('g7le-data-sources-form-submit'));
    const own = onChange.mock.calls[0][1];
    expect(own[0].params).toEqual({ per_page: 10 });
  });

  it('중첩 객체 값 params 는 raw(코드) 모드로 시작', () => {
    render(
      <DataSourcesPanel
        raw={rawWithOwn([{ id: 'nested', params: { filter: { a: 1 } } }])}
        onChange={vi.fn()}
        t={t}
        onClose={vi.fn()}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-data-sources-edit'));
    // 중첩값 → 평탄화 불가 → raw JSON textarea(키-값 블럭 아님).
    const params = screen.getByTestId('g7le-data-sources-field-params') as HTMLTextAreaElement;
    expect(params.tagName).toBe('TEXTAREA');
    expect(params.value).toContain('filter');
  });
});

// 기본값(fallback) — 재귀 블럭 편집기(InitialStateValueEditor) 재사용 (중첩도
// 블럭으로, raw JSON 모드 제거, 레이아웃 편집기 블럭 전면 도입 맥락). 중복 컴포넌트 0 — 초기 상태 탭과
// 동일 위젯. 문자열 리프는 DataChipValueInput(표현식·데이터칩). testid 접두 = g7le-initstate-*-fallback*.
describe('DataSourcesPanel — 기본값(fallback) 재귀 블럭', () => {
  it('fallback 미지정 → 추가 버튼, 클릭 시 재귀 블럭 편집기 노출', () => {
    render(<DataSourcesPanel raw={rawWithOwn([{ id: 's' }])} onChange={vi.fn()} t={t} onClose={vi.fn()} />);
    fireEvent.click(screen.getByTestId('g7le-data-sources-edit'));
    expect(screen.getByTestId('g7le-data-sources-field-fallback-add')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-data-sources-field-fallback-add'));
    // 재귀 블럭 편집기(InitialStateValueEditor) — 값 종류 select + 하위 키 추가. raw JSON textarea 없음.
    expect(screen.getByTestId('g7le-data-sources-field-fallback')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-initstate-type-fallback')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-ds-fallback-raw')).toBeNull();
    expect(document.querySelector('[data-testid="g7le-ds-fallback-kv"]')).toBeNull();
  });

  it('[회귀] 중첩 객체 fallback 은 raw JSON 아니라 재귀 블럭(중첩 키)으로 노출', () => {
    render(
      <DataSourcesPanel
        raw={rawWithOwn([{ id: 's', fallback: { reviews: { data: [], meta: { total: 0 } } } }])}
        onChange={vi.fn()}
        t={t}
        onClose={vi.fn()}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-data-sources-edit'));
    // raw JSON textarea 가 더 이상 없다(중첩이라도 블럭으로).
    expect(screen.queryByTestId('g7le-ds-fallback-raw')).toBeNull();
    // 루트 객체 + 중첩 키가 재귀 블럭으로 — fallback.reviews, fallback.reviews.data, ...meta.total.
    expect(screen.getByTestId('g7le-initstate-type-fallback')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-initstate-type-fallback.reviews')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-initstate-type-fallback.reviews.data')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-initstate-type-fallback.reviews.meta.total')).toBeInTheDocument();
  });

  it('[회귀] 중첩 객체 fallback 저장 → 구조 보존(블럭 편집이 raw 와 동등)', () => {
    const onChange = vi.fn();
    const fallback = { reviews: { data: [], meta: { total: 0 } } };
    render(
      <DataSourcesPanel raw={rawWithOwn([{ id: 's', fallback }])} onChange={onChange} t={t} onClose={vi.fn()} />,
    );
    fireEvent.click(screen.getByTestId('g7le-data-sources-edit'));
    fireEvent.click(screen.getByTestId('g7le-data-sources-form-submit'));
    const own = onChange.mock.calls[0][1];
    // 편집 없이 저장 → 중첩 구조 무손실 라운드트립.
    expect(own[0].fallback).toEqual(fallback);
  });

  it('문자열 리프는 데이터칩 입력기(표현식/데이터칩 1급)', () => {
    render(
      <DataSourcesPanel
        raw={rawWithOwn([{ id: 's', fallback: { status: 'ready' } }])}
        onChange={vi.fn()}
        t={t}
        onClose={vi.fn()}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-data-sources-edit'));
    // status 리프(문자열) = DataChipValueInput(평문 input testid).
    expect(screen.getByTestId('g7le-initstate-value-fallback.status-input')).toBeInTheDocument();
  });

  it('제거 시 저장 페이로드에서 fallback 키 삭제', () => {
    const onChange = vi.fn();
    render(
      <DataSourcesPanel
        raw={rawWithOwn([{ id: 's', fallback: { data: [] } }])}
        onChange={onChange}
        t={t}
        onClose={vi.fn()}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-data-sources-edit'));
    expect(screen.getByTestId('g7le-data-sources-field-fallback')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-data-sources-field-fallback-clear'));
    fireEvent.click(screen.getByTestId('g7le-data-sources-form-submit'));
    const own = onChange.mock.calls[0][1];
    expect('fallback' in own[0]).toBe(false);
  });

  it('JSON 미리보기는 [미리보기 ▾] 토글(기본 접힘)', () => {
    render(
      <DataSourcesPanel
        raw={rawWithOwn([{ id: 's', fallback: { data: { data: [], meta: { total: 0 } } } }])}
        onChange={vi.fn()}
        t={t}
        onClose={vi.fn()}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-data-sources-edit'));
    const toggle = screen.getByTestId('g7le-ds-fallback-preview-toggle');
    expect(toggle).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-ds-fallback-preview')).toBeNull();
    fireEvent.click(toggle);
    const preview = screen.getByTestId('g7le-ds-fallback-preview');
    expect(preview).toBeInTheDocument();
    expect(preview.textContent).toContain('meta');
  });
});

describe('DataSourcesPanel — 요청 파라미터 JSON 프리뷰', () => {
  it('params 행이 있으면 결과 JSON 프리뷰가 노출된다', () => {
    render(
      <DataSourcesPanel
        raw={rawWithOwn([{ id: 's', params: { page: '{{query.page}}' } }])}
        onChange={vi.fn()}
        t={t}
        onClose={vi.fn()}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-data-sources-edit'));
    // 미리보기는 [미리보기 ▾] 토글로.
    const toggle = screen.getByTestId('g7le-ds-params-preview-toggle');
    expect(toggle).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-ds-params-preview')).toBeNull();
    fireEvent.click(toggle);
    const preview = screen.getByTestId('g7le-ds-params-preview');
    expect(preview).toBeInTheDocument();
    expect(preview.textContent).toContain('{{query.page}}');
  });
});

describe('DataSourcesPanel — 엔드포인트 데이터칩 입력', () => {
  it('엔드포인트는 평문 input 이 아니라 데이터칩 입력기로 렌더', () => {
    render(<DataSourcesPanel raw={rawWithOwn([])} onChange={vi.fn()} t={t} onClose={vi.fn()} />);
    fireEvent.click(screen.getByTestId('g7le-data-sources-add'));
    // DataChipValueInput 컨테이너 + 평문 입력 leaf 존재(검색 피커 포함).
    expect(screen.getByTestId('g7le-data-sources-field-endpoint')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-ds-endpoint-input')).toBeInTheDocument();
  });
});

describe('DataSourcesPanel — 신규 추가 시 불러오기 조건 저장', () => {
  const conditionSpec = {
    conditionRecipes: {
      operators: [{ value: 'logged_in', label: '로그인', expr: '{{currentUser?.uuid}}', params: [] }],
    },
  } as never;

  it('신규 추가 폼에서도 불러오기 조건 빌더가 동작(draft.if 일원화)', () => {
    render(
      <DataSourcesPanel
        raw={rawWithOwn([])}
        onChange={vi.fn()}
        t={t}
        onClose={vi.fn()}
        conditionSpec={conditionSpec}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-data-sources-add'));
    // 신규(add) 상태에서도 조건 빌더가 렌더되고(종전엔 own[index] 없어 빈 채로),
    // 절 추가 버튼이 존재한다(draft.if 로 저장 경로 확보).
    expect(screen.getByTestId('g7le-ds-loadcondition')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-condition-builder')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-condition-add-and')).toBeInTheDocument();
  });

  it('신규 추가 시 조건 절을 추가하면 if 가 entry 에 저장', () => {
    const onChange = vi.fn();
    render(
      <DataSourcesPanel
        raw={rawWithOwn([])}
        onChange={onChange}
        t={t}
        onClose={vi.fn()}
        conditionSpec={conditionSpec}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-data-sources-add'));
    fireEvent.change(screen.getByTestId('g7le-data-sources-field-id'), { target: { value: 'gated' } });
    // 조건 절 추가 → draft.if 채워짐.
    fireEvent.click(screen.getByTestId('g7le-condition-add-and'));
    fireEvent.click(screen.getByTestId('g7le-data-sources-form-submit'));
    const own = onChange.mock.calls[onChange.mock.calls.length - 1][1];
    const saved = own.find((d: any) => d.id === 'gated');
    expect(typeof saved.if).toBe('string');
    expect(saved.if).toContain('currentUser');
  });
});
