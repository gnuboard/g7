/**
 * markLocalOnce 핸들러 — 데이터소스 onSuccess 의 「한 번만」 가드
 *
 * 데이터소스 onSuccess 는 `response` 컨텍스트만 받는다(DataSourceManager.executeOnSuccessHandler) —
 * 레이아웃 표현식의 `_local` 은 거기서 비어 있어 「이미 했는가」 를 판정할 수 없다. 그래서 목록을
 * 다시 받을 때마다 같은 onSuccess 가 처음처럼 돈다. 주문서의 기본 배송지 채움이 그랬다 — 배송지 관리
 * 창에서 주소를 추가·수정해 목록을 다시 받을 때마다 구매자가 고른 배송지를 기본 배송지로 덮어,
 * 다른 주소로 배송될 수 있었다(#599 validate 2026-09-23).
 *
 * 이 핸들러는 **현재** 로컬 상태를 읽어, `params.key` 플래그가 참이 아니면 참으로 세우고 `true` 를,
 * 이미 참이면 `false` 를 돌려준다. 레이아웃은 같은 sequence 의 다음 액션에서 `{{$prev === true}}` 로
 * 받는다. 화면 진입 때 그 플래그를 `false` 로 세워 두면(레이아웃 initLocal) 진입마다 한 번씩 돈다.
 *
 * @example
 * <sequence>
 *   <action handler="sirsoft-basic.markLocalOnce" key="checkoutDefaultAddressPrefilled"/>
 *   <conditions><when if="{{$prev === true}}">…</when></conditions>
 * </sequence>
 */
/**
 * 로컬 플래그가 아직 서지 않았으면 세우고 true 를 돌려줍니다.
 *
 * @param action 액션 정의 (params.key: 로컬 플래그 이름)
 * @param _context 액션 컨텍스트 (미사용 — 상태는 G7Core.state 에서 직접 읽는다)
 * @return 이번이 첫 회이면 true
 */
export declare function markLocalOnceHandler(action?: {
    params?: {
        key?: unknown;
    };
} & Record<string, any>, _context?: any): boolean;
