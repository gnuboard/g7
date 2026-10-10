/**
 * DynamicRenderer.hostPropLeak.test.tsx
 *
 * @scenario leak_path=extension_point_host, prop_shape=object
 * @effects extension_point_host_clean, extension_point_host_keeps_dom_standard_attributes, icon_not_autobound, unmarked_component_still_autobinds
 *
 * 호스트 DOM 으로 새면 안 되는 props 두 갈래를 잠근다.
 *
 * ① `extension_point` 컨테이너 — 선언된 props 는 주입된 자식이
 *    `{{extensionPointProps.xxx}}` 로 소비하는 페이로드다. 호스트 `<div>` 는 단순 래퍼이므로
 *    객체/배열 값이 DOM 속성으로 흘러 `[object Object]` 가 되면 안 된다.
 * ② Form 자동 바인딩 — 조건이 "`name` prop 존재 + 부모 dataKey" 뿐이라, `name` 이 폼 필드가
 *    아닌 컴포넌트(Icon 의 아이콘 이름 등)까지 잡아 `value` 를 주입한다. 매니페스트가
 *    `autoBinding: false` 를 선언하면 그 컴포넌트는 대상에서 빠져야 한다.
 *
 * 실측 배경 — 브라우저에서 확인된 두 누출:
 *   `<div id="address_search_slot" targetfields="[object Object]">` (쇼핑몰 설정)
 *   `<i class="fas fa-images" value="[object Object],[object Object]">` (상품 등록/수정)
 *
 * @since engine-v1.68.1
 */

import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';

const TestDiv: React.FC<any> = ({ className, children, ...rest }) => (
  <div className={className} {...rest}>
    {children}
  </div>
);

/** Icon 은 `<i>` 글리프 래퍼 — 받은 props 를 그대로 DOM 에 흘린다(실제 구현과 동일 형태). */
const TestIcon: React.FC<any> = ({ name, className, ...rest }) => (
  <i className={`fas fa-${name} ${className ?? ''}`.trim()} {...rest} />
);

describe('DynamicRenderer — 호스트 DOM prop 누출 차단', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  let translationContext: TranslationContext;

  beforeEach(() => {
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Div: {
        component: TestDiv,
        metadata: { name: 'Div', type: 'basic' },
      },
      Icon: {
        component: TestIcon,
        // 아이콘 이름은 폼 필드명이 아니다 — 자동 바인딩 대상에서 제외됨을 매니페스트가 선언한다.
        metadata: { name: 'Icon', type: 'basic', autoBinding: false },
      },
      Input: {
        component: (props: any) => <input {...props} />,
        metadata: { name: 'Input', type: 'basic' },
      },
    };

    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });
    translationContext = { templateId: 'test-template', locale: 'ko' };
  });

  const renderDef = (componentDef: ComponentDefinition, dataContext: any = {}) =>
    render(
      <DynamicRenderer
        componentDef={componentDef}
        dataContext={dataContext}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={bindingEngine}
        translationEngine={translationEngine}
        actionDispatcher={actionDispatcher}
      />
    );

  describe('① extension_point 컨테이너', () => {
    it('객체/배열 props 가 호스트 div 속성으로 새지 않는다', () => {
      const componentDef: ComponentDefinition = {
        id: 'address_search_slot',
        type: 'extension_point',
        name: 'address_search_slot',
        props: {
          'data-testid': 'ep-host',
          readOnlyFields: ['basic_info.zipcode', 'basic_info.base_address'],
          targetFields: {
            zipcode: 'form.basic_info.zipcode',
            address: 'form.basic_info.base_address',
          },
        },
      } as any;

      renderDef(componentDef);

      const el = screen.getByTestId('ep-host');
      expect(el.outerHTML).not.toContain('[object Object]');
      expect(el.getAttribute('targetfields')).toBeNull();
      expect(el.getAttribute('readonlyfields')).toBeNull();
    });

    it('원시값 페이로드도 호스트 속성으로 새지 않는다', () => {
      // `html_editor` 계열 확장점이 실제로 싣는 키들 — 전부 주입 자식이 소비하는 페이로드다.
      const componentDef: ComponentDefinition = {
        id: 'html_editor',
        type: 'extension_point',
        name: 'html_editor',
        props: {
          'data-testid': 'ep-primitive',
          content: '<p>본문</p>',
          isHtml: true,
          label: '내용',
          name: 'description',
          placeholder: '입력하세요',
          rows: 10,
          showHtmlModeToggle: true,
          multilingual: true,
          disabled: 'false',
          value: '초기값',
        },
      } as any;

      renderDef(componentDef);

      const el = screen.getByTestId('ep-primitive');
      for (const key of [
        'content', 'ishtml', 'label', 'name', 'placeholder',
        'rows', 'showhtmlmodetoggle', 'multilingual', 'disabled', 'value',
      ]) {
        expect(el.getAttribute(key)).toBeNull();
      }
    });

    it('DOM 표준 속성(data-*·aria-*·role·title)과 id·className 은 그대로 통과한다 (regression 가드)', () => {
      const componentDef: ComponentDefinition = {
        id: 'ep_passthrough',
        type: 'extension_point',
        name: 'ep_passthrough',
        props: {
          'data-testid': 'ep-pass',
          'data-slot': 'checkout',
          'aria-label': '확장 영역',
          role: 'region',
          title: '확장 영역 설명',
          className: 'wrapper',
        },
      } as any;

      renderDef(componentDef);

      const el = screen.getByTestId('ep-pass');
      expect(el.getAttribute('data-slot')).toBe('checkout');
      expect(el.getAttribute('aria-label')).toBe('확장 영역');
      expect(el.getAttribute('role')).toBe('region');
      expect(el.getAttribute('title')).toBe('확장 영역 설명');
      expect(el.className).toContain('wrapper');
      expect(el.id).toBe('ep_passthrough');
    });
  });

  describe('② Form 자동 바인딩 대상 제한', () => {
    /** dataKey 를 가진 부모 아래에 자식을 두어 FormContext 를 형성한다. */
    const formTree = (child: ComponentDefinition): ComponentDefinition =>
      ({
        id: 'form_root',
        type: 'basic',
        name: 'Div',
        dataKey: 'form',
        props: { 'data-testid': 'form-root' },
        children: [child],
      }) as any;

    const dataContext = {
      _local: {
        form: {
          images: [{ id: 1, url: '/a.png' }, { id: 2, url: '/b.png' }],
          title: '상품명',
        },
      },
    };

    it('`autoBinding: false` 컴포넌트는 name 이 폼 필드와 겹쳐도 value 를 받지 않는다', () => {
      renderDef(
        formTree({
          id: 'images_icon',
          type: 'basic',
          name: 'Icon',
          props: { name: 'images', 'data-testid': 'images-icon' },
        } as any),
        dataContext
      );

      const el = screen.getByTestId('images-icon');
      expect(el.outerHTML).not.toContain('[object Object]');
      expect(el.getAttribute('value')).toBeNull();
      // 아이콘 자체는 정상 렌더 — name 은 글리프 이름으로만 쓰인다.
      expect(el.className).toContain('fa-images');
    });

    it('일반 폼 컨트롤의 자동 바인딩은 그대로 동작한다 (regression 가드)', () => {
      renderDef(
        formTree({
          id: 'title_input',
          type: 'basic',
          name: 'Input',
          props: { name: 'title', 'data-testid': 'title-input' },
        } as any),
        dataContext
      );

      const el = screen.getByTestId('title-input') as HTMLInputElement;
      expect(el.value).toBe('상품명');
    });
  });
});
