/**
 * 코어 업데이트 웹 단계 실행 핸들러 (W6 · 이슈 #609 G6)
 *
 * 서버는 **한 요청에 한 단계**만 수행한다. 그 경계가 편의가 아니라 안전 요건인 이유는
 * 파일을 교체한 프로세스가 사라진 클래스를 참조하면 fatal 이 나기 때문이다(§7 R25).
 * 그래서 진행을 이어 가는 주체는 화면이고, 이 핸들러가 그 반복 호출을 맡는다.
 *
 * 레이아웃 JSON 의 액션 체인으로 같은 일을 하려면 자기 자신을 참조하는 재귀
 * `actionRef` 가 필요한데, 이 저장소에 그 선례가 없고 반복 횟수가 데이터에 따라
 * 달라진다(업그레이드 스텝 핸드오프가 같은 단계를 여러 번 요구할 수 있다).
 * 형제 사례(`detectAssetUrlMode`)와 같은 판단으로 핸들러에 둔다.
 *
 * 상태는 `_global.coreUpdateStatus` 하나에 싣는다 — 화면이 그 한 값만 보고 W6 의 네
 * 상태를 고르므로, 진행 중 다른 곳에서 부분 갱신이 끼어들 여지가 없다.
 */
/**
 * 기다리기 함수를 바꿔 끼운다 (테스트 전용).
 *
 * @param fn 대체 함수 (null 이면 기본값으로 되돌린다)
 */
export declare function __setCoreUpdateSleepForTests(fn: ((ms: number) => Promise<void>) | null): void;
/**
 * 상태를 다시 읽어 화면에 반영한다.
 *
 * 화면 진입 시(진행 중이던 실행을 이어받는 경우 포함)와 [업데이트 확인] 이후에
 * 호출한다.
 *
 * @returns 조회한 상태 (실패 시 null)
 */
export declare function refreshCoreUpdateStatusHandler(): Promise<any>;
/**
 * 업데이트를 착수하고 끝까지 진행한다.
 *
 * 착수 선택(`action.params`)은 레이아웃이 폼 상태에서 채워 넘긴다. **여기 없는 값은
 * 서버에도 없다** — `force`·`prune`·백업 생략은 웹 동선에 존재하지 않는 선택이다(D27).
 *
 * 서버가 미서명 패키지(409)·서명 불일치(422)로 착수를 거절하면(#916) 토스트 대신 거절 내용을 전역에 실어
 * 화면의 공용 안내 조각이 그리게 한다. 정책이 허용하면 화면의 시작 버튼이 「미서명으로 계속」이 되어
 * 같은 선택에 `allow_unsigned` 만 더해 이 핸들러를 다시 부른다(서버는 앞 요청을 보관하지 않는다).
 *
 * @param action 액션 정의 (params: update_bundled_extensions · rebuild_search_index · layout_strategy · zip_file · allow_unsigned)
 */
export declare function startCoreUpdateHandler(action?: any): Promise<void>;
/**
 * 진행 중에 물은 선택을 보내고 이어서 진행한다 (7.2.1).
 *
 * 화면은 항목마다 운영자가 바꾼 값만 `_global.coreUpdateChoices`(`{key: {selected, strategy}}`)에 둔다. 바꾸지 않은
 * 항목은 선택 창이 보여 준 기본값으로 보낸다 — 번들 업데이트는 「올림」·항목의 기본 전략, 새로 들어온 미배치 번들은
 * 「배치하지 않음」(명령줄 비대화형 기본과 같다). 서버가 받으면 대기가 풀리고, 이 핸들러가 다시 단계를 몬다.
 */
export declare function submitCoreUpdateSelectionHandler(): Promise<void>;
/**
 * 중단된 진행을 이어받는다.
 *
 * 브라우저를 닫았다 열었거나 한 단계가 네트워크 오류로 끊긴 뒤의 복귀 경로다.
 * 진행 지점은 서버가 갖고 있으므로 화면은 그저 다시 부르기만 하면 된다.
 */
export declare function resumeCoreUpdateHandler(): Promise<void>;
/**
 * 진행 중인 실행이 남아 있으면 **운영자 조작 없이** 이어받는다.
 *
 * 화면의 진행 블록이 마운트될 때(= 진행 중이거나 되돌리는 중인 실행을 화면이 처음
 * 그릴 때) 호출된다. 이 자동 경로가 없으면 창을 닫았다 들어온 운영자에게는 진행률만
 * 복원되고 아무도 다음 단계를 부르지 않아, **사이트가 점검 모드에 멈춘 채** [이어서
 * 진행] 을 누를 때까지 대기한다. 실패 처리(D10)의 「조작 없이 되돌린다」도 같은
 * 이유로 이 경로에 기댄다 — 되돌리기는 다음 호출이 수행하기 때문이다.
 *
 * 상한에 걸려 멈춘 진행이 곧바로 자동 재개되면 같은 왕복이 무한히 돌므로, 자동
 * 경로는 페이지 수명당 `MAX_AUTO_RESUMES` 회로 제한한다. 그 뒤로는 [이어서 진행] 이
 * 유일한 진행 수단이 되고, 그 버튼은 이 상한을 쓰지 않는다.
 */
export declare function autoResumeCoreUpdateHandler(): Promise<void>;
/**
 * 끝난 실행의 기록을 지운다 (운영자가 결과를 확인한 뒤).
 */
export declare function dismissCoreUpdateHandler(): Promise<void>;
