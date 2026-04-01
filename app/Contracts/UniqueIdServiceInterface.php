<?php

namespace App\Contracts;

/**
 * 코어 고유 ID 생성 서비스 인터페이스
 *
 * UUID v7 및 NanoID 생성 기능을 제공합니다.
 */
interface UniqueIdServiceInterface
{
    /**
     * UUID v7을 생성합니다.
     *
     * 시간 기반 정렬 가능한 UUID를 반환합니다.
     *
     * @return string UUID v7 문자열 (36자)
     */
    public function generateUuid(): string;

    /**
     * NanoID를 생성합니다.
     *
     * 암호학적으로 안전한 랜덤 문자열을 반환합니다.
     * Rejection sampling으로 모듈로 편향을 방지합니다.
     *
     * @param int $length 생성할 문자열 길이
     * @param string|null $alphabet 사용할 문자 집합 (null이면 기본 알파벳)
     * @return string 생성된 NanoID
     */
    public function generateNanoId(int $length = 16, ?string $alphabet = null): string;
}
