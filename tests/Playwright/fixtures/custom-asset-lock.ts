/**
 * 사용자 추가 에셋(`custom/`) E2E 상호 배제 잠금
 *
 * `custom-assets.spec.ts` 와 `custom-asset-management.spec.ts` 는 **실서버의 같은
 * `custom/` 디렉토리**를 만진다. 파일명을 갈라 놓아도 충돌은 사라지지 않는다 — 어느
 * 디렉토리든 쓰기가 일어나면 서버가 파일 서명 변화를 감지해 확장 캐시 버전을 올리고,
 * 그 버전이 자산 URL(`?v` / `/build/ext/{v}/`)과 정적 게시본을 통째로 회전시키기
 * 때문이다. 즉 두 spec 은 **전역 상태 하나를 공유**한다.
 *
 * Playwright 의 `mode: 'serial'` 은 파일 **안**의 순서만 정한다. 파일끼리는
 * `fullyParallel: true` 로 동시에 돌므로, 한쪽의 쓰기가 다른 쪽의 측정 창에 끼어들어
 * 제품이 정상인데도 단언이 무작위로 깨진다(각각 단독 실행하면 둘 다 통과한다).
 *
 * 스위트 전체 설정(`workers` · `fullyParallel`)은 건드리지 않는다 — 다른 spec 의
 * 실행 특성까지 바꾸게 되기 때문이다. 이 잠금을 쓰는 두 파일만 서로를 기다린다.
 *
 * 디렉토리 생성(`mkdir`)은 원자적이라 별도 동기화 없이 상호 배제가 성립한다.
 */

import { mkdirSync, rmSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

/** 잠금 디렉토리 — 워커 프로세스가 갈라져도 파일시스템은 공유된다 */
const LOCK_DIR = join(tmpdir(), 'g7-custom-asset-e2e.lock');

/** 이 시간이 지난 잠금은 죽은 워커가 남긴 것으로 보고 걷어낸다 */
const STALE_MS = 5 * 60 * 1000;

/** 획득 대기 상한 — 넘으면 조용히 계속하지 않고 실패시킨다 */
const ACQUIRE_TIMEOUT_MS = 4 * 60 * 1000;

/**
 * 잠금을 획득할 때까지 기다립니다.
 *
 * @return Promise<void>
 */
export async function acquireCustomAssetLock(): Promise<void> {
    const deadline = Date.now() + ACQUIRE_TIMEOUT_MS;

    for (;;) {
        try {
            mkdirSync(LOCK_DIR);

            return;
        } catch {
            try {
                if (Date.now() - statSync(LOCK_DIR).mtimeMs > STALE_MS) {
                    rmSync(LOCK_DIR, { recursive: true, force: true });

                    continue;
                }
            } catch {
                // 그 사이 다른 워커가 풀었다 — 다음 시도에서 바로 잡는다
            }

            if (Date.now() > deadline) {
                throw new Error(
                    `custom 자산 E2E 잠금을 ${ACQUIRE_TIMEOUT_MS}ms 안에 얻지 못했습니다. ` +
                        `이전 실행이 남긴 잠금이면 ${LOCK_DIR} 을 지우십시오.`
                );
            }

            await new Promise(resolve => setTimeout(resolve, 250));
        }
    }
}

/**
 * 잠금을 해제합니다. 이미 없어도 오류로 보지 않습니다.
 *
 * @return void
 */
export function releaseCustomAssetLock(): void {
    rmSync(LOCK_DIR, { recursive: true, force: true });
}
