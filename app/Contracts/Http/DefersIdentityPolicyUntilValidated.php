<?php

namespace App\Contracts\Http;

/**
 * 라우트 범위 본인인증 정책을 입력 검증을 통과한 뒤에 강제받는 폼 요청의 표식입니다.
 *
 * 라우트 미들웨어(`EnforceIdentityPolicy`)는 컨트롤러가 이 표식을 단 폼 요청을 받으면 정책을 앞에서 강제하지 않고 요청에 맡겨 두고,
 * 폼 요청은 검증을 통과한 뒤(`passedValidation()`) `IdentityPolicyService::enforceDeferredRoutePolicies()` 로 같은 판정을 한다.
 * 그래서 입력 오류(422)를 본인 확인 요구(428)보다 먼저 알린다 — 빈 칸으로 제출해도 인증부터 시키지 않고, 인증을 마친 뒤에야
 * 입력 오류를 보는 일도 없다.
 *
 * 입력 검증이 정책보다 먼저 돌면 곤란한 작업(현재 비밀번호 확인처럼 검증 자체가 민감한 판정인 작업)에는 달지 않는다.
 *
 * @since 7.2.0
 */
interface DefersIdentityPolicyUntilValidated {}
