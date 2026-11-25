/**
 * @file deprecatedSemanticClassesMarked.test.ts
 * @description 레이아웃과 컴포넌트가 쓰지 않는 의미 클래스는 정의를 남긴 채 7.3.0 제거 예고 주석을 단다
 *
 * 관리자 템플릿 레이아웃, 템플릿 컴포넌트, 코어 화면 코드, 확장이 복사해 쓰는 사본(`{ns}-x`) 어디에서도 쓰지 않는 의미
 * 클래스 194종은 이번 출시에서 지우지 않고 폐지를 예고한다. 사이트가 편집기로 고쳐 저장한 레이아웃이나 사이트 CSS 가
 * 이 이름을 쓰고 있을 수 있어서다. 7.3.0 에서 정의를 지운다.
 *
 * 잠그는 것:
 *   - 목록의 클래스는 `src/styles/semantic.css` 에 정의가 남아 있고, 정의 규칙마다 바로 위 주석 묶음에 예고 표식이 있다.
 *   - 예고 표식은 목록 밖 클래스의 정의에 붙지 않는다(쓰는 클래스를 잘못 예고하지 않는다).
 *   - 번들 관리자 레이아웃의 클래스 값에 목록의 이름이 다시 들어오지 않는다(다시 쓰게 되면 목록에서 빼야 한다).
 *   - `.form-hint` 는 한 번만 정의되고, `.text-tertiary` 머리 주석이 적은 값이 정의 값과 같다.
 *
 * 빌드 CSS 가 아니라 원본을 읽는다. 표식은 주석이라 빌드 산출에 남지 않는다.
 *
 * @scenario-manifest tests/scenarios/admin-deprecated-semantic-classes-marked.yaml
 */

import fs from 'fs';
import path from 'path';
import { describe, expect, it } from 'vitest';

const TEMPLATE_DIR = path.resolve(__dirname, '../../..');
const SEMANTIC_CSS = path.join(TEMPLATE_DIR, 'src/styles/semantic.css');
const LAYOUT_DIR = path.join(TEMPLATE_DIR, 'layouts');
const MARKER = 'deprecated(7.3.0 제거 예정): 레이아웃/컴포넌트 미사용';

