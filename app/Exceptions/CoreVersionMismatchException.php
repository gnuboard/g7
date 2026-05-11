<?php

namespace App\Exceptions;

/**
 * 확장 코어 버전 호환성 검사 실패.
 *
 * `update*`/`activate*`/원클릭 복구 등 확장 매니저가 코어 버전 호환성 사전 검증에서
 * 실패할 때 던집니다. 글로벌 Handler 가 HTTP 422 + `error_code: 'core_version_mismatch'`
 * 응답으로 매핑하여 프론트가 일관된 toast/배너/모달 안내를 표시할 수 있게 합니다.
 *
 * **부모 클래스 선택**: 의도적으로 `\Error` 를 상속한다 (IDV 예외와 동일 패턴).
 *
 * 이유: 코어/모듈/플러그인 컨트롤러 다수가 `try { ... } catch (\Exception $e) { ... }`
 * catch-all 로 자체 응답 변환을 수행한다. 본 예외가 `\Exception` 자식이면 그 catch-all
 * 에 포획되어 generic 422 로 강등 → `error_code` 와 구조화 payload 가 모두 사라져
 * 프론트가 일관된 안내(toast/배너/모달)를 표시할 수 없다. `\Error` 는 PHP 의 `\Exception`
 * 과 별도 계층이므로 catch-all 을 통과하여 글로벌 render() 콜백으로 도달한다.
 *
 * 명시 catch 가 필요한 호출자는 `catch (CoreVersionMismatchException $e)` 또는
 * `catch (\Throwable $e)` 로 잡을 수 있다.
 *
 * @since 7.0.0-beta.4
 */
class CoreVersionMismatchException extends \Error
{
    /**
     * @param  string  $extensionType  확장 타입 (module|plugin|template)
     * @param  string  $identifier  확장 식별자
     * @param  string  $requiredCoreVersion  요구된 코어 버전 제약
     * @param  string  $currentCoreVersion  현재 설치된 코어 버전
     * @param  string  $message  사람이 읽을 수 있는 메시지 (이미 번역된 문자열)
     */
    public function __construct(
        public readonly string $extensionType,
        public readonly string $identifier,
        public readonly string $requiredCoreVersion,
        public readonly string $currentCoreVersion,
        string $message = '',
    ) {
        parent::__construct($message ?: __('extensions.errors.core_version_mismatch', [
            'extension' => $identifier,
            'type' => __('extensions.types.'.$extensionType),
            'required' => $requiredCoreVersion,
            'installed' => $currentCoreVersion,
        ]));
    }

    /**
     * 응답 payload 빌더.
     *
     * @return array{extension_type: string, identifier: string, required_core_version: string, current_core_version: string, guide_url: string}
     */
    public function getPayload(): array
    {
        return [
            'extension_type' => $this->extensionType,
            'identifier' => $this->identifier,
            'required_core_version' => $this->requiredCoreVersion,
            'current_core_version' => $this->currentCoreVersion,
            'guide_url' => '/admin/core/update',
        ];
    }
}
