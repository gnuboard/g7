<?php

namespace App\Contracts\Analytics;

/**
 * 서버 측 전환 기록 계약
 *
 * 결제 승인·회원가입처럼 서버만 확실히 아는 순간에 확장·코어가 전환을 남기는 입구입니다.
 * 브라우저의 `G7Core.analytics.trackGoal()` 과 같은 규칙(등록부 조회 → 속성 검증 → 중복
 * 판정)을 거쳐 현재 요청의 방문에 붙습니다.
 *
 * 기록은 실패해도 예외를 던지지 않습니다 — 전환 기록 때문에 주문이나 가입이 실패하면
 * 안 됩니다. 버려진 전환은 진단 화면의 수집 실패 목록에 사유와 함께 남습니다.
 */
interface ConversionRecorderInterface
{
    /**
     * 현재 요청의 방문에 전환을 남깁니다.
     *
     * @param  string  $goalKey  정규화 목표 키 (`{공급자}.{키}`)
     * @param  array<string, mixed>  $attributes  전환 속성 (선언된 스키마로 검증)
     * @param  array<string, string>  $dimensions  함께 남길 차원 값
     * @return void 없음
     */
    public function record(string $goalKey, array $attributes = [], array $dimensions = []): void;
}
