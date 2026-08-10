/**
 * detectAssetUrlMode 핸들러 (이슈 #486 §8)
 *
 * 관리자 환경설정에서 자산 URL 방식을 **브라우저에서** 재감지한다.
 *
 * 서버측에서 자기 `APP_URL` 로 curl 하면 loopback 이 nginx vhost·SSL·프록시 체인을
 * 우회하거나 다른 vhost 를 타서 오판한다. 실제로 방문자가 겪는 경로를 재현하려면
 * 브라우저가 던져야 한다.
 *
 * 감지 지점이 인스톨러 하나로 부족한 이유가 여기 있다 — 관리자가 설치 6개월 뒤
 * 정적 최적화 블록을 추가하면 저장된 값은 "확장자 OK" 인 채 사이트가 다시 죽는다.
 * 이 버튼이 그 상황의 재판정 수단이다.
 */
/** 판정 결과 */
export type DetectResult = 'extension' | 'extensionless' | 'unavailable';
/**
 * 프로브 쌍을 던져 자산 URL 방식을 판정한다.
 *
 * 단일 프로브는 "PHP 자체가 죽음" 과 구분되지 않으므로 반드시 쌍으로 던진다.
 *
 * | probe.js | probe | 판정 |
 * |---|---|---|
 * | 성공 | 성공 | `extension` |
 * | 실패 | 성공 | `extensionless` (정적 블록 가로채기 확정) |
 * | 그 외 | | `unavailable` (모드 문제 아님 — PHP/라우팅 장애) |
 *
 * @returns 판정 결과
 */
export declare function detectAssetUrlMode(): Promise<DetectResult>;
/**
 * 저장된 모드와 실제 환경을 대조해 불일치를 전역 상태에 실어준다 (§5).
 *
 * 관리자 화면 자체가 안 뜨는 결함이라 "브라우저로 설정을 바꾸세요" 는 순환 참조다.
 * 반대로 자가 복구가 조용히 성공하면 관리자는 사이트가 정상이라 믿고 저장값을
 * 영영 고치지 않는다. 그래서 **대시보드가 스스로 프로브를 던져 저장값과 대조**한다.
 *
 * L5 위반이 아니다 — 서버 설정을 쓰지 않고 관리자에게 보여주기만 한다.
 * L9 위반도 아니다 — L9 가 금지하는 것은 엔진 레이어(Router/LayoutLoader/
 * ComponentRegistry)의 자산 로딩 중 재감지 캐스케이드이고, 이것은 관리자 화면
 * 1회 진단이다.
 *
 * 판정 불가(`unavailable`)면 아무것도 표시하지 않는다. 일시적 네트워크 장애로
 * 대시보드에 경고가 뜨면 신호가 아니라 소음이 된다.
 *
 * @param _action 액션 정의 (미사용)
 */
export declare function checkAssetUrlModeDriftHandler(_action?: any): Promise<void>;
/**
 * 자산 URL 방식 자동 감지 핸들러.
 *
 * 판정 결과를 폼 상태(`general.asset_url_mode`)에 반영하고, 감지 상태를
 * `asset_url_mode_detect_status` 로 로컬 폼 상태에 기록한다 — 레이아웃이 이 값을 읽어
 * 필드 하단에 결과 문구를 조건부로 렌더한다(토스트가 아니라 필드 옆 인라인 안내).
 * **저장은 하지 않는다** — 관리자가 결과를 보고 저장 버튼을 누르는 흐름을 유지해,
 * 감지가 오판했을 때 되돌릴 여지를 남긴다.
 *
 * 판정 불가(`unavailable`)면 값을 건드리지 않는다. 서버가 응답하지 않는 상황에서
 * 임의 값을 넣으면 멀쩡한 설정을 덮어쓸 수 있다(상태만 `unavailable` 로 표시).
 *
 * @param _action 액션 정의 (미사용)
 * @param context 액션 컨텍스트 (setState 등)
 */
export declare function detectAssetUrlModeHandler(_action: any, context?: any): Promise<void>;
