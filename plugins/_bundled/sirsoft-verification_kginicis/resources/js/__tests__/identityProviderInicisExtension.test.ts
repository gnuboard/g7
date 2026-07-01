/**
 * identity_provider_inicis.json (모달 Extension Point) 구조 검증.
 *
 * 본 회차에서 추가한 두 가지 회귀 차단 포인트:
 *  1. 모달 헤더 제목/설명에 `purpose === 'inicis.adult_verification'` 분기 두 쌍 존재
 *  2. if 표현식이 CLAUDE.md 규정 (`{{식}}` 한 쌍 안에 비교 식 전체) 부합
 *
 * 레이아웃 JSON 파일을 직접 읽어 구조를 단언하는 가벼운 단위 테스트.
 * 코어 DynamicRenderer 환경 셋업 없이도 회귀 차단 가능.
 */
import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

interface ComponentNode {
  type?: string;
  name?: string;
  if?: string;
  text?: string;
  children?: ComponentNode[];
  props?: Record<string, unknown>;
  comment?: string;
}

interface ExtensionPointSpec {
  extension_point: string;
  mode?: string;
  priority?: number;
  components: ComponentNode[];
}

function walk(node: ComponentNode, visit: (n: ComponentNode) => void): void {
  visit(node);
  if (Array.isArray(node.children)) {
    for (const child of node.children) walk(child, visit);
  }
}

describe('identity_provider_inicis Extension Point — 본 회차 회귀 차단', () => {
  const extensionPath = path.resolve(
    __dirname,
    '../../extensions/identity_provider_inicis.json',
  );

  const spec: ExtensionPointSpec = JSON.parse(fs.readFileSync(extensionPath, 'utf-8'));

  it('extension_point 매니페스트 기본 키 보유', () => {
    expect(spec.extension_point).toBe('identity_provider_ui:provider');
    expect(spec.mode).toBe('append');
    expect(Array.isArray(spec.components)).toBe(true);
  });

  it('purpose === inicis.adult_verification 분기를 가진 H3 와 P 노드가 각각 정확히 1개', () => {
    const adultBranches: { tag: string; text: string | undefined }[] = [];

    for (const root of spec.components) {
      walk(root, (n) => {
        if (
          typeof n.if === 'string' &&
          n.if.includes("=== 'inicis.adult_verification'") &&
          (n.name === 'H3' || n.name === 'P')
        ) {
          adultBranches.push({ tag: n.name, text: n.text });
        }
      });
    }

    expect(adultBranches).toHaveLength(2);
    expect(adultBranches.some((b) => b.tag === 'H3')).toBe(true);
    expect(adultBranches.some((b) => b.tag === 'P')).toBe(true);
  });

  it('purpose !== inicis.adult_verification 분기 (기본 본인인증 제목/설명) 도 H3 + P 각 1개', () => {
    const defaultBranches: { tag: string; text: string | undefined }[] = [];

    for (const root of spec.components) {
      walk(root, (n) => {
        if (
          typeof n.if === 'string' &&
          n.if.includes("!== 'inicis.adult_verification'") &&
          (n.name === 'H3' || n.name === 'P')
        ) {
          defaultBranches.push({ tag: n.name, text: n.text });
        }
      });
    }

    expect(defaultBranches).toHaveLength(2);
    expect(defaultBranches.some((b) => b.tag === 'H3')).toBe(true);
    expect(defaultBranches.some((b) => b.tag === 'P')).toBe(true);
  });

  it('성인인증 분기 텍스트가 modal.adult_title / modal.adult_description 다국어 키를 참조', () => {
    const texts: string[] = [];

    for (const root of spec.components) {
      walk(root, (n) => {
        if (
          typeof n.if === 'string' &&
          n.if.includes("=== 'inicis.adult_verification'") &&
          typeof n.text === 'string'
        ) {
          texts.push(n.text);
        }
      });
    }

    expect(texts).toContain('$t:sirsoft-verification_kginicis.modal.adult_title');
    expect(texts).toContain('$t:sirsoft-verification_kginicis.modal.adult_description');
  });

  it('기본 분기 텍스트가 modal.title / modal.description 다국어 키 참조 (회귀 차단)', () => {
    const texts: string[] = [];

    for (const root of spec.components) {
      walk(root, (n) => {
        if (
          typeof n.if === 'string' &&
          n.if.includes("!== 'inicis.adult_verification'") &&
          typeof n.text === 'string'
        ) {
          texts.push(n.text);
        }
      });
    }

    expect(texts).toContain('$t:sirsoft-verification_kginicis.modal.title');
    expect(texts).toContain('$t:sirsoft-verification_kginicis.modal.description');
  });

  it('모든 purpose 분기 if 표현식이 CLAUDE.md 규정 (식 전체를 `{{}}` 한 쌍에) 부합', () => {
    const purposeIfs: string[] = [];

    for (const root of spec.components) {
      walk(root, (n) => {
        if (typeof n.if === 'string' && n.if.includes('identityChallenge?.purpose')) {
          purposeIfs.push(n.if);
        }
      });
    }

    expect(purposeIfs.length).toBeGreaterThanOrEqual(4);

    for (const expr of purposeIfs) {
      // 식 전체가 한 쌍의 `{{...}}` 안에 있어야 함
      expect(expr.startsWith('{{')).toBe(true);
      expect(expr.endsWith('}}')).toBe(true);

      // 보간형 `{{x}} === 'y'` (`{{` 가 2번 이상 등장) 금지
      const openCount = (expr.match(/\{\{/g) || []).length;
      const closeCount = (expr.match(/\}\}/g) || []).length;
      expect(openCount).toBe(1);
      expect(closeCount).toBe(1);
    }
  });
});
