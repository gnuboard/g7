<?php

namespace Plugins\Sirsoft\Sso\Services\Provisioning;

use App\Support\SiteUrl;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;

/**
 * SCIM 2.0 서버 설명 문서 — ServiceProviderConfig, ResourceTypes, Schemas (RFC 7643 §5~§7).
 *
 * 지원 범위: Users, Groups 의 조회, 생성, 교체, 부분 수정, 삭제(= 퇴사 처리). 미지원(`supported:false`): Bulk, 정렬, ETag,
 * 비밀번호 변경(G7 비밀번호는 SCIM 으로 만들지 않는다). 인증은 IdP 마다 발급하는 Bearer 토큰.
 *
 * @since 1.0.0
 */
final class ScimSchema
{
    /** 핵심 User 스키마 */
    public const USER = 'urn:ietf:params:scim:schemas:core:2.0:User';

    /** 기업 확장 User 스키마 */
    public const ENTERPRISE_USER = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';

    /** Group 스키마 */
    public const GROUP = 'urn:ietf:params:scim:schemas:core:2.0:Group';

    /** 목록 응답 */
    public const LIST_RESPONSE = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';

    /** 부분 수정 요청 */
    public const PATCH_OP = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';

    /** 서버 설정 */
    public const SERVICE_PROVIDER_CONFIG = 'urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig';

    /** 자원 종류 */
    public const RESOURCE_TYPE = 'urn:ietf:params:scim:schemas:core:2.0:ResourceType';

    /** 스키마 설명 */
    public const SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:Schema';

    /** 목록 한 번 최대 건수 */
    public const MAX_RESULTS = 100;

    /**
     * SCIM 기준 주소 (`SiteUrl` 기준 — 요청 Host 를 따르지 않는다).
     *
     * @param  IdentityProvider  $idp  IdP
     * @return string 절대 주소
     */
    public static function baseUrl(IdentityProvider $idp): string
    {
        return SiteUrl::absolute('/api/plugins/sirsoft-sso/scim/v2/'.$idp->key);
    }

    /**
     * ServiceProviderConfig 문서.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return array<string, mixed> 문서
     */
    public static function serviceProviderConfig(IdentityProvider $idp): array
    {
        return [
            'schemas' => [self::SERVICE_PROVIDER_CONFIG],
            'patch' => ['supported' => true],
            'bulk' => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
            'filter' => ['supported' => true, 'maxResults' => self::MAX_RESULTS],
            'changePassword' => ['supported' => false],
            'sort' => ['supported' => false],
            'etag' => ['supported' => false],
            'authenticationSchemes' => [[
                'type' => 'oauthbearertoken',
                'name' => 'Bearer Token',
                'description' => 'IdP 마다 발급하는 Bearer 토큰 (Authorization: Bearer …)',
                'primary' => true,
            ]],
            'meta' => ['resourceType' => 'ServiceProviderConfig', 'location' => self::baseUrl($idp).'/ServiceProviderConfig'],
        ];
    }

    /**
     * ResourceTypes 목록.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return array<int, array<string, mixed>> 자원 종류
     */
    public static function resourceTypes(IdentityProvider $idp): array
    {
        $base = self::baseUrl($idp);

        return [
            [
                'schemas' => [self::RESOURCE_TYPE],
                'id' => 'User',
                'name' => 'User',
                'endpoint' => '/Users',
                'schema' => self::USER,
                'schemaExtensions' => [['schema' => self::ENTERPRISE_USER, 'required' => false]],
                'meta' => ['resourceType' => 'ResourceType', 'location' => $base.'/ResourceTypes/User'],
            ],
            [
                'schemas' => [self::RESOURCE_TYPE],
                'id' => 'Group',
                'name' => 'Group',
                'endpoint' => '/Groups',
                'schema' => self::GROUP,
                'meta' => ['resourceType' => 'ResourceType', 'location' => $base.'/ResourceTypes/Group'],
            ],
        ];
    }

