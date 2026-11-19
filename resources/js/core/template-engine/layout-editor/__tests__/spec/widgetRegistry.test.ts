/**
 * widgetRegistry.test.ts — 위젯 레지스트리 + 코어 위젯 등록
 */

import { describe, it, expect, beforeEach } from 'vitest';
import {
  registerWidget,
  getWidget,
  getRegisteredWidgetNames,
  clearWidgetRegistry,
} from '../../spec/widgetRegistry';
import {
  registerCoreWidgets,
  resetCoreWidgetRegistration,
  isCoreWidgetsRegistered,
} from '../../spec/registerCoreWidgets';

// 더미 위젯 — 컴포넌트 함수 식별만 검증
const Dummy = (): null => null;

describe('widgetRegistry — 등록/조회', () => {
  beforeEach(() => {
    clearWidgetRegistry();
    resetCoreWidgetRegistration();
  });

  // @scenario surface=registry
  // @effects widget_lookup_by_registered_name
  it('등록한 위젯을 이름으로 조회한다', () => {
    registerWidget('foo', Dummy);
    expect(getWidget('foo')).toBe(Dummy);
  });

  // @scenario surface=registry
  // @effects unregistered_widget_lookup_returns_null
  it('미등록 위젯은 null 을 반환한다', () => {
    expect(getWidget('nope')).toBeNull();
    expect(getWidget(undefined)).toBeNull();
  });

  // @scenario surface=registry
  // @effects widget_reregistration_overwrites
  it('같은 이름 재등록은 덮어쓴다', () => {
    const A = (): null => null;
    const B = (): null => null;
    registerWidget('w', A);
    registerWidget('w', B);
    expect(getWidget('w')).toBe(B);
  });
});

describe('registerCoreWidgets — Phase 4 위젯 7종', () => {
  beforeEach(() => {
    clearWidgetRegistry();
    resetCoreWidgetRegistration();
  });

  // @scenario surface=core_widgets
  // @effects core_widgets_all_registered
  it('7개 코어 위젯을 모두 등록한다', () => {
    registerCoreWidgets();
    for (const name of ['segmented', 'slider', 'select', 'toggle', 'color', 'image', 'tag-input']) {
      expect(getWidget(name)).toBeTruthy();
    }
    expect(getRegisteredWidgetNames()).toEqual(
      expect.arrayContaining(['segmented', 'slider', 'select', 'toggle', 'color', 'image', 'tag-input']),
    );
    expect(isCoreWidgetsRegistered()).toBe(true);
  });

  // @scenario surface=core_widgets
  // @effects number_widget_registered
  it('4-5 number 위젯을 등록한다 (미등록 시 「지원하지 않는 컨트롤」 폴백)', () => {
    // `widget:"number"` 컨트롤(예 Header 「탭 표시 게시판 수」)이 속성 모달에서 편집
    // 불가였던 원인. 레지스트리 등록만이 그 폴백을 없앤다.
    registerCoreWidgets();
    expect(getWidget('number')).toBeTruthy();
    expect(getRegisteredWidgetNames()).toContain('number');
  });

  // @scenario surface=core_widgets
  // @effects component_target_picker_widget_registered
  it('component-target-picker 위젯을 등록한다', () => {
    // 캔버스 컴포넌트 영역 picker. [로딩 화면] target/fallback·navigate transition_overlay_target·
    // 향후 요소 ID param 공용. editor-spec param widget 타입으로 어느 폼에서나 선언 가능.
    registerCoreWidgets();
    expect(getWidget('component-target-picker')).toBeTruthy();
    expect(getRegisteredWidgetNames()).toContain('component-target-picker');
  });

  // @scenario surface=core_widgets
  // @effects thumbnail_preset_widget_registered
  it('thumbnail-preset 위젯을 등록한다 (Img 「썸네일 규격」 컨트롤)', () => {
    // 두 템플릿 controls.json `imgThumbPreset` 이 이 이름을 가리킨다. 미등록이면 속성 탭에서
    // 「지원하지 않는 컨트롤」 폴백으로 떨어져 규격을 고를 수 없다.
    registerCoreWidgets();
    expect(getWidget('thumbnail-preset')).toBeTruthy();
    expect(getRegisteredWidgetNames()).toContain('thumbnail-preset');
  });

  // @scenario surface=core_widgets
  // @effects core_widget_registration_idempotent
  it('중복 호출은 멱등(재등록 no-op)', () => {
    registerCoreWidgets();
    const first = getWidget('color');
    registerCoreWidgets();
    expect(getWidget('color')).toBe(first);
  });
});
