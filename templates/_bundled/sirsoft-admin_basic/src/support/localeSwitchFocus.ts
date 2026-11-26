/**
 * 언어 전환 뒤 포커스 되돌리기 (8차 5부 실측)
 *
 * 언어 전환은 엔진을 다시 띄워 같은 화면을 새로 마운트한다(`TemplateApp.changeLocale` → `destroyTemplate` → 다시 그리기).
 * 그래서 언어 단추에 있던 포커스가 body 로 빠진다. 전환 중 언마운트될 때 단추(또는 지정한 영역)에 포커스가 있었으면
 * 엔진 인계 저장소(`G7Core.locale.handoff`, engine-v1.79.0)에 맡기고, 새로 마운트되면 같은 자리로 돌린다.
 * 그 사이 사용자가 다른 곳에 포커스를 두었으면(body 가 아니면) 건드리지 않는다.
 */
import { useEffect, useLayoutEffect, useRef, type RefObject } from 'react';

interface LocaleHandoff {
  isSwitching?: () => boolean;
  stash?: (key: string, value: unknown) => boolean;
  take?: <T = unknown>(key: string) => T | undefined;
}

const handoff = (): LocaleHandoff | undefined => (window as any).G7Core?.locale?.handoff;

/**
 * 언어 전환으로 다시 마운트된 뒤 포커스를 같은 단추로 돌린다.
 *
 * @param key 인스턴스를 가르는 키(부품 이름과 id). 같은 화면에서 겹치지 않게 한다
 * @param watchRef 포커스가 있는지 볼 영역(단추 자신이나 단추를 품은 감싸개)
 * @param getTarget 돌려줄 요소를 고른다(기본은 watchRef)
 */
export function useLocaleSwitchFocusReturn(
  key: string,
  watchRef: RefObject<HTMLElement | null>,
  getTarget?: () => HTMLElement | null
): void {
  // 마지막으로 이 영역에 포커스가 있었는가 — 요소가 문서에서 빠질 때 브라우저가 blur 를 주지 않는 경우가 있어 직접 센다
  const focusedRef = useRef(false);
  const targetRef = useRef(getTarget);
  targetRef.current = getTarget;

  useEffect(() => {
    const el = watchRef.current;
    if (!el) return;
    const onIn = () => {
      focusedRef.current = true;
    };
    const onOut = (event: FocusEvent) => {
      if (!el.contains(event.relatedTarget as Node | null)) focusedRef.current = false;
    };
    el.addEventListener('focusin', onIn);
    el.addEventListener('focusout', onOut);
    focusedRef.current = el.contains(document.activeElement);
    return () => {
      el.removeEventListener('focusin', onIn);
      el.removeEventListener('focusout', onOut);
    };
  }, [watchRef]);

  // 마운트: 맡겨 둔 값이 있고 포커스가 아무 데도 없으면(body) 같은 자리로 돌린다.
  // 언마운트: 전환 중이고 마지막 포커스가 이 영역이었으면 맡긴다 — 레이아웃 효과라 노드가 아직 문서에 있을 때 돈다
  useLayoutEffect(() => {
    if (handoff()?.take?.(key) === true) {
      const active = document.activeElement;
      if (!active || active === document.body) {
        (targetRef.current?.() ?? watchRef.current)?.focus();
      }
    }
    return () => {
      const store = handoff();
      if (store?.isSwitching?.() && focusedRef.current) store.stash?.(key, true);
    };
  }, [key, watchRef]);
}
