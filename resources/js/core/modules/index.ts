/**
 * 모듈 시스템 관련 유틸리티 모듈
 */

export {
    ModuleAssetLoader,
    getModuleAssetLoader,
    parseModuleAssetsFromConfig,
    parsePluginAssetsFromConfig,
    parseBundleUrlsFromConfig,
} from './ModuleAssetLoader';

export type { ModuleAsset, ExternalScript, ExtensionBundleUrls } from './ModuleAssetLoader';
