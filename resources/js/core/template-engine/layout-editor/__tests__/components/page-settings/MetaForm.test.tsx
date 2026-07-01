// e2e:allow 페이지 설정 [기본 정보] 폼 단위(RTL) — I18nTextField/TagInput 위젯 합성. 칩/contentEditable
// 의존으로 Playwright 부적합(정책), 단위 RTL + Chrome MCP 매트릭스(세션 D)로 검증.
/**
 * MetaForm.test.tsx — [기본 정보] 탭 폼 RTL
 *
 * 검증:
 *  ① 제목/설명/트리라벨 I18nTextField 렌더 + meta 필드 패치(빈 값=키 제거 비파괴)
 *  ② 표현식 제목 → I18nTextField 위임
 *  ③ icon-picker 미등록 → 자유 입력 폴백 / 등록 시 위젯 렌더
 *  ④ 권한 TagInput — 후보 칩 추가/제거 → permissions 패치(빈 배열=키 제거)
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';

// I18nTextField — 자체 테스트가 있으므로 경량 input 으로 대체(MetaForm 패치 라우팅에 집중).
// `t` 프롭으로 받은 해석기가 특정 키를 무엇으로 푸는지 data-resolved 로 노출(값필드 t 회귀 가드).
vi.mock('../../../components/property-controls/I18nTextField', () => ({
  I18nTextField: ({
    value,
    onChange,
    testidPrefix,
    t,
  }: {
    value: string;
    onChange: (v: string | undefined) => void;
    testidPrefix: string;
    t: (k: string) => string;
  }) => (
    <input
      data-testid={`${testidPrefix}-mock`}
      data-resolved-board={t('board.edit_post')}
      value={value ?? ''}
      onChange={(e) => onChange(e.target.value === '' ? undefined : e.target.value)}
    />
  ),
}));

import { MetaForm } from '../../../components/page-settings/MetaForm';
import { clearWidgetRegistry, registerWidget } from '../../../spec/widgetRegistry';

const t = (k: string) => k;

/** getValue/patch 를 보유한 store 헬퍼 */
function makeStore(initial: Record<string, unknown> = {}) {
  const raw: Record<string, unknown> = { ...initial };
  const patch = vi.fn((key: string, value: unknown) => {
    if (value === undefined) delete raw[key];
    else raw[key] = value;
  });
  const getValue = <T,>(key: string, fb?: T): T =>
    (raw[key] === undefined ? fb : raw[key]) as T;
  return { raw, patch, getValue };
}

beforeEach(() => {
  cleanup();
  clearWidgetRegistry();
});

