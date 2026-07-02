/**
 * DynamicRenderer 런타임 shim — 편집기 번들 전용 (vite.config.editor.js alias 대상)
 *
 * @since engine-v1.51.0
 *
 * 편집기 번들은 `../../DynamicRenderer` import 를 이 shim 으로 치환해, 코어 런타임의
 * DynamicRenderer 를 `window.G7Core.__runtime` 에서 빌려 쓴다(재번들 0바이트, 싱글톤 동일성).
 * 값은 런타임 전역에서, 타입은 원본 모듈에서 가져온다(타입은 컴파일 시 소거 → 런타임 코드 미포함).
 */

import { getCoreRuntime } from './runtime';

// 값: 코어 런타임 전역에서 획득 (default export 재현)
const DynamicRenderer = getCoreRuntime().DynamicRenderer;

export default DynamicRenderer;

// 타입: 원본에서 재export (런타임 코드 없음)
export type {
  ComponentDefinition,
  DynamicRendererProps,
} from '../../DynamicRenderer';