/** 폐지 예고 목록 (계열별) */
const DEPRECATED: Record<string, string[]> = {
  status_tone: [
    'bg-complete', 'bg-complete-light', 'bg-complete-solid', 'bg-error', 'bg-error-light', 'bg-error-solid',
    'bg-hold', 'bg-hold-light', 'bg-hold-solid', 'bg-info', 'bg-info-light', 'bg-info-solid', 'bg-progress',
    'bg-progress-light', 'bg-progress-solid', 'bg-ready', 'bg-ready-light', 'bg-ready-solid', 'bg-success',
    'bg-success-light', 'bg-success-solid', 'bg-warning', 'bg-warning-light', 'bg-warning-solid', 'border-complete',
    'border-error', 'border-hold', 'border-info', 'border-progress', 'border-ready', 'border-success',
    'border-warning', 'text-complete', 'text-complete-light', 'text-error', 'text-error-light', 'text-hold',
    'text-hold-light', 'text-info', 'text-info-light', 'text-progress', 'text-progress-light', 'text-ready',
    'text-ready-light', 'text-success', 'text-success-light', 'text-warning', 'text-warning-light',
  ],
  status_dot: [
    'status-active', 'status-error', 'status-inactive', 'status-pending',
  ],
  button_badge: [
    'badge-base', 'badge-size-md', 'badge-size-sm', 'btn-badge-indigo', 'btn-badge-pink', 'btn-base', 'btn-size-lg',
    'btn-size-md', 'btn-size-sm',
  ],
  form_control: [
    'form-success', 'input-success', 'input-w-auto', 'input-w-xl', 'select-composite-sm', 'select-w-lg',
    'select-w-md', 'select-w-sm', 'tag-input-container', 'textarea-lg',
  ],
  search_filter: [
    'filter-row', 'search-bar', 'search-bar-input', 'search-bar-reset', 'search-bar-select',
    'search-bar-select-custom',
  ],
  dropdown_select: [
    'dropdown-divider', 'dropdown-item-icon', 'multi-select', 'multi-select-count', 'multi-select-header',
    'multi-select-hint', 'multi-select-item', 'multi-select-items', 'multi-select-trigger', 'searchable-dropdown',
    'searchable-dropdown-check', 'searchable-dropdown-check-area', 'searchable-dropdown-items',
    'searchable-dropdown-message', 'searchable-dropdown-option-content', 'searchable-dropdown-option-description',
    'searchable-dropdown-search', 'searchable-dropdown-search-input', 'searchable-dropdown-trigger',
    'select-dropdown-inner', 'select-ghost', 'select-ghost-check', 'select-ghost-trigger', 'select-option-check',
  ],
  modal_toast: [
    'danger-confirm-footer', 'danger-confirm-title', 'danger-confirm-warning', 'danger-confirm-warning-list',
    'danger-confirm-warning-title', 'modal-body', 'modal-close', 'modal-description', 'modal-header', 'modal-lg',
    'modal-overlay', 'modal-xl', 'toast-icon', 'toast-item', 'toast-message',
  ],
  card_stats: [
    'card-account', 'card-account-default', 'card-account-delete', 'card-border', 'card-border-xl',
    'card-section-title', 'dashboard-panel', 'excel-card-creator', 'excel-card-creator-label', 'stats-card',
    'stats-icon-orange', 'stats-trend', 'stats-value', 'surface', 'surface-alt',
  ],
  list_tree_table: [
    'empty-state', 'empty-state-description', 'empty-state-icon', 'empty-state-title', 'kv-list-2', 'list-item',
    'list-item-hover', 'table-actions', 'table-desktop', 'table-mobile-cards', 'tree-children', 'tree-item-content',
    'tree-item-icon', 'tree-item-label', 'tree-level-1', 'tree-level-2', 'tree-level-3', 'tree-level-4',
  ],
  page_frame: [
    'action-buttons', 'admin-footer-row', 'admin-page-content-responsive', 'admin-page-content-responsive-fluid',
    'admin-page-content-viewport', 'admin-page-content-viewport-compact', 'content-area', 'content-panel',
    'info-row', 'page-actions', 'page-container', 'page-header', 'page-header-fullbleed', 'page-header-padded',
    'page-title', 'section-card', 'section-description', 'sticky-tab-nav-responsive', 'sub-tab-nav',
  ],
  layout_helper: [
    'col-span-1-lg', 'col-span-2-lg', 'flex-col-start', 'flex-row-between', 'flex-row-center', 'flex-row-end',
    'gap-lg', 'gap-md', 'grid-3col-responsive', 'grid-cols-1-lg-3', 'inline-flex-center', 'mb-sm', 'responsive-grid',
  ],
  icon_effect: [
    'border-light', 'disabled-state', 'focus-ring', 'focus-ring-inset', 'hover-bg', 'hover-bg-light', 'icon-2xl',
    'icon-action', 'icon-xl', 'icon-xs', 'shadow-card', 'shadow-dropdown', 'shadow-hover',
  ],
};

const ALL_DEPRECATED = new Set(Object.values(DEPRECATED).flat());

interface Rule {
  /** 규칙 머리(선택자 목록) */
  prelude: string;
  /** 규칙 본문 */
  body: string;
  /** 규칙 머리 바로 위에 붙은 주석 묶음(공백과 주석만 사이에 있는 것) */
  leadingComments: string[];
  /** 선택자마다 반드시 있어야 하는 클래스(함수형 의사 클래스 안은 제외) */
  required: string[][];
}

/**
 * 최상위 쉼표로 선택자 목록을 나눈다.
 *
 * @param prelude 규칙 머리
 * @returns 선택자 목록
 */
function splitSelectors(prelude: string): string[] {
  const out: string[] = [];
  let depth = 0;
  let cur = '';
  for (const c of prelude) {
    if (c === '(') depth += 1;
    if (c === ')') depth -= 1;
    if (c === ',' && depth === 0) {
      out.push(cur.trim());
      cur = '';
      continue;
    }
    cur += c;
  }
  out.push(cur.trim());

  return out.filter(Boolean);
}

/**
 * 선택자가 맞으려면 반드시 있어야 하는 클래스 이름. `:is()`·`:not()` 등 함수형 의사 클래스 안은 뺀다.
 *
 * @param selector 선택자 하나
 * @returns 클래스 이름 목록
 */
function requiredClasses(selector: string): string[] {
  let s = selector.replace(/\[[^\]]*\]/g, '');
  let prev = '';
  while (prev !== s) {
    prev = s;
    s = s.replace(/:(not|has|is|where)\([^()]*\)/g, '');
  }

  return [...s.matchAll(/\.(-?[_a-zA-Z][\w-]*)/g)].map((m) => m[1]);
}

