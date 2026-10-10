/**
 * 번들 레이아웃의 창(Modal)이 템플릿 Modal 이 선언한 속성만 쓰는지 검증한다.
 *
 * 쇼핑몰 관리자 창 17곳이 `description` 으로 설명 문구를 넘겼고 메인 배너 이탈 확인 창은
 * `titleIcon`·`titleIconColor` 를 넘겼지만, 관리자 템플릿 Modal 은 이 속성을 받지 않아 화면에
 * 한 번도 나온 적이 없었다(7.1.0 브라우저 실측, 2026-10-04 결정 1 로 레이아웃에서 걷어냄). 번역 키까지
 * 만들어 두고 화면에서는 사라지는 속성이라 다시 들어와도 눈으로는 찾을 수 없다.
 *
 * 허용 목록은 각 템플릿 `components.json` 의 Modal `props` 선언이다. 관리자 화면 레이아웃은
 * sirsoft-admin_basic, 그 밖은 sirsoft-basic 의 선언과 대조한다. `data-*` 와 `id` 는 화면 문구나
 * 모양을 바꾸지 않는 표지라 대상이 아니다.
 *
 * 감사 규칙 `component-unknown-prop` 은 변경된 마크업만, 정해 둔 4개 컴포넌트만 본다. 창은 두 템플릿의
 * 선언이 서로 달라(사용자 템플릿에만 `icon` 이 있다) 이 가드가 번들 전체를 템플릿별로 대조한다.
 *
 * 시나리오 매니페스트: tests/scenarios/bundled-modal-props-declared.yaml
 */
import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, resolve, relative, sep } from 'node:path';

const ROOT = resolve(process.cwd());

/** components.json 에서 이름이 같은 컴포넌트 선언을 찾는다. */
function findComponent(node: unknown, name: string): { props?: Record<string, unknown> } | null {
  if (Array.isArray(node)) {
    for (const child of node) {
      const found = findComponent(child, name);
      if (found) return found;
    }
  } else if (node && typeof node === 'object') {
    const record = node as Record<string, unknown>;
    if (record.name === name && record.props && typeof record.props === 'object') {
      return record as { props?: Record<string, unknown> };
    }
    for (const value of Object.values(record)) {
      const found = findComponent(value, name);
      if (found) return found;
    }
  }
  return null;
}

/** 템플릿이 선언한 Modal 속성 이름 집합을 읽는다. */
function declaredModalProps(templateDir: string): Set<string> {
  const manifest = JSON.parse(readFileSync(join(templateDir, 'components.json'), 'utf-8'));
  return new Set(Object.keys(findComponent(manifest, 'Modal')?.props ?? {}));
}

/**
 * 레이아웃 JSON 에서 선언되지 않은 Modal 속성을 모은다.
 *
 * @param layout 레이아웃 JSON
 * @param allowed 템플릿 Modal 이 선언한 속성 이름
 * @returns 선언되지 않은 속성 이름 목록(나온 순서)
 */
export function undeclaredModalProps(layout: unknown, allowed: Set<string>): string[] {
  const found: string[] = [];
  const visit = (node: unknown): void => {
    if (Array.isArray(node)) {
      node.forEach(visit);
      return;
    }
    if (!node || typeof node !== 'object') return;
    const record = node as Record<string, unknown>;
    if (record.name === 'Modal' && record.props && typeof record.props === 'object') {
      for (const key of Object.keys(record.props as Record<string, unknown>)) {
        if (key === 'id' || key.startsWith('data-')) continue;
        if (!allowed.has(key)) found.push(key);
      }
    }
    Object.values(record).forEach(visit);
  };
  visit(layout);
  return found;
}

/** 번들 디렉터리에서 layouts 아래 JSON 을 모은다. */
function collect(dir: string, out: string[]): void {
  for (const name of readdirSync(dir)) {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) {
      if (!/^(node_modules|__tests__|dist|tests|test-results)$/.test(name)) collect(path, out);
    } else if (path.endsWith('.json') && path.split(sep).includes('layouts')) {
      out.push(path);
    }
  }
}

/** 관리자 화면 레이아웃인지 경로로 판정한다(관리자 템플릿 자체, 확장의 admin 레이아웃). */
function isAdminLayout(path: string): boolean {
  const rel = relative(ROOT, path).split(sep).join('/');
  if (rel.startsWith('templates/_bundled/sirsoft-admin_basic/')) return true;
  const afterLayouts = rel.slice(rel.indexOf('/layouts/') + '/layouts/'.length);
  return afterLayouts.startsWith('admin/') || afterLayouts.startsWith('admin_');
}

describe('번들 레이아웃 창 속성 선언', () => {
  // @scenario template=admin_basic
  // @effects undeclared_modal_prop_is_reported
  it('선언되지 않은 속성을 찾아내고 data-* 와 id 는 넘긴다', () => {
    const allowed = new Set(['title', 'size', 'footer']);
    const layout = {
      components: [
        {
          name: 'Modal',
          props: { title: 't', size: 'md', description: 'd', 'data-testid': 'x', id: 'm' },
          children: [{ name: 'Modal', props: { titleIcon: 'alert-circle', footer: [] } }],
        },
        { name: 'Div', props: { description: 'not a modal' } },
      ],
    };

    expect(undeclaredModalProps(layout, allowed)).toEqual(['description', 'titleIcon']);
  });

  // @scenario template=admin_basic
  // @effects bundled_modals_use_only_declared_props
  // @scenario template=basic
  // @effects bundled_modals_use_only_declared_props
  it('번들 레이아웃의 모든 창이 템플릿 Modal 이 선언한 속성만 쓴다', () => {
    const adminAllowed = declaredModalProps(join(ROOT, 'templates/_bundled/sirsoft-admin_basic'));
    const userAllowed = declaredModalProps(join(ROOT, 'templates/_bundled/sirsoft-basic'));
    expect(adminAllowed.has('title'), '관리자 템플릿 Modal 선언을 읽지 못했습니다.').toBe(true);
    expect(userAllowed.has('title'), '사용자 템플릿 Modal 선언을 읽지 못했습니다.').toBe(true);

    const files: string[] = [];
    for (const base of ['templates/_bundled', 'modules/_bundled', 'plugins/_bundled']) collect(join(ROOT, base), files);
    expect(files.length, '검사 대상 레이아웃이 없습니다(경로 해석 확인).').toBeGreaterThan(100);

    const violations: string[] = [];
    for (const file of files) {
      let layout: unknown;
      try {
        layout = JSON.parse(readFileSync(file, 'utf-8'));
      } catch {
        continue;
      }
      const props = undeclaredModalProps(layout, isAdminLayout(file) ? adminAllowed : userAllowed);
      if (props.length) violations.push(`${relative(ROOT, file).split(sep).join('/')}: ${props.join(', ')}`);
    }

    expect(violations).toEqual([]);
  });
});
