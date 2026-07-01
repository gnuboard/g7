/**
 * baseEditorSlotMarkers.ts — 공통(base) 편집 모드 슬롯 표시 변환
 *
 * base 단독 편집 캔버스에서 슬롯 노드(`slot: "X"`)는 SlotContext 활성 시 원위치에서
 * `return null`(SlotContainer 이관)이라 통째로 사라진다. 슬롯 종류별로 표시 방식을 나눈다
 * (어떤 템플릿/모듈이 어떤 슬롯에 주입하든 동일 적용 — 코어 범용):
 *
 *  1) **소비처(SlotContainer)가 같은 base 레이아웃 안에 있는 슬롯**: 치환하지 않고 `slot` 키를
 *     그대로 둔다 → 슬롯 메커니즘이 정상 동작해 SlotContainer 가 실제 위치(예: 헤더 안)에서
 *     떙겨 렌더한다. 주입 앵커(`slot` 노드의 원래 위치)는 보통 헤더 밖 최상단 등 SlotContainer 와
 *     다른 자리라, 치환해 원위치에 표시하면 운영 화면과 어긋나 보인다(헤더 위 별도 박스 등).
 *  2) **소비처가 base 레이아웃에 없는 슬롯**(예: 자식 라우트 레이아웃이 채우는 `content`):
 *     base 단독 편집 캔버스엔 떙겨 갈 SlotContainer 가 없어 사라진다 → 어디가 그 자리인지
 *     알 수 없고 선택도 불가. 이때만 `slot` 키를 표시 마커(`__editorSlotName`)로 치환해
 *     원위치에 일반 컨테이너로 렌더(DynamicRenderer 가 data-editor-slot 부여 → 점선 박스+라벨).
 *
 * 소비처 존재 판정 = base 레이아웃 컴포넌트 트리에 `SlotContainer`(props.slotId === 슬롯) 노드가
 * 있는가. 통짜 TSX 컴포넌트(예: 사용자 Header) 내부 SlotContainer 는 JSON 트리에 안 보이나,
 * 동일 슬롯을 쓰는 SlotContainer JSON 노드가 같은 레이아웃에 하나라도 있으면(데스크톱/모바일
 * 페어 등) 그 슬롯은 소비처 보유로 간주해 치환 스킵 → 통짜 컴포넌트 내부 SlotContainer 도 정상 수신.
 *
 * 표시용 사본만 생성 — 입력 raw 는 변형하지 않는다(운영 content 무오염). 노드 구조(개수/순서)는
 * 불변이라 data-editor-path ↔ raw 좌표 정합도 유지된다.
 *
 * @since engine-v1.50.0
 */

/** SlotContainer 컴포넌트의 표준 이름 (templates 의 composite 등록명) */
const SLOT_CONTAINER_NAME = 'SlotContainer';

type NodeLike = Record<string, unknown>;

/**
 * base 레이아웃 컴포넌트 트리에서 `SlotContainer`(props.slotId) 노드의 slotId 집합을 수집.
 *
 * @param nodes 컴포넌트 노드 배열
 * @param out 누적 집합 (재귀 내부용)
 * @return 레이아웃이 자체 보유한 SlotContainer 의 slotId 집합
 */
export function collectContainerSlotIds(nodes: unknown[], out = new Set<string>()): Set<string> {
  for (const n of nodes) {
    if (!n || typeof n !== 'object') continue;
    const node = n as NodeLike;
    if (node.name === SLOT_CONTAINER_NAME) {
      const sid = (node.props as NodeLike | undefined)?.slotId;
      if (typeof sid === 'string') out.add(sid);
    }
    if (Array.isArray(node.children)) collectContainerSlotIds(node.children as unknown[], out);
  }
  return out;
}

/**
 * base 편집 모드 표시용 컴포넌트 트리를 생성.
 *
 * 소비처(SlotContainer)가 있는 슬롯은 `slot` 키를 보존(슬롯 메커니즘 위임 → 실제 위치 렌더),
 * 소비처가 없는 슬롯만 `slot` → `__editorSlotName` 마커로 치환(원위치 점선 박스).
 *
 * @param raw base 레이아웃의 원본 components 배열
 * @return 표시용 사본 (raw 미변형)
 */
export function buildBaseEditorComponents<T = unknown>(raw: unknown[]): T[] {
  const containerSlotIds = collectContainerSlotIds(raw);

  const mapSlot = (nodes: unknown[]): unknown[] =>
    nodes.map((n) => {
      if (!n || typeof n !== 'object') return n;
      const node = n as NodeLike;
      let next = node;
      if (typeof node.slot === 'string' && !containerSlotIds.has(node.slot)) {
        const { slot, ...rest } = node;
        next = { ...rest, __editorSlotName: slot };
      }
      if (Array.isArray(next.children)) {
        const mapped = mapSlot(next.children as unknown[]);
        if (mapped !== next.children) next = { ...next, children: mapped };
      }
      return next;
    });

  return mapSlot(raw) as T[];
}
