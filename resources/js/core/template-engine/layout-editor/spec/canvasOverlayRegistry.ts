/**
 * canvasOverlayRegistry.ts — 캔버스 인플레이스 오버레이 레지스트리
 *
 * capability 의 일반 슬롯 `canvasOverlay: { kind, params }` 가 가리키는 `kind` 를
 * 실제 React 오버레이 컴포넌트로 매핑한다. `EditorCanvasOverlay` 가 선택/hover 노드의
 * `capability.canvasOverlay.kind` 로 본 레지스트리를 조회해 오버레이 레이어에 마운트하고
 * 측정 박스/콜백을 주입한다(코어는 메커니즘만 — 어포던스 시각/상호작용은 빌트인 또는
 * 템플릿이 정의). nodeEditorRegistry 와 동일한 kind-agnostic 모델.
 *
 * 코어 빌트인(table 인플레이스)도 `registerCoreEditors` 가 본 API 로 등록한다(특권
 * 분기 0). 템플릿이 `registerCanvasOverlay` 로 같은/새 kind 를 등록하면 그 편집기에서
 * 해당 kind 를 제공/대체한다. 미등록 kind 는 기존 코어 선택/삽입 오버레이로 디그레이드.
 *
 * 단계 0 은 본 레지스트리 프리미티브만 도입한다 — EditorCanvasOverlay 디스패치 배선과
 * 빌트인 TableInplaceOverlay 등록은 단계 3-b.
 *
 * @since engine-v1.50.0
 */

import type React from 'react';
import type { EditorNode } from '../utils/layoutTreeUtils';

/** 측정된 박스 좌표 (캔버스 오버레이 레이어 기준). */
export interface OverlayBox {
  top: number;
  left: number;
  width: number;
  height: number;
}

/**
 * 캔버스 오버레이 컴포넌트가 받는 공통 props.
 *
 * 코어가 `data-editor-path`/measure 로 측정한 노드 박스(`nodeBox`)와 셀/항목별 박스
 * (`cellBoxes`)를 주입하고, 오버레이는 그 좌표에 자기 어포던스(셀 +버튼, 거터 핸들
 * 등)를 렌더한 뒤 콜백으로 노드를 패치/삽입/삭제한다. `params` 는 capability 의
 * `canvasOverlay.params`(그 kind 소유의 불투명 객체)를 그대로 전달받는다.
 */
export interface CanvasOverlayProps {
  /** 편집 대상 노드(현재 패치 반영본) */
  node: EditorNode;
  /** capability `canvasOverlay.params` — 그 kind 오버레이가 해석하는 불투명 객체 */
  params?: Record<string, unknown>;
  /** 선택/hover 노드의 측정 박스(오버레이 레이어 기준 좌표) */
  nodeBox: OverlayBox;
  /** 셀/항목별 측정 박스(표 셀, 탭 헤더 등) — `data-editor-path` 단위 */
  cellBoxes?: Array<OverlayBox & { path: string }>;
  /**
   * 현재 선택된 셀/항목의 **표(노드) 기준 상대 path**(`children.a.children.b`). 코어 표준
   * 선택(실제 셀 노드 클릭)으로 셀이 선택됐을 때 주입된다. 오버레이는 이 path 로 선택 셀을
   * 식별해 병합/테두리 도구를 활성화한다(불투명 셀 클릭 레이어 없이 — 표 본체의 드래그/
   * 더블클릭 인라인 편집을 보존). null/미공급이면 표 자체 선택.
   */
  selectedCellPath?: string;
  /**
   * 캔버스 미리보기 색 스킴('light'|'dark') — 상단 미리보기 토글(`previewColorScheme`)에서
   * 파생. 오버레이의 색 컨트롤(라이트/다크 탭)의 **초기값**으로 쓰인다(로컬 토글은 독립).
   * 미공급이면 'light'.
   */
  colorScheme?: 'light' | 'dark';
  /** 다국어 해석 함수 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 노드 패치 — 호출자가 PATCH_LAYOUT 으로 캔버스 반영 */
  onPatchNode: (patched: EditorNode) => void;
  /** 자식 삽입 — 부모 path + 인덱스에 새 노드 삽입(코어 삽입 인프라 위임) */
  onInsertChild?: (newNode: EditorNode, parentPath: string, index: number) => void;
  /** 자식 삭제 — 대상 path 노드 제거 */
  onRemoveChild?: (path: string) => void;
  /**
   * 셀/항목 인라인 텍스트 편집 진입 요청 — 컨테이너(표) 기준 상대 path(`children.a.children.b`)
   * 를 받아 호스트(EditorCanvasOverlay)가 절대 path 로 변환해 엔진 인라인 편집을 연다. 단일
   * 클릭=선택+인라인. 미공급이면 no-op(인라인 편집 비지원 컨테이너).
   */
  onRequestInlineEdit?: (cellRelPath: string) => void;
}

/** 캔버스 오버레이 컴포넌트 타입 */
export type CanvasOverlayComponent = React.ComponentType<CanvasOverlayProps>;

/** 내부 레지스트리 — kind → 컴포넌트 */
const registry = new Map<string, CanvasOverlayComponent>();

/**
 * 캔버스 오버레이 등록. 같은 kind 재등록은 덮어쓰기(템플릿이 코어 빌트인을 대체할 수
 * 있도록 — 단, 코어 빌트인은 부팅 시 1회 등록).
 *
 * @param kind 핸들러 kind (`table`/신규 종류)
 * @param overlay 오버레이 컴포넌트
 */
export function registerCanvasOverlay(kind: string, overlay: CanvasOverlayComponent): void {
  registry.set(kind, overlay);
}

/**
 * 등록된 캔버스 오버레이 조회. 미등록이면 null(코어 디그레이드).
 *
 * @param kind 핸들러 kind
 * @return 오버레이 컴포넌트 또는 null
 */
export function getCanvasOverlay(kind: string | undefined): CanvasOverlayComponent | null {
  if (!kind) return null;
  return registry.get(kind) ?? null;
}

/** 등록된 kind 목록 (진단/테스트용) */
export function getRegisteredCanvasOverlayKinds(): string[] {
  return Array.from(registry.keys());
}

/** 레지스트리 초기화 (테스트 격리용) */
export function clearCanvasOverlayRegistry(): void {
  registry.clear();
}
