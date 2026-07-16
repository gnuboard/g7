/**
 * matchStateScope.ts — 현재 편집 대상에 매칭되는 states 그룹 필터
 *
 * `editor-spec.json.states.groups` 의 각 그룹은 `scope`(라우트 path / base 식별자 /
 * modal id) 단위로 선언된다. 본 유틸은 편집기 도메인 상태(`editMode` + `selectedRoute`)
 * 로부터 현재 편집 대상의 scope 기준을 도출하고, 그 기준에 매칭되는 그룹의 items 만
 * 골라 캔버스 툴바(PageStateSwitcher)에 노출한다.
 *
 * 매칭 정책("scope.match 일치 정책"):
 *  - kind: route — 라우트 path 와 정확 일치 또는 glob 1단계(`*` 세그먼트). path param
 *    토큰(`:slug`)은 그대로 매칭 키로 사용(샘플 시뮬레이션이므로 URL 채움값과 별개).
 *  - kind: base  — 베이스 레이아웃 식별자(`_user_base` 등). base 편집 모드에서 적용.
 *  - kind: modal — 호스트 레이아웃 modals[].id. 모달 편집 모드에서 적용.
 *
 * 편집기 selectedRoute.path 인코딩(ayoutEditorContext):
 *  - route          → 실제 path (예: `/admin/users`)
 *  - base           → `__base__/{layoutName}`
 *  - modal          → `__modal__/{modalId}`
 *  - extension → `__extension__/{extensionId}` — **호스트 scope 로 매칭**.
 *    확장이 주입되는 호스트 레이아웃의 라우트 path 를 `hostRoutePath` 로 받아 그 호스트의
 *    route scope states 를 적용한다(게이트/모달 뒤 확장 조각을 상태 시뮬레이션으로 노출).
 *    호스트 path 미상이면 null(디그레이드 — 폴백 안내).
 *  - iteration_item → `__iteration__/{sourcePath}` (states scope 미지원 — 디그레이드)
 *
 * @since engine-v1.50.0
 * @since engine-v1.50.0
 */

import type { EditMode } from '../LayoutEditorContext';
import type {
  EditorStateGroupSpec,
  EditorStateItemSpec,
  EditorStateScopeSpec,
} from '../spec/specTypes';

/** 현재 편집 대상에서 도출한 scope 기준 */
export interface CurrentStateScope {
  kind: 'route' | 'base' | 'modal';
  match: string;
}

/**
 * 편집기 도메인 상태로부터 현재 편집 대상의 scope 기준을 도출한다.
 *
 * route/base/modal 만 states scope 를 가질 수 있다. extension 모드는 그 확장이
 * 주입되는 **호스트 레이아웃의 라우트 path**(`hostRoutePath`)로 route scope 를 도출한다
 * 호스트 path
 * 미상이면 null. iteration_item, 또는 라우트 미선택 시도 `null` 을 반환해 디그레이드한다.
 *
 * @param editMode 현재 편집 모드
 * @param selectedRoutePath selectedRoute.path (가상 path 인코딩 포함)
 * @param hostRoutePath extension 모드에서 호스트 레이아웃의 라우트 path (옵션 — 비파괴).
 *   route/base/modal 모드는 무시. extension 모드에서만 route scope 도출에 사용.
 * @return scope 기준 또는 null(디그레이드)
 */
export function deriveCurrentScope(
  editMode: EditMode,
  selectedRoutePath: string | null | undefined,
  hostRoutePath?: string | null,
): CurrentStateScope | null {
  if (editMode === 'extension') {
    // 확장 편집 — 호스트 라우트 path 로 route scope 도출. 호스트 path 미상이면 디그레이드.
    // (호스트가 base 레이아웃이면 호출부가 `__base__/` path 를 넘겨 base scope 로 매칭 가능.)
    if (typeof hostRoutePath === 'string' && hostRoutePath.length > 0) {
      const baseName = stripPrefix(hostRoutePath, '__base__/');
      if (baseName) return { kind: 'base', match: baseName };
      return { kind: 'route', match: hostRoutePath };
    }
    return null;
  }

  if (!selectedRoutePath) return null;

  switch (editMode) {
    case 'route':
      return { kind: 'route', match: selectedRoutePath };
    case 'base': {
      // `__base__/{layoutName}` → base 식별자만 추출
      const name = stripPrefix(selectedRoutePath, '__base__/');
      return name ? { kind: 'base', match: name } : null;
    }
    case 'modal': {
      // `__modal__/{modalId}` → modal id 만 추출
      const id = stripPrefix(selectedRoutePath, '__modal__/');
      return id ? { kind: 'modal', match: id } : null;
    }
    // iteration_item 은 states scope 비대상 — 디그레이드
    default:
      return null;
  }
}

