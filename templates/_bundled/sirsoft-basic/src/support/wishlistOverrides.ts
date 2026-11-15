/**
 * 상품 카드 찜 상태 공유 저장소
 *
 * 같은 상품이 한 화면의 여러 카드(인기·신상품·최근 본 상품 줄, 목록)에 함께 나온다. 한 카드에서 찜을 바꾸면
 * 목록 응답은 그대로라 다른 카드는 옛 값을 보인다. 그래서 「방금 바꾼 결과」를 여기에 두고 카드가
 * `덮어쓴 값 ?? 응답 값` 으로 그린다.
 *
 * 값은 로그인한 회원의 것이다 — 회원이 바뀌면(로그아웃·다른 계정) 통째로 버린다.
 */

type Listener = () => void;

const overrides = new Map<string, boolean>();
const listeners = new Set<Listener>();
let ownerUuid: string | null = null;

/**
 * 지금 로그인한 회원의 식별값을 읽는다.
 *
 * @returns 회원 uuid (비회원이면 null)
 */
function currentUserUuid(): string | null {
  const uuid = (window as any).G7Core?.state?.get?.()?.currentUser?.uuid;
  return typeof uuid === 'string' && uuid !== '' ? uuid : null;
}

/**
 * 회원이 바뀌었으면 앞 회원의 값을 버린다.
 */
function syncOwner(): void {
  const uuid = currentUserUuid();
  if (uuid === ownerUuid) return;
  overrides.clear();
  ownerUuid = uuid;
}

/**
 * 상품의 덮어쓴 찜 상태를 읽는다.
 *
 * @param productId 상품 번호
 * @returns 덮어쓴 값 (바꾼 적이 없으면 undefined)
 */
export function getWishlistOverride(productId: number | string): boolean | undefined {
  syncOwner();
  return overrides.get(String(productId));
}

/**
 * 상품의 찜 상태를 덮어쓰고 구독 중인 카드에 알린다.
 *
 * @param productId 상품 번호
 * @param wishlisted 찜 여부
 */
export function setWishlistOverride(productId: number | string, wishlisted: boolean): void {
  syncOwner();
  overrides.set(String(productId), wishlisted);
  listeners.forEach((listener) => listener());
}

/**
 * 덮어쓴 값의 변경을 구독한다.
 *
 * @param listener 변경 때 부를 함수
 * @returns 구독 해제 함수
 */
export function subscribeWishlistOverrides(listener: Listener): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

/**
 * 덮어쓴 값을 모두 버린다.
 */
export function clearWishlistOverrides(): void {
  overrides.clear();
  ownerUuid = currentUserUuid();
  listeners.forEach((listener) => listener());
}
