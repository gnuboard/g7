<?php

namespace App\Privacy\Contracts;

/**
 * 직권 실행도 자동 실행 토글이 켜져 있어야 하는 대상의 표지
 *
 * 직권 실행은 원래 토글과 무관하다({@see PrivacyManualRunnerInterface}). 그런데 처리 결과의 효력이
 * 토글에 묶여 있는 조치는 꺼진 채 처리하면 안내와 실제 동작이 어긋난다 — 비밀번호 만료는 꺼져 있으면
 * 로그인 관문이 변경 단계를 띄우지 않으므로, 꺼진 채 만료 처리를 돌리면 회원은 "다음 로그인 때 새
 * 비밀번호를 설정한다" 는 안내와 함께 로그아웃되지만 다시 로그인하면 그냥 들어간다(#736, 2026-10-03 확정).
 *
 * 이 표지를 단 대상은 {@see PrivacyManualRunnerInterface::isEnabled()} 가 거짓이면 코어가 접수를
 * 거부하고(409), 러너도 큐에서 실행될 때 토글을 다시 보고 꺼져 있으면 처리하지 않는다(접수 뒤 꺼진 경우).
 * 메서드는 없다 — 기존 계약을 구현한 확장은 영향이 없다.
 */
interface PrivacyManualRunRequiresEnabledInterface {}
