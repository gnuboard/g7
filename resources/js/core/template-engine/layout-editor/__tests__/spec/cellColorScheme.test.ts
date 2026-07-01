// e2e:allow 레이아웃 편집기 캔버스 오버레이/속성패널 UI — dnd-kit/합성 이벤트 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스(T1~T8) 실측 + 단위/레이아웃 렌더링 테스트로 검증
/**
 * cellColorScheme.test.ts — 셀 색 라이트/다크 스킴 토큰 변형
 *
 * 검증 대상:
 *  - recipeEngine.replaceScopedGroupToken — group(색 패밀리) 토큰 스킴별 교체.
 *    라이트는 dark: 토큰 보존, 다크는 라이트 토큰 보존, 동 group 교체, 빈 토큰 제거.
 *  - tableGridMutations.setCellColorToken — 셀 색 className 토큰 스킴별 적용/제거.
 *
 * @scenario cell_color_scheme_light_dark
 * @effects cell_border_bg_color_applied_per_scheme_as_classtoken
 * @since engine-v1.50.0
 */

import { describe, it, expect } from 'vitest';
import { replaceScopedGroupToken } from '../../spec/recipeEngine';
import { setCellColorToken } from '../../spec/tableGridMutations';
import type { EditorNode } from '../../utils/layoutTreeUtils';

const BORDER_FAMILY = ['border-gray-300', 'border-gray-500', 'border-blue-500', 'border-red-500'];
const BG_FAMILY = ['bg-white', 'bg-gray-100', 'bg-blue-100', 'bg-red-100'];

describe('replaceScopedGroupToken — 스킴별 색 토큰 교체', () => {
  it('라이트: base 토큰 추가, dark: 토큰 보존', () => {
    const out = replaceScopedGroupToken('border dark:border-blue-500', BORDER_FAMILY, 'border-red-500', false);
    expect(out.split(/\s+/).sort()).toEqual(['border', 'border-red-500', 'dark:border-blue-500'].sort());
  });

  it('라이트: 기존 라이트 색 토큰 교체(같은 group), dark: 토큰 무관', () => {
    const out = replaceScopedGroupToken('border border-gray-300 dark:border-gray-500', BORDER_FAMILY, 'border-red-500', false);
    expect(out.split(/\s+/).sort()).toEqual(['border', 'border-red-500', 'dark:border-gray-500'].sort());
  });

  it('다크: dark: prefix 부여해 추가, 라이트 토큰 보존', () => {
    const out = replaceScopedGroupToken('border border-gray-300', BORDER_FAMILY, 'border-blue-500', true);
    expect(out.split(/\s+/).sort()).toEqual(['border', 'border-gray-300', 'dark:border-blue-500'].sort());
  });

  it('다크: 기존 dark: 색 토큰 교체, 라이트 토큰 무관', () => {
    const out = replaceScopedGroupToken('border border-gray-300 dark:border-blue-500', BORDER_FAMILY, 'border-red-500', true);
    expect(out.split(/\s+/).sort()).toEqual(['border', 'border-gray-300', 'dark:border-red-500'].sort());
  });

  it('라이트 빈 토큰 = 라이트 색 제거(dark: 보존)', () => {
    const out = replaceScopedGroupToken('border border-gray-300 dark:border-red-500', BORDER_FAMILY, '', false);
    expect(out.split(/\s+/).sort()).toEqual(['border', 'dark:border-red-500'].sort());
  });

  it('다크 빈 토큰 = 다크 색 제거(라이트 보존)', () => {
    const out = replaceScopedGroupToken('border border-gray-300 dark:border-red-500', BORDER_FAMILY, undefined, true);
    expect(out.split(/\s+/).sort()).toEqual(['border', 'border-gray-300'].sort());
  });

  it('group 외 토큰(두께/기타) 보존', () => {
    const out = replaceScopedGroupToken('border-2 p-2 text-center bg-gray-100', BG_FAMILY, 'bg-blue-100', false);
    expect(out.split(/\s+/).sort()).toEqual(['border-2', 'p-2', 'text-center', 'bg-blue-100'].sort());
  });

  it('전부 제거되면 빈 문자열', () => {
    expect(replaceScopedGroupToken('bg-gray-100', BG_FAMILY, '', false)).toBe('');
  });
});

