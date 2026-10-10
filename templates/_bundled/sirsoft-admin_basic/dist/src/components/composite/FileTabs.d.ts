import { default as React } from 'react';
/** 열린 파일 탭 1건. */
export interface FileTabItem {
    /** 노드 키 — `layout:<name>` 또는 `ext:<id>` */
    key: string;
    /** 탭에 보일 짧은 이름 */
    label: string;
    /** 전체 경로 (툴팁) */
    title?: string;
    /** 미저장 여부 — ● 점 */
    dirty?: boolean;
    /**
     * 서버에 더 이상 없는 파일인지 (지워졌거나 이름이 바뀜).
     *
     * 초안이 파일보다 오래 살아 복원이 «없는 파일» 의 탭을 세울 수 있다. 그 탭을 여느 탭과
     * 똑같이 그리면 사용자는 왜 내용이 안 오는지 알 수 없다 — 실측 2026-09-12 에는 빈 편집기와
     * 범용 404 문구만 떴다.
     */
    missing?: boolean;
}
export interface FileTabsProps {
    tabs?: FileTabItem[];
    activeKey?: string;
    /** 터치 환경에서 탭을 조금 키운다 */
    size?: 'compact' | 'touch';
    onSelect?: (key: string) => void;
    onClose?: (key: string) => void;
    className?: string;
    id?: string;
}
/**
 * 열린 파일 탭 줄.
 *
 * 편집기가 한 번에 한 파일만 보여 주더라도, **무엇이 열려 있고 그중 무엇이 저장되지 않았는지**는
 * 늘 보여야 한다. 미저장 표시는 트리와 같은 규약을 쓴다 — 점 모양 + `aria-label`.
 *
 * 좁은 폭에서는 한 줄 가로 스크롤이고, 활성 탭은 스스로 시야 안으로 들어온다 — 단축키
 * (`Ctrl+Alt+←/→`)로 탭을 옮기면 화면 밖으로 나간 탭이 활성이 되는 일이 생기기 때문이다.
 */
export declare const FileTabs: React.FC<FileTabsProps>;
