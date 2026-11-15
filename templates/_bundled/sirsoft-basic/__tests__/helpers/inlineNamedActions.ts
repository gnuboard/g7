/**
 * 레이아웃 시험 도우미 — 이름 붙은 액션과 조각 포함을 그 자리에 펼친다.
 *
 * 상품 상세의 담기·바로구매·찜과 장바구니의 주문하기는 화면의 이름 붙은 액션(`named_actions`)이고, 단추는
 * `actionRef` 로 이름만 가리킨다. 옵션 선택 영역도 조각(`partial`)으로 떨어져 있다. 조각 JSON 하나만 읽는 시험은
 * 그래서 단추가 무엇을 하는지·옵션 칸이 어떻게 생겼는지 볼 수 없다.
 *
 * 이 도우미는 엔진이 화면을 조립할 때 하는 일을 시험용으로 흉내 낸다:
 * - `{ partial: "partials/….json" }` 노드 → 그 조각의 뿌리 노드
 * - `{ actionRef: "이름" }` 액션 → 화면에 정의된 그 액션 (누름 종류 등 참조 쪽 키는 그대로 둔다)
 *
 * 시험은 읽어 들이는 자리만 이 도우미로 바꾸고, 단언은 그대로 둔다.
 */

import * as fs from 'fs';
import * as path from 'path';

const layoutsDir = path.resolve(__dirname, '../../layouts');

/** 이름 붙은 액션이 정의된 화면 (조각 → 그 조각을 쓰는 화면) */
const DEFAULT_OWNER = 'shop/show.json';

function readLayout(relative: string): any {
  return JSON.parse(fs.readFileSync(path.join(layoutsDir, relative), 'utf-8'));
}

function expand(node: any, named: Record<string, any>, depth = 0): any {
  if (depth > 40) throw new Error('레이아웃 펼치기가 너무 깊다 — 조각이 서로를 포함하는지 확인');
  if (Array.isArray(node)) return node.map((child) => expand(child, named, depth));
  if (!node || typeof node !== 'object') return node;

  // 조각 포함 — 조각의 뿌리 노드로 바꾼다 (포함 쪽이 건 조건은 남긴다)
  if (typeof node.partial === 'string' && node.type === undefined) {
    const { meta: _meta, ...root } = readLayout(node.partial);
    const { partial: _partial, comment: _comment, ...rest } = node;
    return expand({ ...root, ...rest }, named, depth + 1);
  }

  // 이름 붙은 액션 참조 — 정의로 바꾼다
  if (typeof node.actionRef === 'string') {
    const definition = named[node.actionRef];
    if (!definition) throw new Error(`이름 붙은 액션이 없다: ${node.actionRef}`);
    const { actionRef: _ref, ...rest } = node;
    const { comment: _comment, ...body } = definition;
    return expand({ ...rest, ...body }, named, depth + 1);
  }

  const out: Record<string, any> = {};
  for (const [key, value] of Object.entries(node)) out[key] = expand(value, named, depth);
  return out;
}

/**
 * 이미 읽은 레이아웃 JSON 의 조각 포함·이름 붙은 액션 참조를 펼친다.
 *
 * @param layout 레이아웃(또는 조각) JSON
 * @param owner 이름 붙은 액션이 정의된 화면의 JSON 경로 (layouts 기준, 기본 상품 상세)
 * @returns 펼친 사본
 */
export function inlineNamedActions<T>(layout: T, owner: string = DEFAULT_OWNER): T {
  const named = readLayout(owner).named_actions ?? {};
  return expand(layout, named) as T;
}

/**
 * 레이아웃 JSON 을 읽어 조각 포함·이름 붙은 액션 참조를 펼친다.
 *
 * @param relative layouts 기준 JSON 경로
 * @param owner 이름 붙은 액션이 정의된 화면의 JSON 경로 (기본 상품 상세)
 * @returns 펼친 레이아웃
 */
export function loadExpandedLayout(relative: string, owner: string = DEFAULT_OWNER): any {
  return inlineNamedActions(readLayout(relative), owner);
}
