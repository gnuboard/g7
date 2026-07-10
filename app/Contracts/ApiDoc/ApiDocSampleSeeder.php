<?php

namespace App\Contracts\ApiDoc;

/**
 * API 문서 실측용 완전 샘플 시더 계약
 *
 * 확장(모듈/플러그인)이 자신의 API 도메인 대표 엔티티에 완전한 샘플 레코드를
 * 멱등하게 생성하고, docgen 이 상세 GET 실측(path 파라미터 치환)에 사용할
 * 도메인별 대표 route key 맵을 제공하기 위한 인터페이스입니다.
 *
 * 확장은 이 인터페이스를 구현한 클래스를 `{Namespace}\Support\ApiDoc\ApiDocSampleService`
 * 규약 위치에 두며, `api:docgen --scope=module:{id}` 실행 시 커맨드가 자동으로 발견합니다.
 * 코어의 `App\Support\ApiDoc\ApiDocSampleService` 도 동일 계약을 구현합니다.
 */
interface ApiDocSampleSeeder
{
    /**
     * 도메인별 완전 샘플을 멱등 생성하고 대표 route key 맵을 반환합니다.
     *
     * 반환 맵의 키는 라우트 도메인 그룹명(`domain_group`, 예: pages)이며,
     * 값은 그 도메인의 상세 GET path 파라미터를 실측 가능한 실제 레코드로
     * 치환하기 위한 모델 FQCN·route key 이름·route key 값입니다.
     *
     * 선택 필드 `path_params`(param 명 => 실제 값 맵)를 두면, route-model binding
     * 도 도메인 폴백도 없는 문자열 path 파라미터(예: 게시판 slug 라우팅
     * `boards/{slug}/posts/{id}`)를 param 명 정확 일치로 치환해 상세 GET 을
     * 실측할 수 있습니다. 미제공 시 route key 기반 자동 치환만 적용됩니다.
     *
     * @return array<string, array{model: class-string, key: string, value: string, path_params?: array<string, string>}> 도메인 => 대표 레코드 정보
     */
    public function seed(): array;
}
