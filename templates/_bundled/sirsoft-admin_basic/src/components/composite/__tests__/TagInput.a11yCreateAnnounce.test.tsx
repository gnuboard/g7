/**
 * @file TagInput.a11yCreateAnnounce.test.tsx
 * @description 태그 입력 — 새 태그를 만들 때 화면 읽기 알림(라이브 영역)이 태그 이름을 읽는다
 *
 * 환경설정 > 본문 콘텐츠 > 「운영자 추가 태그」 실측(#887): 태그를 치고 고르면 라이브 영역이 「[object Object] 선택됨」 을
 * 읽었다. 만들기 행의 label 은 화면용 요소(`<span><strong>값</strong> 추가</span>`)이고, react-select 는 그 label 을
 * 알림 문구 자리표시자에 그대로 넘긴다 — 문자열로 바뀌며 「[object Object]」 가 됐다. 만들기 행은 친 값으로 읽는다.
 *
 * 데스크톱 환경 재현: jsdom 은 TouchEvent 를 만들 수 있어 react-select 가 「터치 기기」로 보고 고른 뒤 입력칸을 흐린다
 * (흐리면 라이브 영역 문구가 비어 결함이 가려진다). 데스크톱 브라우저처럼 TouchEvent 생성을 거부한 뒤 불러온다.
 */

import { vi } from 'vitest';

vi.hoisted(() => {
  const original = document.createEvent.bind(document);
  document.createEvent = ((type: string) => {
    if (type === 'TouchEvent') throw new Error('NotSupportedError: 데스크톱 브라우저는 TouchEvent 를 만들지 않는다');
    return original(type);
  }) as typeof document.createEvent;
});

import React from 'react';
import fs from 'fs';
import path from 'path';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { TagInput } from '../TagInput';

const commonKo = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../../../lang/partial/ko/common.json'), 'utf-8'));

/** ko 사전으로 G7Core.t 를 흉내 낸다 — `{{name}}` 자리표시자를 채운다(G7Core 처럼 값을 문자열로 바꾼다). */
function koTranslate(key: string, params?: Record<string, unknown>): string {
  const value = key.split('.').reduce<any>((node, part) => node?.[part], { common: commonKo });
  if (typeof value !== 'string') return key;
  return value.replace(/\{\{\s*(\w+)\s*\}\}/g, (_, name) => String(params?.[name] ?? ''));
}

/** react-select 라이브 영역 글자. */
const liveText = (container: HTMLElement) => container.querySelector('[role="log"]')?.textContent ?? '';

describe('TagInput — 새 태그 만들기 화면 읽기 알림 (#887)', () => {
  let original: any;

  beforeEach(() => {
    original = (window as any).G7Core;
    (window as any).G7Core = { ...(original ?? {}), t: koTranslate };
  });

  afterEach(() => {
    (window as any).G7Core = original;
  });

  // @scenario control=tag_input
  // @effects tag_input_created_value_announced_by_name
  it('여러 개 만들기 칸: 친 값을 Enter 로 만들면 알림이 「iframe 선택됨」 이고 「[object Object]」 가 아니다', async () => {
    const user = userEvent.setup();
    const { container } = render(<TagInput value={[]} options={[]} onChange={() => {}} creatable />);

    await user.type(screen.getByRole('combobox'), 'iframe');
    await user.keyboard('{Enter}');

    expect(liveText(container)).not.toContain('[object Object]');
    expect(liveText(container)).toContain(koTranslate('common.tag_input.selected', { label: 'iframe' }));
  });

  // @scenario control=tag_input
  // @effects tag_input_created_value_announced_by_name
  it('여러 개 만들기 칸: 만들기 행을 눌러 만들어도 태그 이름으로 읽는다', async () => {
    const user = userEvent.setup();
    const { container } = render(<TagInput value={['details']} options={[]} onChange={() => {}} creatable />);

    await user.type(screen.getByRole('combobox'), 'summary');
    const createRow = document.body.querySelector('.tag-input__menu-portal [class*="option"]') as HTMLElement;
    expect(createRow, '만들기 행이 메뉴에 있어야 한다').not.toBeNull();
    await user.click(createRow);

    expect(liveText(container)).not.toContain('[object Object]');
    expect(liveText(container)).toContain(koTranslate('common.tag_input.selected', { label: 'summary' }));
  });

  // @scenario control=tag_input
  // @effects tag_input_created_value_announced_by_name
  it('하나만 만들기 칸(isMulti=false): 만든 값도 태그 이름으로 읽는다', async () => {
    const user = userEvent.setup();
    const { container } = render(<TagInput value={[]} options={[]} onChange={() => {}} creatable isMulti={false} />);

    await user.type(screen.getByRole('combobox'), 'section');
    await user.keyboard('{Enter}');

    expect(liveText(container)).not.toContain('[object Object]');
    expect(liveText(container)).toContain(koTranslate('common.tag_input.selected', { label: 'section' }));
  });
});
