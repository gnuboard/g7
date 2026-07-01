/**
 * InlineTextEditor.test.tsx — 더블클릭 인라인 텍스트 편집 RTL
 *
 *  - 마운트 시 초기값 주입 + 포커스.
 *  - Enter → onCommit(편집값), Escape → onCancel, blur → onCommit.
 *  - 커스텀 키 노드 vs 평문 노드의 힌트 배지 분기.
 *  - 빈값 경고 표시.
 */

import React from 'react';
import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { InlineTextEditor } from '../../components/InlineTextEditor';
import type { OverlayBox } from '../../utils/overlayGeometry';

const t = (k: string) => k;
const box: OverlayBox = { left: 10, top: 20, width: 100, height: 24, scale: 1 };

afterEach(() => cleanup());

describe('InlineTextEditor', () => {
  it('초기값을 contentEditable 에 주입한다', () => {
    render(
      <InlineTextEditor box={box} initialValue="환영합니다" isCustomKey={false} t={t} onCommit={vi.fn()} onCancel={vi.fn()} />,
    );
    expect(screen.getByTestId('g7le-inline-text-editable').textContent).toBe('환영합니다');
  });

  it('Enter → onCommit(현재값)', () => {
    const onCommit = vi.fn();
    render(
      <InlineTextEditor box={box} initialValue="old" isCustomKey={false} t={t} onCommit={onCommit} onCancel={vi.fn()} />,
    );
    const el = screen.getByTestId('g7le-inline-text-editable');
    el.textContent = 'new value';
    fireEvent.keyDown(el, { key: 'Enter' });
    expect(onCommit).toHaveBeenCalledWith('new value');
  });

  it('Escape → onCancel (onCommit 미발화)', () => {
    const onCommit = vi.fn();
    const onCancel = vi.fn();
    render(
      <InlineTextEditor box={box} initialValue="x" isCustomKey={false} t={t} onCommit={onCommit} onCancel={onCancel} />,
    );
    const el = screen.getByTestId('g7le-inline-text-editable');
    fireEvent.keyDown(el, { key: 'Escape' });
    expect(onCancel).toHaveBeenCalledTimes(1);
    expect(onCommit).not.toHaveBeenCalled();
  });

  it('blur → onCommit', () => {
    const onCommit = vi.fn();
    render(
      <InlineTextEditor box={box} initialValue="hello" isCustomKey={false} t={t} onCommit={onCommit} onCancel={vi.fn()} />,
    );
    const el = screen.getByTestId('g7le-inline-text-editable');
    fireEvent.blur(el);
    expect(onCommit).toHaveBeenCalledWith('hello');
  });

  it('Enter 후 blur 가 와도 onCommit 은 1회만 (중복 가드)', () => {
    const onCommit = vi.fn();
    render(
      <InlineTextEditor box={box} initialValue="a" isCustomKey={false} t={t} onCommit={onCommit} onCancel={vi.fn()} />,
    );
    const el = screen.getByTestId('g7le-inline-text-editable');
    fireEvent.keyDown(el, { key: 'Enter' });
    fireEvent.blur(el);
    expect(onCommit).toHaveBeenCalledTimes(1);
  });

  it('커스텀 키 노드 → "기존 키" 힌트, 평문 → "신규 키" 힌트', () => {
    const { rerender } = render(
      <InlineTextEditor box={box} initialValue="x" isCustomKey t={t} onCommit={vi.fn()} onCancel={vi.fn()} />,
    );
    expect(screen.getByTestId('g7le-inline-text-editor').dataset.customKey).toBe('true');
    expect(screen.getByText(/existing_key_hint/)).toBeTruthy();

    rerender(
      <InlineTextEditor box={box} initialValue="x" isCustomKey={false} t={t} onCommit={vi.fn()} onCancel={vi.fn()} />,
    );
    expect(screen.getByText(/new_key_hint/)).toBeTruthy();
  });

  it('onOpenTranslations 제공 시 힌트 배지가 클릭형 버튼 → 클릭 시 콜백', () => {
    const onOpenTranslations = vi.fn();
    render(
      <InlineTextEditor
        box={box}
        initialValue="x"
        isCustomKey={false}
        t={t}
        onCommit={vi.fn()}
        onCancel={vi.fn()}
        onOpenTranslations={onOpenTranslations}
      />,
    );
    const btn = screen.getByTestId('g7le-inline-text-editor-open-translations');
    expect(btn.tagName).toBe('BUTTON');
    // "모든 언어 편집" 진입 라벨 노출.
    expect(btn.textContent).toContain('open_translations');
    fireEvent.click(btn);
    expect(onOpenTranslations).toHaveBeenCalledTimes(1);
  });

  it('onOpenTranslations 미제공 시 배지는 정적 안내(버튼 아님)', () => {
    render(
      <InlineTextEditor box={box} initialValue="x" isCustomKey={false} t={t} onCommit={vi.fn()} onCancel={vi.fn()} />,
    );
    expect(screen.queryByTestId('g7le-inline-text-editor-open-translations')).toBeNull();
    // 안내 텍스트는 여전히 표시.
    expect(screen.getByText(/new_key_hint/)).toBeTruthy();
  });

  it('빈값일 때 경고 표시', () => {
    render(
      <InlineTextEditor box={box} initialValue="" isCustomKey={false} t={t} onCommit={vi.fn()} onCancel={vi.fn()} />,
    );
    expect(screen.getByTestId('g7le-inline-text-editor-empty-warn')).toBeTruthy();
  });

  it('mirrorClassName/Style 을 contentEditable 에 명령형 반영', () => {
    const { rerender } = render(
      <InlineTextEditor box={box} initialValue="x" isCustomKey={false} t={t} onCommit={vi.fn()} onCancel={vi.fn()}
        mirrorClassName="text-lg font-semibold" mirrorStyle={{ color: 'rgb(10, 20, 30)' }} />,
    );
    const el = screen.getByTestId('g7le-inline-text-editable');
    expect(el.className).toBe('text-lg font-semibold');
    expect(el.style.color).toBe('rgb(10, 20, 30)');
    // 서식 변경(굵기 토글) 미러 — mirrorClassName 갱신 시 contentEditable className 즉시 반영.
    rerender(
      <InlineTextEditor box={box} initialValue="x" isCustomKey={false} t={t} onCommit={vi.fn()} onCancel={vi.fn()}
        mirrorClassName="text-lg font-bold" mirrorStyle={{ color: 'rgb(10, 20, 30)' }} />,
    );
    expect(el.className).toBe('text-lg font-bold');
  });

  it('classToken 색(text-blue-600)이 인라인 폴백색에 가려지지 않는다 — 인라인 color 미설정', () => {
    // mirrorClassName 에 색 토큰이 있고 mirrorStyle.color 가 없으면, 편집 오버레이는 인라인 color 를
    // 깔지 않아야 한다(template CSS 의 색 토큰이 적용되도록). 종전엔 항상 '#0f172a' 폴백을 깔아
    // classToken 색이 인라인 우선순위에 가려 편집 중 색 변경이 안 보였다.
    render(
      <InlineTextEditor
        box={box}
        initialValue="x"
        isCustomKey={false}
        t={t}
        onCommit={vi.fn()}
        onCancel={vi.fn()}
        mirrorClassName="text-2xl font-bold text-blue-600"
      />,
    );
    const el = screen.getByTestId('g7le-inline-text-editable');
    expect(el.className).toContain('text-blue-600');
    // 인라인 color 가 비어 있어야 className 색 토큰이 우선한다.
    expect(el.style.color).toBe('');
  });

  it('styleProp 색(mirrorStyle.color)은 그대로 적용 (className 무관)', () => {
    render(
      <InlineTextEditor box={box} initialValue="x" isCustomKey={false} t={t} onCommit={vi.fn()} onCancel={vi.fn()}
        mirrorStyle={{ color: 'rgb(220, 38, 38)' }} />,
    );
    const el = screen.getByTestId('g7le-inline-text-editable');
    expect(el.style.color).toBe('rgb(220, 38, 38)');
  });

  it('색 출처가 전혀 없으면 가독성 폴백색(#0f172a) 적용', () => {
    render(
      <InlineTextEditor box={box} initialValue="x" isCustomKey={false} t={t} onCommit={vi.fn()} onCancel={vi.fn()} />,
    );
    const el = screen.getByTestId('g7le-inline-text-editable');
    expect(el.style.color).toBe('rgb(15, 23, 42)');
  });

  it('흰 글자색(mirrorStyle.color) → 오버레이 배경을 어둡게 전환 (흰 배경 위 흰 글자 묻힘 회귀)', () => {
    // 글자색이 흰색인 노드(원래 어두운 배경 위라 캔버스에선 보임)를 인라인 편집할 때,
    // 오버레이가 흰 배경을 깔면 흰 글자 + 흰 배경으로 타이핑 텍스트가 안 보인다.
    // 글자색 밝기를 측정해 밝은 글자면 오버레이 배경을 어둡게 전환해 대비를 보장한다.
    render(
      <InlineTextEditor box={box} initialValue="흰색 텍스트" isCustomKey={false} t={t} onCommit={vi.fn()} onCancel={vi.fn()}
        mirrorStyle={{ color: 'rgb(255, 255, 255)' }} />,
    );
    const el = screen.getByTestId('g7le-inline-text-editable');
    // 글자색은 흰색 그대로(색 출처 존중).
    expect(el.style.color).toBe('rgb(255, 255, 255)');
    // 배경은 흰색이 아니라 어두운 색으로 전환되어야 한다.
    expect(el.style.background).not.toBe('#ffffff');
    expect(el.style.background).not.toBe('rgb(255, 255, 255)');
    expect(el.style.background).toBe('rgb(15, 23, 42)');
  });

  it('어두운 글자색(mirrorStyle.color) → 오버레이 배경은 흰색 유지', () => {
    render(
      <InlineTextEditor box={box} initialValue="x" isCustomKey={false} t={t} onCommit={vi.fn()} onCancel={vi.fn()}
        mirrorStyle={{ color: 'rgb(15, 23, 42)' }} />,
    );
    const el = screen.getByTestId('g7le-inline-text-editable');
    expect(el.style.background).toBe('rgb(255, 255, 255)');
  });

  it('색 출처가 없으면 오버레이 배경은 흰색 유지(폴백 글자색이 어두우므로)', () => {
    render(
      <InlineTextEditor box={box} initialValue="x" isCustomKey={false} t={t} onCommit={vi.fn()} onCancel={vi.fn()} />,
    );
    const el = screen.getByTestId('g7le-inline-text-editable');
    expect(el.style.background).toBe('rgb(255, 255, 255)');
  });

  it('#rrggbb 흰색 글자색도 어두운 배경으로 전환 (hex 색 파싱)', () => {
    render(
      <InlineTextEditor box={box} initialValue="x" isCustomKey={false} t={t} onCommit={vi.fn()} onCancel={vi.fn()}
        mirrorStyle={{ color: '#ffffff' }} />,
    );
    const el = screen.getByTestId('g7le-inline-text-editable');
    expect(el.style.background).toBe('rgb(15, 23, 42)');
  });

  it('밝은 회색(#e5e7eb) 글자색도 어두운 배경으로 전환 (밝기 임계값)', () => {
    render(
      <InlineTextEditor box={box} initialValue="x" isCustomKey={false} t={t} onCommit={vi.fn()} onCancel={vi.fn()}
        mirrorStyle={{ color: '#e5e7eb' }} />,
    );
    const el = screen.getByTestId('g7le-inline-text-editable');
    expect(el.style.background).toBe('rgb(15, 23, 42)');
  });

  it('중간 명도 회색(#6b7280) 글자색은 흰 배경 유지 (대비 확보)', () => {
    // 다크 모드에서 흔한 중간 회색. 임계값(180) 미만이라 어둡다고 판정 → 흰 배경(대비 OK).
    render(
      <InlineTextEditor box={box} initialValue="x" isCustomKey={false} t={t} onCommit={vi.fn()} onCancel={vi.fn()}
        mirrorStyle={{ color: '#6b7280' }} />,
    );
    const el = screen.getByTestId('g7le-inline-text-editable');
    expect(el.style.background).toBe('rgb(255, 255, 255)');
  });

  it('nodeEffectiveColor(흰색) → 어두운 배경 + 그 색을 명시 글자색으로 적용 (다크 컨텍스트 패리티)', () => {
    // 다크 모드에서 dark:text-white 노드는 캔버스에선 흰 글자지만, 오버레이는 다크 컨텍스트 밖이라
    // 자체 computed 로는 색이 어긋난다. EditorCanvasOverlay 가 측정한 nodeEffectiveColor 를 받아
    // ① 어두운 배경 ② 캔버스와 같은 흰 글자색을 적용한다.
    render(
      <InlineTextEditor box={box} initialValue="회원가입" isCustomKey={false} t={t} onCommit={vi.fn()} onCancel={vi.fn()}
        mirrorClassName="text-2xl font-bold text-gray-900 dark:text-white"
        nodeEffectiveColor="rgb(255, 255, 255)" />,
    );
    const el = screen.getByTestId('g7le-inline-text-editable');
    expect(el.style.background).toBe('rgb(15, 23, 42)');
    expect(el.style.color).toBe('rgb(255, 255, 255)');
  });

  it('nodeEffectiveColor(oklch 밝은 L) → 어두운 배경 (Tailwind v4 oklch 파싱)', () => {
    // 이 템플릿(Tailwind v4)은 oklch 색을 쓴다. rgb 파싱이 안 되던 결함 — oklch L 값으로 판정.
    render(
      <InlineTextEditor box={box} initialValue="x" isCustomKey={false} t={t} onCommit={vi.fn()} onCancel={vi.fn()}
        nodeEffectiveColor="oklch(0.985 0.002 247.839)" />,
    );
    const el = screen.getByTestId('g7le-inline-text-editable');
    expect(el.style.background).toBe('rgb(15, 23, 42)');
    expect(el.style.color).toBe('oklch(0.985 0.002 247.839)');
  });

  it('nodeEffectiveColor(oklch 어두운 L) → 흰 배경 유지', () => {
    render(
      <InlineTextEditor box={box} initialValue="x" isCustomKey={false} t={t} onCommit={vi.fn()} onCancel={vi.fn()}
        nodeEffectiveColor="oklch(0.21 0.034 264.665)" />,
    );
    const el = screen.getByTestId('g7le-inline-text-editable');
    expect(el.style.background).toBe('rgb(255, 255, 255)');
  });

  it('nodeEffectiveColor 가 mirrorStyle.color 보다 우선 (대상 노드 측정값 신뢰)', () => {
    // mirror 는 어두운 인라인색이라도, 대상 노드의 캔버스 측정색(흰색)이 우선 → 어두운 배경.
    render(
      <InlineTextEditor box={box} initialValue="x" isCustomKey={false} t={t} onCommit={vi.fn()} onCancel={vi.fn()}
        mirrorStyle={{ color: 'rgb(15, 23, 42)' }}
        nodeEffectiveColor="rgb(255, 255, 255)" />,
    );
    const el = screen.getByTestId('g7le-inline-text-editable');
    expect(el.style.background).toBe('rgb(15, 23, 42)');
    expect(el.style.color).toBe('rgb(255, 255, 255)');
  });
});
