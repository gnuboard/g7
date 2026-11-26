/**
 * 전역 window.G7Core 타입 선언
 *
 * 종전 선언은 resources/js/core/template-engine.d.ts 에 있었다. 같은 이름의 template-engine.ts 가 함께
 * 포함되면 TypeScript 는 .d.ts 를 읽지 않으므로 그 선언은 한 번도 타입 검사에 실리지 않았고, 함께 들어 있던
 * 와일드카드 모듈 선언(이름이 template-engine 으로 끝나는 모듈)의 사본도 실제 모듈이 먼저 해석되어 쓰인 적이 없다. 그래서 이름이 겹치지 않는 이
 * 파일로 옮기고, 엔진 API 모양은 사본 대신 실제 모듈에서 가져온다.
 *
 * 시험 설정(tsconfig.test.json)은 시험 파일만 담으므로 이 파일을 include 에 따로 적는다.
 */
import type { TemplateEngineAPI } from './template-engine';

declare global {
  interface Window {
    /** 코어 전역 객체. 대부분의 코드는 아직 `(window as any).G7Core` 로 읽는다 */
    G7Core?: {
      /** 템플릿 엔진 공개 API (template-engine.ts 가 노출) */
      TemplateEngine?: TemplateEngineAPI;
    };
  }
}

export {};
