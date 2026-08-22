/**
 * deviceList.ts — 편집기 디바이스 목록 동적 수집 SSoT
 *
 * 캔버스 상단 디바이스 토글(`DevicePreviewToolbar`)과 속성 모달 스타일 세부탭
 * (`StyleScopeTabs`)이 **같은 디바이스 목록을 공유**하도록 한 곳에서 도출한다
 *
 *
 * 목록 = 엔진 프리셋 4개(`desktop`/`tablet`/`mobile`/`portable`, 항상) + 그 레이아웃의
 * 모든 노드 `responsive` 키 중 위 4개에 없는 키(커스텀 범위 등). 중복 제거, 순서 고정
 * (프리셋 먼저 — 캔버스 폭 넓은 순, 그 뒤 커스텀 키 발견 순).
 *
 * 캔버스 폭 산출(상한값 규칙):
 *  - 단일폭 프리셋(`desktop`/`tablet`/`mobile`)은 기존 단일폭 상수(useDevicePreview)와 동일.
 *  - 범위/포괄 프리셋(`portable`=0~1023 → 1023, 커스텀 `"600-900"` → 900)은 **상한값**.
 *  - 무한대 범위(`desktop`=1024~∞, 커스텀 `"1200-"`)는 **가용 프리셋 중 가장 넓은 폭**
 *    (= 편집기 desktop 기본 폭, DESKTOP_PRESET_WIDTH).
 *
 * 프레임워크 비의존 — `g7le-*` 영역의 순수 유틸. ResponsiveManager 의 프리셋/파서만 의존.
 *
 * @since engine-v1.50.0
 */

import { responsiveManager, BREAKPOINT_PRESETS } from '../../ResponsiveManager';
import type { EditorNode } from '../utils/layoutTreeUtils';

/**
 * 엔진 프리셋 디바이스 키 — 항상 목록 맨 앞에 고정 노출(캔버스 폭 넓은 순).
 * desktop(1024+) → tablet(768~1023) → mobile(0~767) → portable(0~1023, 포괄).
 *
 * 순서: 단일폭 데스크톱/태블릿/모바일 다음에 포괄 프리셋 portable. portable 은 모바일+태블릿
 * 포괄이라 "한 벌로 좁은 화면 전체" 용도 — 단일폭 셋 뒤에 둔다(주류 키지만 의미상 포괄군).
 */
export const PRESET_DEVICE_KEYS: readonly string[] = ['desktop', 'tablet', 'mobile', 'portable'];

/**
 * 단일폭 프리셋 캔버스 폭(px) — useDevicePreview 의 DEVICE_WIDTH_PX 와 동일 SSoT.
 * 범위/무한대 키 폭 산출의 상·하한 기준으로도 쓴다.
 */
export const DESKTOP_PRESET_WIDTH = 1280;
export const TABLET_PRESET_WIDTH = 820;
export const MOBILE_PRESET_WIDTH = 390;

/** 폭 산출 결과의 안전 상한 — 무한대/과대 범위 클램프(useDevicePreview CUSTOM_WIDTH_MAX 와 동일). */
const WIDTH_CLAMP_MAX = 1920;
/** 폭 산출 결과의 안전 하한. */
const WIDTH_CLAMP_MIN = 320;

/**
 * 레이아웃 컴포넌트 트리를 깊이 순회하며 모든 노드의 `responsive` 키를 수집한다.
 *
 * base children 과 `responsive[*].children` 분기 children 을 **모두** 순회한다(분기 안 노드가
 * 또 다른 분기를 선언할 수 있으므로). 순서는 발견 순(트리 DFS) — 프리셋과 합칠 때 중복 제거된다.
 *
 * @param components 레이아웃 최상위 컴포넌트 배열
 * @return 발견된 responsive 키 집합(발견 순 배열, 중복 제거)
 */
export function collectResponsiveKeys(components: EditorNode[] | undefined | null): string[] {
  const found: string[] = [];
  const seen = new Set<string>();

  const visit = (node: EditorNode | undefined | null): void => {
    if (!node || typeof node !== 'object') return;

    const responsive = node.responsive;
    if (responsive && typeof responsive === 'object') {
      for (const key of Object.keys(responsive)) {
        if (!seen.has(key)) {
          seen.add(key);
          found.push(key);
        }
        // 분기 children 도 하강(분기 안 노드의 중첩 responsive 수집)
        const branchChildren = (responsive[key] as { children?: unknown } | undefined)?.children;
        if (Array.isArray(branchChildren)) {
          for (const child of branchChildren) visit(child as EditorNode);
        }
      }
    }

    if (Array.isArray(node.children)) {
      for (const child of node.children) visit(child as EditorNode);
    }
  };

  if (Array.isArray(components)) {
    for (const node of components) visit(node);
  }
  return found;
}

