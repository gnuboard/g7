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

  /** @effects extension_component_requires_identifier_prefix */
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

  /** @effects extension_component_requires_identifier_prefix */
  it('프리픽스 없는 이름은 거부된다 (템플릿 컴포넌트 덮어쓰기 방지)', () => {
    const registry = registryFor('sirsoft-basic');

    const registered = registry.registerExtensionComponent('ProductCard', Base, meta('ProductCard'));

    expect(registered).toBe(false);
    expect(registry.hasComponent('ProductCard')).toBe(false);
  });

  /** @effects template_override_wins_over_extension_self_registration */
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

  /** @effects core_selects_the_variant_matching_the_active_template */
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

  /** @effects core_selects_the_variant_matching_the_active_template */
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

  /** @effects variant_choice_is_independent_of_registration_order */
  it('전용 판본이 기본 판본을 이긴다 (전용 → 기본 순서)', () => {
    const registry = registryFor('sirsoft-basic');
    const name = 'sirsoft-ecommerce.ProductCard';

    expect(registry.registerExtensionComponent(name, Variant, meta(name), { forTemplate: 'sirsoft-basic' })).toBe(true);
    expect(registry.registerExtensionComponent(name, Base, meta(name))).toBe(false);

    expect(registry.getMetadata(name)?.name).toBe(name);
    expect(registry.hasComponent(name)).toBe(true);
  });

  /** @effects variant_choice_is_independent_of_registration_order */
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

/**
 * 템플릿 오버라이드 런타임 경로 가드.
 *
 * 마크업 태그가 방출하는 좌표는 `{확장식별자}.{이름}` 하나뿐이다. 대체본이 그 좌표에 정확히
 * 들어앉지 못하면 아무도 안 보는 키에 등록되고, 확장 원본이 경고 한 줄 없이 그대로 렌더된다
 * — 이 규약이 막으려던 바로 그 침묵이다. `exportName` 은 번들 전역의 평면성을 피하는 로컬
 * 별칭일 뿐 좌표가 아니며, 미선언 시 `name` 으로 폴백해 결함이 잠복하므로 별칭이 실제로
 * 쓰이는 경우를 직접 잠근다.
 */
describe('ComponentRegistry.registerTemplateOverrides', () => {
  beforeEach(() => {
    ComponentRegistry.resetInstance();
  });

  /**
   * 템플릿 번들 로딩을 네트워크 없이 재현합니다.
   *
   * `loadComponents()` 는 매니페스트를 fetch 하지만, 오버라이드 등록은 그 뒤의
   * `registerComponentsFromManifest` 가 전담하므로 매니페스트만 세우고 그 경로를 직접 탄다.
   */
  async function loadTemplateBundle(
    registry: ComponentRegistry,
    overrides: any[],
    module: Record<string, unknown>
  ): Promise<void> {
    (registry as any).manifest = {
      templateId: (registry as any).templateId,
      version: '1.0.0',
      components: { basic: [], composite: [], layout: [] },
      overrides,
    };

    await (registry as any).registerComponentsFromManifest(module);
  }

  /** @effects template_override_registers_at_the_extension_coordinate */
  it('export 별칭을 써도 대체본은 확장의 좌표에 등록된다', async () => {
    const registry = registryFor('sirsoft-basic');

    await loadTemplateBundle(
      registry,
      [{ target: 'sirsoft-ecommerce', name: 'ProductCard', exportName: 'EcommerceProductCard', type: 'composite' }],
      { EcommerceProductCard: Variant }
    );

    // 마크업 태그가 방출하는 좌표
    expect(registry.hasComponent('sirsoft-ecommerce.ProductCard')).toBe(true);
    // 별칭이 섞인 유령 좌표는 존재해선 안 된다
    expect(registry.hasComponent('sirsoft-ecommerce.EcommerceProductCard')).toBe(false);
  });

  /** @effects template_override_wins_over_extension_self_registration */
  it('별칭으로 대체한 좌표도 확장의 자가등록이 되덮지 못한다', async () => {
    const registry = registryFor('sirsoft-basic');
    const name = 'sirsoft-ecommerce.ProductCard';

    await loadTemplateBundle(
      registry,
      [{ target: 'sirsoft-ecommerce', name: 'ProductCard', exportName: 'EcommerceProductCard', type: 'composite' }],
      { EcommerceProductCard: Variant }
    );

    // 확장 번들은 이보다 나중에 실행된다 (TemplateApp :468 → :687).
    expect(registry.registerExtensionComponent(name, Base, meta(name))).toBe(false);
    expect(registry.getComponent(name)).not.toBeNull();
    expect((registry as any).appliedExtensionChoice.has(name)).toBe(false);
  });

  /** @effects template_override_registers_at_the_extension_coordinate */
  it('exportName 미선언이면 name 으로 번들을 조회한다', async () => {
    const registry = registryFor('sirsoft-basic');

    await loadTemplateBundle(
      registry,
      [{ target: 'sirsoft-ecommerce', name: 'ProductCard', type: 'composite' }],
      { ProductCard: Variant }
    );

    expect(registry.hasComponent('sirsoft-ecommerce.ProductCard')).toBe(true);
  });

  /** @effects template_override_missing_implementation_falls_back_to_original */
  it('번들에 대체본 구현이 없으면 확장 원본이 그대로 쓰인다', async () => {
    const registry = registryFor('sirsoft-basic');
    const name = 'sirsoft-ecommerce.ProductCard';

    await loadTemplateBundle(
      registry,
      [{ target: 'sirsoft-ecommerce', name: 'ProductCard', exportName: 'EcommerceProductCard', type: 'composite' }],
      {} // 템플릿이 선언만 하고 구현을 안 내보냈다
    );

    expect(registry.hasComponent(name)).toBe(false);
    // 대체가 성립하지 않았으므로 확장의 원본을 막아서는 안 된다.
    expect(registry.registerExtensionComponent(name, Base, meta(name))).toBe(true);
    expect(registry.hasComponent(name)).toBe(true);
  });
});

