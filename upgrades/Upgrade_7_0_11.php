<?php

namespace App\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * 코어 7.0.11 업그레이드 스텝
 *
 * 모든 비즈니스 로직은 본 클래스 파일이 아닌 `upgrades/data/7.0.11/` 안에 격리된다:
 *
 *   - migrations/
 *       01_NarrowImageObjectPropsInLayouts.php
 *         저장된 레이아웃의 **prop 자리**에 통째로 들어간 이미지 값 객체
 *         (`{url,size,repeat,position}`)를 그 `url` 문자열로 축약한다. 레이아웃 편집기의
 *         `image` 위젯이 배경용 객체를 내보내는데 `propValue` 경로가 그것을 그대로
 *         기록해, 소비 컴포넌트가 `<Img src={객체}>` 로 받아 `[object Object]` 를 URL 로
 *         해석하던 결함(공개 #135)의 **기존 데이터 보정**이다. 편집기 쪽 소스 교정만으로는
 *         이미 저장된 설치본이 낫지 않는다.
 *
 * 실행 순서는 파일명 정렬(`sort()`)을 따른다.
 *
 * 본 클래스는 `AbstractUpgradeStep` 의 default `run()` 에 위임 — 별도 override 없음
 * (`run()` 은 final 이라 override 자체가 불가능하다).
 *
 * @upgrade-path 7.0.x → 7.0.11
 *
 * 의존성 제약: 본 스텝은 변환/핫픽스를 `data/7.0.11/migrations/` 의 버전 namespace
 * 클래스에 위임한다. 미래 버전에서 *그 디렉토리는 동결* (수정 금지) 되어 "각 스텝별 동작
 * 100% 동일 보장" invariant 가 성립.
 *
 * 상세: docs/extension/upgrade-step-guide.md §13 "버전별 데이터 스냅샷"
 */
class Upgrade_7_0_11 extends AbstractUpgradeStep
{
    // 모든 로직 위임 — data/7.0.11/ 가 SSoT.
}
