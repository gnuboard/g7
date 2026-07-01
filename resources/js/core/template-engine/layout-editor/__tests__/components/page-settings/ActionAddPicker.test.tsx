// e2e:allow [화면 동작]/[동작] 추가 피커 단위(RTL) — 드롭다운/검색 위젯 합성, Chrome MCP 매트릭스(세션 D)로 보강.
/**
 * ActionAddPicker.test.tsx — 핸들러 스펙 추가 목록 RTL
 *
 * 검증:
 *  ① 검색 필터(라벨·핸들러명 부분일치)
 *  ② 그룹 표시 + 모든 항목 출처 배지(코어=〔코어〕/확장=제공자명)
 *  ③ 확장 제공 항목 = extension 그룹
 *  ④ 선택 시 buildAction(빈값) → onAdd
 *  ⑤ context='component' 그룹 정렬 차이(같은 카탈로그)
 *  ⑥ 디그레이드(빈 recipes → no_recipes)
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { ActionAddPicker } from '../../../components/page-settings/ActionAddPicker';

const t = (k: string) => k;

const RECIPES = {
  setState: { label: '$t:화면 상태', build: { handler: 'setState' }, __source: { kind: 'core' } },
  navigate: { label: '$t:페이지 이동', build: { handler: 'navigate' }, __source: { kind: 'core' } },
  toast: { label: '$t:안내 메시지', build: { handler: 'toast' }, __source: { kind: 'core' } },
  ecPay: {
    label: '$t:결제 요청',
    build: { handler: 'ecPay' },
    __source: { kind: 'plugin', id: 'tosspayments' },
  },
} as const;

beforeEach(() => cleanup());

describe('ActionAddPicker', () => {
  it('펼치면 그룹과 모든 항목 출처 배지를 표시한다', () => {
    render(<ActionAddPicker recipes={RECIPES} t={t} onAdd={vi.fn()} />);
    fireEvent.click(screen.getByTestId('g7le-action-add-toggle'));

    expect(screen.getByTestId('g7le-init-action-spec-setState')).toBeInTheDocument();
    // 코어 출처 배지.
    expect(screen.getByTestId('g7le-init-action-spec-source-setState').textContent).toContain(
      'layout_editor.action.source_core',
    );
    // 확장(플러그인) 출처 배지 = 식별자.
    expect(screen.getByTestId('g7le-init-action-spec-source-ecPay').textContent).toContain('tosspayments');
    // 확장 그룹.
    expect(screen.getByTestId('g7le-action-add-group-extension')).toBeInTheDocument();
  });

  it('검색은 라벨/핸들러명 부분일치로 필터한다', () => {
    render(<ActionAddPicker recipes={RECIPES} t={t} onAdd={vi.fn()} />);
    fireEvent.click(screen.getByTestId('g7le-action-add-toggle'));
    fireEvent.change(screen.getByTestId('g7le-action-add-search'), { target: { value: 'toast' } });
    expect(screen.getByTestId('g7le-init-action-spec-toast')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-init-action-spec-setState')).not.toBeInTheDocument();
  });

  it('항목 선택 시 buildAction(빈값) 으로 onAdd 한다', () => {
    const onAdd = vi.fn();
    render(<ActionAddPicker recipes={RECIPES} t={t} onAdd={onAdd} />);
    fireEvent.click(screen.getByTestId('g7le-action-add-toggle'));
    fireEvent.click(screen.getByTestId('g7le-init-action-spec-toast'));
    expect(onAdd).toHaveBeenCalledTimes(1);
    expect(onAdd.mock.calls[0][0]).toMatchObject({ handler: 'toast' });
  });

  it('빈 recipes 면 디그레이드 안내를 표시한다', () => {
    render(<ActionAddPicker recipes={{}} t={t} onAdd={vi.fn()} />);
    expect(screen.getByTestId('g7le-action-add-empty')).toBeInTheDocument();
  });

  it('context=component 도 같은 카탈로그를 노출한다(정렬만 차이)', () => {
    render(<ActionAddPicker recipes={RECIPES} t={t} onAdd={vi.fn()} context="component" />);
    fireEvent.click(screen.getByTestId('g7le-action-add-toggle'));
    expect(screen.getByTestId('g7le-init-action-spec-navigate')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-init-action-spec-setState')).toBeInTheDocument();
  });
});
