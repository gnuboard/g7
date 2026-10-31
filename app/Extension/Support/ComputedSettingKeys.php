<?php

namespace App\Extension\Support;

use Illuminate\Support\Arr;

/**
 * 확장 설정의 「계산값 키」 — 매번 계산하는 값이라 설정 파일에 저장하지 않는 키 (7.1.3).
 *
 * 확장은 `getComputedSettingKeys()`(AbstractModule·AbstractPlugin 기본 `[]`)로 이런 키를 선언한다. 코어는
 * ① 설치 때 설정 파일에 쓰지 않고 ② 저장 요청·기존 파일에서 빼고 ③ 읽을 때는 저장값을 무시하고 늘
 * `getConfigValues()` 의 실시간 값을 쓴다. 예전에는 설치 때 그 순간의 계산값이 파일에 굳어, 이후 실시간 값을
 * 영원히 덮었다(GDPR 필수 저장 키 목록).
 *
 * 인터페이스를 직접 구현한 확장(추상 클래스 미상속)도 깨지지 않도록 메서드 존재를 확인해 읽는다.
 */
final class ComputedSettingKeys
{
    /**
     * 확장이 선언한 계산값 키를 읽습니다.
     *
     * @param  object  $extension  모듈·플러그인 인스턴스
     * @return array<int, string> 계산값 키 (도트 표기 가능)
     */
    public static function of(object $extension): array
    {
        if (! method_exists($extension, 'getComputedSettingKeys')) {
            return [];
        }

        return array_values(array_filter((array) $extension->getComputedSettingKeys(), 'is_string'));
    }

    /**
     * 설정 배열에서 계산값 키를 뺍니다.
     *
     * @param  array<string, mixed>  $settings  설정 배열
     * @param  array<int, string>  $keys  계산값 키 (도트 표기 가능)
     * @return array<string, mixed> 계산값 키를 뺀 설정
     */
    public static function strip(array $settings, array $keys): array
    {
        return $keys === [] ? $settings : Arr::except($settings, $keys);
    }
}