    /**
     * Schemas 목록 — 이 서버가 읽고 쓰는 속성만 적는다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return array<int, array<string, mixed>> 스키마
     */
    public static function schemas(IdentityProvider $idp): array
    {
        $base = self::baseUrl($idp);
        $string = static fn (string $name, bool $required = false, string $uniqueness = 'none') => [
            'name' => $name, 'type' => 'string', 'multiValued' => false, 'required' => $required,
            'caseExact' => false, 'mutability' => 'readWrite', 'returned' => 'default', 'uniqueness' => $uniqueness,
        ];

        return [
            [
                'schemas' => [self::SCHEMA],
                'id' => self::USER,
                'name' => 'User',
                'attributes' => [
                    $string('userName', true, 'server'),
                    ['name' => 'name', 'type' => 'complex', 'multiValued' => false, 'required' => false, 'mutability' => 'readWrite', 'returned' => 'default',
                        'subAttributes' => [$string('formatted'), $string('givenName'), $string('familyName')]],
                    $string('displayName'),
                    $string('title'),
                    ['name' => 'active', 'type' => 'boolean', 'multiValued' => false, 'required' => false, 'mutability' => 'readWrite', 'returned' => 'default'],
                    ['name' => 'emails', 'type' => 'complex', 'multiValued' => true, 'required' => false, 'mutability' => 'readWrite', 'returned' => 'default',
                        'subAttributes' => [$string('value'), $string('type'), ['name' => 'primary', 'type' => 'boolean', 'multiValued' => false, 'required' => false, 'mutability' => 'readWrite', 'returned' => 'default']]],
                    ['name' => 'phoneNumbers', 'type' => 'complex', 'multiValued' => true, 'required' => false, 'mutability' => 'readWrite', 'returned' => 'default',
                        'subAttributes' => [$string('value'), $string('type')]],
                ],
                'meta' => ['resourceType' => 'Schema', 'location' => $base.'/Schemas/'.self::USER],
            ],
            [
                'schemas' => [self::SCHEMA],
                'id' => self::ENTERPRISE_USER,
                'name' => 'EnterpriseUser',
                'attributes' => [$string('department'), $string('employeeNumber')],
                'meta' => ['resourceType' => 'Schema', 'location' => $base.'/Schemas/'.self::ENTERPRISE_USER],
            ],
            [
                'schemas' => [self::SCHEMA],
                'id' => self::GROUP,
                'name' => 'Group',
                'attributes' => [
                    $string('displayName', true, 'server'),
                    ['name' => 'members', 'type' => 'complex', 'multiValued' => true, 'required' => false, 'mutability' => 'readWrite', 'returned' => 'default',
                        'subAttributes' => [$string('value'), $string('display')]],
                ],
                'meta' => ['resourceType' => 'Schema', 'location' => $base.'/Schemas/'.self::GROUP],
            ],
        ];
    }

    /**
     * 목록 응답을 만듭니다.
     *
     * @param  array<int, array<string, mixed>>  $resources  자원
     * @param  int  $total  전체 수
     * @param  int  $startIndex  시작 위치 (1부터)
     * @return array<string, mixed> 목록 응답
     */
    public static function listResponse(array $resources, int $total, int $startIndex): array
    {
        return [
            'schemas' => [self::LIST_RESPONSE],
            'totalResults' => $total,
            'startIndex' => $startIndex,
            'itemsPerPage' => count($resources),
            'Resources' => $resources,
        ];
    }

    /**
     * 목록 쪽 매개변수(startIndex, count)를 정규화합니다.
     *
     * @param  mixed  $startIndex  1부터 (1 미만은 1)
     * @param  mixed  $count  건수 (0 은 전체 수만, 상한 100)
     * @return array{start: int, count: int} 값
     */
    public static function page(mixed $startIndex, mixed $count): array
    {
        $start = is_numeric($startIndex) ? max(1, (int) $startIndex) : 1;
        $size = is_numeric($count) ? max(0, min(self::MAX_RESULTS, (int) $count)) : self::MAX_RESULTS;

        return ['start' => $start, 'count' => $size];
    }
}
