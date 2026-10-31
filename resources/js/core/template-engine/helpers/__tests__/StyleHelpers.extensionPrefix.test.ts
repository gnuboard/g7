/**
 * 확장 CSS 접두 빌드 앞글자 해석 (engine-v1.86.0)
 *
 * 확장은 자기 CSS 를 `{letters}:utility`(예 `ecommerce:px-2`) 클래스로 공급한다. className 을 Tailwind
 * 이름으로 해석하는 코어 코드(`mergeClasses`)는 앞글자를 떼고 읽어야 확장 클래스를 템플릿 클래스와 같은
 * 뜻으로 다룬다. 앞글자 목록은 서버가 `G7Config.extensionClassPrefixes` 로 내려 준다 — 목록에 없는 첫 구간
 * (`md:`·`hover:`)은 변형으로 남긴다.
 */
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { splitExtensionClassPrefix, mergeClasses } from '../StyleHelpers';

describe('splitExtensionClassPrefix / mergeClasses — 확장 앞글자', () => {
  beforeEach(() => {
    (window as any).G7Config = { extensionClassPrefixes: ['ecommerce', 'board'] };
  });

  afterEach(() => {
    delete (window as any).G7Config;
  });

  // @scenario case=class_prefix_c01
  // @effects extension_class_prefix_read_as_template_class
  it('목록에 있는 앞글자만 떼고 변형은 남긴다', () => {
    expect(splitExtensionClassPrefix('ecommerce:w-44')).toEqual({ prefix: 'ecommerce:', rest: 'w-44' });
    expect(splitExtensionClassPrefix('ecommerce:md:hidden')).toEqual({ prefix: 'ecommerce:', rest: 'md:hidden' });
    expect(splitExtensionClassPrefix('md:w-44')).toEqual({ prefix: '', rest: 'md:w-44' });
    expect(splitExtensionClassPrefix('w-44')).toEqual({ prefix: '', rest: 'w-44' });
    expect(splitExtensionClassPrefix('unknown:w-44')).toEqual({ prefix: '', rest: 'unknown:w-44' });
  });

  // @scenario case=class_prefix_c02
  // @effects extension_class_prefix_read_as_template_class
  it('서버 목록이 없으면 아무것도 떼지 않는다', () => {
    delete (window as any).G7Config;
    expect(splitExtensionClassPrefix('ecommerce:w-44')).toEqual({ prefix: '', rest: 'ecommerce:w-44' });
  });

  // @scenario case=class_prefix_c03
  // @effects extension_class_prefix_read_as_template_class
  it('mergeClasses — 확장 클래스가 같은 속성의 템플릿 기본 클래스를 대체한다', () => {
    expect(mergeClasses('px-4 rounded-lg', 'ecommerce:px-2')).toBe('rounded-lg ecommerce:px-2');
  });

  // @scenario case=class_prefix_c04
  // @effects extension_class_prefix_read_as_template_class
  it('mergeClasses — 변형이 다르면 확장 앞글자가 있어도 충돌하지 않는다', () => {
    expect(mergeClasses('dark:bg-gray-800 bg-gray-50', 'ecommerce:bg-blue-50')).toBe('dark:bg-gray-800 ecommerce:bg-blue-50');
    expect(mergeClasses('hover:bg-gray-100', 'ecommerce:hover:bg-blue-100')).toBe('ecommerce:hover:bg-blue-100');
  });

  // 7.1.3 의 상위 그룹 덮기(engine-v1.83.0 CONFLICTING_CLASS_GROUPS)와 앞글자 해석이 만나는 자리 — 리베이스로
  // 처음 한 함수에 모였다. 앞글자 붙은 상위 그룹도 같은 변형의 하위 축을 덮고, 반대 방향은 덮지 않는다.
  // @scenario case=class_prefix_c05
  // @effects extension_class_prefix_read_as_template_class
  it('mergeClasses — 확장 앞글자 붙은 상위 그룹도 하위 축을 덮는다(반대 방향은 남긴다)', () => {
    expect(mergeClasses('px-4 pt-2 text-sm', 'ecommerce:p-0')).toBe('text-sm ecommerce:p-0');
    expect(mergeClasses('ecommerce:px-4 ecommerce:mt-2', 'm-0')).toBe('ecommerce:px-4 m-0');
    expect(mergeClasses('p-4', 'ecommerce:px-2')).toBe('p-4 ecommerce:px-2');
    expect(mergeClasses('dark:px-4 px-4', 'ecommerce:p-0')).toBe('dark:px-4 ecommerce:p-0');
  });
});
