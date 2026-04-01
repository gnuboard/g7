<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Laravel 기본 검증 메시지
    |--------------------------------------------------------------------------
    |
    | Laravel의 기본 검증 규칙에 대한 한국어 메시지입니다.
    |
    */

    'accepted' => ':attribute 필드를 승인해야 합니다.',
    'accepted_if' => ':other이(가) :value일 때 :attribute 필드를 승인해야 합니다.',
    'active_url' => ':attribute 필드는 유효한 URL이어야 합니다.',
    'after' => ':attribute 필드는 :date 이후 날짜여야 합니다.',
    'after_or_equal' => ':attribute 필드는 :date 이후 또는 같은 날짜여야 합니다.',
    'alpha' => ':attribute 필드는 문자만 포함해야 합니다.',
    'alpha_dash' => ':attribute 필드는 문자, 숫자, 대시, 밑줄만 포함해야 합니다.',
    'alpha_num' => ':attribute 필드는 문자와 숫자만 포함해야 합니다.',
    'array' => ':attribute 필드는 배열이어야 합니다.',
    'ascii' => ':attribute 필드는 싱글바이트 영숫자 문자와 기호만 포함해야 합니다.',
    'before' => ':attribute 필드는 :date 이전 날짜여야 합니다.',
    'before_or_equal' => ':attribute 필드는 :date 이전 또는 같은 날짜여야 합니다.',
    'between' => [
        'array' => ':attribute 필드는 :min ~ :max개의 항목을 포함해야 합니다.',
        'file' => ':attribute 필드는 :min ~ :max KB 사이여야 합니다.',
        'numeric' => ':attribute 필드는 :min ~ :max 사이여야 합니다.',
        'string' => ':attribute 필드는 :min ~ :max자 사이여야 합니다.',
    ],
    'boolean' => ':attribute 필드는 true 또는 false여야 합니다.',
    'can' => ':attribute 필드에 허용되지 않은 값이 포함되어 있습니다.',
    'confirmed' => ':attribute 필드 확인이 일치하지 않습니다.',
    'contains' => ':attribute 필드에 필수 값이 누락되었습니다.',
    'current_password' => '비밀번호가 올바르지 않습니다.',
    'date' => ':attribute 필드는 유효한 날짜여야 합니다.',
    'date_equals' => ':attribute 필드는 :date와 같은 날짜여야 합니다.',
    'date_format' => ':attribute 필드는 :format 형식과 일치해야 합니다.',
    'decimal' => ':attribute 필드는 :decimal 자릿수의 소수점이어야 합니다.',
    'declined' => ':attribute 필드를 거부해야 합니다.',
    'declined_if' => ':other이(가) :value일 때 :attribute 필드를 거부해야 합니다.',
    'different' => ':attribute 필드와 :other은(는) 달라야 합니다.',
    'digits' => ':attribute 필드는 :digits 자릿수여야 합니다.',
    'digits_between' => ':attribute 필드는 :min ~ :max 자릿수 사이여야 합니다.',
    'dimensions' => ':attribute 필드의 이미지 크기가 올바르지 않습니다.',
    'distinct' => ':attribute 필드에 중복된 값이 있습니다.',
    'doesnt_end_with' => ':attribute 필드는 다음으로 끝나면 안 됩니다: :values.',
    'doesnt_start_with' => ':attribute 필드는 다음으로 시작하면 안 됩니다: :values.',
    'email' => ':attribute 필드는 유효한 이메일 주소여야 합니다.',
    'ends_with' => ':attribute 필드는 다음 중 하나로 끝나야 합니다: :values.',
    'enum' => '선택한 :attribute이(가) 올바르지 않습니다.',
    'exists' => '선택한 :attribute이(가) 올바르지 않습니다.',
    'extensions' => ':attribute 필드는 다음 확장자 중 하나여야 합니다: :values.',
    'file' => ':attribute 필드는 파일이어야 합니다.',
    'filled' => ':attribute 필드에 값이 있어야 합니다.',
    'gt' => [
        'array' => ':attribute 필드는 :value개보다 많은 항목을 포함해야 합니다.',
        'file' => ':attribute 필드는 :value KB보다 커야 합니다.',
        'numeric' => ':attribute 필드는 :value보다 커야 합니다.',
        'string' => ':attribute 필드는 :value자보다 길어야 합니다.',
    ],
    'gte' => [
        'array' => ':attribute 필드는 :value개 이상의 항목을 포함해야 합니다.',
        'file' => ':attribute 필드는 :value KB 이상이어야 합니다.',
        'numeric' => ':attribute 필드는 :value 이상이어야 합니다.',
        'string' => ':attribute 필드는 :value자 이상이어야 합니다.',
    ],
    'hex_color' => ':attribute 필드는 유효한 16진수 색상이어야 합니다.',
    'image' => ':attribute 필드는 이미지여야 합니다.',
    'in' => '선택한 :attribute이(가) 올바르지 않습니다.',
    'in_array' => ':attribute 필드는 :other에 존재해야 합니다.',
    'integer' => ':attribute 필드는 정수여야 합니다.',
    'ip' => ':attribute 필드는 유효한 IP 주소여야 합니다.',
    'ipv4' => ':attribute 필드는 유효한 IPv4 주소여야 합니다.',
    'ipv6' => ':attribute 필드는 유효한 IPv6 주소여야 합니다.',
    'json' => ':attribute 필드는 유효한 JSON 문자열이어야 합니다.',
    'list' => ':attribute 필드는 목록이어야 합니다.',
    'lowercase' => ':attribute 필드는 소문자여야 합니다.',
    'lt' => [
        'array' => ':attribute 필드는 :value개보다 적은 항목을 포함해야 합니다.',
        'file' => ':attribute 필드는 :value KB보다 작아야 합니다.',
        'numeric' => ':attribute 필드는 :value보다 작아야 합니다.',
        'string' => ':attribute 필드는 :value자보다 짧아야 합니다.',
    ],
    'lte' => [
        'array' => ':attribute 필드는 :value개를 초과하면 안 됩니다.',
        'file' => ':attribute 필드는 :value KB 이하여야 합니다.',
        'numeric' => ':attribute 필드는 :value 이하여야 합니다.',
        'string' => ':attribute 필드는 :value자 이하여야 합니다.',
    ],
    'mac_address' => ':attribute 필드는 유효한 MAC 주소여야 합니다.',
    'max' => [
        'array' => ':attribute 필드는 :max개를 초과하면 안 됩니다.',
        'file' => ':attribute 필드는 :max KB를 초과하면 안 됩니다.',
        'numeric' => ':attribute 필드는 :max를 초과하면 안 됩니다.',
        'string' => ':attribute 필드는 :max자를 초과하면 안 됩니다.',
    ],
    'max_digits' => ':attribute 필드는 :max 자릿수를 초과하면 안 됩니다.',
    'mimes' => ':attribute 필드는 다음 유형의 파일이어야 합니다: :values.',
    'mimetypes' => ':attribute 필드는 다음 유형의 파일이어야 합니다: :values.',
    'min' => [
        'array' => ':attribute 필드는 최소 :min개 이상이어야 합니다.',
        'file' => ':attribute 필드는 최소 :min KB 이상이어야 합니다.',
        'numeric' => ':attribute 필드는 최소 :min 이상이어야 합니다.',
        'string' => ':attribute 필드는 최소 :min자 이상이어야 합니다.',
    ],
    'min_digits' => ':attribute 필드는 최소 :min 자릿수 이상이어야 합니다.',
    'missing' => ':attribute 필드가 없어야 합니다.',
    'missing_if' => ':other이(가) :value일 때 :attribute 필드가 없어야 합니다.',
    'missing_unless' => ':other이(가) :value이(가) 아닌 경우 :attribute 필드가 없어야 합니다.',
    'missing_with' => ':values이(가) 있을 때 :attribute 필드가 없어야 합니다.',
    'missing_with_all' => ':values이(가) 모두 있을 때 :attribute 필드가 없어야 합니다.',
    'multiple_of' => ':attribute 필드는 :value의 배수여야 합니다.',
    'not_in' => '선택한 :attribute이(가) 올바르지 않습니다.',
    'not_regex' => ':attribute 필드 형식이 올바르지 않습니다.',
    'numeric' => ':attribute 필드는 숫자여야 합니다.',
    'password' => [
        'letters' => ':attribute 필드는 최소 하나의 문자를 포함해야 합니다.',
        'mixed' => ':attribute 필드는 최소 하나의 대문자와 소문자를 포함해야 합니다.',
        'numbers' => ':attribute 필드는 최소 하나의 숫자를 포함해야 합니다.',
        'symbols' => ':attribute 필드는 최소 하나의 기호를 포함해야 합니다.',
        'uncompromised' => '주어진 :attribute이(가) 데이터 유출에 나타났습니다. 다른 :attribute을(를) 선택해 주세요.',
    ],
    'present' => ':attribute 필드가 있어야 합니다.',
    'present_if' => ':other이(가) :value일 때 :attribute 필드가 있어야 합니다.',
    'present_unless' => ':other이(가) :value이(가) 아닌 경우 :attribute 필드가 있어야 합니다.',
    'present_with' => ':values이(가) 있을 때 :attribute 필드가 있어야 합니다.',
    'present_with_all' => ':values이(가) 모두 있을 때 :attribute 필드가 있어야 합니다.',
    'prohibited' => ':attribute 필드는 금지되어 있습니다.',
    'prohibited_if' => ':other이(가) :value일 때 :attribute 필드는 금지되어 있습니다.',
    'prohibited_unless' => ':other이(가) :values에 없는 경우 :attribute 필드는 금지되어 있습니다.',
    'prohibits' => ':attribute 필드는 :other이(가) 존재하는 것을 금지합니다.',
    'regex' => ':attribute 필드 형식이 올바르지 않습니다.',
    'required' => ':attribute 필드는 필수입니다.',
    'required_array_keys' => ':attribute 필드는 다음 항목을 포함해야 합니다: :values.',
    'required_if' => ':other이(가) :value일 때 :attribute 필드는 필수입니다.',
    'required_if_accepted' => ':other이(가) 승인되면 :attribute 필드는 필수입니다.',
    'required_if_declined' => ':other이(가) 거부되면 :attribute 필드는 필수입니다.',
    'required_unless' => ':other이(가) :values에 없는 경우 :attribute 필드는 필수입니다.',
    'required_with' => ':values이(가) 있을 때 :attribute 필드는 필수입니다.',
    'required_with_all' => ':values이(가) 모두 있을 때 :attribute 필드는 필수입니다.',
    'required_without' => ':values이(가) 없을 때 :attribute 필드는 필수입니다.',
    'required_without_all' => ':values이(가) 모두 없을 때 :attribute 필드는 필수입니다.',
    'same' => ':attribute 필드와 :other이(가) 일치해야 합니다.',
    'size' => [
        'array' => ':attribute 필드는 :size개의 항목을 포함해야 합니다.',
        'file' => ':attribute 필드는 :size KB여야 합니다.',
        'numeric' => ':attribute 필드는 :size여야 합니다.',
        'string' => ':attribute 필드는 :size자여야 합니다.',
    ],
    'starts_with' => ':attribute 필드는 다음 중 하나로 시작해야 합니다: :values.',
    'string' => ':attribute 필드는 문자열이어야 합니다.',
    'timezone' => ':attribute 필드는 유효한 시간대여야 합니다.',
    'unique' => ':attribute은(는) 이미 사용 중입니다.',
    'uploaded' => ':attribute 업로드에 실패했습니다.',
    'uppercase' => ':attribute 필드는 대문자여야 합니다.',
    'url' => ':attribute 필드는 유효한 URL이어야 합니다.',
    'ulid' => ':attribute 필드는 유효한 ULID여야 합니다.',
    'uuid' => ':attribute 필드는 유효한 UUID여야 합니다.',

    /*
    |--------------------------------------------------------------------------
    | 프로젝트 검증 메시지
    |--------------------------------------------------------------------------
    */

    // 레이아웃 구조 검증 메시지
    'layout' => [
        'invalid_json' => '유효하지 않은 JSON 형식입니다.',
        'must_be_array' => '레이아웃 데이터는 배열이어야 합니다.',
        'required_field_missing' => "필수 필드 ':field'가 누락되었습니다.",
        'version_must_be_string' => 'version 필드는 문자열이어야 합니다.',
        'layout_name_must_be_string' => 'layout_name 필드는 문자열이어야 합니다.',
        'components_must_be_array' => 'components 필드는 배열이어야 합니다.',
        'components_or_slots_required' => '상속 레이아웃은 components 또는 slots 중 하나가 필요합니다.',
        'component_must_be_array' => 'components[:index]는 배열이어야 합니다.',
        'max_depth_exceeded' => '컴포넌트 중첩 깊이가 최대 허용 깊이(:max)를 초과했습니다.',
        'component_required_field_missing' => "components[:index]에 필수 필드 ':field'가 누락되었습니다.",
        'component_field_must_be_string' => 'components[:index].component는 문자열이어야 합니다.',
        'component_name_must_be_string' => 'components[:index].name은 문자열이어야 합니다.',
        'component_type_invalid' => 'components[:index].type은 basic, composite, layout 중 하나여야 합니다.',
        'props_must_be_object' => 'components[:index].props는 객체(배열)여야 합니다.',
        'children_must_be_array' => 'components[:index].children은 배열이어야 합니다.',
        'permissions_must_be_array' => 'components[:index].permissions는 배열이어야 합니다.',
        'permissions_must_be_array_or_object' => 'permissions는 배열 또는 구조화 객체(or/and)여야 합니다.',
        'permissions_invalid_operator' => 'permissions 구조에서 유효한 연산자는 "or" 또는 "and"만 허용됩니다. 키는 정확히 1개여야 합니다.',
        'permissions_operator_must_be_array' => 'permissions의 ":operator" 연산자 값은 배열이어야 합니다.',
        'permissions_operator_min_items' => 'permissions의 ":operator" 연산자에는 최소 :min개 항목이 필요합니다.',
        'permissions_max_depth_exceeded' => 'permissions 구조의 최대 중첩 깊이(:max)를 초과했습니다.',
        'permission_must_be_string' => 'components[:index].permissions[:perm_index]는 문자열이어야 합니다.',
        'permission_must_be_string_or_group' => 'permissions의 항목[:index]은 문자열(권한 식별자) 또는 구조화 객체(or/and)여야 합니다.',
        'permission_invalid_format' => 'components[:index].permissions의 ":permission"은 올바른 권한 식별자 형식이 아닙니다.',
        'actions_must_be_array' => 'components[:index].actions는 배열이어야 합니다.',
        'action_must_be_array' => 'components[:index].actions[:actionIndex]는 배열이어야 합니다.',
        'action_type_missing' => "components[:index].actions[:actionIndex]에 필수 필드 'type'이 누락되었습니다.",
        'action_type_or_event_missing' => "components[:index].actions[:actionIndex]에 'type' 또는 'event' 중 하나가 필요합니다.",
        'action_type_must_be_string' => 'components[:index].actions[:actionIndex].type은 문자열이어야 합니다.',
        'action_event_must_be_string' => 'components[:index].actions[:actionIndex].event는 문자열이어야 합니다.',
        // UpdateLayoutRequest 검증 메시지
        'content' => [
            'required' => '레이아웃 content가 필요합니다.',
            'array' => '레이아웃 content는 배열이어야 합니다.',
        ],
        'version' => [
            'required' => '레이아웃 버전이 필요합니다.',
            'string' => '레이아웃 버전은 문자열이어야 합니다.',
        ],
        'layout_name' => [
            'required' => '레이아웃 이름이 필요합니다.',
            'string' => '레이아웃 이름은 문자열이어야 합니다.',
            'max' => '레이아웃 이름은 :max자를 초과할 수 없습니다.',
        ],
        'endpoint' => [
            'required' => 'API 엔드포인트가 필요합니다.',
            'string' => 'API 엔드포인트는 문자열이어야 합니다.',
        ],
        'extends' => [
            'string' => 'extends는 문자열이어야 합니다.',
        ],
        'slots' => [
            'array' => 'slots는 배열이어야 합니다.',
        ],
        'components' => [
            'required' => '컴포넌트 배열이 필요합니다.',
            'array' => 'components 필드는 배열이어야 합니다.',
        ],
        'data_sources' => [
            'array' => 'data_sources 필드는 배열이어야 합니다.',
        ],
        'metadata' => [
            'array' => 'metadata 필드는 배열이어야 합니다.',
        ],
        'meta' => [
            'array' => 'meta 필드는 배열이어야 합니다.',
            'title' => [
                'string' => 'meta.title은 문자열이어야 합니다.',
            ],
            'description' => [
                'string' => 'meta.description은 문자열이어야 합니다.',
            ],
            'auth_required' => [
                'boolean' => 'meta.auth_required는 불린이어야 합니다.',
            ],
            'is_base' => [
                'boolean' => 'meta.is_base는 불린이어야 합니다.',
            ],
        ],
        'modals' => [
            'array' => 'modals 필드는 배열이어야 합니다.',
        ],
        'state' => [
            'array' => 'state 필드는 배열이어야 합니다.',
        ],
        'init_actions' => [
            'array' => 'init_actions 필드는 배열이어야 합니다.',
        ],
        'defines' => [
            'array' => 'defines 필드는 배열이어야 합니다.',
        ],
        'init_state' => [
            'array' => 'init_state 필드는 배열이어야 합니다.',
        ],
        'routes' => [
            'array' => 'routes 필드는 배열이어야 합니다.',
        ],
        'computed' => [
            'array' => 'computed 필드는 배열이어야 합니다.',
        ],
        'permissions' => [
            'array' => 'permissions 필드는 배열이어야 합니다.',
            'string' => '각 권한 식별자는 문자열이어야 합니다.',
            'regex' => '권한 식별자 형식이 올바르지 않습니다. (예: module.entity.action)',
        ],
        'globalHeaders' => [
            'array' => 'globalHeaders 필드는 배열이어야 합니다.',
            'pattern' => [
                'required' => '각 globalHeaders 규칙에는 pattern 필드가 필요합니다.',
                'string' => 'globalHeaders pattern은 문자열이어야 합니다.',
            ],
            'headers' => [
                'required' => '각 globalHeaders 규칙에는 headers 필드가 필요합니다.',
                'array' => 'globalHeaders headers는 배열이어야 합니다.',
                'string' => '헤더 값은 문자열이어야 합니다.',
            ],
        ],
        'transition_overlay' => [
            'enabled' => [
                'boolean' => '전환 오버레이 활성화는 true 또는 false 값이어야 합니다.',
            ],
            'style' => [
                'string' => '전환 오버레이 스타일은 문자열이어야 합니다.',
                'in' => '전환 오버레이 스타일은 opaque, blur, fade, skeleton 중 하나여야 합니다.',
            ],
            'target' => [
                'string' => '전환 오버레이 타겟은 문자열이어야 합니다.',
                'max' => '전환 오버레이 타겟은 :max자를 초과할 수 없습니다.',
            ],
            'fallback_target' => [
                'string' => '전환 오버레이 대체 타겟은 문자열이어야 합니다.',
                'max' => '전환 오버레이 대체 타겟은 :max자를 초과할 수 없습니다.',
            ],
            'skeleton' => [
                'array' => '스켈레톤 설정은 배열이어야 합니다.',
                'component' => [
                    'string' => '스켈레톤 컴포넌트 이름은 문자열이어야 합니다.',
                    'max' => '스켈레톤 컴포넌트 이름은 :max자를 초과할 수 없습니다.',
                ],
                'animation' => [
                    'string' => '스켈레톤 애니메이션은 문자열이어야 합니다.',
                    'in' => '스켈레톤 애니메이션은 pulse, wave, none 중 하나여야 합니다.',
                ],
                'iteration_count' => [
                    'integer' => '스켈레톤 반복 횟수는 정수여야 합니다.',
                    'min' => '스켈레톤 반복 횟수는 최소 :min이어야 합니다.',
                    'max' => '스켈레톤 반복 횟수는 최대 :max를 초과할 수 없습니다.',
                ],
            ],
        ],
    ],

    // API 엔드포인트 검증 메시지
    'endpoint' => [
        'must_be_string' => 'API 엔드포인트는 문자열이어야 합니다.',
        'external_url_not_allowed' => '외부 URL은 허용되지 않습니다.',
        'not_whitelisted' => '허용되지 않은 API 엔드포인트입니다. 허용된 패턴: :pattern',
        'path_traversal_detected' => '경로 트래버설 공격이 감지되었습니다.',
    ],

    // 외부 URL 차단 검증 메시지
    'external_url' => [
        'detected_in_props' => 'props에서 외부 URL이 감지되었습니다: :url',
        'detected_in_actions' => 'actions에서 외부 URL이 감지되었습니다: :url',
        'http_not_allowed' => 'HTTP 프로토콜 URL은 허용되지 않습니다.',
        'https_not_allowed' => 'HTTPS 프로토콜 URL은 허용되지 않습니다.',
        'data_uri_not_allowed' => 'Data URI 스킴은 허용되지 않습니다.',
        'javascript_uri_not_allowed' => 'JavaScript URI 스킴은 허용되지 않습니다.',
        'dangerous_scheme_detected' => '위험한 URI 스킴이 감지되었습니다: :scheme',
    ],

    // 컴포넌트 존재 여부 검증 메시지
    'component' => [
        'template_id_required' => '컴포넌트 검증을 위해서는 template_id가 필요합니다.',
        'manifest_not_found' => '템플릿 :templateId의 컴포넌트 매니페스트(components.json)를 찾을 수 없습니다.',
        'name_empty' => 'components[:index].component 이름은 비어있을 수 없습니다.',
        'not_found' => "컴포넌트 ':component'는 템플릿에 등록되지 않았습니다. (components[:index])",
    ],

    // FormRequest 필드 검증 메시지
    'request' => [
        'template_id' => [
            'required' => '템플릿 ID는 필수입니다.',
            'integer' => '템플릿 ID는 정수여야 합니다.',
            'exists' => '존재하지 않는 템플릿입니다.',
        ],
        'layout_name' => [
            'required' => '레이아웃 이름은 필수입니다.',
            'string' => '레이아웃 이름은 문자열이어야 합니다.',
            'max' => '레이아웃 이름은 :max자를 초과할 수 없습니다.',
            'unique' => '이미 존재하는 레이아웃 이름입니다.',
        ],
        'content' => [
            'required' => '레이아웃 내용은 필수입니다.',
            'array' => '레이아웃 내용은 배열(객체)이어야 합니다.',
        ],
    ],

    // 레이아웃 상속 검증 메시지
    'layout_inheritance' => [
        // 부모 레이아웃 검증
        'parent_not_found' => '부모 레이아웃 ":parent"를 찾을 수 없습니다.',
        'parent_not_in_same_template' => '부모 레이아웃은 같은 템플릿 내에 있어야 합니다.',
        'circular_reference' => '레이아웃 순환 참조가 감지되었습니다: :trace',
        'max_depth_exceeded' => '레이아웃 상속 깊이가 최대 허용 깊이(:max)를 초과했습니다.',
        'extends_must_be_string' => 'extends 필드는 문자열이어야 합니다.',

        // 슬롯 검증
        'slots_must_be_object' => 'slots 필드는 객체여야 합니다.',
        'slot_name_must_be_string' => '슬롯 이름은 문자열이어야 합니다.',
        'slot_value_must_be_array' => 'slots[:slotName]은 배열이어야 합니다.',
        'slot_not_defined_in_parent' => '슬롯 ":slotName"은 부모 레이아웃에 정의되지 않았습니다.',
        'parent_has_no_slots' => '부모 레이아웃에는 슬롯이 정의되지 않았습니다.',

        // 데이터 소스 병합 검증
        'data_source_id_duplicate' => 'data_sources에 중복된 ID ":id"가 있습니다.',
        'data_sources_must_be_array' => 'data_sources는 배열이어야 합니다.',
        'data_source_must_have_id' => 'data_sources[:index]에 필수 필드 "id"가 누락되었습니다.',
        'data_source_id_must_be_string' => 'data_sources[:index].id는 문자열이어야 합니다.',
    ],

    // 다국어 필드 검증 메시지
    'translatable' => [
        'must_be_array' => '다국어 필드는 배열이어야 합니다.',
        'unsupported_language' => "지원되지 않는 언어 코드입니다: ':lang'",
        'must_be_string' => "':lang' 번역은 문자열이어야 합니다.",
        'max_length' => "':lang' 번역은 :max자를 초과할 수 없습니다.",
        'min_length' => "':lang' 번역은 최소 :min자 이상이어야 합니다.",
        'at_least_one_required' => '최소 하나의 언어로 번역이 필요합니다.',
        'current_locale_required' => ':locale 언어의 값은 필수입니다.',
    ],

    // 템플릿 검증 메시지
    'template' => [
        'type' => [
            'in' => 'type 파라미터는 user 또는 admin만 가능합니다.',
        ],
        'description' => [
            'string' => '템플릿 설명은 문자열이어야 합니다.',
            'max' => '템플릿 설명은 :max자를 초과할 수 없습니다.',
        ],
        'metadata' => [
            'array' => 'metadata는 배열이어야 합니다.',
        ],
        'status' => [
            'in' => 'status는 active 또는 inactive여야 합니다.',
        ],
    ],

    // 메뉴 검증 메시지
    'menu' => [
        'name' => [
            'required' => '메뉴 이름을 입력해주세요.',
        ],
        'slug' => [
            'required' => '슬러그를 입력해주세요.',
            'unique' => '이미 사용 중인 슬러그입니다.',
            'max' => '슬러그는 :max자를 초과할 수 없습니다.',
        ],
        'url' => [
            'required' => '경로(URL)를 입력해주세요.',
            'max' => 'URL은 :max자를 초과할 수 없습니다.',
        ],
        'icon' => [
            'max' => '아이콘은 :max자를 초과할 수 없습니다.',
        ],
        'order' => [
            'integer' => '순서는 정수여야 합니다.',
            'min' => '순서는 :min 이상이어야 합니다.',
        ],
        'parent_id' => [
            'exists' => '존재하지 않는 부모 메뉴입니다.',
        ],
        'is_active' => [
            'boolean' => '활성화 상태는 true 또는 false 값이어야 합니다.',
        ],
        'extension_type' => [
            'in' => '확장 타입은 core, module, plugin 중 하나여야 합니다.',
        ],
        'extension_identifier' => [
            'max' => '확장 식별자는 최대 255자까지 입력 가능합니다.',
            'must_be_string' => '확장 식별자는 문자열이어야 합니다.',
            'min_parts' => '확장 식별자는 vendor-name 형식이어야 합니다 (예: sirsoft-board).',
            'empty_part' => '확장 식별자에 빈 부분이 있습니다. 하이픈이 연속되거나 양끝에 올 수 없습니다.',
            'invalid_characters' => '확장 식별자는 영문 소문자, 숫자, 언더스코어(_)만 사용할 수 있습니다.',
            'empty_word' => '확장 식별자에서 언더스코어가 연속되거나 양끝에 올 수 없습니다.',
            'word_starts_with_digit' => '확장 식별자의 각 단어는 숫자로 시작할 수 없습니다.',
        ],
        'parent_menus' => [
            'required' => '부모 메뉴 순서 배열은 필수입니다.',
            'array' => '부모 메뉴 순서는 배열이어야 합니다.',
            'min' => '최소 1개 이상의 부모 메뉴가 필요합니다.',
            'id' => [
                'required' => '각 부모 메뉴 ID는 필수입니다.',
                'integer' => '부모 메뉴 ID는 정수여야 합니다.',
                'exists' => '존재하지 않는 부모 메뉴 ID가 포함되어 있습니다.',
            ],
            'order' => [
                'required' => '각 부모 메뉴 순서는 필수입니다.',
                'integer' => '부모 메뉴 순서는 정수여야 합니다.',
                'min' => '메뉴 순서는 1 이상이어야 합니다.',
            ],
        ],
        'child_menus' => [
            'array' => '자식 메뉴 순서는 배열이어야 합니다.',
            'id' => [
                'required' => '각 자식 메뉴 ID는 필수입니다.',
                'integer' => '자식 메뉴 ID는 정수여야 합니다.',
                'exists' => '존재하지 않는 자식 메뉴 ID가 포함되어 있습니다.',
            ],
            'order' => [
                'required' => '각 자식 메뉴 순서는 필수입니다.',
                'integer' => '자식 메뉴 순서는 정수여야 합니다.',
                'min' => '메뉴 순서는 1 이상이어야 합니다.',
            ],
        ],
        'moved_items' => [
            'array' => '이동 항목은 배열이어야 합니다.',
            'id' => [
                'required' => '이동할 메뉴 ID는 필수입니다.',
                'integer' => '메뉴 ID는 정수여야 합니다.',
                'exists' => '존재하지 않는 메뉴 ID가 포함되어 있습니다.',
            ],
            'new_parent_id' => [
                'integer' => '새 부모 메뉴 ID는 정수여야 합니다.',
                'exists' => '존재하지 않는 부모 메뉴 ID입니다.',
            ],
        ],
    ],

    // 권한 검증 메시지
    'permission' => [
        'name' => [
            'required' => '권한 이름을 입력해주세요.',
        ],
        'description' => [
            'max' => '권한 설명은 :max자를 초과할 수 없습니다.',
        ],
    ],

    // 역할 검증 메시지
    'role' => [
        'name' => [
            'required' => '역할 이름을 입력해주세요.',
        ],
        'description' => [
            'max' => '역할 설명은 :max자를 초과할 수 없습니다.',
        ],
    ],

    // 템플릿 검증 메시지
    'template' => [
        'name' => [
            'max' => '템플릿 이름은 :max자를 초과할 수 없습니다.',
        ],
        'description' => [
            'max' => '템플릿 설명은 :max자를 초과할 수 없습니다.',
        ],
        'metadata' => [
            'array' => 'metadata는 배열이어야 합니다.',
        ],
        'status' => [
            'in' => 'status는 active 또는 inactive여야 합니다.',
        ],
    ],

    // 모듈 검증 메시지
    'module' => [
        'status' => [
            'in' => 'status는 active, inactive, installed, uninstalled 중 하나여야 합니다.',
        ],
    ],

    // 플러그인 검증 메시지
    'plugin' => [
        'status' => [
            'in' => 'status는 active, inactive, installed, uninstalled 중 하나여야 합니다.',
        ],
    ],

    // 템플릿 경로 검증 메시지
    'template_path' => [
        'must_be_string' => '템플릿 경로는 문자열이어야 합니다.',
        'traversal_detected' => '경로 트래버설 패턴이 감지되었습니다: :pattern',
        'absolute_path_not_allowed' => '절대 경로는 허용되지 않습니다.',
        'null_byte_detected' => 'NULL 바이트 공격이 감지되었습니다.',
        'outside_base_directory' => '기준 디렉토리 외부 경로는 허용되지 않습니다.',
        'file_type_not_allowed' => '허용되지 않은 파일 타입입니다. 확장자: :extension (허용: :allowed)',
    ],

    // 모듈 경로 검증 메시지
    'module_path' => [
        'must_be_string' => '경로는 문자열이어야 합니다.',
        'traversal_detected' => '경로 트래버설이 감지되었습니다: :pattern',
        'absolute_path_not_allowed' => '절대 경로는 허용되지 않습니다.',
        'null_byte_detected' => 'NULL 바이트가 감지되었습니다.',
        'outside_base_directory' => '기준 디렉토리 외부 접근은 허용되지 않습니다.',
    ],

    // 플러그인 경로 검증 메시지
    'plugin_path' => [
        'must_be_string' => '경로는 문자열이어야 합니다.',
        'traversal_detected' => '경로 트래버설이 감지되었습니다: :pattern',
        'absolute_path_not_allowed' => '절대 경로는 허용되지 않습니다.',
        'null_byte_detected' => 'NULL 바이트가 감지되었습니다.',
        'outside_base_directory' => '기준 디렉토리 외부 접근은 허용되지 않습니다.',
    ],

    // 인증 관련 검증 메시지
    'auth' => [
        'email' => [
            'required' => '이메일은 필수입니다.',
            'email' => '올바른 이메일 형식이 아닙니다.',
            'exists' => '등록되지 않은 이메일입니다.',
            'unique' => '이미 사용 중인 이메일입니다.',
        ],
        'password' => [
            'required' => '비밀번호는 필수입니다.',
            'min' => '비밀번호는 최소 :min자 이상이어야 합니다.',
            'confirmed' => '비밀번호 확인이 일치하지 않습니다.',
        ],
        'name' => [
            'required' => '이름은 필수입니다.',
            'min' => '이름은 최소 :min자 이상이어야 합니다.',
            'max' => '이름은 :max자를 초과할 수 없습니다.',
        ],
        'nickname' => [
            'max' => '닉네임은 :max자를 초과할 수 없습니다.',
        ],
        'agree_terms' => [
            'accepted' => '이용약관에 동의해주세요.',
        ],
        'agree_privacy' => [
            'accepted' => '개인정보처리방침에 동의해주세요.',
        ],
        'token' => [
            'required' => '토큰은 필수입니다.',
            'string' => '토큰은 문자열이어야 합니다.',
        ],
    ],

    // 에셋 관련 검증 메시지
    'asset' => [
        'identifier' => [
            'required' => '식별자는 필수입니다.',
            'string' => '식별자는 문자열이어야 합니다.',
        ],
        'path' => [
            'required' => '경로는 필수입니다.',
            'string' => '경로는 문자열이어야 합니다.',
        ],
    ],

    // 설정값 검증 메시지
    'setting' => [
        'value' => [
            'required' => '설정 값은 필수입니다.',
            'string' => '설정 값은 문자열이어야 합니다.',
            'max' => '설정 값은 :max자를 초과할 수 없습니다.',
        ],
    ],

    // 사용자 관련 검증 메시지
    'exclude_current_user' => '현재 로그인된 사용자는 일괄 변경 대상에 포함할 수 없습니다.',

    // 계층 구조 관련 검증 메시지
    'not_self_parent' => '자기 자신을 부모로 설정할 수 없습니다.',
    'not_circular_parent' => '자기 자신의 하위 메뉴로 이동할 수 없습니다.',

    // 설정 검증 메시지
    'settings' => [
        // 일반 설정 - 필수 필드
        'site_name_required' => '사이트 이름을 입력해주세요.',
        'site_name_max' => '사이트 이름은 100자를 초과할 수 없습니다.',
        'site_url_required' => '사이트 URL을 입력해주세요.',
        'site_url_invalid' => '올바른 URL 형식이 아닙니다.',
        'site_url_max' => '사이트 URL은 255자를 초과할 수 없습니다.',
        'site_description_max' => '사이트 설명은 500자를 초과할 수 없습니다.',
        'admin_email_required' => '관리자 이메일을 입력해주세요.',
        'admin_email_invalid' => '올바른 이메일 형식이 아닙니다.',
        'admin_email_max' => '관리자 이메일은 255자를 초과할 수 없습니다.',
        'timezone_required' => '시간대를 선택해주세요.',
        'timezone_invalid' => '올바른 시간대를 선택해주세요.',
        'language_required' => '기본 언어를 선택해주세요.',
        'language_invalid' => '올바른 언어를 선택해주세요.',
        'currency_max' => '통화 코드는 10자를 초과할 수 없습니다.',
        'maintenance_mode_boolean' => '유지보수 모드는 true 또는 false 값이어야 합니다.',

        // 메일 설정
        'mailer_required' => '메일러를 선택해주세요.',
        'mailer_invalid' => '올바른 메일러를 선택해주세요.',
        'host_required' => 'SMTP 호스트를 입력해주세요.',
        'host_max' => 'SMTP 호스트는 255자를 초과할 수 없습니다.',
        'port_required' => '포트를 입력해주세요.',
        'port_integer' => '포트는 정수여야 합니다.',
        'port_min' => '포트는 1 이상이어야 합니다.',
        'port_max' => '포트는 65535를 초과할 수 없습니다.',
        'username_max' => '사용자명은 255자를 초과할 수 없습니다.',
        'password_max' => '비밀번호는 255자를 초과할 수 없습니다.',
        'encryption_invalid' => '올바른 암호화 방식을 선택해주세요.',
        'from_address_required' => '발신자 이메일을 입력해주세요.',
        'from_address_invalid' => '올바른 이메일 형식이 아닙니다.',
        'from_address_max' => '발신자 이메일은 255자를 초과할 수 없습니다.',
        'from_name_required' => '발신자 이름을 입력해주세요.',
        'from_name_max' => '발신자 이름은 255자를 초과할 수 없습니다.',

        // 업로드 설정
        'max_file_size_required' => '최대 파일 크기를 입력해주세요.',
        'max_file_size_integer' => '최대 파일 크기는 정수여야 합니다.',
        'max_file_size_min' => '최대 파일 크기는 1MB 이상이어야 합니다.',
        'max_file_size_max' => '최대 파일 크기는 1024MB를 초과할 수 없습니다.',
        'allowed_extensions_required' => '허용 확장자를 입력해주세요.',
        'allowed_extensions_max' => '허용 확장자는 500자를 초과할 수 없습니다.',
        'allowed_extensions_invalid_type' => '허용 확장자는 문자열 또는 배열이어야 합니다.',
        'image_max_width_integer' => '이미지 최대 너비는 정수여야 합니다.',
        'image_max_width_min' => '이미지 최대 너비는 100px 이상이어야 합니다.',
        'image_max_width_max' => '이미지 최대 너비는 10000px를 초과할 수 없습니다.',
        'image_max_height_integer' => '이미지 최대 높이는 정수여야 합니다.',
        'image_max_height_min' => '이미지 최대 높이는 100px 이상이어야 합니다.',
        'image_max_height_max' => '이미지 최대 높이는 10000px를 초과할 수 없습니다.',
        'image_quality_integer' => '이미지 품질은 정수여야 합니다.',
        'image_quality_min' => '이미지 품질은 1 이상이어야 합니다.',
        'image_quality_max' => '이미지 품질은 100을 초과할 수 없습니다.',

        // SEO 설정
        'meta_title_suffix_max' => '타이틀 접미사는 100자를 초과할 수 없습니다.',
        'meta_description_max' => '메타 설명은 160자를 초과할 수 없습니다.',
        'meta_keywords_max' => '메타 키워드는 255자를 초과할 수 없습니다.',
        'google_analytics_id_max' => 'Google Analytics ID는 50자를 초과할 수 없습니다.',
        'google_site_verification_max' => 'Google 사이트 확인 코드는 100자를 초과할 수 없습니다.',
        'naver_site_verification_max' => '네이버 사이트 확인 코드는 100자를 초과할 수 없습니다.',
        'bot_user_agents_array' => '봇 User-Agent 목록은 배열이어야 합니다.',
        'bot_user_agents_item_string' => '봇 User-Agent 항목은 문자열이어야 합니다.',
        'bot_user_agents_item_max' => '봇 User-Agent 항목은 100자를 초과할 수 없습니다.',
        'bot_detection_enabled_boolean' => '봇 감지 설정은 true 또는 false 값이어야 합니다.',
        'seo_cache_enabled_boolean' => 'SEO 캐시 설정은 true 또는 false 값이어야 합니다.',
        'seo_cache_ttl_integer' => 'SEO 캐시 TTL은 정수여야 합니다.',
        'seo_cache_ttl_min' => 'SEO 캐시 TTL은 최소 60초 이상이어야 합니다.',
        'seo_cache_ttl_max' => 'SEO 캐시 TTL은 최대 86400초(24시간)를 초과할 수 없습니다.',
        'sitemap_enabled_boolean' => 'Sitemap 생성 설정은 true 또는 false 값이어야 합니다.',
        'sitemap_cache_ttl_integer' => 'Sitemap 캐시 TTL은 정수여야 합니다.',
        'sitemap_cache_ttl_min' => 'Sitemap 캐시 TTL은 최소 3600초(1시간) 이상이어야 합니다.',
        'sitemap_cache_ttl_max' => 'Sitemap 캐시 TTL은 최대 604800초(7일)를 초과할 수 없습니다.',
        'sitemap_schedule_invalid' => '유효한 Sitemap 생성 주기를 선택해주세요.',
        'sitemap_schedule_time_invalid' => 'Sitemap 생성 시각은 HH:mm 형식이어야 합니다.',

        // 보안 설정
        'force_https_required' => 'HTTPS 강제 적용 설정을 선택해주세요.',
        'force_https_boolean' => 'HTTPS 강제 적용은 true 또는 false 값이어야 합니다.',
        'login_attempt_enabled_required' => '로그인 시도 제한 설정을 선택해주세요.',
        'login_attempt_enabled_boolean' => '로그인 시도 제한은 true 또는 false 값이어야 합니다.',
        'auth_token_lifetime_integer' => '인증 토큰 유지시간은 정수여야 합니다.',
        'auth_token_lifetime_min' => '인증 토큰 유지시간은 0 이상이어야 합니다.',
        'auth_token_lifetime_max' => '인증 토큰 유지시간은 최대 3600분(60시간)을 초과할 수 없습니다.',
        'auth_token_lifetime_range' => '인증 토큰 유지시간은 0(무한대) 또는 30~3600분 사이여야 합니다.',
        'max_login_attempts_integer' => '최대 로그인 시도 횟수는 정수여야 합니다.',
        'max_login_attempts_min' => '최대 로그인 시도 횟수는 0 이상이어야 합니다.',
        'max_login_attempts_max' => '최대 로그인 시도 횟수는 100회를 초과할 수 없습니다.',
        'login_lockout_time_integer' => '차단 시간은 정수여야 합니다.',
        'login_lockout_time_min' => '차단 시간은 0 이상이어야 합니다.',
        'login_lockout_time_max' => '차단 시간은 최대 1440분(24시간)을 초과할 수 없습니다.',

        // 캐시 설정
        'cache_enabled_required' => '전체 캐시 활성화 설정을 선택해주세요.',
        'cache_enabled_boolean' => '캐시 활성화는 true 또는 false 값이어야 합니다.',
        'layout_cache_enabled_required' => '레이아웃 캐시 설정을 선택해주세요.',
        'layout_cache_enabled_boolean' => '레이아웃 캐시는 true 또는 false 값이어야 합니다.',
        'layout_cache_ttl_required' => '레이아웃 캐시 만료 시간을 입력해주세요.',
        'layout_cache_ttl_integer' => '레이아웃 캐시 만료 시간은 정수여야 합니다.',
        'layout_cache_ttl_min' => '레이아웃 캐시 만료 시간은 최소 0초여야 합니다.',
        'layout_cache_ttl_max' => '레이아웃 캐시 만료 시간은 최대 14400초(4시간)를 초과할 수 없습니다.',
        'stats_cache_enabled_required' => '통계 캐시 설정을 선택해주세요.',
        'stats_cache_enabled_boolean' => '통계 캐시는 true 또는 false 값이어야 합니다.',
        'stats_cache_ttl_required' => '통계 캐시 만료 시간을 입력해주세요.',
        'stats_cache_ttl_integer' => '통계 캐시 만료 시간은 정수여야 합니다.',
        'stats_cache_ttl_min' => '통계 캐시 만료 시간은 최소 0초여야 합니다.',
        'stats_cache_ttl_max' => '통계 캐시 만료 시간은 최대 14400초(4시간)를 초과할 수 없습니다.',
        'seo_cache_enabled_required' => 'SEO 캐시 설정을 선택해주세요.',
        'seo_cache_enabled_boolean' => 'SEO 캐시는 true 또는 false 값이어야 합니다.',
        'seo_cache_ttl_required' => 'SEO 캐시 만료 시간을 입력해주세요.',
        'seo_cache_ttl_integer' => 'SEO 캐시 만료 시간은 정수여야 합니다.',
        'seo_cache_ttl_min' => 'SEO 캐시 만료 시간은 최소 0초여야 합니다.',
        'seo_cache_ttl_max' => 'SEO 캐시 만료 시간은 최대 14400초(4시간)를 초과할 수 없습니다.',

        // 디버그 설정
        'debug_mode_required' => '디버그 모드 설정을 선택해주세요.',
        'debug_mode_boolean' => '디버그 모드는 true 또는 false 값이어야 합니다.',
        'sql_query_log_required' => 'SQL 쿼리 로그 설정을 선택해주세요.',
        'sql_query_log_boolean' => 'SQL 쿼리 로그는 true 또는 false 값이어야 합니다.',

        // 코어 업데이트 설정
        'core_update_github_url_invalid' => 'GitHub 저장소 URL 형식이 올바르지 않습니다.',
        'core_update_github_url_max' => 'GitHub 저장소 URL은 500자를 초과할 수 없습니다.',
        'core_update_github_token_max' => 'GitHub 액세스 토큰은 500자를 초과할 수 없습니다.',

        // 드라이버 설정
        'storage_driver_required' => '스토리지 드라이버를 선택해주세요.',
        'storage_driver_invalid' => '올바른 스토리지 드라이버를 선택해주세요.',
        's3_bucket_max' => 'S3 버킷 이름은 255자를 초과할 수 없습니다.',
        's3_region_invalid' => '올바른 S3 리전을 선택해주세요.',
        's3_access_key_max' => 'S3 Access Key는 255자를 초과할 수 없습니다.',
        's3_secret_key_max' => 'S3 Secret Key는 255자를 초과할 수 없습니다.',
        's3_url_invalid' => '올바른 S3 URL 형식이 아닙니다.',
        's3_url_max' => 'S3 URL은 500자를 초과할 수 없습니다.',
        'cache_driver_required' => '캐시 드라이버를 선택해주세요.',
        'cache_driver_invalid' => '올바른 캐시 드라이버를 선택해주세요.',
        'redis_host_max' => 'Redis 호스트는 255자를 초과할 수 없습니다.',
        'redis_port_integer' => 'Redis 포트는 정수여야 합니다.',
        'redis_port_min' => 'Redis 포트는 1 이상이어야 합니다.',
        'redis_port_max' => 'Redis 포트는 65535를 초과할 수 없습니다.',
        'redis_password_max' => 'Redis 비밀번호는 255자를 초과할 수 없습니다.',
        'redis_database_integer' => 'Redis 데이터베이스는 정수여야 합니다.',
        'redis_database_min' => 'Redis 데이터베이스는 0 이상이어야 합니다.',
        'redis_database_max' => 'Redis 데이터베이스는 15를 초과할 수 없습니다.',
        'memcached_host_max' => 'Memcached 호스트는 255자를 초과할 수 없습니다.',
        'memcached_port_integer' => 'Memcached 포트는 정수여야 합니다.',
        'memcached_port_min' => 'Memcached 포트는 1 이상이어야 합니다.',
        'memcached_port_max' => 'Memcached 포트는 65535를 초과할 수 없습니다.',
        'session_driver_required' => '세션 드라이버를 선택해주세요.',
        'session_driver_invalid' => '올바른 세션 드라이버를 선택해주세요.',
        'session_lifetime_integer' => '세션 유효시간은 정수여야 합니다.',
        'session_lifetime_min' => '세션 유효시간은 최소 1분이어야 합니다.',
        'session_lifetime_max' => '세션 유효시간은 최대 30일(43200분)을 초과할 수 없습니다.',
        'queue_driver_required' => '큐 드라이버를 선택해주세요.',
        'queue_driver_invalid' => '올바른 큐 드라이버를 선택해주세요.',
        'websocket_enabled_boolean' => '웹소켓 사용 설정은 true 또는 false 값이어야 합니다.',
        'websocket_app_key_max' => '웹소켓 앱 키는 255자를 초과할 수 없습니다.',
        'websocket_host_max' => '웹소켓 호스트는 255자를 초과할 수 없습니다.',
        'websocket_port_integer' => '웹소켓 포트는 정수여야 합니다.',
        'websocket_port_min' => '웹소켓 포트는 1 이상이어야 합니다.',
        'websocket_port_max' => '웹소켓 포트는 65535를 초과할 수 없습니다.',
        'websocket_scheme_invalid' => '올바른 웹소켓 프로토콜을 선택해주세요.',
        'search_engine_driver_invalid' => '올바른 검색엔진 드라이버를 선택해주세요.',
    ],

    // 검증 속성명 (validation.attributes)
    'attributes' => [
        'ids' => '사용자 ID 목록',
        'user_id' => '사용자 ID',
        'status' => '상태',
        // 일반 설정 필드
        'site_name' => '사이트 이름',
        'site_url' => '사이트 URL',
        'site_description' => '사이트 설명',
        'admin_email' => '관리자 이메일',
        'timezone' => '시간대',
        'language' => '기본 언어',
        // 메일 설정 필드
        'mailer' => '메일러',
        'host' => 'SMTP 호스트',
        'port' => 'SMTP 포트',
        'username' => 'SMTP 사용자명',
        'password' => 'SMTP 비밀번호',
        'encryption' => '암호화',
        'from_address' => '발신자 이메일',
        'from_name' => '발신자 이름',
        // 업로드 설정 필드
        'max_file_size' => '최대 파일 크기',
        'allowed_extensions' => '허용 확장자',
        'image_max_width' => '이미지 최대 너비',
        'image_max_height' => '이미지 최대 높이',
        'image_quality' => '이미지 품질',
        // SEO 설정 필드
        'meta_title_suffix' => '메타 타이틀 접미사',
        'meta_description' => '메타 설명',
        'meta_keywords' => '메타 키워드',
        'google_analytics_id' => 'Google Analytics ID',
        'google_site_verification' => 'Google 사이트 확인',
        'naver_site_verification' => '네이버 사이트 확인',
        // Changelog 필드
        'from_version' => '시작 버전',
        'to_version' => '종료 버전',
    ],
];