/**
 * 편집기 격리 인스턴스 가드.
 *
 * 확장 IIFE 는 호스트 페이지 부팅 때 한 번만 실행된다. 레이아웃 편집기는 그 **뒤에** 격리
 * 인스턴스를 만들어 다른 템플릿을 렌더하므로, 선언을 인스턴스에만 담아 두면 캔버스에서
 * 확장 컴포넌트가 조용히 사라진다. 이 블록이 그 회귀를 잠근다.
 */
describe('ComponentRegistry.applyExtensionComponents (편집기 격리 인스턴스)', () => {
  beforeEach(() => {
    ComponentRegistry.resetInstance();
  });

  /** @effects isolated_registry_created_after_extension_boot_still_gets_extension_components */
  it('확장 등록 뒤에 만들어진 격리 인스턴스도 확장 컴포넌트를 갖는다', () => {
    const host = registryFor('sirsoft-admin_basic');
    const name = 'sirsoft-ecommerce.ProductCard';
    host.registerExtensionComponent(name, Base, meta(name));

    // 확장 IIFE 는 이미 끝났다. 편집기가 이제서야 격리 인스턴스를 만든다.
    const canvas = registryFor('sirsoft-basic');
    expect(canvas.hasComponent(name)).toBe(false);

    expect(canvas.applyExtensionComponents()).toBe(1);
    expect(canvas.hasComponent(name)).toBe(true);
  });

  /** @effects host_and_canvas_resolve_variants_independently */
  it('호스트와 캔버스가 서로 다른 판본을 고른다 (싱글톤 결과 복사가 아님)', () => {
    const name = 'sirsoft-ecommerce.ProductCard';

    // 호스트는 admin 템플릿 — user 전용 판본은 호스트에서 채택되지 않는다.
    const host = registryFor('sirsoft-admin_basic');
    host.registerExtensionComponent(name, Base, meta(name));
    host.registerExtensionComponent(name, Variant, meta(name), { forTemplate: 'sirsoft-basic' });

    expect((host as any).appliedExtensionChoice.get(name)).toBe(Base);

    // 캔버스는 user 템플릿을 편집한다 — 같은 선언에서 전용 판본을 골라야 한다.
    const canvas = registryFor('sirsoft-basic');
    canvas.applyExtensionComponents();

    expect((canvas as any).appliedExtensionChoice.get(name)).toBe(Variant);
  });

  /** @effects edited_template_override_wins_in_the_canvas */
  it('편집 대상 템플릿이 오버라이드한 이름은 확장 판본이 물러난다', () => {
    const name = 'sirsoft-ecommerce.ProductCard';
    const host = registryFor('sirsoft-admin_basic');
    host.registerExtensionComponent(name, Base, meta(name));

    const canvas = registryFor('sirsoft-basic');
    canvas.markOverriddenByTemplate([name]);
    canvas.applyExtensionComponents();

    expect(canvas.hasComponent(name)).toBe(false);
  });

  /** @effects applying_extension_components_twice_is_idempotent */
  it('두 번 호출해도 결과가 같다 (재적용 안전)', () => {
    const name = 'sirsoft-ecommerce.ProductCard';
    registryFor('sirsoft-admin_basic').registerExtensionComponent(name, Base, meta(name));

    const canvas = registryFor('sirsoft-basic');
    expect(canvas.applyExtensionComponents()).toBe(1);
    expect(canvas.applyExtensionComponents()).toBe(1);
    expect(canvas.hasComponent(name)).toBe(true);
  });

  it('다른 템플릿 전용 판본만 있으면 이 인스턴스에는 반영하지 않는다', () => {
    const name = 'sirsoft-ecommerce.ProductCard';
    const host = registryFor('sirsoft-admin_basic');
    host.registerExtensionComponent(name, Variant, meta(name), { forTemplate: 'sirsoft-basic' });

    const other = registryFor('gnuboard7-hello_user_template');
    expect(other.applyExtensionComponents()).toBe(0);
    expect(other.hasComponent(name)).toBe(false);
  });
});

describe('ComponentRegistry.getMissingExtensionComponents', () => {
  /** @effects missing_extension_components_are_observable */
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