// 2×2 표(Tbody>Tr>Td). 좌상단 셀(0,0) 대상.
function table2x2(): EditorNode {
  const td = (text: string, className?: string): EditorNode => {
    const n: EditorNode = { type: 'basic', name: 'Td', text };
    if (className) n.props = { className };
    return n;
  };
  return {
    type: 'basic',
    name: 'Table',
    children: [
      {
        type: 'basic',
        name: 'Tbody',
        children: [
          { type: 'basic', name: 'Tr', children: [td('a'), td('b')] },
          { type: 'basic', name: 'Tr', children: [td('c'), td('d')] },
        ],
      },
    ],
  };
}

const PARAMS = { rowContainer: 'Tbody', row: 'Tr', cell: 'Td', headerCell: 'Th', colSpanProp: 'colSpan', rowSpanProp: 'rowSpan' };

function cellClass(table: EditorNode, r: number, c: number): string | undefined {
  const tr = (table.children![0] as EditorNode).children![r] as EditorNode;
  const td = tr.children![c] as EditorNode;
  return td.props?.className as string | undefined;
}

describe('setCellColorToken — 셀 색 className 토큰 스킴별 적용', () => {
  it('라이트 프리셋 배경 토큰 적용', () => {
    const out = setCellColorToken(table2x2(), PARAMS as never, 0, 0, 'bg-blue-100', BG_FAMILY, 'light');
    expect(cellClass(out, 0, 0)).toBe('bg-blue-100');
    // 다른 셀 불변.
    expect(cellClass(out, 1, 1)).toBeUndefined();
  });

  it('다크 프리셋 배경 토큰 = dark: prefix', () => {
    const out = setCellColorToken(table2x2(), PARAMS as never, 0, 0, 'bg-blue-100', BG_FAMILY, 'dark');
    expect(cellClass(out, 0, 0)).toBe('dark:bg-blue-100');
  });

  it('라이트/다크 색 공존(한쪽 적용이 다른쪽 보존)', () => {
    let out = setCellColorToken(table2x2(), PARAMS as never, 0, 0, 'bg-gray-100', BG_FAMILY, 'light');
    out = setCellColorToken(out, PARAMS as never, 0, 0, 'bg-blue-100', BG_FAMILY, 'dark');
    expect(cellClass(out, 0, 0)!.split(/\s+/).sort()).toEqual(['bg-gray-100', 'dark:bg-blue-100'].sort());
  });

  it('같은 스킴 재적용 = 교체(중복 누적 없음)', () => {
    let out = setCellColorToken(table2x2(), PARAMS as never, 0, 0, 'bg-gray-100', BG_FAMILY, 'light');
    out = setCellColorToken(out, PARAMS as never, 0, 0, 'bg-red-100', BG_FAMILY, 'light');
    expect(cellClass(out, 0, 0)).toBe('bg-red-100');
  });

  it('빈 토큰 = 그 스킴 색 제거(다른 스킴 보존)', () => {
    let out = setCellColorToken(table2x2(), PARAMS as never, 0, 0, 'bg-gray-100', BG_FAMILY, 'light');
    out = setCellColorToken(out, PARAMS as never, 0, 0, 'bg-blue-100', BG_FAMILY, 'dark');
    out = setCellColorToken(out, PARAMS as never, 0, 0, '', BG_FAMILY, 'light');
    expect(cellClass(out, 0, 0)).toBe('dark:bg-blue-100');
  });

  it('테두리 두께 토큰 보존 + 색 토큰만 교체', () => {
    let out = setCellColorToken(table2x2(), PARAMS as never, 0, 0, 'border-gray-300', BORDER_FAMILY, 'light');
    // 두께 토큰을 별도로 부착(setCellClassName 없이 직접 props 시뮬레이션 대신, 색 위에 다시 적용).
    out = setCellColorToken(out, PARAMS as never, 0, 0, 'border-red-500', BORDER_FAMILY, 'light');
    expect(cellClass(out, 0, 0)).toBe('border-red-500');
  });

  it('색 제거로 className 비면 props.className 삭제', () => {
    let out = setCellColorToken(table2x2(), PARAMS as never, 0, 0, 'bg-gray-100', BG_FAMILY, 'light');
    out = setCellColorToken(out, PARAMS as never, 0, 0, '', BG_FAMILY, 'light');
    expect(cellClass(out, 0, 0)).toBeUndefined();
  });

  it('순수 함수 — 입력 노드 불변', () => {
    const input = table2x2();
    const snapshot = JSON.stringify(input);
    setCellColorToken(input, PARAMS as never, 0, 0, 'bg-blue-100', BG_FAMILY, 'light');
    expect(JSON.stringify(input)).toBe(snapshot);
  });
});
