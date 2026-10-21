// e2e:allow 편집기 폼 검증 단위 잠금 — 같은 id 조건부 판 레이아웃의 편집기 저장은 재실측(재적용 뒤)이 본다.
/**
 * DataSourcesPanel — 같은 id 의 조건부 데이터 소스 판 (7.1.1 출시 전 실측)
 *
 * 엔진은 같은 id 판 중 조건(`if`·`conditions`)을 만족하는 첫 판만 쓰고, 백엔드 검증(`LayoutDataSourceIds::conflictingDuplicates`)은
 * 같은 id 묶음의 모든 판에 조건이 있으면 허용한다. 편집기 폼은 조건과 무관하게 같은 id 를 중복으로 거부해, 조건부 두 판이 있는
 * 레이아웃에서 한 판을 고쳐 저장할 수 없었다. 폼도 같은 규칙을 쓴다(상속 id 와의 중복은 종전대로 차단).
 * 매핑은 각 테스트 앞 주석에 둔다.
 */

import React from 'react';
import { describe, expect, it, vi } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';

vi.mock('../../LayoutEditorContext', () => ({
  useLayoutEditor: () => ({
    state: { templateIdentifier: 'sirsoft-basic', locale: 'ko', selectedRoute: { path: '/home', layoutName: 'home' } },
  }),
}));
vi.mock('../../../TranslationEngine', () => ({
  TranslationEngine: { getInstance: () => ({ translate: (k: string) => k }) },
}));

import { DataSourcesPanel } from '../../components/property-controls/DataSourcesPanel';

const t = (key: string): string => key;

function rawWithOwn(own: Array<Record<string, unknown>>, merged?: Array<Record<string, unknown>>) {
  return { components: [], data_sources: merged ?? own, __editor: { original: { data_sources: own } } };
}

const variants = [
  { id: 'claimable', if: '{{!!query.order_id}}', endpoint: '/api/a/by-query' },
  { id: 'claimable', if: '{{!query.order_id}}', endpoint: '/api/a/by-local', auto_fetch: false },
];

describe('DataSourcesPanel — 같은 id 조건부 판', () => {
  /**
   * 모두 조건부인 같은 id 두 판 중 자체 소스 둘째 판(api)을 편집해 저장 — id_duplicate 없이 onChange 로 반영되고 조건은 보존된다.
   *
   * @scenario operation=edit, source_origin=own, ds_type=api, template=sirsoft-basic
   * @effects same_id_conditional_variant_editable
   */
  it('조건부 두 판 중 둘째 판의 endpoint 를 고쳐 저장할 수 있다(다른 판도 조건부)', () => {
    const onChange = vi.fn();
    render(<DataSourcesPanel raw={rawWithOwn(variants)} onChange={onChange} t={t} onClose={vi.fn()} />);

    const items = screen.getAllByTestId('g7le-data-sources-item');
    expect(items).toHaveLength(2);
    fireEvent.click(within(items[1]).getByTestId('g7le-data-sources-edit'));
    fireEvent.change(screen.getByTestId('g7le-ds-endpoint-input'), { target: { value: '/api/b' } });
    fireEvent.click(screen.getByTestId('g7le-data-sources-form-submit'));

    // 종전: id_duplicate 로 거부, onChange 미호출
    expect(screen.queryByTestId('g7le-data-sources-form-error')).toBeNull();
    expect(onChange).toHaveBeenCalledTimes(1);
    const own = onChange.mock.calls[0][1];
    expect(own.map((d: any) => d.endpoint)).toEqual(['/api/a/by-query', '/api/b']);
    expect(own[1].if).toBe('{{!query.order_id}}');
  });

  /**
   * 조건부 판이 있는 id 로 조건 없는 새 소스(기본 타입 api)를 추가 — 폼 오류가 뜨고 onChange 는 불리지 않는다.
   *
   * @scenario operation=add, source_origin=own, ds_type=api, template=sirsoft-basic
   * @effects unconditional_same_id_still_rejected
   */
  it('조건 없는 판으로 같은 id 를 추가하면 종전대로 id_duplicate 로 거부한다', () => {
    const onChange = vi.fn();
    render(<DataSourcesPanel raw={rawWithOwn(variants)} onChange={onChange} t={t} onClose={vi.fn()} />);

    fireEvent.click(screen.getByTestId('g7le-data-sources-add'));
    fireEvent.change(screen.getByTestId('g7le-data-sources-field-id'), { target: { value: 'claimable' } });
    fireEvent.click(screen.getByTestId('g7le-data-sources-form-submit'));

    expect(screen.getByTestId('g7le-data-sources-form-error')).toBeInTheDocument();
    expect(onChange).not.toHaveBeenCalled();
  });

  /**
   * 같은 id 의 다른 판에 조건이 없으면 조건부 판(api)을 편집해 저장해도 거부된다.
   *
   * @scenario operation=edit, source_origin=own, ds_type=api, template=sirsoft-basic
   * @effects unconditional_same_id_still_rejected
   */
  it('다른 판에 조건이 없으면 조건부 판이어도 같은 id 는 거부한다', () => {
    const onChange = vi.fn();
    const raw = rawWithOwn([{ id: 'user', endpoint: '/api/u' }, { id: 'user', if: '{{route.id}}', endpoint: '/api/u/1' }]);
    render(<DataSourcesPanel raw={raw} onChange={onChange} t={t} onClose={vi.fn()} />);

    const items = screen.getAllByTestId('g7le-data-sources-item');
    fireEvent.click(within(items[1]).getByTestId('g7le-data-sources-edit'));
    fireEvent.click(screen.getByTestId('g7le-data-sources-form-submit'));

    expect(screen.getByTestId('g7le-data-sources-form-error')).toBeInTheDocument();
    expect(onChange).not.toHaveBeenCalled();
  });
});
