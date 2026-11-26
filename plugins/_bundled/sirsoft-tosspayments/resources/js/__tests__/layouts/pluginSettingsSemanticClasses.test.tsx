/**
 * @file pluginSettingsSemanticClasses.test.tsx
 * @description sirsoft-tosspayments 환경설정 화면의 시맨틱 클래스 매핑 회귀 테스트
 *
 * 전수 시맨틱화 당시 이 레이아웃의 클래스가 한 칸씩 밀려 순환 배치되었다:
 *   field_* 컨테이너(3열 그리드) → section-heading-md (그리드 아님, 제목 타이포)
 *   섹션 H3(제목)              → text-error-strong (에러 색)
 *   에러 H3                    → text-error-soft
 *   에러 Li                    → grid-3col-responsive (리스트 항목에 그리드)
 *
 * 결과적으로 field_* 자식의 `lg:col-span-1/2` 가 부모 grid 부재로 무효화되어
 * 2열 폼 레이아웃이 무너졌다. 본 테스트는 각 노드가 자기 역할에 맞는 시맨틱
 * 클래스를 갖는지 고정한다.
 *
 * 시맨틱 클래스 정의 (templates/_bundled/sirsoft-admin_basic/src/styles/main.css):
 *   .grid-3col-responsive → @apply grid grid-cols-1 lg:grid-cols-3 gap-4 items-start
 *     (폭 미디어를 품은 이 클래스는 이제 쓰지 않는다 — 같은 값을 접두 유틸과 엔진 responsive `1024-` 구간으로 쓴다)
 *   .section-heading-md   → @apply text-base font-semibold text-gray-900 dark:text-white
 *   .text-error-strong    → @apply text-sm font-medium text-red-800 dark:text-red-200
 *   .text-error-soft      → @apply text-sm text-red-700 dark:text-red-300
 */

import { describe, it, expect } from 'vitest';
import pluginSettingsLayout from '../../../layouts/admin/plugin_settings.json';

/** 의미 클래스는 이 플러그인 접두 판본이다 — 템플릿 CSS 가 아니라 플러그인 CSS(`@layer g7-extension`)가 정의한다 */
const NS = 'pay-tosspayments-';

type Node = Record<string, unknown>;

/** 레이아웃 트리를 순회하며 술어를 만족하는 노드를 모두 수집한다. */
function collect(node: unknown, predicate: (n: Node) => boolean, acc: Node[] = []): Node[] {
  if (!node || typeof node !== 'object') {
    return acc;
  }
  const value = node as Node;
  if (predicate(value)) {
    acc.push(value);
  }
  for (const child of Object.values(value)) {
    collect(child, predicate, acc);
  }
  return acc;
}

/** 3열 그리드 입력 줄 — 좁은 폭 1열, 1024px 이상 3열 */
const GRID3_BASE = 'paytosspayments:grid paytosspayments:grid-cols-1 paytosspayments:gap-4 paytosspayments:items-start';
const GRID3_WIDE = 'paytosspayments:grid paytosspayments:grid-cols-3 paytosspayments:gap-4 paytosspayments:items-start';

/** 노드의 1024px 이상 구간(`1024-`) 클래스 */
function wideClassNameOf(node: Node | undefined): string {
  const responsive = (node?.responsive ?? {}) as Record<string, { props?: Record<string, unknown> }>;
  const value = responsive['1024-']?.props?.className;
  return typeof value === 'string' ? value : '';
}

function classNameOf(node: Node | undefined): string {
  const props = (node?.props ?? {}) as Record<string, unknown>;
  return typeof props.className === 'string' ? props.className : '';
}

/** `lg:col-span-*` 를 쓰는 2열 폼 필드 행 (토글 단독 행인 field_test_mode 는 제외). */
const GRID_FIELD_IDS = [
  'field_test_client_key',
  'field_test_secret_key',
  'field_live_client_key',
  'field_live_secret_key',
  'field_redirect_success_url',
  'field_redirect_fail_url',
];

