/**
 * extensionPaletteComponents.ts — 확장이 팔레트에 등재한 자기 컴포넌트를 편집기에 잇는다
 *
 * 모듈·플러그인은 자기가 만든 컴포넌트(`{확장식별자}.{이름}`)를 자기 editor-spec 의
 * `componentPalette` 에 등재한다(docs/extension/extension-components.md 「팔레트 등재」).
 * 그런데 팔레트가 보여 줄 컴포넌트 목록의 원천은 편집 대상 템플릿의 components.json 하나였고,
 * 놓을 자리를 정하는 `nesting` 은 템플릿이 자기 컴포넌트 이름으로 쓴 규칙이라 확장 이름을 모른다.
 * 둘 다 확장 이름을 걸러 내서, 등재한 컴포넌트가 팔레트에 한 번도 나타나지 않았다.
 *
 *  - 목록: 등재한 확장의 components.json 에서 그 컴포넌트 항목을 읽어 등록 이름으로 바꿔 더한다.
 *    템플릿이 그 컴포넌트를 대체(`overrides`)했으면 대체본의 메타를 쓴다(화면에 그려지는 것이 대체본이다).
 *  - 자리: 확장 컴포넌트를 블록 하나로 보고, 템플릿이 일반 블록(`Div`)을 받는 자리에 함께 받는다.
 *    템플릿이 그 이름을 어느 컨테이너에 직접 적었으면 그 선언을 따르고 손대지 않는다.
 *
 * @since engine-v1.76.0
 */

import type { EditorSpec, NestingSpec, NestingContainerRule } from './specTypes';
import type { ComponentManifest, ComponentManifestEntry } from '../components/ComponentPalette';

/** 확장 컴포넌트를 놓을 자리를 빌려 오는 템플릿 컴포넌트 — 일반 블록 */
const BLOCK_PLACEMENT_PROXY = 'Div';

/**
 * 확장 컴포넌트 등록 이름인지 — 템플릿 컴포넌트 이름에는 점이 없다.
 *
 * 코어 레지스트리도 같은 기준으로 확장 등록을 받는다(`registerExtensionComponent`).
 *
 * @param name 컴포넌트 이름
 * @returns `{확장식별자}.{이름}` 형태면 true
 */
export function isExtensionComponentName(name: unknown): name is string {
  return typeof name === 'string' && name.indexOf('.') > 0 && !name.endsWith('.');
}

/**
 * 병합 스펙의 팔레트에 등재된 확장 컴포넌트 이름을 확장 식별자별로 모은다.
 *
 * `entries` 의 키와 `groups[].components` 를 모두 본다 — 묶음에만 적어도 팔레트 대상이다.
 *
 * @param spec 병합 스펙
 * @returns 확장 식별자 → 등록 이름 집합
 */
export function collectExtensionPaletteComponents(
  spec: EditorSpec | null | undefined,
): Map<string, Set<string>> {
  const byExtension = new Map<string, Set<string>>();
  const palette = spec?.componentPalette;
  if (!palette) return byExtension;

  const names: string[] = [
    ...Object.keys(palette.entries ?? {}),
    ...(palette.groups ?? []).flatMap((group) => group?.components ?? []),
  ];

  for (const name of names) {
    if (!isExtensionComponentName(name)) continue;
    const identifier = name.slice(0, name.indexOf('.'));
    const set = byExtension.get(identifier) ?? new Set<string>();
    set.add(name);
    byExtension.set(identifier, set);
  }

  return byExtension;
}

/**
 * 확장 컴포넌트에 놓을 자리를 준다 — 템플릿이 일반 블록(`Div`)을 받는 자리.
 *
 * 템플릿이 `Div` 를 끌어 놓을 수 있게 했으면 확장 컴포넌트도 끌어 놓을 수 있고, `Div` 를 자식으로
 * 받는 컨테이너면 확장 컴포넌트도 받는다. 템플릿이 그 이름을 어느 컨테이너의 `accepts` 에 직접
 * 적었으면 템플릿이 자리를 정한 것이므로 컨테이너 쪽은 손대지 않는다.
 *
 * nesting 이 없으면(템플릿이 놓을 자리 규칙을 두지 않음) 그대로 둔다 — 팔레트는 그때 모든 항목을 보여 준다.
 *
 * @param nesting 병합 nesting (템플릿 + 확장)
 * @param names 팔레트에 등재된 확장 컴포넌트 등록 이름
 * @returns 확장 컴포넌트 자리가 더해진 새 nesting. 더할 것이 없으면 입력을 그대로 돌려준다.
 */