/**
 * 캔버스 토글/스타일 세부탭이 공유할 디바이스 키 목록을 도출한다(SSoT).
 *
 * = 프리셋 4개(항상) + 레이아웃 사용 커스텀 키(프리셋에 없는 키만). 중복 제거,
 * 순서 = 프리셋 먼저(PRESET_DEVICE_KEYS 순) → 커스텀 키(발견 순).
 *
 * @param components 레이아웃 최상위 컴포넌트 배열
 * @return 디바이스 키 목록(프리셋 + 동적 커스텀)
 */
export function collectDeviceKeys(components: EditorNode[] | undefined | null): string[] {
  const result: string[] = [...PRESET_DEVICE_KEYS];
  const presetSet = new Set<string>(PRESET_DEVICE_KEYS);
  for (const key of collectResponsiveKeys(components)) {
    if (!presetSet.has(key) && !result.includes(key)) {
      result.push(key);
    }
  }
  return result;
}

/**
 * 노드가 가진 **디바이스 전용 children 교체 구성** 키 목록을 도출한다.
 *
 * `responsive.{key}.children` 가 배열인 키만(= children 교체 구성). `currentBp` 와 같은 키는
 * 제외한다(이미 그 구성을 보고 있으므로 점프 대상 아님). props-only 분기는 제외(분리 구성 아님).
 * 다중 커스텀 범위(`"600-900"`, `"0-480"` 등)도 각각 별도 항목으로 수집한다(키 순회 — 단일 가정 없음).
 *
 * @param node 대상 노드
 * @param currentBp 현재 보고 있는 breakpoint 키(제외 대상). 미지정 시 전부 포함.
 * @return children 교체 구성 키 배열(발견 순)
 */
export function collectDefinedDeviceBranches(
  node: { responsive?: Record<string, { children?: unknown }> } | undefined | null,
  currentBp?: string,
): string[] {
  const responsive = node?.responsive;
  if (!responsive || typeof responsive !== 'object') return [];
  const out: string[] = [];
  for (const key of Object.keys(responsive)) {
    const branch = responsive[key];
    if (!branch || !Array.isArray(branch.children)) continue; // children 교체 구성만
    if (key === currentBp) continue; // 현재 보고 있는 구성 제외
    out.push(key);
  }
  return out;
}

/**
 * breakpoint 키 → 캔버스 미리보기 폭(px) 산출(상한값 규칙).
 *
 *  - 단일폭 프리셋: desktop=1280 / tablet=820 / mobile=390(기존 상수 유지).
 *  - 범위 프리셋/커스텀(`portable`=0~1023, `"600-900"`): **상한값(max)**.
 *  - 무한대 범위(`desktop`은 위에서 1280 으로 선처리; `"1200-"` 등 max=∞): 데스크톱 기본 폭.
 *  - 파싱 불가/알 수 없는 키: 데스크톱 기본 폭으로 안전 폴백.
 *
 * 산출값은 [320, 1920] 으로 클램프한다(캔버스 안전 범위).
 *
 * @param key breakpoint 키(프리셋 또는 커스텀 범위 문자열)
 * @return 캔버스 미리보기 폭(px)
 */
export function resolveDeviceWidth(key: string): number {
  // 단일폭 프리셋은 기존 단일폭 상수를 그대로 사용(상한값 규칙보다 우선 — 디자인 관행 폭).
  switch (key) {
    case 'desktop':
      return DESKTOP_PRESET_WIDTH;
    case 'tablet':
      return TABLET_PRESET_WIDTH;
    case 'mobile':
      return MOBILE_PRESET_WIDTH;
    default:
      break;
  }

  const range = key in BREAKPOINT_PRESETS ? BREAKPOINT_PRESETS[key] : responsiveManager.parseRange(key);
  if (!range) return DESKTOP_PRESET_WIDTH; // 알 수 없는 키 — 안전 폴백

  // 무한대 상한(max=∞) → 데스크톱 기본 폭(가용 프리셋 중 가장 넓은 폭).
  if (!Number.isFinite(range.max)) return DESKTOP_PRESET_WIDTH;

  return Math.round(Math.max(WIDTH_CLAMP_MIN, Math.min(WIDTH_CLAMP_MAX, range.max)));
}