/**
 * 규칙 머리 앞에 공백과 주석만 끼어 있는 연속 주석 묶음을 거꾸로 모은다.
 *
 * @param css 원본 CSS
 * @param pos 규칙 머리 시작 위치
 * @returns 주석 목록 (가까운 것부터)
 */
function commentsBefore(css: string, pos: number): string[] {
  const out: string[] = [];
  let head = css.slice(0, pos).trimEnd();
  while (head.endsWith('*/')) {
    const open = head.lastIndexOf('/*');
    if (open < 0) break;
    out.push(head.slice(open));
    head = head.slice(0, open).trimEnd();
  }

  return out;
}

/**
 * 원본 CSS 의 일반 규칙(at-rule 머리 제외)을 모은다. 주석은 같은 길이 공백으로 가려 위치를 보존한다.
 *
 * @param css 원본 CSS
 * @returns 규칙 목록
 */
function parseRules(css: string): Rule[] {
  const masked = css.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));
  const rules: Rule[] = [];
  let start = 0;
  for (let i = 0; i < masked.length; i += 1) {
    const c = masked[i];
    if (c === '{') {
      const raw = masked.slice(start, i);
      const lead = raw.length - raw.trimStart().length;
      const prelude = raw.trim();
      if (prelude && !prelude.startsWith('@')) {
        const end = masked.indexOf('}', i);
        rules.push({
          prelude,
          body: masked.slice(i + 1, end).trim(),
          leadingComments: commentsBefore(css, start + lead),
          required: splitSelectors(prelude).map(requiredClasses),
        });
      }
      start = i + 1;
    } else if (c === '}' || c === ';') {
      start = i + 1;
    }
  }

  return rules;
}

/**
 * 폐지 예고 대상 규칙인가 — 목록의 선택자마다 폐지 예고 클래스가 하나 이상 반드시 있어야 맞는다.
 *
 * @param rule 규칙
 * @returns 대상이면 true
 */
function isDeprecatedRule(rule: Rule): boolean {
  return rule.required.every((cls) => cls.some((c) => ALL_DEPRECATED.has(c)));
}

/**
 * 규칙 바로 위 주석 묶음에 예고 표식이 있는가.
 *
 * @param rule 규칙
 * @returns 표식이 있으면 true
 */
function hasMarker(rule: Rule): boolean {
  return rule.leadingComments.some((c) => c.includes(MARKER));
}

/**
 * 계열 하나의 클래스가 정의를 남기고 정의마다 예고 표식을 달았는지 확인한다.
 *
 * @param rules 원본 규칙 목록
 * @param family 계열 이름 (`DEPRECATED` 의 키)
 * @returns 없음
 */
function expectFamilyMarked(rules: Rule[], family: string): void {
  const names = DEPRECATED[family];
  expect(names, `${family} 목록`).toBeDefined();
  for (const name of names) {
    // 선택자 하나라도 이 클래스를 반드시 요구하는 규칙은 모두 이 클래스의 정의다(묶은 선택자 포함).
    const defs = rules.filter((r) => r.required.some((cls) => cls.includes(name)));
    expect(defs.length, `.${name} 정의가 semantic.css 에 남아 있다`).toBeGreaterThan(0);
    for (const def of defs) {
      expect(def.body, `.${name} 정의 본문 (${def.prelude})`).not.toBe('');
      expect(hasMarker(def), `.${name} 정의 바로 위 예고 표식 (${def.prelude})`).toBe(true);
    }
  }
}

/**
 * 관리자 레이아웃 JSON 의 클래스 값(className·classMap·classMerge 등 이름에 class 가 든 키 아래 문자열)에서 낱말을 모은다.
 * `{{ }}` 식 안 문자열 리터럴도 낱말 단위로 들어온다.
 *
 * @param dir 레이아웃 디렉터리
 * @returns 낱말과 처음 나온 파일
 */