export function withExtensionComponentPlacement(
  nesting: NestingSpec | undefined,
  names: Iterable<string>,
): NestingSpec | undefined {
  if (!nesting) return nesting;
  const candidates = Array.from(names).filter(isExtensionComponentName);
  if (candidates.length === 0) return nesting;

  const draggable = Array.isArray(nesting.draggable) ? [...nesting.draggable] : undefined;
  let changed = false;

  if (draggable && draggable.includes(BLOCK_PLACEMENT_PROXY)) {
    for (const name of candidates) {
      if (!draggable.includes(name)) {
        draggable.push(name);
        changed = true;
      }
    }
  }

  const containers = nesting.containers;
  let nextContainers: Record<string, NestingContainerRule> | undefined = containers;
  if (containers && typeof containers === 'object') {
    const declared = new Set<string>();
    for (const rule of Object.values(containers)) {
      for (const accepted of rule?.accepts ?? []) declared.add(accepted);
    }
    const placeable = candidates.filter((name) => !declared.has(name));

    if (placeable.length > 0) {
      nextContainers = {};
      for (const [containerName, rule] of Object.entries(containers)) {
        if (Array.isArray(rule?.accepts) && rule.accepts.includes(BLOCK_PLACEMENT_PROXY)) {
          nextContainers[containerName] = { ...rule, accepts: [...rule.accepts, ...placeable] };
          changed = true;
        } else {
          nextContainers[containerName] = rule;
        }
      }
    }
  }

  if (!changed) return nesting;

  return {
    ...nesting,
    ...(draggable ? { draggable } : {}),
    ...(nextContainers ? { containers: nextContainers } : {}),
  };
}

/** components.json 의 한 판 — 템플릿과 확장이 같은 모양을 쓴다 */
interface ManifestLike {
  components?: Record<string, ComponentManifestEntry[] | undefined>;
  overrides?: Array<Partial<ComponentManifestEntry> & { target?: string }>;
}

/**
 * 확장 components.json 의 항목을 팔레트 매니페스트 항목으로 바꾼다.
 *
 * 이름은 등록 이름(`{확장식별자}.{이름}`)으로 바꾸고, 팔레트에 등재한 것만 고른다. 템플릿
 * 매니페스트가 그 컴포넌트를 대체(`overrides`, `target` = 확장 식별자)했으면 대체본의 설명·속성을 쓴다.
 *
 * @param identifier 확장 식별자
 * @param extensionManifest 확장 components.json
 * @param registeredNames 팔레트에 등재된 등록 이름
 * @param templateManifest 편집 대상 템플릿 components.json (대체본 확인용)
 * @returns 팔레트 매니페스트 항목
 */
export function toExtensionManifestEntries(
  identifier: string,
  extensionManifest: unknown,
  registeredNames: Set<string>,
  templateManifest?: ComponentManifest | null,
): ComponentManifestEntry[] {
  const manifest = extensionManifest as ManifestLike | null;
  if (!manifest || typeof manifest !== 'object' || !manifest.components) return [];

  const overrides = new Map<string, Partial<ComponentManifestEntry>>();
  for (const override of (templateManifest as ManifestLike | null)?.overrides ?? []) {
    if (override?.target === identifier && typeof override.name === 'string') {
      overrides.set(`${identifier}.${override.name}`, override);
    }
  }

  const entries: ComponentManifestEntry[] = [];
  for (const [groupKey, list] of Object.entries(manifest.components)) {
    if (!Array.isArray(list)) continue;
    for (const entry of list) {
      if (!entry || typeof entry.name !== 'string') continue;
      const name = `${identifier}.${entry.name}`;
      if (!registeredNames.has(name)) continue;
      const override = overrides.get(name);
      entries.push({
        ...entry,
        ...(override
          ? {
              ...(override.description !== undefined ? { description: override.description } : {}),
              ...(override.props !== undefined ? { props: override.props } : {}),
            }
          : {}),
        name,
        type: override?.type ?? entry.type ?? groupKey,
      });
    }
  }

  return entries;
}

