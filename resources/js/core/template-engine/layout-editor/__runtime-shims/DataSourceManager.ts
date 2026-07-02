/**
 * DataSourceManager 런타임 shim — 편집기 번들 전용 (vite.config.editor.js alias 대상)
 * @since engine-v1.51.0
 * 값은 window.G7Core.__runtime(싱글톤 동일성 필수), 타입은 원본에서 재export.
 */

import { getCoreRuntime } from './runtime';

const rt = getCoreRuntime();

export const DataSourceManager = rt.DataSourceManager;
export const dataSourceManager = rt.dataSourceManager;

export type {
  DataSourceType,
  WebSocketChannelType,
  HttpMethod,
  LoadingStrategy,
  DataSourceLoadingState,
  DataSource,
  DataSourceResult,
  DataSourceManagerOptions,
  SampleDataProvider,
  ConditionContext,
} from '../../DataSourceManager';