function layoutClassWords(dir: string): Map<string, string> {
  const words = new Map<string, string>();
  const visit = (node: unknown, inClass: boolean, file: string): void => {
    if (typeof node === 'string') {
      if (!inClass) return;
      for (const w of node.match(/[A-Za-z_][\w-]*/g) ?? []) if (!words.has(w)) words.set(w, file);
      return;
    }
    if (Array.isArray(node)) {
      node.forEach((v) => visit(v, inClass, file));
      return;
    }
    if (node && typeof node === 'object') {
      for (const [k, v] of Object.entries(node)) visit(v, inClass || /class/i.test(k), file);
    }
  };
  const walk = (d: string): void => {
    for (const e of fs.readdirSync(d, { withFileTypes: true })) {
      const p = path.join(d, e.name);
      if (e.isDirectory()) walk(p);
      else if (e.name.endsWith('.json')) visit(JSON.parse(fs.readFileSync(p, 'utf8')), false, path.relative(dir, p));
    }
  };
  walk(dir);

  return words;
}

describe('미사용 의미 클래스 폐지 예고 (7.3.0 제거 예정)', () => {
  const css = fs.readFileSync(SEMANTIC_CSS, 'utf8');
  const rules = parseRules(css);

  /**
   * @scenario family=status_tone
   * @effects unused_semantic_class_marked_and_kept
   */
  it('상태 색 클래스(bg-/text-/border- 의 success·error 등)는 정의가 남고 예고 표식이 붙어 있다', () => {
    expectFamilyMarked(rules, 'status_tone');
  });

  /**
   * @scenario family=status_dot
   * @effects unused_semantic_class_marked_and_kept
   */
  it('상태 점 클래스(status-*)는 정의가 남고 예고 표식이 붙어 있다', () => {
    expectFamilyMarked(rules, 'status_dot');
  });

  /**
   * @scenario family=button_badge
   * @effects unused_semantic_class_marked_and_kept
   */
  it('버튼·배지 보조 클래스(btn-base·btn-size-*·badge-size-* 등)는 정의가 남고 예고 표식이 붙어 있다', () => {
    expectFamilyMarked(rules, 'button_badge');
  });

  /**
   * @scenario family=form_control
   * @effects unused_semantic_class_marked_and_kept
   */
  it('입력 칸 폭·상태 클래스(input-w-*·select-w-* 등)는 정의가 남고 예고 표식이 붙어 있다', () => {
    expectFamilyMarked(rules, 'form_control');
  });

  /**
   * @scenario family=search_filter
   * @effects unused_semantic_class_marked_and_kept
   */
  it('옛 검색 줄·필터 줄 클래스는 정의가 남고 예고 표식이 붙어 있다', () => {
    expectFamilyMarked(rules, 'search_filter');
  });

  /**
   * @scenario family=dropdown_select
   * @effects unused_semantic_class_marked_and_kept
   */
  it('드롭다운·다중 선택 클래스는 정의가 남고 예고 표식이 붙어 있다', () => {
    expectFamilyMarked(rules, 'dropdown_select');
  });

  /**
   * @scenario family=modal_toast
   * @effects unused_semantic_class_marked_and_kept
   */
  it('모달·위험 확인·토스트 클래스는 정의가 남고 예고 표식이 붙어 있다', () => {
    expectFamilyMarked(rules, 'modal_toast');
  });

  /**
   * @scenario family=card_stats
   * @effects unused_semantic_class_marked_and_kept
   */
  it('카드·통계 상자 클래스는 정의가 남고 예고 표식이 붙어 있다', () => {
    expectFamilyMarked(rules, 'card_stats');
  });

  /**
   * @scenario family=list_tree_table
   * @effects unused_semantic_class_marked_and_kept
   */
  it('목록·트리·표·빈 상태 클래스는 정의가 남고 예고 표식이 붙어 있다', () => {
    expectFamilyMarked(rules, 'list_tree_table');
  });

  /**
   * @scenario family=page_frame
   * @effects unused_semantic_class_marked_and_kept
   */
  it('페이지 머리·본문 틀 클래스는 정의가 남고 예고 표식이 붙어 있다', () => {
    expectFamilyMarked(rules, 'page_frame');
  });

  /**
   * @scenario family=layout_helper
   * @effects unused_semantic_class_marked_and_kept
   */
  it('배치 도우미 클래스(flex-row-*·responsive-grid·gap-md 등)는 정의가 남고 예고 표식이 붙어 있다', () => {
    expectFamilyMarked(rules, 'layout_helper');
  });

  /**
   * @scenario family=icon_effect
   * @effects unused_semantic_class_marked_and_kept
   */
  it('아이콘 크기·초점·그림자 효과 클래스는 정의가 남고 예고 표식이 붙어 있다', () => {
    expectFamilyMarked(rules, 'icon_effect');
  });

  /**
   * @effects deprecation_marker_only_on_listed_classes
   */
  it('예고 표식은 목록 밖 클래스의 정의에 붙지 않고, 표식 수는 대상 규칙 수와 같다', () => {
    const marked = rules.filter(hasMarker);
    const stray = marked.filter((r) => !isDeprecatedRule(r)).map((r) => r.prelude);
    expect(stray, '목록 밖 클래스 정의에 붙은 예고 표식').toEqual([]);
    const targets = rules.filter(isDeprecatedRule);
    expect(marked.length).toBe(targets.length);
    expect(css.split(MARKER).length - 1, '파일 안 표식 수').toBe(targets.length);
    expect(ALL_DEPRECATED.size).toBe(194);
  });

  /**
   * @effects deprecated_class_absent_from_bundled_layouts
   */
  it('번들 관리자 레이아웃의 클래스 값에 폐지 예고 클래스가 없다', () => {
    const words = layoutClassWords(LAYOUT_DIR);
    const used = [...ALL_DEPRECATED].filter((n) => words.has(n)).map((n) => `${n} (${words.get(n)})`);
    expect(used, '레이아웃이 다시 쓰기 시작한 폐지 예고 클래스 — 목록과 표식에서 뺀다').toEqual([]);
  });
});