/**
 * 템플릿 매니페스트에 확장 항목을 더한 새 매니페스트.
 *
 * 항목은 자기 종류(basic/composite/layout) 칸에 붙는다. 템플릿에 같은 이름이 이미 있으면 템플릿 것을 둔다.
 *
 * @param manifest 템플릿 매니페스트
 * @param entries 확장 항목
 * @returns 합친 매니페스트 (더할 항목이 없으면 입력 그대로)
 */
export function mergeExtensionManifestEntries(
  manifest: ComponentManifest | null,
  entries: ComponentManifestEntry[],
): ComponentManifest | null {
  if (!manifest || entries.length === 0) return manifest;

  const existing = new Set<string>();
  for (const list of Object.values(manifest.components ?? {})) {
    if (Array.isArray(list)) list.forEach((entry) => existing.add(entry.name));
  }

  const components: NonNullable<ComponentManifest['components']> = { ...(manifest.components ?? {}) };
  let added = 0;
  for (const entry of entries) {
    if (existing.has(entry.name)) continue;
    const key = entry.type ?? 'composite';
    components[key] = [...(components[key] ?? []), entry];
    existing.add(entry.name);
    added++;
  }

  return added > 0 ? { ...manifest, components } : manifest;
}

export interface ExtensionPaletteManifestOptions {
  /** 활성 모듈 식별자 — 미지정 시 `window.G7Config.activeModules` */
  moduleIdentifiers?: string[];
  /** 활성 플러그인 식별자 — 미지정 시 `window.G7Config.activePlugins` */
  pluginIdentifiers?: string[];
  /** 확장 components.json URL — 서버 확장자 모드를 아는 호출자가 만든다 */
  urlFor: (kind: 'modules' | 'plugins', identifier: string) => string;
  /** fetch 주입 — 테스트용 */
  fetcher?: typeof fetch;
}

/**
 * window.G7Config 의 활성 확장 식별자 목록.
 *
 * @param key activeModules | activePlugins
 * @returns 식별자 배열
 */
function readActiveIdentifiers(key: 'activeModules' | 'activePlugins'): string[] {
  if (typeof window === 'undefined') return [];
  const list = (window as { G7Config?: Record<string, unknown> }).G7Config?.[key];
  if (!Array.isArray(list)) return [];
  return list
    .map((item) => (item as { identifier?: unknown } | null)?.identifier)
    .filter((id): id is string => typeof id === 'string' && id.length > 0);
}

/**
 * 팔레트에 컴포넌트를 등재한 확장의 components.json 을 받아 팔레트 매니페스트 항목을 만든다.
 *
 * 등재한 확장만 받는다(대부분의 확장은 컴포넌트를 등재하지 않아 요청이 없다). 모듈인지 플러그인인지는
 * 활성 목록으로 가린다. 받지 못한 확장은 건너뛴다 — 그 컴포넌트가 팔레트에 나오지 않을 뿐 편집기는 그대로다.
 *
 * @param spec 병합 스펙
 * @param templateManifest 편집 대상 템플릿 매니페스트 (대체본 확인용)
 * @param options 식별자·URL·fetch
 * @returns 팔레트 매니페스트 항목
 */
export async function loadExtensionPaletteManifestEntries(
  spec: EditorSpec | null | undefined,
  templateManifest: ComponentManifest | null,
  options: ExtensionPaletteManifestOptions,
): Promise<ComponentManifestEntry[]> {
  const byExtension = collectExtensionPaletteComponents(spec);
  if (byExtension.size === 0) return [];

  const fetcher = options.fetcher ?? (typeof fetch !== 'undefined' ? fetch : undefined);
  if (!fetcher) return [];

  const modules = new Set(options.moduleIdentifiers ?? readActiveIdentifiers('activeModules'));
  const plugins = new Set(options.pluginIdentifiers ?? readActiveIdentifiers('activePlugins'));

  const loaded = await Promise.all(
    Array.from(byExtension.entries()).map(async ([identifier, names]) => {
      const kind = modules.has(identifier) ? 'modules' : plugins.has(identifier) ? 'plugins' : null;
      if (!kind) return [];
      try {
        const response = await fetcher(options.urlFor(kind, identifier), {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        });
        if (!response.ok) return [];
        const body = await response.json().catch(() => null);
        return toExtensionManifestEntries(identifier, body, names, templateManifest);
      } catch {
        return [];
      }
    }),
  );

  return loaded.flat();
}
