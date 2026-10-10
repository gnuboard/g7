/**
 * StatusBadge·CodeEditor — 화면 문구를 번역 키로 낸다 (#599 validate P7)
 *
 * 두 컴포넌트는 기본 라벨·로딩 문구·자산 실패 안내를 한국어 리터럴로 들고 있었다. 영어·일본어
 * 관리자 화면에도 「성공」「편집기를 불러오는 중...」이 그대로 떴다.
 */

import fs from 'node:fs';
import path from 'node:path';
import React from 'react';
import { describe, it, expect, afterEach, vi } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';
import { StatusBadge } from '../../src/components/composite/StatusBadge';

const root = path.resolve(__dirname, '../..');

/** 주석을 걷어낸 소스에서 한글이 든 문자열 리터럴을 찾는다. */
function koreanLiterals(rel: string): string[] {
  const src = fs
    .readFileSync(path.join(root, rel), 'utf-8')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|[^:])\/\/.*$/gm, '$1');

  return (src.match(/(['"`])(?:(?!\1)[^\\\n]|\\.)*[가-힣](?:(?!\1)[^\\\n]|\\.)*\1|>[^<{}\n]*[가-힣][^<{}\n]*</g) ?? []);
}

describe('StatusBadge·CodeEditor 다국어', () => {
  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
  });

  /**
   * 두 컴포넌트 소스에 한국어 문구 리터럴이 남지 않는다(주석 제외).
   *
   * @scenario case=non_korean
   * @effects component_default_texts_come_from_translation_keys
   */
  it('소스에 한국어 문구 리터럴이 없다', () => {
    expect(koreanLiterals('src/components/composite/StatusBadge.tsx')).toEqual([]);
    expect(koreanLiterals('src/components/composite/CodeEditor.tsx')).toEqual([]);
  });

  /**
   * 기본 라벨은 번역 함수가 돌려준 문구다 — 영어 로케일이면 영어가 뜬다.
   *
   * @scenario case=en
   * @effects component_default_texts_come_from_translation_keys
   */
  it('StatusBadge 기본 라벨은 common.status_badge.* 키로 번역된다', () => {
    const g7 = (window as any).G7Core;
    vi.spyOn(g7, 't').mockImplementation((key: string) =>
      key === 'common.status_badge.danger' ? 'Danger' : key,
    );

    render(<StatusBadge status="danger" />);

    expect(screen.getByText('Danger')).toBeInTheDocument();
  });
});
