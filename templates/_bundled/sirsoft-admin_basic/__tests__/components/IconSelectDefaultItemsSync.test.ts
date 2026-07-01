/**
 * @file IconSelectDefaultItemsSync.test.ts
 * @description IconSelect 내장 기본 아이콘 목록 ↔ editor-spec defaultItems 동기화 가드
 *
 *
 * 배경: IconSelect 는 options prop 미지정 시 내장 기본 목록(defaultIconOptions)으로
 * 렌더한다. 편집기 array 에디터가 빈 목록에서 시작하면 항목 1개 추가가 prop 을
 * `[추가분]` 으로 기록해 내장 목록 전체를 교체하는 함정이 있었다. 스펙
 * `nodeEditor.params.defaultItems` 가 내장 목록을 그대로 선언해 에디터 시작 목록으로
 * 시드한다 — 본 테스트는 두 목록(컴포넌트 SSoT ↔ 스펙 선언)의 드리프트를 차단한다.
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { defaultIconOptions } from '../../src/components/composite/IconSelect';

describe('IconSelect defaultItems 동기화 가드', () => {
  it('editor-spec defaultItems 가 컴포넌트 내장 기본 목록과 1:1 일치한다', () => {
    const caps = JSON.parse(
      readFileSync(resolve(__dirname, '../../editor-spec/componentCapabilities.json'), 'utf8'),
    );
    const specItems = caps.IconSelect?.nodeEditor?.params?.defaultItems;
    expect(Array.isArray(specItems)).toBe(true);
    expect(specItems).toEqual(defaultIconOptions);
  });
});
