import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * ColumnSelector 의 `onColumnVisibilityChange` 발화 출처 표기 전수 확인 (#599 S10)
 *
 * 이 콜백은 사람이 체크박스를 누른 순간에만 오는 것이 아니다. 마운트 시 저장값·기본값으로
 * 부모 상태를 맞출 때(`init`)와 동적 컬럼이 새로 생겨 자동 포함될 때(`dynamic`)도 온다.
 * 그 셋을 구분하지 못하면 소비처가 "복원" 을 "사용자 조작" 으로 오해해, 주소를 다시 쓰는
 * 화면에서는 들어올 때마다 주소가 바뀐다.
 *
 * 두 가지를 고정한다:
 *
 *  1. **발화 지점 전수가 출처를 싣는다.** 한 지점만 빠뜨리면 그 경로의 `meta` 가 `undefined`
 *     가 되고, 출처로 분기하는 소비처의 가드가 조용히 죽는다 — 오류도 경고도 없다.
 *  2. **선언(components.json)과 구현이 같은 어휘를 쓴다.** 레이아웃 JSON 은 TS 타입 검사
 *     밖이라, 선언에 없는 값을 소비처가 기대해도 컴파일에 걸리지 않는다.
 *
 * 이 API 는 현재 이 저장소 안에 소비처가 없다(주문 목록은 6차 확정으로 출처를 가리지
 * 않는다). 그래도 표면으로 존재하는 이상 선언과 구현이 어긋나면 다음 소비처가 그 어긋남을
 * 그대로 물려받으므로 여기서 묶어 둔다.
 */
describe('ColumnSelector — onColumnVisibilityChange 발화 출처', () => {
  const here = dirname(fileURLToPath(import.meta.url));

  const source = readFileSync(resolve(here, '../ColumnSelector.tsx'), 'utf8');
  const manifest = JSON.parse(readFileSync(resolve(here, '../../../../components.json'), 'utf8'));

  /** 이 컴포넌트가 약속하는 출처 어휘 — 구현·선언 양쪽이 공유해야 하는 단일 목록 */
  const SOURCES = ['user', 'init', 'dynamic'] as const;

  it('콜백을 부르는 지점 전부가 출처를 함께 싣는다', () => {
    // 호출 지점을 손으로 열거하지 않는다 — 새 발화 경로가 생겨도 이 검사가 먼저 붉어진다.
    // 호출은 `onColumnVisibilityChange(` 와 `onColumnVisibilityChange?.(` 두 형태다.
    // 타입 선언부(`onColumnVisibilityChange?: (...)`)와 조건식(`&& onColumnVisibilityChange`)은
    // 호출이 아니므로 자연히 제외된다.
    const invocations = source.match(/onColumnVisibilityChange(?:\?\.)?\([^;]*?\)/gs) ?? [];

    expect(
      invocations.length,
      '콜백 호출 지점을 찾지 못했다 — 파생 원본이 비면 아래 검사가 공전한다',
    ).toBeGreaterThanOrEqual(3);

    for (const call of invocations) {
      expect(
        call,
        `발화 출처를 싣지 않는 호출이 있다 — 그 경로의 meta 가 undefined 가 되어 소비처 가드가 조용히 죽는다: ${call.slice(0, 80)}`,
      ).toMatch(/source:\s*'(user|init|dynamic)'/);
    }
  });

  it('세 출처가 모두 실제로 발화한다', () => {
    for (const kind of SOURCES) {
      expect(
        source,
        `출처 '${kind}' 를 싣는 호출이 없다 — 선언만 있고 발화하지 않는 값은 소비처를 오도한다`,
      ).toContain(`source: '${kind}'`);
    }
  });

  it('components.json 선언이 구현과 같은 어휘를 쓴다', () => {
    // `composite` 는 배열이다 — 이름으로 찾는다.
    const entry = (manifest?.components?.composite ?? []).find(
      (c: any) => c?.name === 'ColumnSelector' || c?.props?.onColumnVisibilityChange
    );
    const declared = String(entry?.props?.onColumnVisibilityChange?.description ?? '');

    expect(declared, 'components.json 에 ColumnSelector 콜백 선언이 없다').not.toBe('');

    for (const kind of SOURCES) {
      expect(
        declared,
        `선언이 출처 '${kind}' 를 말하지 않는다 — 레이아웃 JSON 은 타입 검사 밖이라 선언이 유일한 계약서다`,
      ).toContain(kind);
    }
  });

  it('DataGrid 의 같은 이름 이벤트는 출처를 싣지 않는다 (혼동 방지)', () => {
    // 두 컴포넌트가 같은 이벤트 이름을 방출하는데 한쪽만 두 번째 인자를 싣는다. 그 사실이
    // 코드에서 사라지면 ColumnSelector 용으로 쓴 `$args[1].source` 가드를 DataGrid 이벤트에
    // 그대로 옮겨도 아무도 막지 못한다 — 그 액션은 항상 undefined 를 만나 조용히 죽는다.
    const dataGrid = readFileSync(resolve(here, '../DataGrid.tsx'), 'utf8');

    expect(
      dataGrid,
      'DataGrid 의 콜백 선언에서 출처 차이를 설명하는 근거가 사라졌다',
    ).toMatch(/onColumnVisibilityChange\?:\s*\(fields: string\[\]\) => void/);
  });
});
