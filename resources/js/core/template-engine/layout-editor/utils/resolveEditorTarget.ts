/**
 * resolveEditorTarget.ts — 캔버스 클릭/더블클릭 좌표에서 편집 대상 노드 탐색
 *
 * 배경(근본 원인): 반복 항목 편집 모드에서 개별 드래그 핸들(`g7le-dnd-handle-<path>`,
 * pointerEvents:auto)이 텍스트 노드 위를 완전히 덮으면, click/dblclick 의 `e.target` 이
 * 핸들(data-editor-path 없음)이 되어 `target.closest('[data-editor-path]')` 가 null →
 * 선택·인라인 텍스트 편집이 막힌다.
 *
 * 본 모듈은 3단계 폴백으로 편집 대상 노드를 찾는다:
 *  1. `e.target.closest('[data-editor-path]')` — 일반 경로(핸들이 안 덮은 경우).
 *  2. 핸들의 testid(`g7le-dnd-handle-<path>`)에서 path 를 역추출 → frame 안 실제 노드 질의.
 *     (핸들은 자신이 가리키는 노드 path 를 testid 에 보유 — 좌표 추측 없이 정확)
 *  3. 좌표 아래 DOM 스택(`elementsFromPoint`)에서 첫 `data-editor-path` 노드(다중 오버레이 대비).
 *
 * @since engine-v1.50.0
 */

/** 드래그 핸들 testid 접두사 — `g7le-dnd-handle-<editorPath>` */
export const DND_HANDLE_TESTID_PREFIX = 'g7le-dnd-handle-';

/**
 * `data-testid` 가 드래그 핸들 형식이면 거기 담긴 editorPath 를 추출한다.
 *
 * @param testid data-testid 값(없으면 null)
 * @returns editorPath 문자열 또는 null
 */
export function extractPathFromHandleTestId(testid: string | null | undefined): string | null {
  if (typeof testid !== 'string') return null;
  if (!testid.startsWith(DND_HANDLE_TESTID_PREFIX)) return null;
  const path = testid.slice(DND_HANDLE_TESTID_PREFIX.length);
  return path.length > 0 ? path : null;
}

/**
 * CSS attribute selector 안전 이스케이프 — `CSS.escape` 가 있으면 사용, 없으면(jsdom 일부)
 * 백슬래시 폴백. editorPath 는 영숫자/점만 포함하지만 방어적으로 처리한다.
 *
 * @param value 이스케이프할 문자열
 * @returns 이스케이프된 문자열
 */
function escapeForSelector(value: string): string {
  if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
    return CSS.escape(value);
  }
  return value.replace(/["\\\]]/g, '\\$&');
}

/**
 * 클릭/더블클릭 이벤트에서 편집 대상(`[data-editor-path]`) 노드를 찾는다.
 *
 * @param target 이벤트 target (보통 `e.target`)
 * @param frameEl 편집 캔버스 frame 루트(노드 역질의용)
 * @param point 좌표 폴백용 클릭 좌표(`{x: e.clientX, y: e.clientY}`) — 미지정 시 좌표 폴백 생략
 * @param options.handleFallback 드래그 핸들 testid → 내부 노드 역질의 폴백을 켤지. **반복 항목
 *   편집 모드(iteration_item/modal)에서만 true**. route(호스트) 모드는 false — 거기서 iteration
 *   묶음 핸들을 클릭하면 핸들 testid 가 내부 노드 path 를 가리켜 선택이 내부로 내려가, 반복 영역이
 *  **통짜로 선택되던 동작이 깨진다**. route 모드는 1차(closest)+3차(좌표)만 쓴다.
 * @returns 편집 대상 HTMLElement 또는 null
 */
export function resolveEditorTargetElement(
  target: EventTarget | null,
  frameEl: HTMLElement | null,
  point?: { x: number; y: number },
  options?: { handleFallback?: boolean },
): HTMLElement | null {
  const handleFallback = options?.handleFallback ?? false;
  if (target instanceof HTMLElement) {
    // 1차 — 직접 조상에 data-editor-path 가 있으면 그 노드.
    const direct = target.closest('[data-editor-path]') as HTMLElement | null;
    if (direct) return direct;

    // 어포던스 가드 — 클릭 대상이 편집기 어포던스 버튼(요소 추가 +, 속성 ⓘ, 부모
    // 선택 ↑ 등 `g7le-` testid 버튼/인터랙티브)이면 폴백을 적용하지 않고 null 반환해 그 버튼의
    // onClick 이 정상 동작하게 한다. handleFallback(좌표/핸들 역질의)이 켜진 반복 항목 편집 모드에서
    // 어포던스 버튼 위 클릭이 버튼 **아래** 캔버스 노드를 잡아 stopPropagation→버튼이 안 먹던 결함.
    // dnd 핸들(`g7le-dnd-handle-*`)은 폴백 대상이므로 가드에서 제외한다.
    const affordance = target.closest('[data-testid^="g7le-"]') as HTMLElement | null;
    if (
      affordance &&
      !(affordance.getAttribute('data-testid') ?? '').startsWith(DND_HANDLE_TESTID_PREFIX)
    ) {
      return null;
    }

    // 2차 — 드래그 핸들이면 testid 의 path 로 frame 안 실제 노드 역질의.
    // **iteration_item/modal 편집 모드 전용**(handleFallback). route 모드에서는 켜지 않는다
    if (handleFallback) {
      const handle = target.closest(
        `[data-testid^="${DND_HANDLE_TESTID_PREFIX}"]`,
      ) as HTMLElement | null;
      if (handle && frameEl) {
        const path = extractPathFromHandleTestId(handle.getAttribute('data-testid'));
        if (path) {
          const node = frameEl.querySelector(
            `[data-editor-path="${escapeForSelector(path)}"]`,
          ) as HTMLElement | null;
          if (node) return node;
        }
      }
    }
  }

  // 3차 — 좌표 아래 DOM 스택에서 첫 data-editor-path 노드(여러 겹 오버레이 대비).
  // 핸들 폴백과 동일하게 **iteration_item/modal 편집 모드 전용**(handleFallback). route 모드에서
  // 켜면 묶음 핸들 위 클릭이 좌표 아래 iteration **내부 노드**를 잡아 반복 영역 통짜 선택이 다시
  // 깨진다. route 모드는 1차(closest)만 — 이전 동작 보존.
  if (
    handleFallback &&
    point &&
    typeof document !== 'undefined' &&
    typeof document.elementsFromPoint === 'function'
  ) {
    for (const cand of document.elementsFromPoint(point.x, point.y)) {
      const node = (cand as HTMLElement).closest?.('[data-editor-path]') as HTMLElement | null;
      if (node) return node;
    }
  }

  return null;
}