describe('plugin_settings 시맨틱 클래스 매핑', () => {
  describe('필드 행 컨테이너', () => {
    // @scenario case=semantic_c01
    // @effects plugin_settings_semantic_classes_in_place
    it.each(GRID_FIELD_IDS)('%s 는 grid-3col-responsive 를 가져야 한다', (id) => {
      const [node] = collect(pluginSettingsLayout, (n) => n.id === id);
      expect(node).toBeDefined();
      expect({ base: classNameOf(node), wide: wideClassNameOf(node) }).toEqual({ base: GRID3_BASE, wide: GRID3_WIDE });
    });

    // @scenario case=semantic_c02
    // @effects plugin_settings_semantic_classes_in_place
    it('자식의 lg:col-span-* 가 유효하도록 부모가 그리드 컨테이너여야 한다', () => {
      for (const id of GRID_FIELD_IDS) {
        const [parent] = collect(pluginSettingsLayout, (n) => n.id === id);
        const children = (parent?.children ?? []) as Node[];
        const spans = children.flatMap((c) => [classNameOf(c), wideClassNameOf(c), String(((c.responsive ?? {}) as Record<string, { props?: Record<string, unknown> }>).desktop?.props?.className ?? '')]).filter((c) => c.includes('col-span'));

        // col-span 을 쓰는 자식이 있다면 부모는 반드시 그리드여야 한다.
        if (spans.length > 0) {
          expect({ base: classNameOf(parent), wide: wideClassNameOf(parent) }).toEqual({ base: GRID3_BASE, wide: GRID3_WIDE });
        }
      }
    });
  });

  describe('섹션 제목 H3', () => {
    // @scenario case=semantic_c03
    // @effects plugin_settings_semantic_classes_in_place
    it('모든 section_* 제목은 section-heading-md 를 가져야 한다', () => {
      const sectionHeadings = collect(
        pluginSettingsLayout,
        (n) => n.name === 'H3' && typeof n.text === 'string' && n.text.startsWith('$t:sirsoft-tosspayments.settings.section_'),
      );

      // 테스트키 · 라이브키 · 리다이렉트 · 결제방식(S4) · 가상계좌(S4) = 5개
      expect(sectionHeadings).toHaveLength(5);
      for (const heading of sectionHeadings) {
        expect(classNameOf(heading)).toBe(`${NS}section-heading-md`);
      }
    });
  });

  describe('검증 에러 영역', () => {
    // @scenario case=semantic_c04
    // @effects plugin_settings_semantic_classes_in_place
    it('에러 제목 H3 는 text-error-strong 을 가져야 한다', () => {
      const [errorHeading] = collect(
        pluginSettingsLayout,
        (n) => n.name === 'H3' && n.text === '$t:admin.plugins.settings.validation_error',
      );

      expect(errorHeading).toBeDefined();
      expect(classNameOf(errorHeading)).toBe(`${NS}text-error-strong`);
    });

    // @scenario case=semantic_c05
    // @effects plugin_settings_semantic_classes_in_place
    it('에러 메시지 Li 는 text-error-soft 를 가져야 한다 (그리드 클래스 금지)', () => {
      const items = collect(pluginSettingsLayout, (n) => n.name === 'Li');

      expect(items.length).toBeGreaterThan(0);
      for (const item of items) {
        expect(classNameOf(item)).toBe(`${NS}text-error-soft`);
      }
    });
  });

  // @scenario case=semantic_c06
  // @effects plugin_settings_semantic_classes_in_place
  it('타이포그래피 시맨틱 클래스가 그리드 역할로 오용되지 않는다', () => {
    const misused = collect(
      pluginSettingsLayout,
      (n) => n.id !== undefined && /^field_/.test(String(n.id)) && classNameOf(n) === `${NS}section-heading-md`,
    );

    expect(misused).toHaveLength(0);
  });
});
