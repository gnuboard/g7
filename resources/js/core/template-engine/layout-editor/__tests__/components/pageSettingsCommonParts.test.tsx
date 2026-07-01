/**
 * pageSettingsCommonParts.test.tsx — 페이지 설정 공용 부품
 *
 * 검증:
 *  - ActionListBuilder: 친화 요약·순서 이동·삭제·코드 보기·추가
 *  - ErrorHandlingRows: 코드별 행·출처 배지·default 경고·동작 정리
 *  - dataSourceConditionAdapter: node ↔ data_source if 왕복
 */

import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ActionListBuilder } from '../../components/page-settings/ActionListBuilder';
import { ErrorHandlingRows } from '../../components/page-settings/ErrorHandlingRows';
import {
  dataSourceToConditionNode,
  applyConditionNodeToDataSource,
} from '../../spec/dataSourceConditionAdapter';

const t = (k: string) => k;

describe('ActionListBuilder', () => {
  const RECIPES = {
    toast: {
      label: '$t:안내 메시지',
      params: [{ key: 'message', widget: 'i18n-text' }],
      build: { handler: 'toast', params: { message: '{{message}}' } },
    },
  };

  it('친화 요약을 표시하고 순서·삭제·추가가 동작한다', () => {
    const onChange = vi.fn();
    const actions = [
      { handler: 'toast', params: { message: '안녕' } },
      { handler: 'navigate', params: { path: '/shop' } },
    ];
    render(
      <ActionListBuilder
        actions={actions}
        onChange={onChange}
        t={t}
        recipes={RECIPES}
        renderAddPicker={(onAdd) => (
          <button data-testid="add" onClick={() => onAdd({ handler: 'closeModal' })}>
            add
          </button>
        )}
      />,
    );
    // toast 카드는 친화 요약(라벨 + 값), navigate 는 핸들러명 폴백.
    expect(screen.getByTestId('g7le-action-list-summary-0').textContent).toContain('안내 메시지');
    expect(screen.getByTestId('g7le-action-list-summary-1').textContent).toContain('navigate');

    // 첫 항목을 둘째 아래 절반으로 드래그 → 순서 뒤집힘(▲▼ 버튼 제거, ⠿ 드래그 전용).
    fireEvent.dragStart(screen.getByTestId('g7le-action-list-drag-0'));
    fireEvent.dragOver(screen.getByTestId('g7le-action-list-item-1'), { clientY: 10 });
    fireEvent.drop(screen.getByTestId('g7le-action-list-item-1'));
    expect(onChange).toHaveBeenLastCalledWith([actions[1], actions[0]]);

    // 둘째 삭제.
    fireEvent.click(screen.getByTestId('g7le-action-list-remove-1'));
    expect(onChange).toHaveBeenLastCalledWith([actions[0]]);

    // 추가.
    fireEvent.click(screen.getByTestId('add'));
    expect(onChange).toHaveBeenLastCalledWith([...actions, { handler: 'closeModal' }]);
  });

  it('코드 보기 토글은 실제 JSON 을 노출한다', () => {
    render(
      <ActionListBuilder
        actions={[{ handler: 'toast', params: { message: 'hi' } }]}
        onChange={vi.fn()}
        t={t}
        recipes={RECIPES}
      />,
    );
    expect(screen.queryByTestId('g7le-action-list-code-view-0')).not.toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-action-list-code-0'));
    const code = screen.getByTestId('g7le-action-list-code-view-0');
    expect(code.textContent).toContain('"handler": "toast"');
  });
});

describe('ErrorHandlingRows', () => {
  it('코드 행·default 경고·출처 배지를 렌더한다', () => {
    render(
      <ErrorHandlingRows
        value={{ '403': { handler: 'showErrorPage' }, default: { handler: 'toast' } }}
        onChange={vi.fn()}
        t={t}
        codes={['403', '404', 'default']}
        mode="badge"
        sourceOf={(code) => (code === '403' ? 'self' : code === 'default' ? 'inherited' : 'none')}
      />,
    );
    // 코드 행.
    expect(screen.getByTestId('g7le-error-rows-row-403')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-error-rows-row-404')).toBeInTheDocument();
    // 출처 배지.
    expect(screen.getByTestId('g7le-error-rows-source-403')).toBeInTheDocument();
    // default 경고.
    expect(screen.getByTestId('g7le-error-rows-default-warn-default')).toBeInTheDocument();
  });

  it('동작 정리(clear) 시 그 코드 키가 제거된다', () => {
    const onChange = vi.fn();
    render(
      <ErrorHandlingRows
        value={{ '403': { handler: 'showErrorPage' } }}
        onChange={onChange}
        t={t}
        codes={['403']}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-error-rows-clear-403'));
    expect(onChange).toHaveBeenCalledWith({});
  });
});

describe('dataSourceConditionAdapter', () => {
  it('data_source 의 if 를 노드로 노출하고 다시 역적용한다', () => {
    const ds = { id: 'products', endpoint: '/api/products', if: '{{ route.id }}' };
    const node = dataSourceToConditionNode(ds);
    expect((node as Record<string, unknown>).if).toBe('{{ route.id }}');

    // 빌더가 if 를 바꾼 노드를 역적용.
    const patchedNode = { ...node, if: '{{ query.q }}' } as typeof node;
    const next = applyConditionNodeToDataSource(patchedNode, ds);
    expect(next.if).toBe('{{ query.q }}');
    // 다른 키 보존.
    expect(next.id).toBe('products');
    expect(next.endpoint).toBe('/api/products');
  });

  it('빈 if 노드는 data_source 의 if 키를 제거한다', () => {
    const ds = { id: 'x', if: '{{ a }}' };
    const next = applyConditionNodeToDataSource({} as never, ds);
    expect('if' in next).toBe(false);
    expect(next.id).toBe('x');
  });
});
