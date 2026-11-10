// e2e:allow 레이아웃 편집기 SEO vars 3그룹 에디터 — I18nTextField/데이터칩 합성 의존, Chrome MCP 매트릭스(세션 D) + 단위로 검증
/**
 * SeoVarsEditor.test.tsx — vars 3그룹 RTL
 *
 * 검증:
 *  ① 자동 채움(읽기전용) source∈{core_setting/setting/query/route}: 🔒+출처 배지, 편집/삭제 불가
 *  ② 값 채우기(data): 확장 정의·레이아웃이 값(표현식) 채움
 *  ③ 직접 추가: 이름+값, 자동 vars 동일 이름 차단(경고)
 *  ④ required(*) 강제: 삭제 버튼 없음·빈 값 경고
 *  ⑥ extensions∧page_type 게이팅: 둘 중 하나라도 비면 자동/data vars 0 + 전제 배너
 *  ⑧ vars filter 없음 → 항상 편집 가능(자동만 source 때문 읽기전용)
 *
 * I18nTextField 는 경량 input 으로 mock(SeoVarsEditor 그룹/패치 라우팅에 집중).
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';

// SEO 동적변수는 값 전용 칸이라 DataChipValueInput 으로 교체됨(설정참조/데이터
// 칩 + [✎수정→칩 편집기]). 경량 input 으로 mock(SeoVarsEditor 그룹/패치 라우팅에 집중).
vi.mock('../../../components/page-settings/DataChipValueInput', () => ({
  DataChipValueInput: ({
    value,
    onChange,
    testidPrefix,
  }: {
    value: string;
    onChange: (v: string) => void;
    testidPrefix: string;
  }) => (
    <input data-testid={`${testidPrefix}-mock`} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />
  ),
}));

import { SeoVarsEditor, type SeoVarCandidate } from '../../../components/page-settings/SeoVarsEditor';

const t = (k: string) => k;
afterEach(() => cleanup());

const cands: SeoVarCandidate[] = [
  { name: 'site_name', source: 'core_setting', owner: { name: '코어' } },
  { name: 'commerce_name', source: 'setting', owner: { name: '이커머스' } },
  { name: 'keyword_name', source: 'query' },
  { name: 'product_name', source: 'data', required: true, owner: { name: '이커머스' } },
  { name: 'product_description', source: 'data' },
];

describe('SeoVarsEditor — 3그룹/게이팅', () => {
  // @scenario area=seo_vars
  // @effects seo_vars_auto_source_rows_readonly_without_input
  it('① 자동 채움 vars 는 🔒 읽기전용 + 출처 배지(편집 칸 없음)', () => {
    render(<SeoVarsEditor vars={{}} onChange={vi.fn()} varCandidates={cands} gatingMet t={t} />);
    expect(screen.getByTestId('g7le-seo-var-auto-site_name')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-seo-var-auto-keyword_name')).toBeInTheDocument();
    // 시험 이름의 🔒 표시와 출처 배지(source 이름)가 행에 실제로 있다.
    const autoRow = screen.getByTestId('g7le-seo-var-auto-site_name');
    expect(autoRow.textContent).toContain('🔒');
    expect(autoRow.textContent).toContain('core_setting');
    expect(screen.getByTestId('g7le-seo-var-auto-keyword_name').textContent).toContain('query');
    // 자동 vars 는 값 입력칸(mock) 없음.
    expect(screen.queryByTestId('g7le-seo-var-data-field-site_name-mock')).not.toBeInTheDocument();
  });

  // @scenario area=seo_vars
  // @effects seo_vars_data_row_value_input_patches_vars
  it('② 값 채우기(data) vars 는 값 입력칸 노출 + 값 패치', () => {
    const onChange = vi.fn();
    render(<SeoVarsEditor vars={{}} onChange={onChange} varCandidates={cands} gatingMet t={t} />);
    expect(screen.getByTestId('g7le-seo-var-data-product_name')).toBeInTheDocument();
    const field = screen.getByTestId('g7le-seo-var-data-field-product_description-mock');
    fireEvent.change(field, { target: { value: '{{product.data.description}}' } });
    expect(onChange).toHaveBeenLastCalledWith({ product_description: '{{product.data.description}}' });
  });

  // @scenario area=seo_vars
  // @effects seo_vars_required_data_var_warned_not_removable
  it('④ required data var 는 * 표시 + 빈 값 경고 + 삭제 버튼 없음', () => {
    render(<SeoVarsEditor vars={{}} onChange={vi.fn()} varCandidates={cands} gatingMet t={t} />);
    expect(screen.getByTestId('g7le-seo-var-required-product_name')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-seo-var-empty-warn-product_name')).toBeInTheDocument();
    // data var 행에는 custom-remove 버튼이 없다.
    expect(screen.queryByTestId('g7le-seo-var-custom-remove-product_name')).not.toBeInTheDocument();
  });

  // @scenario area=seo_vars
  // @effects seo_vars_custom_add_blocks_reserved_auto_name
  it('③ 직접 추가 — 자동 vars 동일 이름 차단(경고 + 추가 비활성)', () => {
    const onChange = vi.fn();
    render(<SeoVarsEditor vars={{}} onChange={onChange} varCandidates={cands} gatingMet t={t} />);
    fireEvent.change(screen.getByTestId('g7le-seo-var-add-name'), { target: { value: 'site_name' } });
    expect(screen.getByTestId('g7le-seo-var-add-reserved-warn')).toBeInTheDocument();
    expect((screen.getByTestId('g7le-seo-var-add-confirm') as HTMLButtonElement).disabled).toBe(true);
  });

  // @scenario area=seo_vars
  // @effects seo_vars_custom_add_creates_empty_key
  it('③ 직접 추가 — 새 이름 추가 시 vars 에 빈 값 키 생성', () => {
    const onChange = vi.fn();
    render(<SeoVarsEditor vars={{}} onChange={onChange} varCandidates={cands} gatingMet t={t} />);
    fireEvent.change(screen.getByTestId('g7le-seo-var-add-name'), { target: { value: 'promo_label' } });
    fireEvent.click(screen.getByTestId('g7le-seo-var-add-confirm'));
    expect(onChange).toHaveBeenLastCalledWith({ promo_label: '' });
  });

  // @scenario area=seo_vars
  // @effects seo_vars_unlisted_name_shows_not_provided_hint
  it('⑦ 목록 밖 이름 — "어떤 확장도 제공 안 함" 안내(정보성, 거부 아님: 추가 버튼 활성)', () => {
    const onChange = vi.fn();
    render(<SeoVarsEditor vars={{}} onChange={onChange} varCandidates={cands} gatingMet t={t} />);
    // 'promo_label' = 자동 vars 도 아니고 data 후보(product_name/product_description)도 아닌 목록 밖 이름.
    fireEvent.change(screen.getByTestId('g7le-seo-var-add-name'), { target: { value: 'promo_label' } });
    expect(screen.getByTestId('g7le-seo-var-add-not-provided-hint')).toBeInTheDocument();
    // 거부 아님 — 추가 버튼 활성(reserved 와 달리 disabled 아님).
    expect((screen.getByTestId('g7le-seo-var-add-confirm') as HTMLButtonElement).disabled).toBe(false);
    // reserved/duplicate 경고는 미표시.
    expect(screen.queryByTestId('g7le-seo-var-add-reserved-warn')).not.toBeInTheDocument();
  });

  // @scenario area=seo_vars
  // @effects seo_vars_unlisted_name_shows_not_provided_hint
  it('⑦ data 후보 이름(목록 안)은 not-provided 안내 미표시', () => {
    render(<SeoVarsEditor vars={{}} onChange={vi.fn()} varCandidates={cands} gatingMet t={t} />);
    // 'product_name' = 확장 제공 data var → 목록 안 → 안내 미표시.
    fireEvent.change(screen.getByTestId('g7le-seo-var-add-name'), { target: { value: 'product_name' } });
    expect(screen.queryByTestId('g7le-seo-var-add-not-provided-hint')).not.toBeInTheDocument();
  });

  // @scenario area=seo_vars
  // @effects seo_vars_custom_var_remove_deletes_key
  it('직접 추가 vars 는 ✕ 삭제 가능(키 제거)', () => {
    const onChange = vi.fn();
    render(<SeoVarsEditor vars={{ promo_label: '$t:custom.promo' }} onChange={onChange} varCandidates={cands} gatingMet t={t} />);
    expect(screen.getByTestId('g7le-seo-var-custom-promo_label')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-seo-var-custom-remove-promo_label'));
    expect(onChange).toHaveBeenLastCalledWith({});
  });

  // @scenario area=seo_vars
  // @effects seo_vars_gating_unmet_hides_auto_and_data_rows
  it('⑥ 게이팅 미충족 → 자동/data vars 0 + 전제 배너', () => {
    render(<SeoVarsEditor vars={{}} onChange={vi.fn()} varCandidates={cands} gatingMet={false} t={t} />);
    expect(screen.getByTestId('g7le-seo-vars-precondition')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-seo-var-auto-site_name')).not.toBeInTheDocument();
    expect(screen.queryByTestId('g7le-seo-var-data-product_name')).not.toBeInTheDocument();
    // 직접 추가 그룹은 게이팅 무관 항상 노출.
    expect(screen.getByTestId('g7le-seo-vars-custom-group')).toBeInTheDocument();
    // 게이팅 마커.
    expect(screen.getByTestId('g7le-seo-var-list')).toHaveAttribute('data-gating', '0');
  });
});