describe('semantic.css 정의 정리', () => {
  const css = fs.readFileSync(SEMANTIC_CSS, 'utf8');
  const rules = parseRules(css);

  /**
   * @effects form_hint_defined_once
   */
  it('.form-hint 는 한 번만 정의되고 값은 text-xs gray-600 mt-1, 다크 gray-300 이다', () => {
    const defs = rules.filter((r) => r.prelude === '.form-hint');
    expect(defs).toHaveLength(1);
    expect(defs[0].body).toContain('@apply text-xs text-gray-600 mt-1');
    expect(defs[0].body).toContain('@apply dark:text-gray-300');
  });

  /**
   * @effects text_tertiary_comment_matches_definition
   */
  it('.text-tertiary 머리 주석이 적은 색 값이 정의 값과 같다', () => {
    const def = rules.find((r) => r.prelude === '.text-tertiary');
    expect(def).toBeDefined();
    expect(def!.body).toContain('text-xs text-gray-600');
    expect(def!.body).toContain('dark:text-gray-300');
    const comment = def!.leadingComments.join('\n');
    expect(comment).toContain('text-xs text-gray-600 dark:text-gray-300');
    expect(comment).not.toMatch(/`text-xs text-gray-500 dark:text-gray-400`/);
  });

  /**
   * @effects component_muted_text_uses_semantic_class
   */
  it('템플릿 컴포넌트는 보조 글자색을 인라인 짝(gray-500 + 다크 gray-400)으로 적지 않고 text-muted 를 쓴다', () => {
    const def = rules.find((r) => r.prelude === '.text-muted');
    expect(def).toBeDefined();
    expect(def!.body).toContain('text-gray-500');
    expect(def!.body).toContain('dark:text-gray-400');

    const componentDir = path.join(TEMPLATE_DIR, 'src/components');
    const files: string[] = [];
    const walk = (dir: string): void => {
      for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
          if (entry.name !== '__tests__') walk(full);
        } else if (entry.name.endsWith('.tsx')) {
          files.push(full);
        }
      }
    };
    walk(componentDir);
    // 모집단이 비면 아래 단정이 조용히 통과하므로 먼저 잰다
    expect(files.length).toBeGreaterThan(50);
    expect(files.some((f) => fs.readFileSync(f, 'utf8').includes('text-muted'))).toBe(true);

    const offenders: string[] = [];
    for (const file of files) {
      fs.readFileSync(file, 'utf8').split(/\r?\n/).forEach((line, index) => {
        // 같은 문자열 안에 두 토큰이 함께 있는 줄 — 주석 줄은 뺀다
        if (/^\s*(\/\/|\*|\/\*)/.test(line)) return;
        if (/(^|[\s'"`])text-gray-500(?=[\s'"`])/.test(line) && /(^|[\s'"`])dark:text-gray-400(?=[\s'"`])/.test(line)) {
          offenders.push(`${path.relative(TEMPLATE_DIR, file).replace(/\\/g, '/')}:${index + 1}`);
        }
      });
    }
    expect(offenders).toEqual([]);
  });
});
