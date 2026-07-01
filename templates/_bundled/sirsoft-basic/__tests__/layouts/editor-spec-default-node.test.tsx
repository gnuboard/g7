/**
 * @file editor-spec-default-node.test.tsx
 * @description 레이아웃 편집기 팔레트 defaultNode 공통 디자인 className 회귀 테스트 (sirsoft-basic)
 *
 * 배경:
 *   팔레트에서 컴포넌트를 추가하면 editor-spec.json 의
 *   componentPalette.entries[name].defaultNode 가 깊은 복사되어 레이아웃 트리에 삽입된다.
 *   이전에는 defaultNode 가 inline style placeholder 수준이라, 추가된 요소가 템플릿의
 *   실제 Tailwind 디자인과 동떨어졌다. 본 작업에서 각 defaultNode.props.className 을
 *   이 템플릿 운영 레이아웃의 대표 패턴으로 채웠다.
 *
 * 본 테스트는:
 *   1. defaultNode 가 공통 디자인 className 을 보유함을 JSON 정합성으로 검증 (회귀 차단)
 *   2. Button defaultNode 가 variant/size 를 더 이상 사용하지 않고 className 만 씀을 검증
 *   3. composite defaultNode 가 디자인 className 강제 없이 샘플 props 를 유지함을 검증
 *   4. defaultNode 를 실제 레이아웃으로 렌더했을 때 className 이 DOM 에 적용됨을 검증
 */

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

// editor-spec 은 ce21ab9da 에서 블록 분할되어 root editor-spec.json 은 $include 참조만
// 갖는다(componentPalette 등은 별도 파일). 따라서 componentPalette 블록을 분할 파일에서
// 직접 import 한다(root 스텁의 componentPalette 는 문자열 경로라 .entries 가 없음).
import componentPalette from '../../editor-spec/componentPalette.json';

type DefaultNode = {
  type?: string;
  name?: string;
  props?: Record<string, any>;
  text?: string;
  children?: DefaultNode[];
};

const entries: Record<string, { defaultNode?: DefaultNode }> = (componentPalette as any)
  .entries;

/**
 * defaultNode 를 단일 컴포넌트로 감싼 최소 레이아웃을 만들어 렌더한다.
 */
function wrapAsLayout(node: DefaultNode) {
  return {
    name: 'editor-default-node-probe',
    components: [
      {
        ...node,
        id: 'probe',
      },
    ],
  };
}

afterEach(() => {
  // createLayoutTest 인스턴스별 cleanup 은 각 it 에서 수행
});

describe('sirsoft-basic editor-spec defaultNode 공통 디자인', () => {
  describe('JSON 정합성 — defaultNode className 보유', () => {
    // 디자인 className 이 반드시 적용되어야 하는 basic 컴포넌트
    const designBearingBasics = [
      'Button',
      'Input',
      'PasswordInput',
      'Textarea',
      'Select',
      'Checkbox',
      'Label',
      'H1',
      'H2',
      'H3',
      'H4',
      'P',
      'Span',
      'A',
      'Img',
      'Hr',
    ];

    it.each(designBearingBasics)('%s defaultNode 가 비어있지 않은 className 을 보유한다', (name) => {
      const node = entries[name]?.defaultNode;
      expect(node, `${name} entry 가 존재해야 함`).toBeDefined();
      const className = node?.props?.className;
      expect(typeof className).toBe('string');
      expect((className as string).trim().length).toBeGreaterThan(0);
    });

    it('className 토큰이 이 템플릿의 디자인 시스템(Tailwind) 토큰을 반영한다', () => {
      // 운영 레이아웃의 대표 패턴 — dark: variant 와 색/간격 유틸이 섞여 있어야 함
      const button = entries.Button?.defaultNode?.props?.className as string;
      expect(button).toContain('rounded-lg');
      expect(button).toContain('dark:');
      const input = entries.Input?.defaultNode?.props?.className as string;
      expect(input).toContain('border');
      expect(input).toContain('focus:');
    });
  });

  describe('Button — variant/size 제거, className 만 사용', () => {
    it('Button defaultNode 가 variant/size props 를 사용하지 않는다', () => {
      const props = entries.Button?.defaultNode?.props ?? {};
      expect(props.variant).toBeUndefined();
      expect(props.size).toBeUndefined();
      expect(typeof props.className).toBe('string');
      expect(props.type).toBe('button');
    });
  });

  describe('composite — 디자인 className 강제 없이 샘플 props 유지', () => {
    it('ProductCard defaultNode 가 샘플 product props 를 유지한다', () => {
      const node = entries.ProductCard?.defaultNode;
      expect(node?.type).toBe('composite');
      expect(node?.props?.product).toBeDefined();
      expect(node?.props?.product?.name).toBeTruthy();
    });

    it('HtmlContent defaultNode 가 composite + content/isHtml 키를 사용한다 (html 키 미사용)', () => {
      const node = entries.HtmlContent?.defaultNode;
      expect(node?.type).toBe('composite');
      expect(node?.props?.content).toBeTruthy();
      expect(node?.props?.isHtml).toBe(false);
      expect(node?.props?.html).toBeUndefined();
    });
  });

  describe('A단계 신규 entries — Form / Table 등록 + className', () => {
    it('Form defaultNode 가 등록되어 있고 자식(Label/Input/Button)을 동반한다', () => {
      const node = entries.Form?.defaultNode;
      expect(node?.name).toBe('Form');
      expect(node?.props?.className).toBeTruthy();
      const childNames = (node?.children ?? []).map((c) => c.name);
      expect(childNames).toEqual(expect.arrayContaining(['Label', 'Input', 'Button']));
    });

    it('Table defaultNode 가 등록되어 있고 table className 을 보유한다', () => {
      const node = entries.Table?.defaultNode;
      expect(node?.name).toBe('Table');
      expect(node?.props?.className).toContain('w-full');
    });
  });

  describe('실제 렌더링 — defaultNode className 이 DOM 에 적용됨', () => {
    // (DOM 태그, defaultNode 의 대표 className 일부)
    const renderCases: Array<[string, string]> = [
      ['Button', 'rounded-lg'],
      ['Input', 'border'],
      ['Label', 'font-medium'],
      ['H1', 'font-bold'],
    ];

    it.each(renderCases)('%s defaultNode 렌더 시 className "%s" 가 DOM 에 반영된다', async (name, token) => {
      const node = entries[name]?.defaultNode as DefaultNode;
      const registry = createMockComponentRegistryWithBasics();
      const t = createLayoutTest(wrapAsLayout(node), {
        templateId: 'sirsoft-basic',
        componentRegistry: registry,
        locale: 'ko',
      });
      const { container } = await t.render();
      const el = container.querySelector(`.${CSS.escape(token)}`);
      expect(el, `${name} 의 "${token}" className 요소가 렌더되어야 함`).not.toBeNull();
      t.cleanup();
    });
  });
});
