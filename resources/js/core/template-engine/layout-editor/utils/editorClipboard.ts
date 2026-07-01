/**
 * editorClipboard.ts — 편집기 노드 복사/잘라내기 버퍼
 *
 * 복사/잘라내기한 노드를 **sessionStorage** 에 직렬화해, 같은 탭에서 다른 레이아웃으로
 * 이동하거나 새로고침한 뒤에도 붙여넣기할 수 있게 한다(세션 유지 —
 * 탭 닫으면 소멸, 다른 탭과 격리). 노드는 EditorNode(평범한 직렬화 가능 객체)라 JSON 으로
 * 안전하게 저장한다(`__source` 등 내부 메타는 붙여넣기 시 제거 — 새 노드로 취급).
 *
 * 코어 편집기 유틸 — DOM/도메인 상태 비의존(순수 storage 래퍼).
 *
 * @since engine-v1.50.0
 */

import type { EditorNode } from './layoutTreeUtils';

const KEY = 'g7le.clipboard';

/** 클립보드 페이로드(직렬화). */
interface ClipboardPayload {
  node: EditorNode;
  /** 출처 표시(진단용) — 붙여넣기 동작엔 미사용. */
  copiedAt: number;
}

function safeStorage(): Storage | null {
  try {
    if (typeof window === 'undefined' || !window.sessionStorage) return null;
    return window.sessionStorage;
  } catch {
    return null; // 프라이버시 모드 등 — 클립보드 비활성(조용히)
  }
}

/** 내부 메타(`__source` 등 `__` 접두)와 편집기 전용 키를 제거한 노드 사본. */
function sanitizeForClipboard(node: EditorNode): EditorNode {
  const clone = JSON.parse(JSON.stringify(node)) as EditorNode;
  const strip = (n: EditorNode): void => {
    for (const k of Object.keys(n)) {
      if (k.startsWith('__')) delete (n as Record<string, unknown>)[k];
    }
    if (Array.isArray(n.children)) (n.children as EditorNode[]).forEach(strip);
  };
  strip(clone);
  return clone;
}

/**
 * 노드를 클립보드에 복사(잘라내기도 동일 — 삭제는 호출자가 별도 수행).
 *
 * @param node 복사 대상 노드
 * @param timestamp 복사 시각(테스트 결정성 위해 주입 가능; 미공급 시 0)
 */
export function writeClipboard(node: EditorNode, timestamp = 0): boolean {
  const s = safeStorage();
  if (!s) return false;
  try {
    const payload: ClipboardPayload = { node: sanitizeForClipboard(node), copiedAt: timestamp };
    s.setItem(KEY, JSON.stringify(payload));
    return true;
  } catch {
    return false;
  }
}

/** 클립보드 노드 읽기(없거나 손상 시 null). 매 읽기마다 새 복제본 반환(중복 붙여넣기 안전). */
export function readClipboard(): EditorNode | null {
  const s = safeStorage();
  if (!s) return null;
  try {
    const raw = s.getItem(KEY);
    if (!raw) return null;
    const payload = JSON.parse(raw) as ClipboardPayload;
    if (!payload || typeof payload.node !== 'object' || payload.node === null) return null;
    // 매번 새 복제본 — 연속 붙여넣기 시 같은 객체 참조 공유 방지.
    return JSON.parse(JSON.stringify(payload.node)) as EditorNode;
  } catch {
    return null;
  }
}

/** 클립보드 보유 여부(붙여넣기 활성 판정). */
export function hasClipboard(): boolean {
  const s = safeStorage();
  if (!s) return false;
  try {
    return !!s.getItem(KEY);
  } catch {
    return false;
  }
}

/** 클립보드 비우기(테스트/명시 초기화). */
export function clearClipboard(): void {
  const s = safeStorage();
  if (!s) return;
  try {
    s.removeItem(KEY);
  } catch {
    /* no-op */
  }
}
