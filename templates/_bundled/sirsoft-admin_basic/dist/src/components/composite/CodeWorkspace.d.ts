import { default as React } from 'react';
import { CodeEditorMarker, CodeEditorReveal } from './CodeEditor';
import { EditorAttrs } from '../../types';
export interface CodeWorkspaceProps {
    /** 지금 서버가 준 파일의 키 */
    fileKey: string;
    /**
     * `serverValue` 가 **실제로 어느 파일 것인지**.
     *
     * `fileKey` 는 클릭 즉시 바뀌지만 `serverValue` 는 응답이 와야 바뀐다. 그 사이의 렌더에서
     * 둘은 서로 다른 파일을 가리키므로, 그대로 받아들이면 **앞 파일 본문이 새 파일 버퍼에 굳는다**
     * (실측 2026-09-12: 300ms 간격으로 10개를 연속 클릭하니 6개 버퍼가 직전 파일 본문을 담았고,
     * 그 상태로 저장하면 다른 레이아웃을 덮어쓴다). 값이 있고 `fileKey` 와 다르면 그 렌더의
     * `serverValue` 는 통째로 무시한다.
     *
     * 주지 않으면(빈 문자열 포함) 대조하지 않는다 — 단일 파일만 다루는 호출부의 종전 동작.
     */
    serverKey?: string;
    /** 그 파일의 서버 본문 */
    serverValue?: string;
    /** 그 파일의 서버 판본 (`lock_version`) */
    serverRevision?: number;
    /**
     * 방금 성공한 **내 저장** — 어느 파일(`key`)에 어떤 본문(`value`)을 보냈고 서버가 어떤 판본(`revision`)을
     * 매겼는지. 저장을 보낼 때 `revision` 없이 적고, 성공 응답의 `lock_version` 으로 채운다.
     *
     * 서버는 저장한 마크업을 다시 역변환해 돌려주므로 공백·속성 표기가 정규화된다. 그 본문은 내 버퍼와
     * 글자가 달라, 이 정보가 없으면 «판본이 오르고 내용이 다르다» 가 곧 «남이 고쳤다» 가 되어 내 저장
     * 직후마다 충돌 바가 섰다(실측 2026-10-04: 태그 안 공백 세 칸 저장 → 「yours v2 → server v3」).
     */
    lastSave?: {
        key?: string;
        value?: string;
        revision?: number | null;
    } | null;
    /** 탭에 보일 이름 — 없으면 키에서 뽑는다 */
    fileLabel?: string;
    /** 본문을 불러오는 중 (편집기를 언마운트하지 않는다 — 모델이 날아간다) */
    isLoading?: boolean;
    language?: string;
    readOnly?: boolean;
    /**
     * 지금 서버에 **실제로 있는** 파일 키 전체.
     *
     * 초안은 파일보다 오래 산다 — 지우거나 이름을 바꾼 뒤에도, 남이 지운 뒤에도 남는다.
     * 그래서 복원이 «없는 파일» 의 탭을 세우고, 그 탭을 누르면 404 가 났다(실측 2026-09-12).
     * 이 목록으로 그런 키를 가려 **서버를 부르지 않고** 복원한 편집분을 읽기 전용으로
     * 보여 준 뒤, 새 이름으로 살리거나 버리게 한다.
     *
     * 비어 있으면(목록을 아직 못 받았으면) 아무것도 «없는 파일» 로 판정하지 않는다 — 로딩
     * 중에 열린 탭을 전부 없는 파일로 몰아 버리는 사고를 막는다.
     */
    knownKeys?: string[];
    /**
     * 부모가 «끝났다» 고 알린 키 — 새 이름으로 살린 없는 파일이다. 그 탭을 닫고 초안을 지운다.
     *
     * 살린 뒤에도 탭과 초안이 남으면 이미 살린 편집분이 다음 진입에서 또 «저장하지 않은 편집» 으로
     * 뜨고 같은 물음을 되풀이한다(실측 2026-10-03). 편집기는 생성이 성공했는지 모르므로 부모가 알린다.
     */
    retiredKey?: string;
    markers?: CodeEditorMarker[];
    /** 검색 결과·오류 패널에서 온 이동 좌표 */
    revealAt?: CodeEditorReveal;
    /** localStorage 초안 네임스페이스 */
    draftNamespace?: string;
    /** 열린 탭 상한 */
    maxTabs?: number;
    /** 줄바꿈 기본값 — 주지 않으면 기기 폭으로 정한다(모바일 ON) */
    defaultWordWrap?: boolean;
    /**
     * 활성 버퍼가 바뀔 때마다 — 레이아웃이 `_global.editorMarkup` 으로 받는다.
     *
     * 파일을 **열었을 때도** 한 번 부른다(저장이 읽는 자리를 비워 둘 수 없다). 그래서 서버 왕복을
     * 거는 쪽은 이 콜백이 아니라 {@link onUserEdit} 이어야 한다.
     */
    onChange?: (event: {
        target: {
            value: string;
        };
    }) => void;
    /**
     * **사람이 글자를 고쳤을 때만** — 파일 전환·초안 복원·충돌 해소로 값이 바뀐 경우는 부르지 않는다.
     *
     * 이 구분이 없으면 파일을 열 때마다 서버 변환이 한 번씩 돌고, 본문이 도착하기 전의 빈 값으로
     * 먼저 나가 422 가 난다(브라우저 실측 2026-09-12 — `_admin_base` 진입에서 422 → 200 두 번).
     */
    onUserEdit?: (event: {
        target: {
            value: string;
        };
    }) => void;
    /** 미저장 키 목록이 바뀔 때 — 트리가 읽는다 */
    onDirtyChange?: (event: {
        target: {
            value: string[];
        };
    }) => void;
    /** 탭 클릭 — 레이아웃이 그 파일을 다시 받아 온다 */
    onRequestSelect?: (key: string) => void;
    onSave?: () => void;
    /**
     * 없는 파일의 복원분을 **새 이름으로** 살릴 때 — `(본문)` 을 들고 생성 흐름으로 넘긴다.
     *
     * 조용히 버리면 사용자가 쓴 글이 사라지고, 그대로 두면 저장할 수 없는 탭이 남는다.
     * 그래서 원본이 없어졌다는 사실을 알리고 내용은 지키는 쪽으로 연다.
     */
    onSaveAs?: (event: {
        target: {
            value: string;
            key: string;
        };
    }) => void;
    onSaveAll?: () => void;
    onPreview?: () => void;
    onNewFile?: () => void;
    onVersionHistory?: () => void;
    onToggleView?: () => void;
    onQuickOpen?: () => void;
    onSearchContent?: () => void;
    onExit?: () => void;
    className?: string;
    id?: string;
    editorAttrs?: EditorAttrs;
    children?: React.ReactNode;
}
/**
 * 파일별 편집 버퍼를 소유하는 작업 공간.
 *
 * ## 무엇을 고치는가
 *
 * 종전에는 편집 버퍼가 전역 변수 **한 벌**(`_global.editorMarkup`)이었다. 파일을 고르면 그
 * 전역을 서버 응답으로 덮어썼으므로, 저장하지 않은 수정이 **아무 경고 없이 사라졌다**.
 *
 * 여기서는 방향을 뒤집는다 — 서버 본문은 `serverValue` prop 으로 **들어오기만** 하고, 편집 중인
 * 값은 이 컴포넌트 안의 `Map<key, Buffer>` 가 소유한다. 그래서 파일을 바꿔도 앞 파일의 버퍼가
 * 그대로 남고, 되돌아오면 편집하던 상태 그대로 이어서 쓴다.
 *
 * ## 버퍼 수명 규칙
 *
 * | 상황 | 동작 |
 * | --- | --- |
 * | 처음 들어온 키 | `serverValue` 로 버퍼를 만든다 (clean) |
 * | 재방문 · dirty | **`serverValue` 를 무시하고 버퍼를 보여준다** |
 * | 재방문 · clean | `serverValue` 로 갱신 (남이 고친 최신본 반영) |
 * | `serverRevision` 증가 + dirty | 충돌 바 — 자동 덮어쓰기 금지 |
 * | 그 판본이 `lastSave` (내 저장) + 서버 정규화로 글자만 다름 | 서버 본문으로 clean. 저장 뒤 더 친 글자가 있으면 기준선만 옮겨 dirty 유지 |
 * | 저장 성공 (판본 증가 + clean 전환) | 초안 삭제 |
 * | 탭 상한 초과 | 오래된 **clean** 탭부터 해제. dirty 탭은 절대 자동으로 닫지 않는다 |
 */
export declare const CodeWorkspace: React.FC<CodeWorkspaceProps>;
