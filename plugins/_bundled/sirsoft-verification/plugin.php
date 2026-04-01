<?php

namespace Plugins\Sirsoft\Verification;

use App\Extension\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    /**
     * 플러그인이 제공하는 역할 목록 반환
     *
     * @return array 역할 정의 배열
     */
    public function getRoles(): array
    {
        return [
            [
                'identifier' => 'sirsoft-verification.manager',
                'name' => [
                    'ko' => '본인인증 관리자',
                    'en' => 'Verification Manager',
                ],
                'description' => [
                    'ko' => '본인인증 설정 및 기록을 관리할 수 있는 역할',
                    'en' => 'Role that can manage verification settings and records',
                ],
            ],
        ];
    }

    /**
     * 플러그인이 제공하는 권한 목록 반환
     *
     * @return array 권한 정의 배열
     */
    public function getPermissions(): array
    {
        return [
            'name' => ['ko' => '본인인증', 'en' => 'Identity Verification'],
            'description' => ['ko' => '본인인증 권한', 'en' => 'Identity verification permissions'],
            'categories' => [
                [
                    'identifier' => 'settings',
                    'name' => ['ko' => '설정 관리', 'en' => 'Settings Management'],
                    'description' => ['ko' => '본인인증 설정 관리', 'en' => 'Verification settings management'],
                    'permissions' => [
                        [
                            'action' => 'view',
                            'name' => ['ko' => '본인인증 설정 조회', 'en' => 'View Verification Settings'],
                            'description' => ['ko' => '본인인증 설정을 조회할 수 있는 권한', 'en' => 'Permission to view verification settings'],
                            'roles' => ['admin', 'sirsoft-verification.manager'],
                        ],
                        [
                            'action' => 'update',
                            'name' => ['ko' => '본인인증 설정 수정', 'en' => 'Update Verification Settings'],
                            'description' => ['ko' => '본인인증 설정을 수정할 수 있는 권한', 'en' => 'Permission to update verification settings'],
                            'roles' => ['admin', 'sirsoft-verification.manager'],
                        ],
                    ],
                ],
                [
                    'identifier' => 'user',
                    'name' => ['ko' => '사용자 인증 정보', 'en' => 'User Verification Info'],
                    'description' => ['ko' => '사용자 본인인증 정보 관리', 'en' => 'User verification info management'],
                    'permissions' => [
                        [
                            'action' => 'view',
                            'name' => ['ko' => '사용자 인증 정보 조회', 'en' => 'View User Verification Info'],
                            'description' => ['ko' => '사용자의 본인인증 정보를 조회할 수 있는 권한', 'en' => 'Permission to view user verification information'],
                            'roles' => ['admin', 'sirsoft-verification.manager'],
                        ],
                        [
                            'action' => 'update',
                            'name' => ['ko' => '사용자 인증 정보 수정', 'en' => 'Update User Verification Info'],
                            'description' => ['ko' => '사용자의 본인인증 정보를 수정할 수 있는 권한', 'en' => 'Permission to update user verification information'],
                            'roles' => ['admin', 'sirsoft-verification.manager'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * 플러그인이 제공하는 훅 정보 반환
     *
     * @return array 훅 정의 배열
     */
    public function getHooks(): array
    {
        return [
            [
                'name' => 'sirsoft-verification.user.before_verify',
                'type' => 'action',
                'description' => [
                    'ko' => '사용자 본인인증 시작 전 실행되는 액션 훅',
                    'en' => 'Action hook executed before user identity verification',
                ],
                'parameters' => [
                    'user' => 'Model - 인증할 사용자 모델',
                    'method' => 'string - 인증 방식 (mobile, ipin)',
                ],
            ],
            [
                'name' => 'sirsoft-verification.user.after_verify',
                'type' => 'action',
                'description' => [
                    'ko' => '사용자 본인인증 완료 후 실행되는 액션 훅',
                    'en' => 'Action hook executed after user identity verification',
                ],
                'parameters' => [
                    'user' => 'Model - 인증된 사용자 모델',
                    'result' => 'array - 인증 결과 데이터',
                ],
            ],
            [
                'name' => 'sirsoft-verification.filter_verification_data',
                'type' => 'filter',
                'description' => [
                    'ko' => '본인인증 데이터를 필터링하는 훅',
                    'en' => 'Filter hook to modify verification data',
                ],
                'parameters' => [
                    'data' => 'array - 인증 데이터',
                    'user' => 'Model - 사용자 모델',
                ],
                'return' => 'array - 필터링된 인증 데이터',
            ],
        ];
    }

    /**
     * 플러그인 설정 값 반환
     *
     * @return array 설정 값 배열
     */
    public function getConfigValues(): array
    {
        return [
            'enabled' => false,
            'providers' => [
                'mobile' => [
                    'enabled' => false,
                    'provider' => '',
                ],
                'ipin' => [
                    'enabled' => false,
                    'provider' => '',
                ],
            ],
            'require_on_signup' => false,
            'require_adult_verification' => false,
        ];
    }

    /**
     * 플러그인 메타데이터 반환
     *
     * @return array 메타데이터 배열
     */
    public function getMetadata(): array
    {
        return [
            'author' => 'Sirsoft',
            'license' => 'MIT',
            'homepage' => 'https://sir.kr',
            'keywords' => ['verification', 'identity', 'mobile', 'ipin'],
        ];
    }

}
