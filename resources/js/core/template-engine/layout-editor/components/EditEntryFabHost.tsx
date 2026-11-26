/**
 * EditEntryFabHost.tsx
 *
 * 운영 화면(관리자·사용자 사이트 공통)에 편집 진입 단추를 붙이는 호스트 (설계 확정 2026-11-09).
 *
 * `template-engine.ts` 의 일반 렌더 트리(renderTemplate·updateTemplateData)가 모달 묶음 뒤 형제로 렌더한다.
 * 편집기 셸 분기는 이 트리를 그리지 않고, 편집기 미리보기 캔버스는 DynamicRenderer 를 직접 쓰므로 둘 다 이 호스트가 없다.
 *
 * 입력:
 * - 로그인·권한: AuthManager 상태 + 로그인 사용자 응답의 `can_edit_layouts`(서버가 편집기 API 가드와 같은 판정으로 싣는다).
 *   로그인 응답처럼 판정이 없는 사용자면 로그인 사용자 조회로 한 번 받아 온다(인증 상태는 바꾸지 않는다)
 * - 라우트: 코어 라우터 `match()` 로 찾은 **라우트 정의 path** — 편집기 트리가 그 값과 정확히 견준다
 * - 켜기/끄기: 환경설정 > 일반 「레이아웃 편집 단추」(`general.layout_edit_button_enabled`) — 렌더 트리가 `_global.settings`
 *   에서 읽어 `enabled` 로 넘긴다(7.2.0). 설정 저장이 `_global.settings` 를 바꾸면 루트 재렌더로 바로 따라간다
 * - 위치: 오른쪽 아래(7.2.0). 단추 자리에 걸친 오른쪽 옆 패널과 하단 고정 요소(하단 바, 토스트, 맨 위로 단추)를 찾아 비킨다
 *
 * 숨김: 설정 꺼짐·비로그인·권한 없음·편집기 주소·iframe 안(미리보기·임베드)·인쇄.
 *
 * 렌더 사이클 영향: 자체 useState 만 쓰고 전역 상태(_global·_local)를 읽거나 쓰지 않는다. 루트 재렌더에 따라 함께 다시 그려질 뿐
 * 루트 렌더를 일으키지 않는다.
 *
 * @since engine-v1.95.0
 */

