<?php

namespace App\Http\Resources\Traits;

use App\Extension\ExtensionManager;

/**
 * 확장 목록·상세 행의 PHP 확장 요구 필드를 직렬화하는 Trait (#151)
 *
 * 확장 composer.json 의 `require.ext-*`(필수 — 설치·활성화 차단)와 `suggest.ext-*`(권장 — 경고만)를
 * 서버 로드 상태와 함께 싣는다. 관리자 모듈·플러그인 목록 카드와 상세 창이 「PHP 확장 필요: soap(미설치)」 /
 * 「권장 PHP 확장: soap(미설치)」 배지를 이 값으로 그린다. 이미 켜진 확장의 요구가 나중에 깨져도(서버에서
 * 확장을 지움) 자동으로 끄지 않고 이 배지로만 알린다(D8).
 *
 * 읽는 위치: 활성 디렉토리 → `_pending` → `_bundled` 순서(미설치 확장은 설치 소스의 선언을 보여 준다).
 */
trait ExposesPhpExtensionRequirements
{
    /**
     * PHP 확장 요구 필드를 반환합니다. (식별자가 없으면 빈 배열)
     *
     * @param  string  $extensionType  확장 타입 (module|plugin)
     * @return array{php_extensions?: array{required: list<string>, suggested: array<string, string>, missing_required: list<string>, missing_suggested: list<string>}} 필드 또는 빈 배열
     */
    protected function phpExtensionRequirementFields(string $extensionType): array
    {
        $identifier = $this->getValue('identifier');
        if (! is_string($identifier) || $identifier === '') {
            return [];
        }

        $domain = base_path($extensionType === 'plugin' ? 'plugins' : 'modules');
        $path = $domain.DIRECTORY_SEPARATOR.$identifier;
        // audit:allow install-path-reads-bundled-or-template-id reason: 미설치 확장 카드는 설치 소스(_pending·_bundled)의 선언을 보여 줘야 설치 전에 요구를 알 수 있다 — 활성 디렉토리가 있으면 그것이 우선
        foreach (['_pending', '_bundled'] as $source) {
            if (is_file($path.DIRECTORY_SEPARATOR.'composer.json')) {
                break;
            }
            $path = $domain.DIRECTORY_SEPARATOR.$source.DIRECTORY_SEPARATOR.$identifier;
        }

        return ['php_extensions' => app(ExtensionManager::class)->checkPhpExtensionRequirementsAt($path)];
    }
}
