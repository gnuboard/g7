// e2e:allow 오류 처리 「설정 안 함」 키 제거 단위(RTL) — 페이지 설정과 데이터 소스 두 편집 경로를 RTL 로 재고, 저장 직렬화와 런타임 해석기는 순수 함수로 잰다.
/**
 * ErrorHandlingUnsetClearsKey.test.tsx — 오류 처리 「설정 안 함」은 그 코드 키를 지운다
 *
 * 결함(#869): 코드 행에서 「설정 안 함」을 고르면 편집기가 키를 지우지 않고 `{ [code]: {} }` 를 남겼다.
 * 저장 직렬화(stripInheritedFromLayoutContent, 당시)와 서버 검증(content.errorHandling 은 배열 여부만 확인)
 * 어디에서도 이 빈 객체가 정리되지 않고, 런타임 findHandler 는 빈 객체도 참으로 보아 「그 코드는 처리됨」
 * 으로 판정한다. 그래서 같은 맵의 default, 데이터 소스 onError, 레이아웃과 템플릿과 시스템 기본 처리가
 * 모두 가려졌다. 「설정 안 함」의 뜻은 「이 코드에 대한 설정이 없다」이므로 키를 지워야 한다.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';

// DataSourcesPanel 의 무거운 하위 위젯 경량 모킹(DataSourcesPanel.enrich.test.tsx 와 같은 방식).
vi.mock('../../../components/property-controls/I18nTextField', () => ({
  I18nTextField: ({ value, onChange }: { value: string; onChange: (v: string | undefined) => void }) => (
    <input data-testid="ds-label-mock" value={value ?? ''} onChange={(e) => onChange(e.target.value)} />
  ),
}));
vi.mock('../../../components/property-controls/ConditionBuilder', () => ({
  ConditionBuilder: () => <div data-testid="condition-builder-mock" />,
}));

import { ErrorHandlingForm } from '../../../components/page-settings/ErrorHandlingForm';
import { ErrorHandlingRows } from '../../../components/page-settings/ErrorHandlingRows';
import { DataSourcesPanel } from '../../../components/property-controls/DataSourcesPanel';
import { ErrorHandlingResolver } from '../../../../../error/ErrorHandlingResolver';

const t = (k: string) => k;

const RECIPES = {
  navigate: { label: '$t:페이지 이동', params: [{ key: 'path' }], build: { handler: 'navigate', params: { path: '{{path}}' } } },
  toast: { label: '$t:안내', params: [{ key: 'message' }], build: { handler: 'toast', params: { message: '{{message}}' } } },
};

beforeEach(() => {
  cleanup();
  ErrorHandlingResolver.resetInstance();
});

describe('오류 처리 「설정 안 함」 — 편집기는 그 코드 키를 지운다', () => {
  /**
   * @scenario subject=error_handling_unset
   * @effects error_unset_page_settings_removes_code_key
   */
  it('페이지 설정: 401 「설정 안 함」 → 401 키만 지워지고 다른 코드는 남는다', () => {
    const onChange = vi.fn();
    render(
      <ErrorHandlingForm
        value={{ '401': { handler: 'navigate' }, '403': { handler: 'toast' } }}
        onChange={onChange}
        t={t}
        errorConfigCodes={['401', '403']}
        recipes={RECIPES}
      />,
    );
    fireEvent.change(screen.getByTestId('g7le-error-handler-401'), { target: { value: '' } });
    expect(onChange).toHaveBeenLastCalledWith({ '403': { handler: 'toast' } });
  });

  /**
   * @scenario subject=error_handling_unset
   * @effects error_unset_rows_on_clear_removes_key
   */
  it('코드 행 공용부: 렌더 프롭의 onClear → 그 코드 키만 제거(patch 의 빈 동작은 [덮기] 시작용으로 그대로)', () => {
    const onChange = vi.fn();
    render(
      <ErrorHandlingRows
        value={{ '401': { handler: 'toast' }, '500': { handler: 'toast' } }}
        onChange={onChange}
        t={t}
        mode="local"
        renderActionList={(code, _action, onPatch, onClear) => (
          <>
            <button data-testid={`clear-${code}`} onClick={() => onClear()} />
            <button data-testid={`empty-${code}`} onClick={() => onPatch({})} />
          </>
        )}
      />,
    );
    fireEvent.click(screen.getByTestId('clear-401'));
    expect(onChange).toHaveBeenLastCalledWith({ '500': { handler: 'toast' } });
    fireEvent.click(screen.getByTestId('empty-500'));
    expect(onChange).toHaveBeenLastCalledWith({ '401': { handler: 'toast' }, '500': {} });
  });

  /**
   * @scenario subject=error_handling_unset
   * @effects error_unset_data_source_saves_without_code_key
   */
  it('데이터 소스: 401 「설정 안 함」 후 저장 → 저장 항목의 errorHandling 에 401 이 없다', () => {
    const onChange = vi.fn();
    const raw = {
      data_sources: [
        {
          id: 'products',
          type: 'api',
          endpoint: '/api/products',
          errorHandling: { '401': { handler: 'toast' }, '500': { handler: 'navigate' } },
        },
      ],
    };
    render(<DataSourcesPanel raw={raw} onChange={onChange} t={t} onClose={vi.fn()} />);
    fireEvent.click(screen.getByTestId('g7le-data-sources-edit'));
    fireEvent.change(screen.getByTestId('g7le-ds-eh-handler-401'), { target: { value: '' } });
    fireEvent.click(screen.getByTestId('g7le-data-sources-form-submit'));
    const merged = onChange.mock.calls.at(-1)![0] as Array<Record<string, unknown>>;
    expect(merged[0].errorHandling).toEqual({ '500': { handler: 'navigate' } });
  });
});

// 저장 직렬화의 빈 항목 제거(설계 확정 2026-11-09)는 __tests__/utils/errorHandlingSavePrune.test.ts 가 잰다.
describe('키를 지워야 하는 근거 — 런타임은 빈 항목을 「처리됨」으로 본다', () => {
  /**
   * @scenario subject=error_handling_unset
   * @effects error_unset_runtime_empty_entry_shadows_fallbacks
   */
  it('런타임: 빈 객체 항목은 같은 맵 default 와 데이터 소스 onError 를 가린다', () => {
    const resolver = ErrorHandlingResolver.getInstance();
    // 레이아웃 맵: 401 이 빈 객체면 그 레이아웃의 default 대신 빈 핸들러가 잡힌다.
    resolver.setLayoutConfig({ '401': {} as never, default: { handler: 'toast' } });
    const layoutLevel = resolver.resolve(401);
    expect(layoutLevel.handler?.handler).toBeUndefined();
    expect(layoutLevel.level).toBe('layout');
    // 데이터 소스 맵: 401 이 빈 객체면 onError 까지 가지 않는다.
    const dsLevel = resolver.resolve(401, { errorHandling: { '401': {} as never }, onError: { handler: 'toast' } });
    expect(dsLevel.level).toBe('action');
    expect(dsLevel.handler?.handler).toBeUndefined();
    // 키가 없으면 대체 처리가 정상으로 잡힌다.
    expect(resolver.resolve(401, { errorHandling: {}, onError: { handler: 'toast' } }).handler?.handler).toBe('toast');
  });
});