import React, { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { AuthManager } from '../../../auth/AuthManager';
import { stripBasePrefix } from '../../../support/assetUrl';
import { getApiClient } from '../../../api/ApiClient';
import { EditEntryFab } from './EditEntryFab';
import {
  computeEditEntryFabOffset,
  loadLayoutEditAbility,
  hasLayoutEditAbilityField,
  isEmbeddedFrame,
  isSidePanelRect,
  readLayoutEditAbility,
  resolveCurrentRouteDefinitionPath,
  EDIT_ENTRY_FAB_GAP,
  EDIT_ENTRY_FAB_SIZE,
  type FabOffset,
  type FabRect,
  type RouteMatcherLike,
} from '../hooks/useEditEntryFab';

export interface EditEntryFabHostProps {
  /** 지금 부팅된 템플릿 식별자 — 편집기를 이 템플릿으로 연다 */
  templateIdentifier: string;
  /** 환경설정 「레이아웃 편집 단추」 — false 면 그리지 않는다(생략하면 켜짐, 7.2.0) */
  enabled?: boolean;
  /** 라우터 주입 — 시험용. 없으면 `window.__templateApp.getRouter()` */
  getRouter?: () => RouteMatcherLike | null | undefined;
  /** 인증 관리자 주입 — 시험용. 없으면 `AuthManager.getInstance()` */
  authManager?: Pick<AuthManager, 'getState' | 'on'> & Partial<Pick<AuthManager, 'getConfig'>>;
  /** 진입 함수 주입 — 시험용. 없으면 같은 탭 이동 */
  openWindow?: (url: string) => void;
}

interface AuthSnapshot {
  isAuthenticated: boolean;
  canEditLayouts: boolean;
  /** 사용자 객체에 서버 판정이 실려 있는지 — 없으면(로그인 응답) 한 번 받아 온다 */
  abilityKnown: boolean;
  uuid: string | null;
  type: 'admin' | 'user' | null;
}

function readAuth(manager: Pick<AuthManager, 'getState'>): AuthSnapshot {
  const s = manager.getState();
  return {
    isAuthenticated: s.isAuthenticated === true,
    canEditLayouts: readLayoutEditAbility(s.user),
    abilityKnown: hasLayoutEditAbilityField(s.user),
    uuid: typeof s.user?.uuid === 'string' ? s.user.uuid : null,
    type: s.type ?? null,
  };
}

function defaultRouter(): RouteMatcherLike | null {
  const app = typeof window !== 'undefined' ? (window as any).__templateApp : null;
  return app?.getRouter?.() ?? null;
}

/**
 * 요소에서 위로 올라가며 피할 상자를 찾는다 — 고정(fixed·sticky) 상자, 또는 오른쪽 옆 패널 모양 상자.
 * 단추 자신과 그 안은 뺀다.
 */
function findObstacleAncestor(el: Element | null, self: Element | null, viewport: { width: number; height: number }): FabRect | null {
  let cur: Element | null = el;
  while (cur && cur !== document.documentElement && cur !== document.body) {
    if (self && (cur === self || self.contains(cur))) return null;
    const pos = window.getComputedStyle(cur).position;
    const b = cur.getBoundingClientRect();
    const rect: FabRect = { left: b.left, top: b.top, right: b.right, bottom: b.bottom };
    if (pos === 'fixed' || pos === 'sticky') return { ...rect, pinned: true };
    if (isSidePanelRect(rect, viewport)) return { ...rect, pinned: false };
    cur = cur.parentElement;
  }
  return null;
}

/**
 * 단추 자리 몇 점에서 겹친 상자를 모은다. `elementsFromPoint` 라 화면 전체를 훑지 않는다.
 */
/**
 * 고정 단추의 `right` 기준이 되는 뷰포트 크기 — 세로 스크롤 막대를 뺀 폭이다(`innerWidth` 는 막대를 포함해 막대 위를 찍는다).
 */
function layoutViewport(): { width: number; height: number } {
  const doc = document.documentElement;
  return {
    width: doc?.clientWidth || window.innerWidth,
    height: window.innerHeight,
  };
}

function collectObstacles(probes: Array<[number, number]>, self: Element | null): FabRect[] {
  if (typeof document === 'undefined' || typeof document.elementsFromPoint !== 'function') return [];
  const viewport = layoutViewport();
  const seen = new Set<string>();
  const rects: FabRect[] = [];
  for (const [x, y] of probes) {
    for (const el of document.elementsFromPoint(x, y)) {
      const found = findObstacleAncestor(el, self, viewport);
      if (!found) continue;
      const key = `${found.left},${found.top},${found.right},${found.bottom}`;
      if (seen.has(key)) continue;
      seen.add(key);
      rects.push(found);
    }
  }
  return rects;
}

export function EditEntryFabHost(props: EditEntryFabHostProps): React.ReactElement | null {
  const manager = props.authManager ?? AuthManager.getInstance();
  const [auth, setAuth] = useState<AuthSnapshot>(() => readAuth(manager));
  const [offset, setOffset] = useState<FabOffset>({ right: EDIT_ENTRY_FAB_GAP, bottom: EDIT_ENTRY_FAB_GAP });
  const buttonRef = useRef<HTMLButtonElement | null>(null);

  // 로그인·로그아웃·부팅 확인(checkAuth) 결과를 따라간다
  useEffect(() => {
    const update = (): void => setAuth(readAuth(manager));
    update();
    const offChange = manager.on('authStateChange', update);
    const offLogout = manager.on('logout', update);
    return () => {
      offChange?.();
      offLogout?.();
    };
  }, [manager]);

  // 로그인 응답으로 세운 세션에는 판정이 없다 — 그 사용자에 대해 한 번만 받아 온다(로그인 뒤 SPA 이동)
  const [fetchedAbility, setFetchedAbility] = useState<{ uuid: string; can: boolean } | null>(null);
  // 묻는 중이거나 물은 사용자 — 인증 스냅샷이 새 객체로 바뀌어도 같은 사용자를 두 번 묻지 않는다
  const requestedUuidRef = useRef<string | null>(null);
  const mountedRef = useRef(true);
  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
    };
  }, []);
  // 로그인 세션 세대 — 로그아웃마다 올린다. 그 전에 나간 조회가 늦게 돌아와도 새 세션 판정으로 쓰지 않는다.
  const sessionGenerationRef = useRef(0);
  // 로그아웃하면 받아 온 판정과 「물은 사용자」를 비운다 — 새로고침 없이 같은 계정으로 다시 로그인했을 때
  // 그사이 바뀐 역할을 다시 받아야 한다(종전에는 예전 판정이 남았다)
  useEffect(() => {
    if (auth.isAuthenticated) return;
    sessionGenerationRef.current += 1;
    requestedUuidRef.current = null;
    setFetchedAbility(null);
  }, [auth.isAuthenticated]);
  useEffect(() => {
    if (!auth.isAuthenticated || auth.abilityKnown || !auth.uuid) return;
    if (requestedUuidRef.current === auth.uuid) return;
    requestedUuidRef.current = auth.uuid;
    const uuid = auth.uuid;
    const generation = sessionGenerationRef.current;
    const endpoint =
      manager.getConfig?.(auth.type ?? 'user')?.userEndpoint ?? (auth.type === 'admin' ? '/admin/auth/user' : '/auth/user');
    void loadLayoutEditAbility(endpoint, getApiClient().getToken()).then((can) => {
      if (mountedRef.current && generation === sessionGenerationRef.current) setFetchedAbility({ uuid, can });
    });
  }, [auth, manager]);

  const canEdit = auth.abilityKnown
    ? auth.canEditLayouts
    : fetchedAbility !== null && fetchedAbility.uuid === auth.uuid && fetchedAbility.can;
  const embedded = typeof window !== 'undefined' && isEmbeddedFrame(window);
  const visible = props.enabled !== false && !embedded && auth.isAuthenticated && canEdit;

  const relocate = useCallback((): void => {
    const btn = buttonRef.current;
    if (!btn || typeof window === 'undefined') return;
    const { width: vw, height: vh } = layoutViewport();
    const size = { width: btn.offsetWidth || EDIT_ENTRY_FAB_SIZE, height: btn.offsetHeight || EDIT_ENTRY_FAB_SIZE };
    // 기본 자리(오른쪽 아래)와 그 위·옆 몇 점 — 오른쪽 옆 패널과 하단 바를 찾는다
    const g = EDIT_ENTRY_FAB_GAP;
    const first = collectObstacles(
      [
        [vw - g - 1, vh - g - 1],
        [vw - g - size.width / 2, vh - g - size.height / 2],
        [vw - g - size.width + 1, vh - 2],
      ],
      btn,
    );
    let next = computeEditEntryFabOffset(first, { width: vw, height: vh }, size);
    if (next.right !== g) {
      // 옆으로 비킨 자리에서 하단 바를 한 번 더 찾는다
      const second = collectObstacles(
        [
          [vw - next.right - 1, vh - 2],
          [vw - next.right - size.width + 1, vh - 2],
        ],
        btn,
      );
      next = computeEditEntryFabOffset([...first, ...second], { width: vw, height: vh }, size);
    }
    setOffset((prev) => (prev.right === next.right && prev.bottom === next.bottom ? prev : next));
  }, []);

  // 루트가 다시 그려질 때(화면 전환·데이터 갱신)마다 다음 프레임에 자리를 다시 본다
  useLayoutEffect(() => {
    if (!visible || typeof window === 'undefined') return;
    const id = window.requestAnimationFrame(relocate);
    return () => window.cancelAnimationFrame(id);
  });

  // 창 크기와 늦게 붙는 고정 요소(토스트, 하단 바)를 따라간다 — 변경 폭주는 250ms 에 한 번으로 묶는다
  useEffect(() => {
    if (!visible || typeof window === 'undefined') return;
    let timer: ReturnType<typeof setTimeout> | null = null;
    const schedule = (): void => {
      if (timer !== null) return;
      timer = setTimeout(() => {
        timer = null;
        relocate();
      }, 250);
    };
    window.addEventListener('resize', schedule);
    // 사이드바 접힘처럼 DOM 변경 없이 끝나는 CSS 전환은 끝난 뒤 다시 본다
    document.addEventListener('transitionend', schedule, true);
    const observer =
      typeof MutationObserver !== 'undefined' ? new MutationObserver(schedule) : null;
    observer?.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'style'] });
    return () => {
      window.removeEventListener('resize', schedule);
      document.removeEventListener('transitionend', schedule, true);
      observer?.disconnect();
      if (timer !== null) clearTimeout(timer);
    };
  }, [visible, relocate]);

  if (!visible || typeof window === 'undefined') return null;

  // base-path:allow 라우트 대조용으로 접두사를 벗긴다(라우트 패턴은 접두사 없이 등록된다)
  const browserPathname = window.location.pathname;
  const routePath = resolveCurrentRouteDefinitionPath(
    (props.getRouter ?? defaultRouter)(),
    stripBasePrefix(browserPathname),
  );

  return (
    <EditEntryFab
      templateIdentifier={props.templateIdentifier}
      pathname={stripBasePrefix(browserPathname)}
      isAuthenticated={auth.isAuthenticated}
      hasLayoutEditPermission={canEdit}
      currentRoutePath={routePath}
      enabled={props.enabled}
      openWindow={props.openWindow}
      offset={offset}
      buttonRef={buttonRef}
    />
  );
}