describe('MetaForm', () => {
  it('제목/설명/트리라벨을 렌더하고 meta 필드를 패치한다', () => {
    const store = makeStore({ meta: { title: '홈', description: '설명' } });
    render(<MetaForm getValue={store.getValue} patch={store.patch} t={t} />);

    expect(screen.getByTestId('g7le-meta-title-mock')).toHaveValue('홈');
    expect(screen.getByTestId('g7le-meta-description-mock')).toHaveValue('설명');

    fireEvent.change(screen.getByTestId('g7le-meta-title-mock'), {
      target: { value: '새 제목' },
    });
    expect(store.patch).toHaveBeenLastCalledWith('meta', { title: '새 제목', description: '설명' });
  });

  it('빈 값 입력 시 meta 키를 제거한다(비파괴)', () => {
    const store = makeStore({ meta: { title: '홈', editor_label: '라벨' } });
    render(<MetaForm getValue={store.getValue} patch={store.patch} t={t} />);

    fireEvent.change(screen.getByTestId('g7le-meta-editor-label-mock'), {
      target: { value: '' },
    });
    expect(store.patch).toHaveBeenLastCalledWith('meta', { title: '홈' });
  });

  it('표현식 제목도 I18nTextField 로 위임한다', () => {
    // 표현식+다국어 분해 트리는 I18nTextField(여기선 mock) 소관 — MetaForm 은 더는 raw 고급 배지로
    // 가로채지 않고 값을 그대로 위임한다. mock 이 값을 받았는지(=고급 배지 미렌더)로 검증.
    const store = makeStore({ meta: { title: "{{route.id ? '$t:edit' : '$t:create'}}" } });
    render(<MetaForm getValue={store.getValue} patch={store.patch} t={t} />);

    expect(screen.queryByTestId('g7le-meta-title-advanced')).not.toBeInTheDocument();
    expect(screen.getByTestId('g7le-meta-title-mock')).toHaveValue("{{route.id ? '$t:edit' : '$t:create'}}");
  });

  // 회귀 — 값 필드(제목/설명/트리라벨)는
  // 런타임 앱 lang 키(`board.edit_post`)를 해석하는 fieldT 를 받아야 한다(편집기 전용 t 는 못 풀어
  // 칩화 시 텍스트 빈 base 로 소실). fieldT 미전달 시 t 폴백.
  it('값 필드는 fieldT(앱 키 해석)를 받는다 — 분기 칩 텍스트 소실 회귀 가드', () => {
    const editorT = (k: string) => (k === 'board.edit_post' ? k : `editor:${k}`); // 앱 키 못 풂.
    const appFieldT = (k: string) => (k === 'board.edit_post' ? '게시글 수정' : `editor:${k}`); // 앱 키 해석.
    const store = makeStore({ meta: { title: '홈', description: '설명', editor_label: '라벨' } });
    render(<MetaForm getValue={store.getValue} patch={store.patch} t={editorT} fieldT={appFieldT} />);
    // 값 필드(제목/설명/트리라벨) I18nTextField 는 fieldT 를 받아 board.edit_post → "게시글 수정".
    expect(screen.getByTestId('g7le-meta-title-mock')).toHaveAttribute('data-resolved-board', '게시글 수정');
    expect(screen.getByTestId('g7le-meta-description-mock')).toHaveAttribute('data-resolved-board', '게시글 수정');
    expect(screen.getByTestId('g7le-meta-editor-label-mock')).toHaveAttribute('data-resolved-board', '게시글 수정');
  });

  it('fieldT 미전달 시 t 폴백(기존 동작 보존)', () => {
    const editorT = (k: string) => (k === 'board.edit_post' ? '게시글 수정(fallback)' : `editor:${k}`);
    const store = makeStore({ meta: { title: '홈' } });
    render(<MetaForm getValue={store.getValue} patch={store.patch} t={editorT} />);
    expect(screen.getByTestId('g7le-meta-title-mock')).toHaveAttribute('data-resolved-board', '게시글 수정(fallback)');
  });

  it('icon-picker 미등록 시 자유 입력으로 폴백한다', () => {
    const store = makeStore({ meta: { icon: 'home' } });
    render(<MetaForm getValue={store.getValue} patch={store.patch} t={t} />);
    const iconInput = screen.getByTestId('g7le-meta-icon');
    expect(iconInput.tagName).toBe('INPUT');
    expect(iconInput).toHaveValue('home');
  });

  it('icon-picker 등록 시 그리드 기본 닫힘 + 토글로 위젯 렌더(S5 합의 — 다 펼치지 않음)', () => {
    registerWidget('icon-picker', ({ value }) => (
      <div data-testid="icon-widget">{String(value)}</div>
    ));
    const store = makeStore({ meta: { icon: 'star' } });
    render(<MetaForm getValue={store.getValue} patch={store.patch} t={t} />);
    // 기본 닫힘 — 그리드 위젯 미렌더, 토글 버튼만(현재 아이콘 표시).
    expect(screen.queryByTestId('icon-widget')).toBeNull();
    const toggle = screen.getByTestId('g7le-meta-icon-toggle');
    expect(toggle).toHaveTextContent('star');
    // 펼침 — 위젯 렌더.
    fireEvent.click(toggle);
    expect(screen.getByTestId('icon-widget')).toHaveTextContent('star');
  });

  it('권한 TagInput — 후보 추가/제거가 permissions 를 패치한다', () => {
    const store = makeStore({ permissions: ['board.manage'] });
    render(
      <MetaForm
        getValue={store.getValue}
        patch={store.patch}
        t={t}
        permissionCandidates={[
          { value: 'board.manage', label: '게시판 관리' },
          { value: 'shop.order.view', label: '주문 조회' },
        ]}
      />,
    );

    // 기존 칩.
    expect(screen.getByTestId('g7le-tag-chip-board.manage')).toBeInTheDocument();

    // 후보 추가.
    fireEvent.click(screen.getByTestId('g7le-tag-add'));
    fireEvent.click(screen.getByTestId('g7le-tag-candidate-shop.order.view'));
    expect(store.patch).toHaveBeenLastCalledWith('permissions', ['board.manage', 'shop.order.view']);
  });

  it('마지막 권한 제거 시 permissions 키를 제거한다(제약 없음)', () => {
    const store = makeStore({ permissions: ['board.manage'] });
    render(
      <MetaForm
        getValue={store.getValue}
        patch={store.patch}
        t={t}
        permissionCandidates={[{ value: 'board.manage', label: '게시판 관리' }]}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-tag-remove-board.manage'));
    expect(store.patch).toHaveBeenLastCalledWith('permissions', undefined);
  });
});
