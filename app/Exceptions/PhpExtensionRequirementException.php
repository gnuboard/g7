<?php

namespace App\Exceptions;

/**
 * 확장(모듈·플러그인)이 요구하는 PHP 확장 미충족.
 *
 * 확장 composer.json 의 `require.ext-*` 가 서버에 없으면 설치·활성화를 막을 때 던집니다.
 * 글로벌 Handler 가 HTTP 422 + `error_code: 'php_extension_missing'` 응답으로 매핑합니다.
 * (`suggest.ext-*` 는 권장이라 막지 않고, 업데이트는 경고만 남깁니다.)
 *
 * **부모 클래스 선택**: `CoreVersionMismatchException` 과 같은 이유로 `\Error` 를 상속한다 —
 * 컨트롤러의 `catch (\Exception)` catch-all 에 포획되어 generic 응답으로 강등되지 않고
 * 글로벌 render() 콜백까지 도달하게 한다.
 *
 * @since 7.1.4
 */
class PhpExtensionRequirementException extends \Error
{
    /**
     * @param  string  $extensionType  확장 타입 (module|plugin)
     * @param  string  $identifier  확장 식별자
     * @param  list<string>  $missingExtensions  서버에 없는 PHP 확장 이름
     * @param  string  $name  사람이 읽는 확장 이름 (비우면 식별자)
     */
    public function __construct(
        public readonly string $extensionType,
        public readonly string $identifier,
        public readonly array $missingExtensions,
        string $name = '',
    ) {
        parent::__construct(__('extensions.errors.php_extension_missing', [
            'name' => $name !== '' ? $name : $identifier,
            'extensions' => implode(', ', $missingExtensions),
        ]));
    }

    /**
     * 응답 payload 빌더.
     *
     * @return array{extension_type: string, identifier: string, missing_extensions: list<string>} 확장 타입·식별자·없는 PHP 확장 목록
     */
    public function getPayload(): array
    {
        return [
            'extension_type' => $this->extensionType,
            'identifier' => $this->identifier,
            'missing_extensions' => $this->missingExtensions,
        ];
    }
}
