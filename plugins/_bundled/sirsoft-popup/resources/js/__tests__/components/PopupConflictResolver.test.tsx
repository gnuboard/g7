/**
 * @file PopupConflictResolver.test.tsx
 * @description 동시 수정 충돌 비교·재적용 — 항목별 기본 선택, 「둘 다 바꿈」 잠금, 최신본 위 병합, 바뀐 이미지의 옛 ID 잠금.
 * (매니페스트 admin-screens.yaml)
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, cleanup, fireEvent } from '@testing-library/react';
import { PopupConflictResolver } from '../../components/composite/PopupConflictResolver';
import { installCore } from './core';

/** 편집을 시작한 기준 상세 */
const base = {
  revision: 1,
  title: '처음 제목',
  is_active: true,
  content_type: 'image',
  display_period: 'always',
  page_scope: 'all',
  routes: [],
  audience: 'all',
  device: 'all',
  image_alt: { ko: '처음' },
  link_url: null,
  link_new_window: false,
  images: { pc: { id: 10, width: 480, height: 520 }, mobile: null },
};

/**
 * 비교 행을 찾습니다.
 *
 * @param container 렌더 결과
 * @param field 비교 항목
 * @returns 행 요소
 */
const row = (container: HTMLElement, field: string) => container.querySelector(`[data-conflict-field="${field}"]`) as HTMLElement | null;

describe('PopupConflictResolver', () => {
  beforeEach(() => {
    installCore();
  });

  afterEach(() => {
    cleanup();
    delete (window as any).G7Core;
  });

  // @scenario surface=conflict_resolver_defaults_by_side
  it('다른 운영자만 바꾼 항목은 최신 값, 나만 바꾼 항목은 내 값이 기본이고 같은 값은 비교하지 않는다', () => {
    const mine = { ...base, title: '내 제목' };
    const latest = { ...base, revision: 2, audience: 'member' };
    const { container } = render(<PopupConflictResolver base={base} mine={mine} latest={latest} locales={['ko']} />);

    expect(row(container, 'title')?.getAttribute('data-change')).toBe('mine');
    expect((row(container, 'title')?.querySelector('input[value="mine"]') as HTMLInputElement).checked).toBe(true);
    expect(row(container, 'audience')?.getAttribute('data-change')).toBe('theirs');
    expect((row(container, 'audience')?.querySelector('input[value="latest"]') as HTMLInputElement).checked).toBe(true);
    expect(row(container, 'device')).toBeNull();
    expect((container.querySelector('.g7pp-conflict__apply') as HTMLButtonElement).disabled).toBe(false);
  });

  // @scenario surface=conflict_resolver_both_changed_requires_choice
  it('둘 다 바꾼 항목은 직접 고르기 전에는 적용할 수 없다', () => {
    const mine = { ...base, title: '내 제목' };
    const latest = { ...base, revision: 2, title: '남의 제목' };
    const { container, getByText } = render(<PopupConflictResolver base={base} mine={mine} latest={latest} locales={['ko']} />);
    const apply = container.querySelector('.g7pp-conflict__apply') as HTMLButtonElement;

    expect(row(container, 'title')?.getAttribute('data-change')).toBe('both');
    expect(apply.disabled).toBe(true);
    expect(getByText('둘 다 바꾼 항목을 모두 골라야 적용할 수 있습니다.')).not.toBeNull();

    fireEvent.click(row(container, 'title')?.querySelector('input[value="mine"]') as HTMLInputElement);
    expect(apply.disabled).toBe(false);
  });

  // @scenario surface=conflict_resolver_apply_merges_onto_latest
  it('적용하면 최신본(최신 revision) 위에 내가 고른 값만 얹고, 다음 비교 기준은 최신본이다', () => {
    const onApply = vi.fn();
    const mine = { ...base, title: '내 제목', link_url: '/mine' };
    const latest = { ...base, revision: 2, audience: 'member', link_url: '/theirs' };
    const { container } = render(<PopupConflictResolver base={base} mine={mine} latest={latest} locales={['ko']} onApply={onApply} />);

    fireEvent.click(row(container, 'link')?.querySelector('input[value="latest"]') as HTMLInputElement);
    fireEvent.click(container.querySelector('.g7pp-conflict__apply') as HTMLButtonElement);

    const [merged, nextBase] = onApply.mock.calls[0];
    expect(merged.revision).toBe(2);
    expect(merged.title).toBe('내 제목');
    expect(merged.audience).toBe('member');
    expect(merged.link_url).toBe('/theirs');
    expect(nextBase).toBe(latest);
  });

  // @scenario surface=conflict_resolver_stale_image_not_selectable
  it('다른 운영자가 바꾼 이미지는 내 쪽(옛 ID)을 고를 수 없다', () => {
    const latest = { ...base, revision: 2, images: { pc: { id: 11, width: 480, height: 520 }, mobile: null } };
    const { container } = render(<PopupConflictResolver base={base} mine={base} latest={latest} locales={['ko']} />);
    const pcRow = row(container, 'pc_image') as HTMLElement;

    expect((pcRow.querySelector('input[value="mine"]') as HTMLInputElement).disabled).toBe(true);
    expect((pcRow.querySelector('input[value="latest"]') as HTMLInputElement).checked).toBe(true);
    expect(pcRow.querySelector('.g7pp-conflict__stale')).not.toBeNull();
  });
});
