/**
 * 인증 경계에서의 비회원 저장값 정리 (#639 ⑦, 결정 D15)
 *
 * 로그인·로그아웃은 SPA 이동이라 페이지가 다시 뜨지 않는다. 그래서 방문자를 가리키는 브라우저 저장값이 경계를 넘어 남았다.
 *
 * - 카트키(`localStorage.g7_cart_key`) — 로그아웃한 뒤에도 회원 장바구니 키가 남고, 로그인 전후로 같은 키를 썼다.
 * - 비회원 주문 토큰(세션 저장소 `g7_guest_order_token` · `_number` · `_expires_at`) — `_global.guestOrderToken` 에 다시 실려
 *   로그인한 회원의 요청에도 `X-Guest-Order-Token` 헤더가 섞여 나갔다.
 *
 * 처치:
 * - **로그아웃**(인증 → 비인증): 카트키와 토큰 3키를 지우고 `_global.cartKey`·`_global.guestOrderToken` 을 비운다.
 *   `AuthManager.logout()` 은 이벤트를 낸 뒤 전체 이동하므로 동기로 처리한다.
 * - **로그인**(코어 `login` 이벤트 — 로그인 폼·2단계 인증 완료가 모두 거친다): 비회원 주문 토큰 3키를 지우고, 서버의 장바구니
 *   병합(`MergeCartOnLoginListener`)이 끝난 뒤이므로 카트키를 새로 발급해 저장한다. `regenerateCartKeyHandler` 의 「로그인 상태면
 *   지움」 분기는 쓰지 않는다.
 * - 부팅 시 인증 복원은 로그인이 아니다. 이 초기화는 window.load 에 돌아 부팅 인증 확인보다 먼저 설치될 수 있어, 그 뒤 도착하는
 *   `authStateChange`(비인증 → 인증)를 로그인으로 보면 회원이 새로고침할 때마다 카트키를 다시 받는다 — 그래서 재발급은
 *   `authStateChange` 가 아니라 `login` 에만 건다.
 * - 같은 (인증 여부, 회원 uuid) 의 재발행(`checkAuth` 등)은 무시한다.
 *
 * 로케일 재초기화로 템플릿 초기화가 다시 돌아도 구독은 하나다 — 이전 구독을 먼저 해제한다.
 */
/**
 * 인증 경계 정리를 설치합니다 (여러 번 불러도 구독은 하나).
 */
export declare function installAuthBoundaryStorageCleanup(): void;
