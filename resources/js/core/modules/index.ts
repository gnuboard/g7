/**
 * 모듈 시스템 관련 유틸리티 모듈
 */

export {
    ModuleAssetLoader,
    getModuleAssetLoader,
    parseModuleAssetsFromConfig,
    parsePluginAssetsFromConfig,
} from './ModuleAssetLoader';

export type { ModuleAsset, ExternalScript } from './ModuleAssetLoader';
