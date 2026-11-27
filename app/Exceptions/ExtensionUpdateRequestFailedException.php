<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * 웹(관리자 화면) 확장 업데이트 실패 — 실패 뒤 상태(outcome)·경고·재실행 명령을 응답까지 싣는다 (7.2.1 출시 전).
 *
 * 서비스는 매니저 실패를 422 검증 오류로 감싸 왔는데, 그 과정에서 {@see ExtensionUpdateFailedException} 의 `outcome` 과 경고가
 * 사라져 화면은 「실패」 한 줄만 보였다. 이 예외는 같은 422 검증 오류(기존 응답 모양 유지)이면서 `data` 로 실을 값을 함께 갖는다.
 */
class ExtensionUpdateRequestFailedException extends ValidationException
{
    /**
     * 응답 `data` 로 실을 값.
     *
     * @var array{outcome: string|null, warnings: array<int, mixed>, retry_command: string|null}
     */
    public array $failureData = ['outcome' => null, 'warnings' => [], 'retry_command' => null];

    /**
     * 실패 메시지와 응답 값을 갖는 예외를 만듭니다.
     *
     * @param  string  $field  오류 칸 이름 (module_name 등 — 기존 응답과 같게)
     * @param  string  $message  번역된 실패 문구
     * @param  array{outcome: string|null, warnings: array<int, mixed>, retry_command: string|null}  $failureData  응답 `data`
     * @return self 예외
     */
    public static function make(string $field, string $message, array $failureData): self
    {
        /** @var self $e */
        $e = static::withMessages([$field => [$message]]);
        $e->failureData = $failureData;

        return $e;
    }
}