/**
 * scope 기준에 매칭되는 그룹들의 items 를 평탄화해 반환한다.
 *
 * - 같은 scope 그룹이 둘 이상이면(확장 네임스페이스 병합) items 를 concat.
 * - default 항목이 둘 이상이거나 없으면 본 함수는 그대로 반환하고, 기본 선택 판정은
 *   `resolveDefaultStateId` 가 수행(첫 default 또는 첫 항목).
 * - 매칭 그룹 없음 → 빈 배열(디그레이드 — 토글 미표시).
 *
 * @param groups editor-spec.json.states.groups (병합 결과)
 * @param scope 현재 편집 대상 scope 기준
 * @return 매칭된 상태 items
 */
export function matchStateItems(
  groups: EditorStateGroupSpec[] | undefined,
  scope: CurrentStateScope | null,
): EditorStateItemSpec[] {
  if (!scope || !Array.isArray(groups) || groups.length === 0) return [];

  const items: EditorStateItemSpec[] = [];
  for (const group of groups) {
    if (matchScope(group.scope, scope)) {
      for (const item of group.items ?? []) {
        if (item && typeof item.id === 'string' && item.id.length > 0) {
          items.push(item);
        }
      }
    }
  }
  return items;
}

/**
 * 매칭된 items 중 기본 진입 상태의 id 를 결정한다.
 *
 * `default: true` 인 첫 항목, 없으면 첫 항목의 id. items 가 비면 null.
 *
 * @param items 매칭된 상태 items
 * @return 기본 상태 id 또는 null
 */
export function resolveDefaultStateId(items: EditorStateItemSpec[]): string | null {
  if (items.length === 0) return null;
  const def = items.find((i) => i.default === true);
  return def ? def.id : items[0].id;
}

/**
 * 그룹 scope 와 현재 scope 기준의 일치 여부.
 *
 * kind 가 같아야 하며, route 는 glob 1단계(`*`)까지 허용, base/modal 은 정확 일치.
 *
 * @param groupScope 그룹에 선언된 scope
 * @param current 현재 편집 대상 scope 기준
 * @return 일치 여부
 */
function matchScope(
  groupScope: EditorStateScopeSpec | undefined,
  current: CurrentStateScope,
): boolean {
  if (!groupScope || groupScope.kind !== current.kind) return false;
  const pattern = groupScope.match;
  if (typeof pattern !== 'string' || pattern.length === 0) return false;

  if (current.kind === 'route') {
    return matchRoutePattern(current.match, pattern);
  }
  // base / modal — 정확 일치
  return pattern === current.match;
}

/**
 * 라우트 path 매칭 — 정확 일치 또는 `*` 세그먼트 glob 1단계.
 *
 * `*` 는 한 path 세그먼트(슬래시 미포함)에 대응한다. `:param` path 토큰은 리터럴로
 * 취급한다(스펙 작성자가 토큰을 그대로 적은 경우 정확 일치).
 *
 * @param path 현재 라우트 path
 * @param pattern scope.match 패턴
 * @return 일치 여부
 */
function matchRoutePattern(path: string, pattern: string): boolean {
  if (pattern === path) return true;
  if (!pattern.includes('*')) return false;
  // 각 세그먼트의 정규식 특수문자를 이스케이프하되 `*` 만 `[^/]+` 로 치환.
  const escaped = pattern
    .replace(/[.+?^${}()|[\]\\]/g, '\\$&')
    .replace(/\*/g, '[^/]+');
  return new RegExp(`^${escaped}$`).test(path);
}

/** path 접두사 제거 헬퍼 — 접두사로 시작하지 않으면 빈 문자열 */
function stripPrefix(value: string, prefix: string): string {
  return value.startsWith(prefix) ? value.slice(prefix.length) : '';
}
