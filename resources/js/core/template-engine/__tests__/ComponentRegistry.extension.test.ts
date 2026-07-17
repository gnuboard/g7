/**
 * 확장 컴포넌트 self-register 계약 가드 (S2.5 — D25)
 *
 * 확장은 자기 컴포넌트를 스스로 등록하고(핸들러와 같은 방식), 코어가 판정한다. 이 테스트가
 * 잠그는 계약은 넷이다:
 *
 *  1. **프리픽스 강제** — 확장 컴포넌트 이름에는 `{확장식별자}.` 가 필요하다. 없으면 템플릿
 *     컴포넌트를 덮어쓸 수 있다.
 *  2. **오버라이드 권한은 템플릿** — 템플릿이 대체한 이름은 확장이 되덮지 못한다. 확장 번들이
 *     레지스트리 로딩보다 나중에 실행되므로(TemplateApp :468 → :687) 이 가드가 없으면 확장의
 *     원본이 항상 이긴다.
 *  3. **판본 선택은 코어** — 확장은 `forTemplate` 으로 선언만 하고, 활성 템플릿과 대조하는
 *     판정은 코어가 한다. 확장이 스스로 분기하면 확장마다 규칙이 갈린다.
 *  4. **등록 순서 무관** — 기본 판본과 전용 판본을 어느 순서로 등록하든 결과가 같아야 한다.
 *     순서에 결과가 걸리면 그것 자체가 침묵 승자다.
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { ComponentRegistry, type ComponentMetadata } from '../ComponentRegistry';

const Base = () => null;
const Variant = () => null;

const meta = (name: string): ComponentMetadata => ({ name, type: 'composite' });

/**
 * 활성 템플릿이 정해진 레지스트리를 만듭니다.
 *
 * `templateId` 는 `loadComponents()` 가 매니페스트를 fetch 하며 세우는 값이라, 네트워크 없이
 * 판본 선택만 검사하려면 테스트가 직접 세워야 한다.
 */
function registryFor(templateId: string): ComponentRegistry {
  const registry = ComponentRegistry.createIsolatedInstance();
  (registry as any).templateId = templateId;
  return registry;
}

describe('ComponentRegistry.registerExtensionComponent', () => {
  beforeEach(() => {
    ComponentRegistry.resetInstance();
  });

  it('프리픽스 있는 이름으로 등록하면 그 이름으로 조회된다', () => {
    const registry = registryFor('sirsoft-basic');

    const registered = registry.registerExtensionComponent(
      'sirsoft-ecommerce.ProductCard',
      Base,
      meta('sirsoft-ecommerce.ProductCard')
    );

    expect(registered).toBe(true);
    expect(registry.hasComponent('sirsoft-ecommerce.ProductCard')).toBe(true);
  });

  it('프리픽스 없는 이름은 거부된다 (템플릿 컴포넌트 덮어쓰기 방지)', () => {
    const registry = registryFor('sirsoft-basic');

    const registered = registry.registerExtensionComponent('ProductCard', Base, meta('ProductCard'));

    expect(registered).toBe(false);
    expect(registry.hasComponent('ProductCard')).toBe(false);
  });

  it('템플릿이 오버라이드한 이름은 확장이 되덮지 못한다', () => {
    const registry = registryFor('sirsoft-basic');
    registry.markOverriddenByTemplate(['sirsoft-ecommerce.ProductCard']);

    const registered = registry.registerExtensionComponent(
      'sirsoft-ecommerce.ProductCard',
      Base,
      meta('sirsoft-ecommerce.ProductCard')
    );

    expect(registered).toBe(false);
    expect(registry.hasComponent('sirsoft-ecommerce.ProductCard')).toBe(false);
  });

  it('활성 템플릿과 맞는 전용 판본은 채택된다', () => {
    const registry = registryFor('sirsoft-basic');

    const registered = registry.registerExtensionComponent(
      'sirsoft-ecommerce.ProductCard',
      Variant,
      meta('sirsoft-ecommerce.ProductCard'),
      { forTemplate: 'sirsoft-basic' }
    );

    expect(registered).toBe(true);
    expect(registry.hasComponent('sirsoft-ecommerce.ProductCard')).toBe(true);
  });

  it('다른 템플릿용 판본은 무시된다 — 확장이 아니라 코어가 고른다', () => {
    const registry = registryFor('sirsoft-basic');

    const registered = registry.registerExtensionComponent(
      'sirsoft-ecommerce.ProductCard',
      Variant,
      meta('sirsoft-ecommerce.ProductCard'),
      { forTemplate: 'sirsoft-admin_basic' }
    );

    expect(registered).toBe(false);
    expect(registry.hasComponent('sirsoft-ecommerce.ProductCard')).toBe(false);
  });

  it('전용 판본이 기본 판본을 이긴다 (전용 → 기본 순서)', () => {
    const registry = registryFor('sirsoft-basic');
    const name = 'sirsoft-ecommerce.ProductCard';

    expect(registry.registerExtensionComponent(name, Variant, meta(name), { forTemplate: 'sirsoft-basic' })).toBe(true);
    expect(registry.registerExtensionComponent(name, Base, meta(name))).toBe(false);

    expect(registry.getMetadata(name)?.name).toBe(name);
    expect(registry.hasComponent(name)).toBe(true);
  });

  it('등록 순서가 바뀌어도 전용 판본이 이긴다 (기본 → 전용 순서)', () => {
    const registry = registryFor('sirsoft-basic');
    const name = 'sirsoft-ecommerce.ProductCard';

    expect(registry.registerExtensionComponent(name, Base, meta(name))).toBe(true);
    expect(registry.registerExtensionComponent(name, Variant, meta(name), { forTemplate: 'sirsoft-basic' })).toBe(true);

    expect(registry.hasComponent(name)).toBe(true);
  });

  it('clear() 는 오버라이드·판본 기록까지 되돌린다', () => {
    const registry = registryFor('sirsoft-basic');
    const name = 'sirsoft-ecommerce.ProductCard';

    registry.markOverriddenByTemplate([name]);
    registry.clear();
    (registry as any).templateId = 'sirsoft-basic';

    expect(registry.registerExtensionComponent(name, Base, meta(name))).toBe(true);
  });
});

describe('ComponentRegistry.getMissingExtensionComponents', () => {
  it('등록되지 않은 확장 컴포넌트만 보고한다 (번들 로드 실패 관측)', () => {
    const registry = registryFor('sirsoft-basic');
    registry.registerExtensionComponent('sirsoft-ecommerce.ProductCard', Base, meta('sirsoft-ecommerce.ProductCard'));

    const missing = registry.getMissingExtensionComponents([
      'sirsoft-ecommerce.ProductCard',
      'sirsoft-ecommerce.CartBadge',
      'Div',
    ]);

    // 템플릿 컴포넌트(Div)는 이 관측 대상이 아니다 — 매니페스트 경로가 따로 보고한다.
    expect(missing).toEqual(['sirsoft-ecommerce.CartBadge']);
  });
});
