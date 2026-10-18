<?php

/**
 * 이커머스 모듈 검증 메시지
 *
 * FormRequest 검증 실패 시 사용되는 메시지
 */
return [
    'exchange_rate_save_failed' => '환율 설정을 저장하지 못했습니다.',
    'exchange_rate_provider_unavailable' => '사용할 수 없는 환율 제공자입니다.',
    // 상품 1:1 문의 검증 메시지
    'inquiries' => [
        'content' => [
            'required' => '문의 내용을 입력해주세요.',
            'min' => '문의 내용은 최소 :min자 이상 입력해주세요.',
            'max' => '문의 내용은 최대 :max자까지 입력 가능합니다.',
        ],
        'reply_content' => [
            'required' => '답변 내용을 입력해주세요.',
            'min' => '답변 내용은 최소 1자 이상 입력해주세요.',
            'max' => '답변 내용은 최대 5000자까지 입력 가능합니다.',
        ],
    ],

    // 커스텀 검증 메시지
    'settings' => [
        'key_required' => '설정 키는 필수입니다.',
        'value_present' => '설정 값은 필수입니다.',
        'key_unknown' => '저장할 수 없는 설정 키입니다: :key',
        'key_server_managed' => '이 설정은 서버가 기록하는 값이라 직접 저장할 수 없습니다.',
        'empty_payload' => '저장할 설정 내용이 없습니다. 화면을 새로고침한 뒤 다시 저장해 주세요.',
    ],

    // 배송사 검증 메시지
    'shipping_carrier' => [
        'code_required' => '배송사 코드는 필수입니다.',
        'code_unique' => '이미 사용 중인 배송사 코드입니다.',
        'code_format' => '배송사 코드는 영문소문자, 숫자, 하이픈만 사용 가능합니다.',
        'name_required' => '배송사명은 필수입니다.',
        'type_required' => '배송사 유형은 필수입니다.',
        'type_invalid' => '배송사 유형은 국내(domestic) 또는 국제(international)만 가능합니다.',
    ],

    // 목록 조회 공통 검증 메시지
    'list' => [
        'per_page_max' => '한 번에 조회할 수 있는 최대 개수는 :max개입니다.',
        // 페이지네이션
        'page' => [
            'integer' => '페이지 번호는 숫자여야 합니다.',
            'min' => '페이지 번호는 1 이상이어야 합니다.',
        ],
        'per_page' => [
            'integer' => '페이지당 항목 수는 숫자여야 합니다.',
            'min' => '페이지당 항목 수는 :min 이상이어야 합니다.',
            'max' => '페이지당 항목 수는 :max 이하여야 합니다.',
        ],
        // 정렬
        'sort' => [
            'string' => '정렬 기준은 문자열이어야 합니다.',
            'in' => '올바른 정렬 기준을 선택해주세요.',
        ],
        'sort_by' => [
            'string' => '정렬 필드는 문자열이어야 합니다.',
            'in' => '올바른 정렬 필드를 선택해주세요.',
        ],
        'sort_order' => [
            'string' => '정렬 순서는 문자열이어야 합니다.',
            'in' => '정렬 순서는 asc 또는 desc여야 합니다.',
        ],
        // 검색
        'search' => [
            'string' => '검색어는 문자열이어야 합니다.',
            'max' => '검색어는 최대 :max자까지 입력 가능합니다.',
        ],
        'search_field' => [
            'string' => '검색 필드는 문자열이어야 합니다.',
            'in' => '올바른 검색 필드를 선택해주세요.',
        ],
        'search_keyword' => [
            'string' => '검색 키워드는 문자열이어야 합니다.',
            'max' => '검색 키워드는 최대 :max자까지 입력 가능합니다.',
        ],
        // 상품 목록 필터
        'category_id' => [
            'integer' => '카테고리 ID는 숫자여야 합니다.',
        ],
        'no_category' => [
            'boolean' => '카테고리 미등록 필터는 true 또는 false여야 합니다.',
        ],
        'date_type' => [
            'in' => '올바른 날짜 유형을 선택해주세요.',
        ],
        'start_date' => [
            'date' => '시작일은 날짜 형식이어야 합니다.',
        ],
        'end_date' => [
            'date' => '종료일은 날짜 형식이어야 합니다.',
            'after_or_equal' => '종료일은 시작일 이후여야 합니다.',
        ],
        'sales_status' => [
            'array' => '판매상태는 배열 형태여야 합니다.',
            'in' => '올바른 판매상태를 선택해주세요.',
        ],
        'display_status' => [
            'in' => '올바른 전시상태를 선택해주세요.',
        ],
        'brand_id' => [
            'integer' => '브랜드 ID는 숫자여야 합니다.',
        ],
        'no_brand' => [
            'boolean' => '브랜드 미등록 필터는 true 또는 false여야 합니다.',
        ],
        'tax_status' => [
            'in' => '올바른 과세여부를 선택해주세요.',
        ],
        'price_type' => [
            'in' => '올바른 가격 유형을 선택해주세요.',
        ],
        'min_price' => [
            'integer' => '최소 가격은 숫자여야 합니다.',
            'min' => '최소 가격은 0 이상이어야 합니다.',
        ],
        'max_price' => [
            'integer' => '최대 가격은 숫자여야 합니다.',
            'min' => '최대 가격은 0 이상이어야 합니다.',
        ],
        'min_stock' => [
            'integer' => '최소 재고는 숫자여야 합니다.',
        ],
        'max_stock' => [
            'integer' => '최대 재고는 숫자여야 합니다.',
        ],
        'shipping_policy_id' => [
            'integer' => '배송정책 ID는 숫자여야 합니다.',
        ],
        'with_options' => [
            'boolean' => '옵션 포함 여부는 true 또는 false여야 합니다.',
        ],
        // 필터
        'is_active' => [
            'boolean' => '사용여부는 true 또는 false여야 합니다.',
            'in' => '사용여부 값이 올바르지 않습니다.',
        ],
        'active_only' => [
            'boolean' => '활성 항목만 필터 옵션은 true 또는 false여야 합니다.',
        ],
        'locale' => [
            'string' => '언어 코드는 문자열이어야 합니다.',
            'in' => '지원하지 않는 언어입니다.',
        ],
        'region' => [
            'string' => '지역은 문자열이어야 합니다.',
            'max' => '지역은 최대 :max자까지 입력 가능합니다.',
        ],
        // 카테고리 목록
        'parent_id' => [
            'exists' => '존재하지 않는 상위 카테고리입니다.',
        ],
        'hierarchical' => [
            'boolean' => '트리 구조 여부는 true 또는 false여야 합니다.',
        ],
        'flat' => [
            'boolean' => '평면 리스트 여부는 true 또는 false여야 합니다.',
        ],
        'max_depth' => [
            'integer' => '최대 깊이는 숫자여야 합니다.',
            'min' => '최대 깊이는 :min 이상이어야 합니다.',
            'max' => '최대 깊이는 :max 이하여야 합니다.',
        ],
        // 쿠폰 목록
        'target_type' => [
            'string' => '적용대상 유형은 문자열이어야 합니다.',
            'in' => '올바른 적용대상 유형을 선택해주세요.',
        ],
        'discount_type' => [
            'string' => '할인유형은 문자열이어야 합니다.',
            'in' => '올바른 할인유형을 선택해주세요.',
        ],
        'issue_status' => [
            'string' => '발급상태는 문자열이어야 합니다.',
            'in' => '올바른 발급상태를 선택해주세요.',
        ],
        'issue_method' => [
            'string' => '발급방법은 문자열이어야 합니다.',
            'in' => '올바른 발급방법을 선택해주세요.',
        ],
        'issue_condition' => [
            'string' => '발급조건은 문자열이어야 합니다.',
            'in' => '올바른 발급조건을 선택해주세요.',
        ],
        'min_benefit_amount' => [
            'numeric' => '최소 혜택 금액은 숫자여야 합니다.',
            'min' => '최소 혜택 금액은 0 이상이어야 합니다.',
        ],
        'max_benefit_amount' => [
            'numeric' => '최대 혜택 금액은 숫자여야 합니다.',
            'min' => '최대 혜택 금액은 0 이상이어야 합니다.',
        ],
        'min_order_amount' => [
            'numeric' => '최소 주문 금액은 숫자여야 합니다.',
            'min' => '최소 주문 금액은 0 이상이어야 합니다.',
        ],
        // 날짜 필터
        'created_start_date' => [
            'date' => '등록 시작일은 날짜 형식이어야 합니다.',
        ],
        'created_end_date' => [
            'date' => '등록 종료일은 날짜 형식이어야 합니다.',
            'after_or_equal' => '등록 종료일은 시작일 이후여야 합니다.',
        ],
        'valid_start_date' => [
            'date' => '유효 시작일은 날짜 형식이어야 합니다.',
        ],
        'valid_end_date' => [
            'date' => '유효 종료일은 날짜 형식이어야 합니다.',
            'after_or_equal' => '유효 종료일은 시작일 이후여야 합니다.',
        ],
        'issue_start_date' => [
            'date' => '발급 시작일은 날짜 형식이어야 합니다.',
        ],
        'issue_end_date' => [
            'date' => '발급 종료일은 날짜 형식이어야 합니다.',
            'after_or_equal' => '발급 종료일은 시작일 이후여야 합니다.',
        ],
        // 배송정책 목록
        'shipping_methods' => [
            'array' => '배송방법은 배열 형태여야 합니다.',
            'string' => '배송방법은 문자열이어야 합니다.',
            'in' => '올바른 배송방법을 선택해주세요.',
        ],
        'charge_policies' => [
            'array' => '부과정책은 배열 형태여야 합니다.',
            'string' => '부과정책은 문자열이어야 합니다.',
            'in' => '올바른 부과정책을 선택해주세요.',
        ],
        'countries' => [
            'array' => '배송국가는 배열 형태여야 합니다.',
            'string' => '배송국가는 문자열이어야 합니다.',
            'in' => '올바른 배송국가를 선택해주세요.',
            'max' => '배송국가 코드는 최대 :max자까지 입력 가능합니다.',
        ],
    ],

    // 상품 카테고리/옵션 검증 메시지 (루트 레벨)
    'category_required' => '카테고리를 선택해주세요.',
    'category_min' => '최소 1개 이상의 카테고리를 선택해주세요.',
    'category_max' => '카테고리는 최대 5개까지 선택 가능합니다.',
    'options_required' => '옵션을 1개 이상 추가해주세요.',
    'options_min' => '옵션을 1개 이상 추가해주세요.',
    'selling_price_lte_list' => '판매가는 정가보다 클 수 없습니다.',
    'option_selling_price_lte_list' => '옵션 판매가는 정가보다 클 수 없습니다.',
    'claim_reason_code_format' => '클레임 사유 코드는 영문 소문자로 시작하고, 소문자·숫자와 단어 사이의 언더스코어(_)만 사용할 수 있습니다(연속 언더스코어·끝 언더스코어 불가). 이미 저장된 코드는 그대로 두어도 됩니다.',

    // 상품 검증 메시지
    'product' => [
        'mileage_percent_max' => '정률 적립률은 100% 를 초과할 수 없습니다.',
        'selling_price_lte_list' => '판매가는 정가보다 클 수 없습니다.',
        'price_min' => '가격은 0보다 커야 합니다.',
        // 에러 메시지에 노출되는 한글 필드명 (StoreProductRequest::attributes())
        'attributes' => [
            'name' => '상품명',
            'product_code' => '상품코드',
            'list_price' => '정가',
            'selling_price' => '판매가',
            'stock_quantity' => '재고 수량',
            'safe_stock_quantity' => '안전재고 수량',
            'option_list_price' => '옵션 정가',
            'option_selling_price' => '옵션 판매가',
            'option_price_adjustment' => '옵션 가격 조정액',
            'option_stock_quantity' => '옵션 재고 수량',
            'option_name' => '옵션명',
            'option_code' => '옵션코드',
            // 다국어 필수 칸 이름 (옵션그룹·추가옵션·상품정보제공고시)
            'option_group_name' => '옵션그룹명',
            'additional_option_name' => '추가옵션 그룹명',
            'additional_option_value_name' => '추가옵션 선택지명',
            'notice_item_name' => '상품정보제공고시 항목명',
            'notice_item_content' => '상품정보제공고시 내용',
        ],
        'name' => [
            'required' => '상품명을 입력해주세요.',
        ],
        'allowed_roles' => [
            'required_when_restricted' => '구매 대상 제한을 선택한 경우 허용 역할을 1개 이상 선택해주세요.',
        ],
        'name_primary' => [
            'required' => '기본 언어 상품명은 필수입니다.',
        ],
        'product_code' => [
            'required' => '상품코드를 입력해주세요.',
            'unique' => '이미 사용중인 상품코드입니다.',
            'regex' => '상품코드는 영문, 숫자, 하이픈(-), 밑줄(_)만 사용할 수 있습니다.',
        ],
        'list_price' => [
            'required' => '정가를 입력해주세요.',
            'min' => '정가는 1 이상이어야 합니다.',
        ],
        'selling_price' => [
            'required' => '판매가를 입력해주세요.',
            'min' => '판매가는 1 이상이어야 합니다.',
            'lte' => '판매가는 정가 이하여야 합니다.',
        ],
        'stock_quantity' => [
            'required' => '재고 수량은 필수입니다.',
        ],
        'sales_status' => [
            'required' => '판매 상태는 필수입니다.',
            'in' => '유효하지 않은 판매 상태입니다.',
        ],
        'display_status' => [
            'required' => '전시 상태는 필수입니다.',
            'in' => '유효하지 않은 전시 상태입니다.',
        ],
        'tax_status' => [
            'required' => '과세 상태는 필수입니다.',
            'in' => '유효하지 않은 과세 상태입니다.',
        ],
        'category_ids' => [
            'required' => '카테고리를 1개 이상 선택해주세요.',
            'min' => '카테고리를 1개 이상 선택해주세요.',
            'max' => '카테고리는 최대 5개까지 선택할 수 있습니다.',
        ],
        'options' => [
            'required' => '상품 옵션은 필수입니다.',
            'min' => '상품 옵션을 1개 이상 추가해주세요.',
            'option_code' => [
                'required_with' => '옵션코드는 필수입니다.',
            ],
            'option_name' => [
                'required_with' => '옵션명은 필수입니다.',
            ],
            'option_values' => [
                'required_with' => '옵션값은 필수입니다.',
            ],
            'list_price' => [
                'required_with' => '옵션 정가는 필수입니다.',
            ],
            'selling_price' => [
                'required_with' => '옵션 판매가는 필수입니다.',
            ],
            'stock_quantity' => [
                'required_with' => '옵션 재고 수량은 필수입니다.',
            ],
        ],
        'additional_options' => [
            'values' => [
                'required_with' => '각 추가옵션 그룹에는 선택지를 1개 이상 등록해주세요.',
                'min' => '각 추가옵션 그룹에는 선택지를 1개 이상 등록해주세요.',
                'max' => '추가옵션 그룹당 선택지는 최대 :max개까지 등록할 수 있습니다.',
                'name' => [
                    'required' => '선택지명을 입력해주세요.',
                ],
                'price_adjustment' => [
                    'min' => '추가금은 0 이상이어야 합니다.',
                ],
            ],
            'name' => [
                'required_with' => '추가옵션 그룹명을 입력해주세요.',
            ],
            'max' => '추가옵션 그룹은 최대 :max개까지 등록할 수 있습니다.',
        ],
        'label_assignments' => [
            'label_id' => [
                'required' => '라벨을 선택해주세요.',
                'exists' => '존재하지 않는 라벨입니다.',
            ],
            'end_date' => [
                'after_or_equal' => '종료일은 시작일 이후여야 합니다.',
            ],
        ],
        'shipping_policy_id' => [
            'exists' => '존재하지 않는 배송정책입니다.',
        ],
        'common_info_id' => [
            'exists' => '존재하지 않는 공통정보입니다.',
        ],
        'use_main_image_for_og' => [
            'boolean' => 'OG 이미지 설정은 참/거짓 값이어야 합니다.',
        ],
        'invalid_sales_status' => '잘못된 판매상태입니다.',
        'invalid_display_status' => '잘못된 전시상태입니다.',
        // 상품 일괄 처리
        'bulk' => [
            'ids_required' => '변경할 상품을 선택해주세요.',
            'ids_min' => '최소 1개 이상의 상품을 선택해주세요.',
            'product_not_found' => '존재하지 않는 상품입니다.',
            'option_not_found' => '존재하지 않는 옵션입니다.',
        ],
    ],

    // 옵션 검증 메시지
    'option' => [
        'bulk' => [
            'ids_required' => '변경할 옵션을 선택해주세요.',
            'ids_min' => '최소 1개 이상의 옵션을 선택해주세요.',
            'invalid_id_format' => '잘못된 옵션 ID 형식입니다.',
        ],
    ],

    // 일괄 처리 검증 메시지
    'bulk' => [
        'ids_required' => '변경할 상품을 선택해주세요.',
        'method_required' => '변경 방식을 선택해주세요.',
        'value_required' => '변경 값을 입력해주세요.',
    ],

    // 옵션 일괄 가격 변경 검증 메시지
    'bulk_option_price' => [
        'ids_required' => '변경할 상품 또는 옵션을 선택해주세요.',
        'product_ids' => [
            'required' => '변경할 상품을 선택해주세요.',
            'min' => '최소 1개 이상의 상품을 선택해주세요.',
        ],
        'method' => [
            'required' => '변경 방식을 선택해주세요.',
            'in' => '올바른 변경 방식을 선택해주세요.',
        ],
        'value' => [
            'required' => '변경 값을 입력해주세요.',
            'integer' => '변경 값은 숫자여야 합니다.',
            'min' => '변경 값은 0 이상이어야 합니다.',
        ],
        'unit' => [
            'required' => '단위를 선택해주세요.',
            'in' => '올바른 단위를 선택해주세요.',
        ],
    ],

    // 옵션 일괄 재고 변경 검증 메시지
    'bulk_option_stock' => [
        'ids_required' => '변경할 상품 또는 옵션을 선택해주세요.',
        'product_ids' => [
            'required' => '변경할 상품을 선택해주세요.',
            'min' => '최소 1개 이상의 상품을 선택해주세요.',
        ],
        'method' => [
            'required' => '변경 방식을 선택해주세요.',
            'in' => '올바른 변경 방식을 선택해주세요.',
        ],
        'value' => [
            'required' => '변경 값을 입력해주세요.',
            'integer' => '변경 값은 숫자여야 합니다.',
            'min' => '변경 값은 0 이상이어야 합니다.',
        ],
    ],

    // 프리셋 검증 메시지
    'preset' => [
        'name_required' => '프리셋 이름을 입력해주세요.',
        'name_exists' => '동일한 이름의 프리셋이 이미 존재합니다.',
        'conditions_required' => '검색 조건을 입력해주세요.',
    ],

    // 브랜드 검증 메시지
    'brand' => [
        'name_required' => '브랜드명을 입력해주세요.',
        'slug_required' => '슬러그를 입력해주세요.',
        'slug_unique' => '이미 사용중인 슬러그입니다.',
        'slug_format' => '슬러그는 영문 소문자로 시작해야 하며, 영문 소문자/숫자/하이픈(-)만 사용 가능합니다.',
        'website_invalid_url' => '올바른 URL 형식이 아닙니다.',
    ],

    // 상품 라벨 검증 메시지
    'label' => [
        'name_required' => '라벨명을 입력해주세요.',
        'color_required' => '라벨 색상을 입력해주세요.',
        'color_invalid' => '라벨 색상은 #RRGGBB 형식이어야 합니다.',
    ],

    // 카테고리 검증 메시지
    'category' => [
        'name_required' => '카테고리명을 입력해주세요.',
        'slug_required' => '슬러그를 입력해주세요.',
        'slug_unique' => '이미 사용중인 슬러그입니다.',
        'slug_format' => '슬러그는 영문 소문자로 시작해야 하며, 영문 소문자/숫자/하이픈(-)만 사용 가능합니다.',
        'parent_not_found' => '상위 카테고리를 찾을 수 없습니다.',
        'parent_id' => [
            'self' => '자기 자신을 상위 카테고리로 지정할 수 없습니다.',
            'circular' => '하위 카테고리를 상위 카테고리로 지정할 수 없습니다.',
        ],
    ],

    // 상품 이미지 검증 메시지
    'product_images' => [
        'file' => [
            'required' => '이미지 파일을 선택해주세요.',
            'file' => '유효한 파일이 아닙니다.',
            'image' => '이미지 파일만 업로드 가능합니다.',
            'mimes' => '지원하는 이미지 형식: jpeg, png, jpg, gif, webp',
            'max' => '이미지 파일 크기는 10MB 이하여야 합니다.',
        ],
        'temp_key' => [
            'string' => '임시 키는 문자열이어야 합니다.',
            'max' => '임시 키는 최대 64자까지 입력 가능합니다.',
        ],
        'collection' => [
            'in' => '올바른 컬렉션을 선택해주세요.',
            'enum' => '올바른 컬렉션을 선택해주세요. (main, detail, additional)',
        ],
        'alt_text' => [
            'array' => '대체 텍스트는 배열 형태여야 합니다.',
        ],
        'orders' => [
            'required' => '정렬 순서를 입력해주세요.',
            'array' => '정렬 순서는 배열 형태여야 합니다.',
            'min' => '최소 1개 이상의 정렬 순서를 입력해주세요.',
            'item' => [
                'required' => '정렬 순서 값을 입력해주세요.',
                'integer' => '정렬 순서는 숫자여야 합니다.',
                'min' => '정렬 순서는 0 이상이어야 합니다.',
            ],
        ],
    ],

    // 카테고리 이미지 검증 메시지
    'category_images' => [
        'file' => [
            'required' => '이미지 파일을 선택해주세요.',
            'file' => '유효한 파일이 아닙니다.',
            'image' => '이미지 파일만 업로드 가능합니다.',
            'mimes' => '지원하는 이미지 형식: jpeg, png, jpg, gif, svg, webp',
            'max' => '이미지 파일 크기는 10MB 이하여야 합니다.',
        ],
        'temp_key' => [
            'string' => '임시 키는 문자열이어야 합니다.',
            'max' => '임시 키는 최대 64자까지 입력 가능합니다.',
        ],
        'collection' => [
            'in' => '올바른 컬렉션을 선택해주세요.',
        ],
        'alt_text' => [
            'array' => '대체 텍스트는 배열 형태여야 합니다.',
        ],
        'orders' => [
            'required' => '정렬 순서를 입력해주세요.',
            'array' => '정렬 순서는 배열 형태여야 합니다.',
            'min' => '최소 1개 이상의 정렬 순서를 입력해주세요.',
            'item' => [
                'required' => '정렬 순서 값을 입력해주세요.',
                'integer' => '정렬 순서는 숫자여야 합니다.',
                'min' => '정렬 순서는 0 이상이어야 합니다.',
            ],
        ],
    ],

    // 상품정보제공고시 템플릿 검증 메시지
    'product_notice_template' => [
        'name_required' => '상품군명을 입력해주세요.',
        'name_max' => '상품군명은 최대 100자까지 입력 가능합니다.',
        'fields_required' => '항목을 1개 이상 추가해주세요.',
        'fields_min' => '항목을 1개 이상 추가해주세요.',
        'field_name_required' => '항목명을 입력해주세요.',
        'field_name_min' => '항목명을 입력해주세요.',
        'field_name_max' => '항목명은 최대 200자까지 입력 가능합니다.',
        'field_content_required' => '내용을 입력해주세요.',
        'field_content_min' => '내용을 입력해주세요.',
        'field_content_max' => '내용은 최대 2000자까지 입력 가능합니다.',
    ],

    // 쿠폰 검증 메시지
    'coupon' => [
        'name' => [
            'required' => '쿠폰명을 입력해주세요.',
        ],
        'name_ko' => [
            'required' => '한국어 쿠폰명을 입력해주세요.',
        ],
        'coupon_code' => [
            'required' => '쿠폰코드를 입력해주세요.',
            'unique' => '이미 사용중인 쿠폰코드입니다.',
        ],
        'target_type' => [
            'required' => '적용대상을 선택해주세요.',
            'in' => '올바른 적용대상을 선택해주세요.',
        ],
        'discount_type' => [
            'required' => '할인유형을 선택해주세요.',
            'in' => '올바른 할인유형을 선택해주세요.',
        ],
        'discount_value' => [
            'required' => '할인값을 입력해주세요.',
            'numeric' => '할인값은 숫자여야 합니다.',
            'min' => '할인값은 0 이상이어야 합니다.',
        ],
        'max_discount_amount' => [
            'numeric' => '최대할인금액은 숫자여야 합니다.',
            'min' => '최대할인금액은 0 이상이어야 합니다.',
        ],
        'min_order_amount' => [
            'numeric' => '최소주문금액은 숫자여야 합니다.',
            'min' => '최소주문금액은 0 이상이어야 합니다.',
        ],
        'max_issue_count' => [
            'integer' => '최대발급수량은 정수여야 합니다.',
            'min' => '최대발급수량은 0 이상이어야 합니다.',
        ],
        'max_use_count_per_user' => [
            'integer' => '1인당 최대 사용횟수는 정수여야 합니다.',
            'min' => '1인당 최대 사용횟수는 0 이상이어야 합니다.',
        ],
        'valid_from' => [
            'date' => '유효시작일은 날짜 형식이어야 합니다.',
        ],
        'valid_until' => [
            'date' => '유효종료일은 날짜 형식이어야 합니다.',
            'after_or_equal' => '유효종료일은 유효시작일 이후여야 합니다.',
        ],
        'issue_start_at' => [
            'date' => '발급시작일은 날짜 형식이어야 합니다.',
        ],
        'issue_end_at' => [
            'date' => '발급종료일은 날짜 형식이어야 합니다.',
            'after_or_equal' => '발급종료일은 발급시작일 이후여야 합니다.',
        ],
        'issue_status' => [
            'required' => '발급상태를 선택해주세요.',
            'in' => '올바른 발급상태를 선택해주세요.',
        ],
        'issue_method' => [
            'in' => '올바른 발급방법을 선택해주세요.',
        ],
        'issue_condition' => [
            'in' => '올바른 발급조건을 선택해주세요.',
        ],
        'combinable' => [
            'boolean' => '중복사용 여부는 true 또는 false여야 합니다.',
        ],
        'products' => [
            'array' => '적용상품은 배열 형태여야 합니다.',
        ],
        'categories' => [
            'array' => '적용카테고리는 배열 형태여야 합니다.',
        ],
        'ids' => [
            'required' => '변경할 쿠폰을 선택해주세요.',
            'array' => '쿠폰 ID는 배열 형태여야 합니다.',
            'min' => '최소 1개 이상의 쿠폰을 선택해주세요.',
        ],
        // 평면 형식 키 (FormRequest와 통일)
        'name_required' => '쿠폰명을 입력해주세요.',
        'target_type_required' => '적용대상을 선택해주세요.',
        'discount_type_required' => '할인유형을 선택해주세요.',
        'discount_value_required' => '할인값을 입력해주세요.',
        'discount_value_rate_min' => '할인율은 1% 이상이어야 합니다.',
        'discount_value_rate_max' => '할인율은 100% 이하여야 합니다.',
        'discount_value_fixed_min' => '할인값은 1원 이상이어야 합니다.',
        'issue_method_required' => '발급방법을 선택해주세요.',
        'issue_condition_required' => '발급조건을 선택해주세요.',
        'valid_type_required' => '유효기간 유형을 선택해주세요.',
        'valid_days_required' => '유효일수를 입력해주세요.',
        'valid_from_required' => '유효시작일을 입력해주세요.',
        'valid_to_required' => '유효종료일을 입력해주세요.',
        'valid_to_after_from' => '유효종료일은 유효시작일 이후여야 합니다.',
        'ids_required' => '변경할 쿠폰을 선택해주세요.',
        'ids_min' => '최소 1개 이상의 쿠폰을 선택해주세요.',
        'issue_status_required' => '발급상태를 선택해주세요.',
        'issue_status_invalid' => '올바른 발급상태를 선택해주세요.',
        'id_required' => '쿠폰 ID는 필수입니다.',
        'id_integer' => '쿠폰 ID는 정수여야 합니다.',
        'id_not_found' => '존재하지 않는 쿠폰입니다.',
        'target_products_required' => '적용 상품을 1개 이상 선택해주세요.',
        'target_categories_required' => '적용 카테고리를 1개 이상 선택해주세요.',
        'user_ids_required' => '발급할 회원을 선택해주세요.',
        'user_ids_min' => '최소 1명 이상의 회원을 선택해주세요.',
        'user_ids_invalid' => '존재하지 않는 회원이 포함되어 있습니다.',
    ],

    // 주문 검증 메시지 (orders.* 형식 - FormRequest와 통일)
    'orders' => [
        'ids' => [
            'required' => '변경할 주문을 선택해주세요.',
            'array' => '주문 ID는 배열 형태여야 합니다.',
            'min' => '최소 1개 이상의 주문을 선택해주세요.',
            'exists' => '존재하지 않는 주문입니다.',
        ],
        'order_status' => [
            'required' => '주문 상태를 선택해주세요.',
            'array' => '주문 상태는 배열 형태여야 합니다.',
            'string' => '주문 상태는 문자열이어야 합니다.',
            'in' => '올바른 주문 상태를 선택해주세요.',
        ],
        'carrier_id' => [
            'required' => '택배사를 선택해주세요.',
            'exists' => '존재하지 않는 택배사입니다.',
        ],
        'tracking_number' => [
            'required' => '운송장 번호를 입력해주세요.',
            'string' => '운송장 번호는 문자열이어야 합니다.',
            'max' => '운송장 번호는 최대 50자까지 입력 가능합니다.',
            'requires_status' => '운송장 번호 입력 시 배송 상태도 함께 변경해주세요.',
        ],
        'admin_memo' => [
            'max' => '관리자 메모는 최대 2000자까지 입력 가능합니다.',
        ],
        'recipient_name' => [
            'required' => '수령인 이름을 입력해주세요.',
            'max' => '수령인 이름은 최대 50자까지 입력 가능합니다.',
        ],
        'recipient_phone' => [
            'required_without' => '휴대폰 번호 또는 전화번호 중 하나는 필수입니다.',
            'max' => '수령인 연락처는 최대 20자까지 입력 가능합니다.',
        ],
        'recipient_tel' => [
            'required_without' => '전화번호 또는 휴대폰 번호 중 하나는 필수입니다.',
            'max' => '수령인 전화번호는 최대 20자까지 입력 가능합니다.',
        ],
        'recipient_zipcode' => [
            'required' => '우편번호를 입력해주세요.',
            'max' => '우편번호는 최대 10자까지 입력 가능합니다.',
        ],
        'recipient_address' => [
            'required' => '기본 주소를 입력해주세요. 우편번호 검색을 이용해 주세요.',
            'max' => '기본 주소는 최대 255자까지 입력 가능합니다.',
        ],
        'recipient_detail_address' => [
            'required' => '상세 주소를 입력해 주세요.',
            'max' => '상세 주소는 최대 255자까지 입력 가능합니다.',
        ],
        'delivery_memo' => [
            'max' => '배송 메모는 최대 500자까지 입력 가능합니다.',
        ],
        'recipient_country_code' => [
            'size' => '국가 코드는 2자리여야 합니다.',
        ],
        'email' => [
            'required' => '이메일 주소를 입력해주세요.',
            'email' => '올바른 이메일 주소를 입력해주세요.',
            'max' => '이메일 주소는 최대 255자까지 입력 가능합니다.',
        ],
        'email_message' => [
            'required' => '이메일 내용을 입력해주세요.',
            'max' => '이메일 내용은 최대 5000자까지 입력 가능합니다.',
        ],
        'not_found' => '주문을 찾을 수 없습니다.',
        'cannot_update' => '이 주문은 수정할 수 없습니다.',
        'cannot_cancel' => '이 주문은 취소할 수 없습니다.',
        'cannot_refund' => '이 주문은 환불할 수 없습니다.',
        'carrier_required' => '해당 상태로 변경하려면 택배사를 선택해주세요.',
        'tracking_number_required' => '해당 상태로 변경하려면 송장번호를 입력해주세요.',
        // 상태 전이 규칙 (역방향/비연속 역행 차단)
        'status_transition' => [
            'invalid' => ':from 상태에서 :to 상태로는 변경할 수 없습니다.',
            'bulk_invalid' => '일부 항목(:count건)을 :to 상태로 변경할 수 없습니다. (현재 상태: :from)',
        ],
        // 일괄 처리 관련
        'bulk_update' => [
            'at_least_one' => '주문 상태, 택배사, 운송장 번호 중 하나 이상을 입력해주세요.',
        ],
        // 검색/목록 관련
        'search_field' => [
            'in' => '올바른 검색 필드를 선택해주세요.',
        ],
        'member_type' => [
            'in' => '올바른 회원 구분을 선택해주세요.',
        ],
        'search_keyword' => [
            'string' => '검색어는 문자열이어야 합니다.',
            'max' => '검색어는 최대 200자까지 입력 가능합니다.',
        ],
        'date_type' => [
            'in' => '올바른 날짜 유형을 선택해주세요.',
        ],
        'start_date' => [
            'date' => '시작일은 날짜 형식이어야 합니다.',
        ],
        'end_date' => [
            'date' => '종료일은 날짜 형식이어야 합니다.',
            'after_or_equal' => '종료일은 시작일 이후여야 합니다.',
        ],
        'date_basis' => [
            'in' => '날짜 기준은 site(사이트 표준시)만 쓸 수 있습니다.',
        ],
        // 옵션상태
        'option_status' => [
            'array' => '옵션 상태는 배열 형태여야 합니다.',
            'string' => '옵션 상태는 문자열이어야 합니다.',
            'in' => '올바른 옵션 상태를 선택해주세요.',
        ],
        // 배송유형
        'shipping_type' => [
            'array' => '배송 유형은 배열 형태여야 합니다.',
            'string' => '배송 유형은 문자열이어야 합니다.',
            'in' => '올바른 배송 유형을 선택해주세요.',
        ],
        // 결제수단
        'payment_method' => [
            'array' => '결제수단은 배열 형태여야 합니다.',
            'string' => '결제수단은 문자열이어야 합니다.',
            'in' => '올바른 결제수단을 선택해주세요.',
        ],
        // 카테고리
        'category_id' => [
            'integer' => '카테고리 ID는 숫자여야 합니다.',
        ],
        // 금액 범위
        'min_amount' => [
            'integer' => '최소 금액은 숫자여야 합니다.',
            'min' => '최소 금액은 0 이상이어야 합니다.',
        ],
        'max_amount' => [
            'integer' => '최대 금액은 숫자여야 합니다.',
            'min' => '최대 금액은 0 이상이어야 합니다.',
        ],
        // 배송비 범위
        'min_shipping_amount' => [
            'integer' => '최소 배송비는 숫자여야 합니다.',
            'min' => '최소 배송비는 0 이상이어야 합니다.',
        ],
        'max_shipping_amount' => [
            'integer' => '최대 배송비는 숫자여야 합니다.',
            'min' => '최대 배송비는 0 이상이어야 합니다.',
        ],
        // 배송정책
        'shipping_policy_id' => [
            'integer' => '배송정책 ID는 숫자여야 합니다.',
        ],
        // 배송국가 코드
        'country_codes' => [
            'array' => '국가 코드는 배열 형태여야 합니다.',
            'string' => '국가 코드는 문자열이어야 합니다.',
            'size' => '국가 코드는 2자리여야 합니다.',
        ],
        // 주문 디바이스
        'order_device' => [
            'array' => '주문 디바이스는 배열 형태여야 합니다.',
            'string' => '주문 디바이스는 문자열이어야 합니다.',
            'in' => '올바른 주문 디바이스를 선택해주세요.',
        ],
        // 회원 ID
        'user_id' => [
            'integer' => '회원 ID는 숫자여야 합니다.',
        ],
        // 주문자 UUID
        'orderer_uuid' => [
            'uuid' => '주문자 UUID 형식이 올바르지 않습니다.',
        ],
        // 클레임 기준 필터 (D22)
        'claim_presence' => [
            'in' => '클레임 유무 필터는 none, any, active 중 하나여야 합니다.',
        ],
        'claim_statuses' => [
            'array' => '클레임 상태 필터는 배열 형태여야 합니다.',
            'in' => '올바른 클레임 상태를 선택해주세요.',
        ],
        'claim_cancel_types' => [
            'array' => '취소 유형 필터는 배열 형태여야 합니다.',
            'in' => '올바른 취소 유형을 선택해주세요.',
        ],
        'claim_fault_types' => [
            'array' => '귀책 필터는 배열 형태여야 합니다.',
            'in' => '올바른 귀책 구분을 선택해주세요.',
        ],
        'claim_requested_from' => [
            'date' => '클레임 신청일 시작은 올바른 날짜여야 합니다.',
        ],
        'claim_requested_to' => [
            'date' => '클레임 신청일 종료는 올바른 날짜여야 합니다.',
            'after_or_equal' => '클레임 신청일 종료는 시작일 이후여야 합니다.',
        ],
        // 정렬 및 페이지네이션
        'sort_by' => [
            'in' => '올바른 정렬 필드를 선택해주세요.',
        ],
        'sort_order' => [
            'in' => '정렬 순서는 asc 또는 desc여야 합니다.',
        ],
        'per_page' => [
            'integer' => '페이지당 항목 수는 숫자여야 합니다.',
            'min' => '페이지당 항목 수는 10 이상이어야 합니다.',
            'max' => '페이지당 항목 수는 100 이하여야 합니다.',
        ],
        'page' => [
            'integer' => '페이지 번호는 숫자여야 합니다.',
            'min' => '페이지 번호는 1 이상이어야 합니다.',
        ],
    ],

    // 주문 옵션 수량 분할 검증 메시지
    'quantity_exceeds_available' => '변경 수량이 보유 수량을 초과합니다.',
    'quantity_min_one' => '변경 수량은 1 이상이어야 합니다.',

    // 주문 옵션 일괄 상태 변경 검증 메시지
    'order_options' => [
        'items' => [
            'required' => '변경할 옵션을 선택해주세요.',
            'min' => '최소 1개 이상의 옵션을 선택해주세요.',
        ],
        'option_id' => [
            'required' => '옵션 ID는 필수입니다.',
            'exists' => '존재하지 않는 옵션입니다.',
        ],
        'quantity' => [
            'required' => '변경 수량을 입력해주세요.',
            'min' => '변경 수량은 1 이상이어야 합니다.',
        ],
        'status' => [
            'required' => '변경할 상태를 선택해주세요.',
            'in' => '올바른 옵션 상태를 선택해주세요.',
        ],
    ],

    // 주문 검증 메시지 (하위 호환성 - order.* 형식)
    'order' => [
        'payment_method_unavailable' => '현재 사용할 수 없는 결제수단입니다. 다른 결제수단을 선택해주세요.',
        'payment_method_not_selectable' => '선택할 수 없는 결제수단입니다.',
        'payment_method_not_allowed_for_currency' => ':currency 결제에서는 선택할 수 없는 결제수단입니다.',
        'ids' => [
            'required' => '변경할 주문을 선택해주세요.',
            'array' => '주문 ID는 배열 형태여야 합니다.',
            'min' => '최소 1개 이상의 주문을 선택해주세요.',
        ],
        'order_status' => [
            'required' => '주문 상태를 선택해주세요.',
            'in' => '올바른 주문 상태를 선택해주세요.',
        ],
        'carrier_id' => [
            'required' => '택배사를 선택해주세요.',
            'exists' => '존재하지 않는 택배사입니다.',
        ],
        'tracking_number' => [
            'required' => '운송장 번호를 입력해주세요.',
            'string' => '운송장 번호는 문자열이어야 합니다.',
            'max' => '운송장 번호는 최대 100자까지 입력 가능합니다.',
        ],
        'not_found' => '주문을 찾을 수 없습니다.',
        'cannot_update' => '이 주문은 수정할 수 없습니다.',
        'cannot_cancel' => '이 주문은 취소할 수 없습니다.',
        'cannot_refund' => '이 주문은 환불할 수 없습니다.',
        // 주문 생성 (결제하기) 검증 메시지
        'orderer_name_required' => '주문자 이름을 입력해주세요.',
        'orderer_phone_required' => '주문자 연락처를 입력해주세요.',
        'orderer_email_required' => '주문자 이메일을 입력해주세요.',
        'orderer_email_invalid' => '올바른 이메일 형식이 아닙니다.',
        'recipient_name_required' => '수령인 이름을 입력해주세요.',
        'recipient_phone_required' => '수령인 연락처를 입력해주세요.',
        'recipient_phone_required_without' => '핸드폰 또는 전화번호 중 하나를 입력해주세요.',
        'recipient_tel_required_without' => '핸드폰 또는 전화번호 중 하나를 입력해주세요.',
        'zipcode_required' => '우편번호를 입력해주세요.',
        'address_required' => '주소를 입력해주세요.',
        'address_detail_required' => '상세 주소를 입력해주세요.',
        'address_line_1_required' => '주소를 입력해주세요.',
        'intl_city_required' => '도시를 입력해주세요.',
        'intl_postal_code_required' => '우편번호를 입력해주세요.',
        'payment_method_required' => '결제 방법을 선택해주세요.',
        'payment_method_invalid' => '올바른 결제 방법을 선택해주세요.',
        'expected_total_amount_required' => '결제 예정 금액이 필요합니다.',
        'expected_total_amount_numeric' => '결제 예정 금액은 숫자여야 합니다.',
        'depositor_name_required' => '입금자명을 입력해주세요.',
        'dbank_bank_code_required' => '입금 은행을 선택해주세요.',
        'dbank_bank_name_required' => '입금 은행명이 필요합니다.',
        'dbank_account_number_required' => '입금 계좌번호가 필요합니다.',
        'dbank_account_holder_required' => '예금주명이 필요합니다.',
        'guest_lookup_password_required' => '주문 조회 비밀번호를 입력해주세요.',
        'guest_lookup_password_min' => '주문 조회 비밀번호는 8자 이상이어야 합니다.',
        'guest_lookup_password_confirmed' => '주문 조회 비밀번호가 일치하지 않습니다.',
        'guest_lookup_password_confirmation_required' => '주문 조회 비밀번호 확인을 입력해주세요.',
        'cash_receipt_type_required' => '현금영수증 발급 용도를 선택해주세요.',
        'cash_receipt_type_invalid' => '현금영수증 발급 용도가 올바르지 않습니다.',
        'cash_receipt_identifier_type_required' => '현금영수증 발급수단을 선택해주세요.',
        'cash_receipt_identifier_type_invalid' => '현금영수증 발급수단이 올바르지 않습니다.',
        'cash_receipt_identifier_required' => '현금영수증 발급에 사용할 번호를 입력해주세요.',
        'refund_bank_required_with' => '환불 계좌는 은행·계좌번호·예금주를 모두 입력해야 합니다.',
        'refund_bank_required_for_vbank' => '입금이 완료된 가상계좌 주문은 환불 계좌가 필요합니다.',
        'refund_bank_required_for_manual_transfer' => '이 주문의 환불은 계좌로 송금해야 합니다. 환불받을 계좌를 입력해 주세요.',
    ],

    // 비회원 주문 조회 인증 검증 메시지
    'guest_order' => [
        'order_number_required' => '주문번호를 입력해주세요.',
        'orderer_phone_required' => '전화번호를 입력해주세요.',
        'guest_lookup_password_required' => '주문 조회 비밀번호를 입력해주세요.',
    ],

    // 주문 일괄 처리 검증 메시지 (하위 호환성)
    'order_bulk' => [
        'ids_required' => '변경할 주문을 선택해주세요.',
        'status_or_shipping_required' => '주문 상태 또는 배송 정보 중 하나는 입력해야 합니다.',
        'invalid_status' => '잘못된 주문 상태입니다.',
        'invalid_carrier' => '잘못된 택배사입니다.',
    ],

    // 주문 내보내기 검증 메시지
    'order_export' => [
        'format' => [
            'required' => '내보내기 형식을 선택해주세요.',
            'in' => '올바른 내보내기 형식을 선택해주세요.',
        ],
        'columns' => [
            'required' => '내보낼 컬럼을 선택해주세요.',
            'array' => '컬럼은 배열 형태여야 합니다.',
            'min' => '최소 1개 이상의 컬럼을 선택해주세요.',
        ],
    ],

    // 장바구니 검증 메시지
    'cart' => [
        'quantity_limit_exceeded' => '장바구니에는 상품당 최대 :limit개까지 담을 수 있습니다. (요청: :attempted개)',
        'ids_required' => '삭제할 상품을 선택해주세요.',
        'ids_array' => '상품 ID는 배열 형태여야 합니다.',
        'ids_min' => '최소 1개 이상의 상품을 선택해주세요.',
        'item_not_found' => '장바구니 상품을 찾을 수 없습니다.',
        'product_id_required' => '상품을 선택해주세요.',
        'product_not_found' => '존재하지 않는 상품입니다.',
        'option_id_required' => '옵션을 선택해주세요.',
        'option_not_found' => '존재하지 않는 옵션입니다.',
        'quantity_required' => '수량을 입력해주세요.',
        'quantity_min' => '수량은 1개 이상이어야 합니다.',
        'quantity_max' => '수량은 최대 :max개까지 가능합니다.',
        'items_required' => '장바구니에 담을 상품을 선택해주세요.',
        'items_min' => '최소 1개 이상의 상품을 선택해주세요.',
        'selected_ids_array' => '선택된 장바구니 상품 ID는 배열 형태여야 합니다.',
        'selected_ids_integer' => '선택된 장바구니 상품 ID는 숫자여야 합니다.',
        'selected_ids_min' => '선택된 장바구니 상품 ID는 1 이상이어야 합니다.',
        'cart_key_required' => '비회원 장바구니 키가 필요합니다.',
        'invalid_cart_key' => '잘못된 장바구니 키 형식입니다.',
        'login_required' => '로그인이 필요합니다.',
    ],

    // 찜 검증 메시지
    'wishlist' => [
        'product_id_required' => '상품을 선택해주세요.',
        'product_not_found' => '상품을 찾을 수 없습니다.',
        'selected_ids_array' => '선택된 상품 ID는 배열 형태여야 합니다.',
        'selected_ids_integer' => '선택된 상품 ID는 숫자여야 합니다.',
        'selected_ids_min' => '선택된 상품 ID는 1 이상이어야 합니다.',
        'cart_key_required' => '비회원 장바구니 키가 필요합니다.',
        'invalid_cart_key' => '잘못된 장바구니 키 형식입니다.',
        'login_required' => '로그인이 필요합니다.',
    ],

    // 체크아웃 검증 메시지
    'checkout' => [
        'item_ids_required' => '주문할 상품을 선택해주세요.',
        'item_ids_array' => '상품 ID는 배열 형태여야 합니다.',
        'item_ids_min' => '최소 1개 이상의 상품을 선택해주세요.',
        'use_points_integer' => '마일리지는 숫자여야 합니다.',
        'use_points_min' => '마일리지는 0 이상이어야 합니다.',
        'coupon_issue_ids_array' => '쿠폰 ID는 배열 형태여야 합니다.',
        'coupon_issue_id_integer' => '쿠폰 ID는 숫자여야 합니다.',
        'item_coupons_array' => '상품별 쿠폰은 배열 형태여야 합니다.',
        'item_coupons_max' => '상품당 쿠폰은 최대 :max개까지 적용할 수 있습니다.',
        'item_coupon_integer' => '상품 쿠폰 ID는 숫자여야 합니다.',
        'item_coupon_not_found' => '존재하지 않는 상품 쿠폰입니다.',
        'order_coupon_integer' => '주문 쿠폰 ID는 숫자여야 합니다.',
        'order_coupon_not_found' => '존재하지 않는 주문 쿠폰입니다.',
        'shipping_coupon_integer' => '배송비 쿠폰 ID는 숫자여야 합니다.',
        'shipping_coupon_not_found' => '존재하지 않는 배송비 쿠폰입니다.',
        'country_code_size' => '국가 코드는 2자리여야 합니다.',
        'zipcode_max' => '우편번호는 최대 20자까지 입력 가능합니다.',
        'region_max' => '지역은 최대 100자까지 입력 가능합니다.',
        'city_max' => '도시는 최대 100자까지 입력 가능합니다.',
        'address_max' => '주소는 최대 255자까지 입력 가능합니다.',
    ],

    // 배송정책 검증 메시지
    'shipping_policy' => [
        'name' => [
            'required' => '배송정책명을 입력해주세요.',
        ],
        // 클레임 설정 (회수지 + 국가별 클레임 배송비 — S6c)
        'claim_settings' => [
            'country_code' => [
                'required' => '국가 코드를 입력해주세요.',
                'distinct' => '국가 코드가 중복되었습니다.',
                'exists' => '이 배송정책의 국가별 설정에 없는 국가 코드입니다.',
            ],
            'claim_fee_mode' => [
                'required' => '클레임 배송비 산정 모드를 선택해주세요.',
                'enum' => '올바른 클레임 배송비 산정 모드를 선택해주세요.',
            ],
            'fee' => [
                'numeric' => '클레임 배송비는 숫자여야 합니다.',
                'min' => '클레임 배송비는 0 이상이어야 합니다.',
            ],
            'recipient_name' => [
                'required' => '회수지 수취인명을 입력해주세요.',
                'max' => '회수지 수취인명은 최대 100자까지 입력 가능합니다.',
            ],
            'address1' => [
                'required' => '회수지 기본주소를 입력해주세요.',
                'max' => '회수지 기본주소는 최대 255자까지 입력 가능합니다.',
            ],
        ],
        'ids_required' => '변경할 배송정책을 선택해주세요.',
        'ids_array' => '배송정책 ID는 배열 형태여야 합니다.',
        'ids_min' => '최소 1개 이상의 배송정책을 선택해주세요.',
        'id_integer' => '배송정책 ID는 숫자여야 합니다.',
        'id_exists' => '존재하지 않는 배송정책입니다.',
        'is_active_required' => '사용여부를 선택해주세요.',
        'is_active_boolean' => '사용여부는 true 또는 false여야 합니다.',
        'base_fee_zero_not_allowed' => '무료배송이 아닌 정책은 배송비를 0원으로 설정할 수 없습니다.',
        'custom_shipping_name_required' => '직접입력 배송방법 선택 시 배송방법명을 입력해주세요.',
        'ranges' => [
            'first_min_zero' => '첫 구간의 시작값은 0이어야 합니다.',
            'last_max_unlimited' => '마지막 구간의 종료값은 비워야 합니다.',
            'continuity' => '구간이 연속적이지 않습니다.',
            'min_less_than_max' => '시작값이 종료값보다 작아야 합니다.',
            'fee_non_negative' => '배송비는 0 이상이어야 합니다.',
            'fee_required' => '구간 배송비를 입력해주세요.',
            'tier_min_non_negative' => '구간 시작값은 0 이상이어야 합니다.',
            'tier_max_non_negative' => '구간 종료값은 0 이상이어야 합니다.',
            'unit_value_min' => '구간 단위값은 0보다 커야 합니다.',
            'tiers_required' => '구간별 배송비 정책은 구간을 1개 이상 등록해야 합니다.',
            'middle_max_required' => '마지막 구간을 제외한 구간에는 종료값을 입력해야 합니다.',
            'tier_value_integer' => '수량 구간의 시작값과 종료값은 정수여야 합니다.',
            'unit_value_required' => '단위당 배송비 정책은 단위값을 입력해야 합니다.',
        ],
        'free_threshold_required' => '조건부 무료배송 정책은 무료배송 기준금액을 입력해야 합니다.',
        'extra_fee' => [
            'zipcode_format' => '우편번호는 "63000", "63000-63999", "63*" 형식 중 하나여야 합니다.',
        ],
        'country_settings' => [
            'required' => '국가별 배송 설정을 1개 이상 추가해주세요.',
            'min' => '국가별 배송 설정을 1개 이상 추가해주세요.',
            'country_code' => [
                'required' => '국가를 선택해주세요.',
                'distinct' => '국가가 중복되었습니다.',
            ],
            'shipping_method' => [
                'required' => '배송방법을 선택해주세요.',
                'in' => '올바른 배송방법을 선택해주세요.',
            ],
            'charge_policy' => [
                'required' => '부과 정책을 선택해주세요.',
                'in' => '올바른 부과 정책을 선택해주세요.',
            ],
            'base_fee' => [
                'numeric' => '기본 배송비는 숫자여야 합니다.',
                'min' => '기본 배송비는 0 이상이어야 합니다.',
            ],
            'free_threshold' => [
                'numeric' => '무료배송 기준금액은 숫자여야 합니다.',
                'min' => '무료배송 기준금액은 0 이상이어야 합니다.',
            ],
            'api_endpoint' => [
                'url' => '올바른 URL 형식이 아닙니다.',
                'required' => '계산 API 정책 선택 시 API 주소를 입력해주세요.',
            ],
            'api_request_fields' => [
                'in' => '지원하지 않는 참고 필드입니다.',
            ],
            'api_config' => [
                'http_method_in' => '지원하지 않는 HTTP 메서드입니다.',
                'auth_type_in' => '지원하지 않는 인증 방식입니다.',
                'auth_header_name_required' => '커스텀 헤더 인증 선택 시 헤더명을 입력해주세요.',
                'auth_header_name_format' => '헤더명에 사용할 수 없는 문자가 포함되어 있습니다.',
                'response_type_in' => '지원하지 않는 응답 형식입니다.',
                'field_map_format' => '외부 키 이름에 사용할 수 없는 문자가 포함되어 있습니다.',
            ],
            'extra_fee_enabled' => [
                'required' => '추가배송비 사용여부를 선택해주세요.',
            ],
            'is_active' => [
                'required' => '사용여부를 선택해주세요.',
            ],
        ],
    ],

    // 추가배송비 템플릿 검증 메시지
    'extra_fee_template' => [
        'zipcode_required' => '우편번호를 입력해주세요.',
        'zipcode_unique' => '이미 등록된 우편번호입니다.',
        'zipcode_max' => '우편번호는 최대 10자까지 입력 가능합니다.',
        'zipcode_format' => '우편번호는 숫자와 하이픈(-)만 입력 가능합니다. (예: 12345 또는 12345-12399)',
        'fee_required' => '추가배송비를 입력해주세요.',
        'fee_numeric' => '추가배송비는 숫자여야 합니다.',
        'fee_min' => '추가배송비는 0 이상이어야 합니다.',
        'ids_required' => '변경할 항목을 선택해주세요.',
        'ids_array' => '항목 ID는 배열 형태여야 합니다.',
        'ids_min' => '최소 1개 이상의 항목을 선택해주세요.',
        'id_not_found' => '존재하지 않는 항목입니다.',
        'is_active_required' => '사용여부를 선택해주세요.',
        'is_active_boolean' => '사용여부는 true 또는 false여야 합니다.',
        'items_required' => '등록할 항목을 입력해주세요.',
        'items_array' => '항목은 배열 형태여야 합니다.',
        'items_min' => '최소 1개 이상의 항목을 입력해주세요.',
        'items_max' => '한 번에 최대 100개까지 등록 가능합니다.',
        'item_zipcode_required' => '우편번호를 입력해주세요.',
        'item_fee_required' => '추가배송비를 입력해주세요.',
    ],

    // 공통정보 검증 메시지
    'product_common_info' => [
        'name_required' => '공통정보명을 입력해주세요.',
        'name_max' => '공통정보명은 최대 100자까지 입력 가능합니다.',
        'content_mode_invalid' => '올바른 콘텐츠 모드를 선택해주세요 (text 또는 html).',
    ],

    // 쿠폰 발급 내역 조회 검증 메시지
    'coupon_issues' => [
        'user_id_integer' => '사용자 ID는 정수여야 합니다.',
        'user_id_exists' => '존재하지 않는 사용자입니다.',
        'status_in' => '유효하지 않은 상태 값입니다.',
        'per_page_integer' => '페이지당 개수는 정수여야 합니다.',
        'per_page_min' => '페이지당 개수는 최소 1개 이상이어야 합니다.',
        'per_page_max' => '페이지당 개수는 최대 100개까지 가능합니다.',
    ],

    // 검색 프리셋 검증 메시지
    'search_preset' => [
        'target_screen_in' => '유효하지 않은 대상 화면입니다.',
    ],

    // 카테고리 순서 변경 검증 메시지
    'category_reorder' => [
        'parent_menus_required' => '부모 메뉴 또는 자식 메뉴 데이터가 필요합니다.',
        'parent_menus_array' => '부모 메뉴는 배열이어야 합니다.',
        'id_required' => '카테고리 ID는 필수입니다.',
        'id_integer' => '카테고리 ID는 정수여야 합니다.',
        'id_exists' => '존재하지 않는 카테고리입니다.',
        'order_required' => '순서 값은 필수입니다.',
        'order_integer' => '순서 값은 정수여야 합니다.',
        'order_min' => '순서 값은 0 이상이어야 합니다.',
    ],

    // 리뷰 검증 메시지 (관리자)
    'reviews' => [
        'search_field' => [
            'in' => '올바른 검색 필드를 선택해주세요.',
        ],
        'search_keyword' => [
            'string' => '검색 키워드는 문자열이어야 합니다.',
            'max' => '검색 키워드는 최대 :max자까지 입력 가능합니다.',
        ],
        'rating' => [
            'in' => '올바른 평점을 선택해주세요.',
            'required' => '평점을 선택해주세요.',
            'integer' => '평점은 숫자여야 합니다.',
            'min' => '평점은 :min점 이상이어야 합니다.',
            'max' => '평점은 :max점 이하여야 합니다.',
        ],
        'reply_status' => [
            'in' => '올바른 답글 상태를 선택해주세요.',
        ],
        'has_photo' => [
            'boolean' => '포토 리뷰 필터는 true 또는 false여야 합니다.',
        ],
        'status' => [
            'in' => '올바른 리뷰 상태를 선택해주세요.',
            'required' => '리뷰 상태를 선택해주세요.',
            'required_if' => '상태 변경을 선택한 경우 리뷰 상태를 함께 선택해주세요.',
        ],
        'start_date' => [
            'date' => '시작일은 날짜 형식이어야 합니다.',
        ],
        'end_date' => [
            'date' => '종료일은 날짜 형식이어야 합니다.',
            'after_or_equal' => '종료일은 시작일 이후여야 합니다.',
        ],
        'sort_by' => [
            'in' => '올바른 정렬 필드를 선택해주세요.',
        ],
        'sort_order' => [
            'in' => '정렬 순서는 asc 또는 desc여야 합니다.',
        ],
        'per_page' => [
            'integer' => '페이지당 항목 수는 숫자여야 합니다.',
            'min' => '페이지당 항목 수는 :min 이상이어야 합니다.',
            'max' => '페이지당 항목 수는 :max 이하여야 합니다.',
        ],
        'page' => [
            'integer' => '페이지 번호는 숫자여야 합니다.',
            'min' => '페이지 번호는 1 이상이어야 합니다.',
        ],
        // 리뷰 작성 (사용자)
        'product_id' => [
            'required' => '상품을 선택해주세요.',
            'exists' => '존재하지 않는 상품입니다.',
        ],
        'order_option_id' => [
            'required' => '리뷰를 작성할 주문 상품을 선택해주세요.',
            'exists' => '존재하지 않는 주문 상품입니다.',
        ],
        'content' => [
            'required' => '리뷰 내용을 입력해주세요.',
            'min' => '리뷰 내용은 최소 :min자 이상 입력해주세요.',
            'max' => '리뷰 내용은 최대 :max자까지 입력 가능합니다.',
        ],
        'content_mode' => [
            'in' => '올바른 리뷰 내용 형식을 선택해주세요.',
        ],
        // 리뷰 답글 (관리자)
        'reply_content' => [
            'required' => '답글 내용을 입력해주세요.',
            'min' => '답글 내용은 최소 :min자 이상 입력해주세요.',
            'max' => '답글 내용은 최대 :max자까지 입력 가능합니다.',
        ],
        'reply_content_mode' => [
            'in' => '올바른 답글 내용 형식을 선택해주세요.',
        ],
        // 리뷰 일괄 처리 (관리자)
        'ids' => [
            'required' => '처리할 리뷰를 선택해주세요.',
            'array' => '리뷰 ID는 배열 형태여야 합니다.',
            'min' => '최소 1개 이상의 리뷰를 선택해주세요.',
            'integer' => '리뷰 ID는 숫자여야 합니다.',
            'exists' => '존재하지 않는 리뷰입니다.',
        ],
        'action' => [
            'required' => '처리 방식을 선택해주세요.',
            'in' => '올바른 처리 방식을 선택해주세요.',
        ],
    ],

    // 공개 상품 조회 검증 메시지
    'public_product' => [
        'category_id' => [
            'integer' => '카테고리 ID는 숫자여야 합니다.',
        ],
        'category_slug' => [
            'string' => '카테고리 슬러그는 문자열이어야 합니다.',
            'max' => '카테고리 슬러그는 최대 :max자까지 입력 가능합니다.',
        ],
        'brand_id' => [
            'integer' => '브랜드 ID는 숫자여야 합니다.',
        ],
        'search' => [
            'string' => '검색어는 문자열이어야 합니다.',
            'max' => '검색어는 최대 :max자까지 입력 가능합니다.',
        ],
        'sort' => [
            'in' => '올바른 정렬 기준을 선택해주세요.',
        ],
        'min_price' => [
            'integer' => '최소 가격은 숫자여야 합니다.',
            'min' => '최소 가격은 0 이상이어야 합니다.',
        ],
        'max_price' => [
            'integer' => '최대 가격은 숫자여야 합니다.',
            'min' => '최대 가격은 0 이상이어야 합니다.',
        ],
        'per_page' => [
            'integer' => '페이지당 항목 수는 숫자여야 합니다.',
            'min' => '페이지당 항목 수는 :min 이상이어야 합니다.',
            'max' => '페이지당 항목 수는 :max 이하여야 합니다.',
        ],
        'limit' => [
            'integer' => '조회 개수는 숫자여야 합니다.',
            'min' => '조회 개수는 :min 이상이어야 합니다.',
            'max' => '조회 개수는 :max 이하여야 합니다.',
        ],
        'ids' => [
            'string' => '상품 ID 목록은 문자열이어야 합니다.',
            'max' => '상품 ID 목록은 최대 :max자까지 입력 가능합니다.',
        ],
    ],

    // 공개 리뷰 조회 검증 메시지
    'public_review' => [
        'sort' => [
            'in' => '올바른 정렬 기준을 선택해주세요.',
        ],
        'photo_only' => [
            'boolean' => '포토 리뷰 필터는 true 또는 false여야 합니다.',
        ],
        'page' => [
            'integer' => '페이지 번호는 숫자여야 합니다.',
            'min' => '페이지 번호는 1 이상이어야 합니다.',
        ],
        'per_page' => [
            'integer' => '페이지당 항목 수는 숫자여야 합니다.',
            'min' => '페이지당 항목 수는 :min 이상이어야 합니다.',
            'max' => '페이지당 항목 수는 :max 이하여야 합니다.',
        ],
        'rating' => [
            'in' => '올바른 평점을 선택해주세요.',
        ],
    ],

    // 사용자 쿠폰 조회 검증 메시지
    'user_coupon' => [
        'status' => [
            'in' => '올바른 쿠폰 상태를 선택해주세요.',
        ],
        'per_page' => [
            'integer' => '페이지당 항목 수는 숫자여야 합니다.',
            'min' => '페이지당 항목 수는 :min 이상이어야 합니다.',
            'max' => '페이지당 항목 수는 :max 이하여야 합니다.',
        ],
        'product_ids' => [
            'array' => '상품 ID 목록은 배열 형태여야 합니다.',
        ],
        'product_ids_item' => [
            'integer' => '상품 ID는 숫자여야 합니다.',
        ],
    ],

    // 사용자 마일리지 조회 검증 메시지
    'user_mileage' => [
        'order_amount' => [
            'required' => '주문 금액은 필수입니다.',
            'integer' => '주문 금액은 숫자여야 합니다.',
            'min' => '주문 금액은 0 이상이어야 합니다.',
        ],
    ],

    // 공개 자산 디스크 (코어 카탈로그 조회 검증)
    'public_asset_disk_invalid' => '올바른 공개 자산 디스크를 선택해주세요.',

    // 필드명 한국어 변환 (Laravel 표준)
    'attributes' => [
        // 수동 결제 처리·재활성 확인 창 세 항목 (Q13·Q14)
        'apply_stock_deduction' => '재고 차감',
        'apply_mileage_deduction' => '사용 마일리지 차감',
        'record_paid_amount' => '결제금액 기록',

        // 관리자 회원 목록 확장 필터 (#705)
        'user_filter_mileage_min' => '마일리지 잔액 최소',
        'user_filter_mileage_max' => '마일리지 잔액 최대',
        'user_filter_shipping_country' => '배송지 국가',
        'user_filter_has_address' => '배송지 등록 여부',

        // 공개 자산 스토리지
        'basic_info.public_asset_disk' => '공개 자산 디스크',

        // 배송정책 국가별 설정
        'country_settings' => '국가별 설정',
        'country_settings.*.country_code' => '국가',
        'country_settings.*.shipping_method' => '배송방법',
        'country_settings.*.currency_code' => '통화',
        'country_settings.*.charge_policy' => '부과 정책',
        'country_settings.*.base_fee' => '기본 배송비',
        'country_settings.*.free_threshold' => '무료배송 기준금액',
        'country_settings.*.ranges.unit_value' => '구간 단위값',
        'country_settings.*.ranges.tiers.*.min' => '구간 시작값',
        'country_settings.*.ranges.tiers.*.max' => '구간 종료값',
        'country_settings.*.ranges.tiers.*.fee' => '구간 배송비',
        'country_settings.*.api_endpoint' => '계산 API 주소',
        'country_settings.*.api_config.http_method' => 'HTTP 메서드',
        'country_settings.*.api_config.auth_type' => '인증 방식',
        'country_settings.*.api_config.auth_token' => '인증 토큰',
        'country_settings.*.api_config.auth_header_name' => '인증 헤더명',
        'country_settings.*.api_config.response_type' => '응답 형식',
        'country_settings.*.api_config.response_path' => '응답 배송비 경로',
        'country_settings.*.extra_fee_settings.*.zipcode' => '우편번호',
        'country_settings.*.extra_fee_settings.*.fee' => '추가 배송비',

        // 리뷰 설정
        'review_settings.write_deadline_days' => '리뷰 작성 기한(일)',
        'review_settings.max_images' => '리뷰 이미지 최대 개수',
        'review_settings.max_image_size_mb' => '리뷰 이미지 최대 용량(MB)',

        // 클레임 설정
        'claim.exchange_reasons' => '교환 사유',
        'claim.exchange_reasons.*.code' => '교환 사유 코드',
        'claim.exchange_reasons.*.name' => '교환 사유명',
        'claim.exchange_reasons.*.fault_type' => '교환 사유 귀책 구분',
        'claim.return_reasons' => '반품 사유',
        'claim.return_reasons.*.code' => '반품 사유 코드',
        'claim.return_reasons.*.name' => '반품 사유명',
        'claim.return_reasons.*.fault_type' => '반품 사유 귀책 구분',
        'claim.refund_reasons' => '환불 사유',
        'claim.refund_reasons.*.code' => '환불 사유 코드',
        'claim.refund_reasons.*.name' => '환불 사유명',
        'claim.refund_reasons.*.fault_type' => '환불 사유 귀책 구분',

        // 기본 정보
        'basic_info' => '기본 정보',
        'basic_info.shop_name' => '쇼핑몰명',
        'basic_info.route_path' => '라우트 경로',
        'basic_info.company_name' => '회사명',
        'basic_info.business_number_1' => '사업자등록번호',
        'basic_info.business_number_2' => '사업자등록번호',
        'basic_info.business_number_3' => '사업자등록번호',
        'basic_info.ceo_name' => '대표자명',
        'basic_info.business_type' => '업태',
        'basic_info.business_category' => '업종',
        'basic_info.zipcode' => '우편번호',
        'basic_info.base_address' => '기본 주소',
        'basic_info.detail_address' => '상세 주소',
        'basic_info.phone_1' => '전화번호',
        'basic_info.phone_2' => '전화번호',
        'basic_info.phone_3' => '전화번호',
        'basic_info.fax_1' => '팩스번호',
        'basic_info.fax_2' => '팩스번호',
        'basic_info.fax_3' => '팩스번호',
        'basic_info.email_id' => '이메일',
        'basic_info.email_domain' => '이메일',
        'basic_info.privacy_officer' => '개인정보 책임자',
        'basic_info.privacy_officer_email' => '개인정보 책임자 이메일',
        'basic_info.mail_order_number' => '통신판매업 신고번호',
        'basic_info.telecom_number' => '부가통신 사업자번호',

        // 언어/통화 설정
        'language_currency' => '언어/통화 설정',
        'language_currency.default_currency' => '기본 통화',
        'language_currency.currencies' => '통화 목록',
        'language_currency.currencies.*.code' => '통화 코드',
        'language_currency.currencies.*.name' => '통화명',
        'language_currency.currencies.*.name.*' => '통화명',
        'language_currency.currencies.*.exchange_rate' => '환율',
        'language_currency.currencies.*.base_unit' => '기준 단위',
        'language_currency.currencies.*.rounding_unit' => '반올림 단위',
        'language_currency.currencies.*.rounding_method' => '반올림 방식',
        'language_currency.currencies.*.decimal_places' => '소수 자릿수',
        'language_currency.currencies.*.locales' => '사용 언어',
        'language_currency.currencies.*.locales.*' => '사용 언어',

        // 마일리지 설정
        'mileage.default_earn_rate' => '기본 적립률',
        'mileage.earn_trigger' => '적립 시점',
        'mileage.earn_delay_days' => '적립 지연일',
        'mileage.currency_rules.*.currency_code' => '통화 코드',
        'mileage.currency_rules.*.point_value' => '1점당 금액',
        'mileage.currency_rules.*.min_use_amount' => '최소 사용 금액',
        'mileage.currency_rules.*.use_unit' => '사용 단위',
        'mileage.currency_rules.*.max_use_percent' => '최대 사용 비율',
        'mileage.currency_rules.*.max_use_value' => '최대 사용 금액',
        'mileage.currency_rules.*.earn_rounding_unit' => '적립 절사 단위',
        'mileage.currency_rules.*.earn_rounding_method' => '적립 절사 방식',
        'mileage.expiry_days' => '유효기간',
        'mileage.expiry_notification_days_before' => '소멸 예정 알림일',

        // SEO 설정
        'seo' => 'SEO 설정',
        'seo.meta_main_title' => '메인 페이지 타이틀',
        'seo.meta_main_description' => '메인 페이지 설명',
        'seo.meta_category_title' => '카테고리 페이지 타이틀',
        'seo.meta_category_description' => '카테고리 페이지 설명',
        'seo.meta_search_title' => '검색 페이지 타이틀',
        'seo.meta_search_description' => '검색 페이지 설명',
        'seo.meta_product_title' => '상품 페이지 타이틀',
        'seo.meta_product_description' => '상품 페이지 설명',
        'seo.meta_shop_index_title' => '쇼핑몰 메인 페이지 타이틀',
        'seo.meta_shop_index_description' => '쇼핑몰 메인 페이지 설명',
        'seo.seo_shop_index' => '쇼핑몰 메인 페이지 SEO 사용',
        'seo.seo_user_agents' => 'SEO 사용자 에이전트',

        // 주문 설정
        'order_settings.payment_methods' => '결제수단',
        'order_settings.payment_methods.*.id' => '결제수단 ID',
        'order_settings.payment_methods.*.sort_order' => '결제수단 정렬순서',
        'order_settings.payment_methods.*.is_active' => '결제수단 사용여부',
        'order_settings.payment_methods.*.min_order_amount' => '최소 주문금액',
        'order_settings.payment_methods.*.stock_deduction_timing' => '재고 차감 시점',
        'order_settings.banks' => '은행 목록',
        'order_settings.bank_accounts' => '계좌 목록',
        'order_settings.bank_accounts.*.bank_code' => '은행코드',
        'order_settings.bank_accounts.*.account_number' => '계좌번호',
        'order_settings.bank_accounts.*.account_holder' => '예금주',
        'order_settings.bank_accounts.*.is_active' => '계좌 사용여부',
        'order_settings.bank_accounts.*.is_default' => '기본 계좌',
        'order_settings.auto_cancel_expired' => '미결제 자동취소',
        'order_settings.auto_cancel_days' => '자동취소 기한(일)',
        'order_settings.pending_order_expire_minutes' => '결제 미완료 주문 만료 기준(분)',
        'order_settings.cart_expiry_days' => '장바구니 보관기간(일)',
        'order_settings.default_pg_provider' => '기본 PG사',
        'order_settings.payment_methods.*.pg_provider' => 'PG사',
        'order_settings.stock_restore_on_cancel' => '취소 시 재고 복구',

        // 주문 정보
        'order_number' => '주문번호',
        'order_status' => '주문상태',
        'payment_status' => '결제상태',
        'payment_method' => '결제수단',
        'total_amount' => '총 주문금액',
        'total_paid_amount' => '총 결제금액',
        'ordered_at' => '주문일시',
        'paid_at' => '결제일시',
        'carrier_id' => '택배사',
        'tracking_number' => '운송장번호',
        'shipping_status' => '배송상태',
        'shipping_type' => '배송유형',
        'orderer_name' => '주문자명',
        'orderer_phone' => '주문자 연락처',
        'orderer_email' => '주문자 이메일',
        'recipient_name' => '수령인',
        'recipient_phone' => '수령인 연락처',
        'recipient_zipcode' => '우편번호',
        'recipient_address' => '배송지 주소',
        'recipient_detail_address' => '상세 주소',
        'recipient_country_code' => '배송 국가',
        'delivery_memo' => '배송 메모',
        'address_id' => '배송지',

        // 상품 라벨
        'label_name' => '라벨명',
        'label_color' => '라벨 색상',
        'is_active' => '사용여부',
        'sort_order' => '정렬순서',

        // 클레임 사유
        'claim_reason_type' => '클레임 유형',
        'claim_reason_code' => '클레임 사유 코드',
        'claim_reason_name' => '클레임 사유명',
        'claim_reason_fault_type' => '귀책 구분',
        'claim_reason_is_user_selectable' => '사용자 선택 가능 여부',
        'claim_reason_is_active' => '사용여부',
        'claim_reason_sort_order' => '정렬순서',
        'claim_reason_search' => '검색어',

        // 다국어 이름 칸 (현재 언어 값 필수 문구의 칸 이름)
        'category_name' => '카테고리명',
        'brand_name' => '브랜드명',
        'coupon_name' => '쿠폰명',
        'common_info_name' => '공통정보명',
        'notice_template_name' => '상품군명',
        'notice_template_field_name' => '항목명',
        'notice_template_field_content' => '항목 내용',
        'shipping_carrier_name' => '배송사명',
        'shipping_policy_name' => '배송정책명',
        'shipping.carriers.*.name' => '배송사명',
        'shipping.types.*.name' => '배송유형명',
    ],

    // 필드별 커스텀 검증 메시지
    'custom' => [
        'basic_info' => [
            'shop_name' => [
                'required' => '쇼핑몰명은 필수 입력 항목입니다.',
                'string' => '쇼핑몰명은 문자열이어야 합니다.',
                'max' => '쇼핑몰명은 최대 255자까지 입력 가능합니다.',
            ],
            'route_path' => [
                'required' => '라우트 경로는 필수 입력 항목입니다.',
                'string' => '라우트 경로는 문자열이어야 합니다.',
                'max' => '라우트 경로는 최대 100자까지 입력 가능합니다.',
            ],
            'no_route' => [
                'boolean' => '라우트 미사용 여부는 참/거짓 값이어야 합니다.',
            ],
            'company_name' => [
                'string' => '회사명은 문자열이어야 합니다.',
                'max' => '회사명은 최대 255자까지 입력 가능합니다.',
            ],
            'business_number' => [
                'string' => '사업자등록번호는 문자열이어야 합니다.',
                'max' => '사업자등록번호 형식이 올바르지 않습니다.',
            ],
            'ceo_name' => [
                'string' => '대표자명은 문자열이어야 합니다.',
                'max' => '대표자명은 최대 100자까지 입력 가능합니다.',
            ],
            'business_type' => [
                'string' => '업태는 문자열이어야 합니다.',
                'max' => '업태는 최대 100자까지 입력 가능합니다.',
            ],
            'business_category' => [
                'string' => '업종은 문자열이어야 합니다.',
                'max' => '업종은 최대 255자까지 입력 가능합니다.',
            ],
            'zipcode' => [
                'string' => '우편번호는 문자열이어야 합니다.',
                'max' => '우편번호는 최대 10자까지 입력 가능합니다.',
            ],
            'base_address' => [
                'string' => '기본 주소는 문자열이어야 합니다.',
                'max' => '기본 주소는 최대 500자까지 입력 가능합니다.',
            ],
            'detail_address' => [
                'string' => '상세 주소는 문자열이어야 합니다.',
                'max' => '상세 주소는 최대 255자까지 입력 가능합니다.',
            ],
            'phone' => [
                'string' => '전화번호는 문자열이어야 합니다.',
                'max' => '전화번호 형식이 올바르지 않습니다.',
            ],
            'fax' => [
                'string' => '팩스번호는 문자열이어야 합니다.',
                'max' => '팩스번호 형식이 올바르지 않습니다.',
            ],
            'email_id' => [
                'string' => '이메일 아이디는 문자열이어야 합니다.',
                'max' => '이메일 아이디는 최대 100자까지 입력 가능합니다.',
            ],
            'email_domain' => [
                'string' => '이메일 도메인은 문자열이어야 합니다.',
                'max' => '이메일 도메인은 최대 100자까지 입력 가능합니다.',
            ],
            'privacy_officer' => [
                'string' => '개인정보 책임자는 문자열이어야 합니다.',
                'max' => '개인정보 책임자는 최대 100자까지 입력 가능합니다.',
            ],
            'privacy_officer_email' => [
                'email' => '올바른 이메일 형식이 아닙니다.',
                'max' => '개인정보 책임자 이메일은 최대 255자까지 입력 가능합니다.',
            ],
            'mail_order_number' => [
                'string' => '통신판매업 신고번호는 문자열이어야 합니다.',
                'max' => '통신판매업 신고번호는 최대 100자까지 입력 가능합니다.',
            ],
            'telecom_number' => [
                'string' => '부가통신 사업자번호는 문자열이어야 합니다.',
                'max' => '부가통신 사업자번호는 최대 100자까지 입력 가능합니다.',
            ],
        ],
        'user_currency' => [
            'required' => '결제 통화를 선택해 주세요.',
            'invalid' => '등록된 통화만 선택할 수 있습니다.',
            'forbidden' => '회원 결제 통화를 변경할 권한이 없습니다.',
        ],
        'user_shipping_country' => [
            'required' => '배송국가를 선택해 주세요.',
            'invalid' => '배송 가능한 국가만 선택할 수 있습니다.',
            'forbidden' => '회원 배송국가를 변경할 권한이 없습니다.',
        ],
        'language_currency' => [
            'base_locked_after_data' => '상품 또는 주문이 1건 이상 등록된 후에는 기본 통화를 변경할 수 없습니다.',
            'default_currency' => [
                'string' => '기본 통화는 문자열이어야 합니다.',
                'max' => '기본 통화는 최대 10자까지 입력 가능합니다.',
            ],
            'currencies' => [
                'exchange_rate_managed' => ':currency 환율은 :provider 제공자가 관리 중입니다. 화면을 새로고침하세요.',
                'duplicate_code' => '중복된 통화 코드가 있습니다.',
                'name_required' => '통화명은 최소 하나의 언어로 입력해야 합니다.',
                'code' => [
                    'required_with' => '통화 코드는 필수입니다.',
                    'string' => '통화 코드는 문자열이어야 합니다.',
                    'regex' => '통화 코드는 ISO 4217 형식(영문 대문자 3자리, 예: KRW)이어야 합니다.',
                ],
                'name' => [
                    'required_with' => '통화명은 필수입니다.',
                    'array' => '통화명은 배열 형태여야 합니다.',
                    'string' => '통화명은 문자열이어야 합니다.',
                    'max' => '통화명은 최대 100자까지 입력 가능합니다.',
                ],
                'exchange_rate' => [
                    'numeric' => '환율은 숫자여야 합니다.',
                    'min' => '환율은 0 이상이어야 합니다.',
                ],
                'rounding_unit' => [
                    'string' => '반올림 단위는 문자열이어야 합니다.',
                ],
                'rounding_method' => [
                    'string' => '반올림 방식은 문자열이어야 합니다.',
                    'in' => '반올림 방식은 floor, round, ceil 중 하나여야 합니다.',
                ],
                'decimal_places' => [
                    'integer' => '소수 자릿수는 정수여야 합니다.',
                    'min' => '소수 자릿수는 0 이상이어야 합니다.',
                    'max' => '소수 자릿수는 최대 8자리까지 가능합니다.',
                ],
                'is_default' => [
                    'boolean' => '기본 통화 여부는 참/거짓 값이어야 합니다.',
                ],
            ],
        ],
        'mileage' => [
            'currency_rules' => [
                'currency_code' => [
                    'required_with' => '통화 코드는 필수입니다.',
                    'regex' => '통화 코드는 ISO 4217 형식(영문 대문자 3자리, 예: KRW)이어야 합니다.',
                ],
                'point_value' => [
                    'numeric' => '1점당 금액은 숫자여야 합니다.',
                    'min' => '1점당 금액은 0보다 커야 합니다.',
                ],
                'max_use_value' => [
                    'integer' => '최대 사용 금액은 정수여야 합니다.',
                    'min' => '최대 사용 금액은 0 이상이어야 합니다.',
                    'max' => '최대 사용 금액이 너무 큽니다. (최대 10억)',
                ],
                'earn_rounding_unit' => [
                    'in' => '적립 절사 단위는 1, 10, 100 중 하나여야 합니다.',
                ],
                'earn_rounding_method' => [
                    'in' => '적립 절사 방식은 floor, round, ceil 중 하나여야 합니다.',
                ],
            ],
        ],
        'seo' => [
            'meta_main_title' => [
                'string' => '메인 페이지 타이틀은 문자열이어야 합니다.',
                'max' => '메인 페이지 타이틀은 최대 500자까지 입력 가능합니다.',
            ],
            'meta_main_description' => [
                'string' => '메인 페이지 설명은 문자열이어야 합니다.',
                'max' => '메인 페이지 설명은 최대 1000자까지 입력 가능합니다.',
            ],
            'meta_category_title' => [
                'string' => '카테고리 페이지 타이틀은 문자열이어야 합니다.',
                'max' => '카테고리 페이지 타이틀은 최대 500자까지 입력 가능합니다.',
            ],
            'meta_category_description' => [
                'string' => '카테고리 페이지 설명은 문자열이어야 합니다.',
                'max' => '카테고리 페이지 설명은 최대 1000자까지 입력 가능합니다.',
            ],
            'meta_search_title' => [
                'string' => '검색 페이지 타이틀은 문자열이어야 합니다.',
                'max' => '검색 페이지 타이틀은 최대 500자까지 입력 가능합니다.',
            ],
            'meta_search_description' => [
                'string' => '검색 페이지 설명은 문자열이어야 합니다.',
                'max' => '검색 페이지 설명은 최대 1000자까지 입력 가능합니다.',
            ],
            'meta_product_title' => [
                'string' => '상품 페이지 타이틀은 문자열이어야 합니다.',
                'max' => '상품 페이지 타이틀은 최대 500자까지 입력 가능합니다.',
            ],
            'meta_product_description' => [
                'string' => '상품 페이지 설명은 문자열이어야 합니다.',
                'max' => '상품 페이지 설명은 최대 1000자까지 입력 가능합니다.',
            ],
            'seo_site_main' => [
                'boolean' => '메인 페이지 SEO 사용 여부는 참/거짓 값이어야 합니다.',
            ],
            'seo_category' => [
                'boolean' => '카테고리 페이지 SEO 사용 여부는 참/거짓 값이어야 합니다.',
            ],
            'seo_search_result' => [
                'boolean' => '검색결과 페이지 SEO 사용 여부는 참/거짓 값이어야 합니다.',
            ],
            'seo_product_detail' => [
                'boolean' => '상품상세 페이지 SEO 사용 여부는 참/거짓 값이어야 합니다.',
            ],
            'meta_shop_index_title' => [
                'string' => '쇼핑몰 메인 페이지 타이틀은 문자열이어야 합니다.',
                'max' => '쇼핑몰 메인 페이지 타이틀은 최대 500자까지 입력 가능합니다.',
            ],
            'meta_shop_index_description' => [
                'string' => '쇼핑몰 메인 페이지 설명은 문자열이어야 합니다.',
                'max' => '쇼핑몰 메인 페이지 설명은 최대 1000자까지 입력 가능합니다.',
            ],
            'seo_shop_index' => [
                'boolean' => '쇼핑몰 메인 페이지 SEO 사용 여부는 참/거짓 값이어야 합니다.',
            ],
            'seo_user_agents' => [
                'string' => 'SEO 사용자 에이전트는 문자열이어야 합니다.',
                'max' => 'SEO 사용자 에이전트는 최대 100자까지 입력 가능합니다.',
            ],
        ],
        'banks' => [
            'code' => [
                'required_with' => '은행코드는 필수 항목입니다.',
                'string' => '은행코드는 문자열이어야 합니다.',
                'max' => '은행코드는 최대 10자까지 입력 가능합니다.',
            ],
            'name' => [
                'required_with' => '은행명은 필수 항목입니다.',
                'array' => '은행명은 다국어 배열 형식이어야 합니다.',
                'string' => '은행명은 문자열이어야 합니다.',
                'max' => '은행명은 최대 100자까지 입력 가능합니다.',
            ],
        ],
        'order_settings' => [
            'payment_methods' => [
                'at_least_one_active' => '결제수단 중 하나 이상은 활성화되어 있어야 합니다.',
                'id' => [
                    'required_with' => '결제수단 ID는 필수 항목입니다.',
                    'string' => '결제수단 ID는 문자열이어야 합니다.',
                ],
                'sort_order' => [
                    'integer' => '결제수단 정렬순서는 정수여야 합니다.',
                    'min' => '결제수단 정렬순서는 1 이상이어야 합니다.',
                ],
                'is_active' => [
                    'boolean' => '결제수단 사용여부는 참/거짓 값이어야 합니다.',
                ],
                'min_order_amount' => [
                    'integer' => '최소 주문금액은 정수여야 합니다.',
                    'min' => '최소 주문금액은 0 이상이어야 합니다.',
                ],
                'stock_deduction_timing' => [
                    'string' => '재고 차감 시점은 문자열이어야 합니다.',
                    'in' => '재고 차감 시점은 주문접수 시, 결제완료 시, 재고 차감 안함 중 하나여야 합니다.',
                ],
                'pg_required_for_activation' => '이 결제수단을 활성화하려면 PG사를 먼저 선택하세요.',
            ],
            'bank_accounts' => [
                'at_least_one_active_default' => '무통장 계좌 중 하나 이상은 기본 선택 및 사용 선택되어 있어야 합니다.',
                'dbank_requires_active_account' => '무통장입금을 사용하려면 사용 중인 입금 계좌(계좌번호 입력)가 하나 이상 있어야 합니다.',
                'bank_code' => [
                    'required_with' => '은행은 필수 항목입니다.',
                    'string' => '은행코드는 문자열이어야 합니다.',
                ],
                'account_number' => [
                    'required_with' => '계좌번호는 필수 항목입니다.',
                    'string' => '계좌번호는 문자열이어야 합니다.',
                    'max' => '계좌번호는 최대 50자까지 입력 가능합니다.',
                ],
                'account_holder' => [
                    'required_with' => '예금주는 필수 항목입니다.',
                    'string' => '예금주는 문자열이어야 합니다.',
                    'max' => '예금주는 최대 100자까지 입력 가능합니다.',
                ],
                'is_active' => [
                    'boolean' => '계좌 사용여부는 참/거짓 값이어야 합니다.',
                ],
                'is_default' => [
                    'boolean' => '기본 계좌 여부는 참/거짓 값이어야 합니다.',
                ],
            ],
            'auto_cancel_expired' => [
                'boolean' => '미결제 자동취소 여부는 참/거짓 값이어야 합니다.',
            ],
            'auto_cancel_days' => [
                'required' => '자동취소 기한을 입력해주세요.',
                'integer' => '자동취소 기한은 정수여야 합니다.',
                'min' => '자동취소 기한은 1일 이상이어야 합니다.',
                'max' => '자동취소 기한은 최대 30일까지 설정 가능합니다.',
            ],
            'pending_order_expire_minutes' => [
                'required' => '결제 미완료 주문 만료 기준을 입력해주세요.',
                'integer' => '결제 미완료 주문 만료 기준은 정수여야 합니다.',
                'min' => '결제 미완료 주문 만료 기준은 0분 이상이어야 합니다. (0 은 정리하지 않음)',
                'max' => '결제 미완료 주문 만료 기준은 최대 20160분(14일)까지 설정 가능합니다.',
            ],
            'cart_expiry_days' => [
                'integer' => '장바구니 보관기간은 정수여야 합니다.',
                'min' => '장바구니 보관기간은 1일 이상이어야 합니다.',
                'max' => '장바구니 보관기간은 최대 365일까지 설정 가능합니다.',
            ],
            'stock_restore_on_cancel' => [
                'boolean' => '취소 시 재고 복구 여부는 참/거짓 값이어야 합니다.',
            ],
        ],
        'claim' => [
            'exchange_reasons' => [
                'duplicate_code' => '중복된 교환 사유 코드가 있습니다.',
                'name_required' => '교환 사유명은 필수 항목입니다.',
                'code_swap' => '다른 교환 사유가 아직 쓰고 있는 코드입니다. 코드를 서로 맞바꾸려면 한쪽을 먼저 다른 코드로 저장한 뒤 바꿔 주세요.',
            ],
            'return_reasons' => [
                'duplicate_code' => '중복된 반품 사유 코드가 있습니다.',
                'name_required' => '반품 사유명은 필수 항목입니다.',
                'code_swap' => '다른 반품 사유가 아직 쓰고 있는 코드입니다. 코드를 서로 맞바꾸려면 한쪽을 먼저 다른 코드로 저장한 뒤 바꿔 주세요.',
            ],
            'refund_reasons' => [
                'duplicate_code' => '중복된 환불 사유 코드가 있습니다.',
                'name_required' => '환불 사유명은 필수 항목입니다.',
                'code_swap' => '다른 환불 사유가 아직 쓰고 있는 코드입니다. 코드를 서로 맞바꾸려면 한쪽을 먼저 다른 코드로 저장한 뒤 바꿔 주세요.',
            ],
        ],
        'shipping' => [
            'default_country' => [
                'string' => '기본 배송국가는 문자열이어야 합니다.',
                'max' => '기본 배송국가는 최대 10자까지 입력 가능합니다.',
                'must_exist_in_countries' => '기본 배송국가는 배송가능국가 목록에 존재해야 합니다.',
            ],
            'available_countries' => [
                'array' => '배송가능국가는 배열 형태여야 합니다.',
                'duplicate_code' => '중복된 국가 코드가 있습니다.',
                'name_required' => '국가명은 최소 하나의 언어로 입력해야 합니다.',
                'code' => [
                    'required_with' => '국가 코드는 필수입니다.',
                    'string' => '국가 코드는 문자열이어야 합니다.',
                    'max' => '국가 코드는 최대 10자까지 입력 가능합니다.',
                ],
                'name' => [
                    'required_with' => '국가명은 필수입니다.',
                    'array' => '국가명은 배열 형태여야 합니다.',
                    'string' => '국가명은 문자열이어야 합니다.',
                    'max' => '국가명은 최대 100자까지 입력 가능합니다.',
                ],
                'is_active' => [
                    'boolean' => '국가 사용여부는 참/거짓 값이어야 합니다.',
                ],
            ],
            'international_shipping_enabled' => [
                'boolean' => '해외배송 사용여부는 참/거짓 값이어야 합니다.',
            ],
            'remote_area_enabled' => [
                'boolean' => '도서산간 사용여부는 참/거짓 값이어야 합니다.',
            ],
            'remote_area_extra_fee' => [
                'integer' => '산간지역 추가배송비는 정수여야 합니다.',
                'min' => '산간지역 추가배송비는 0원 이상이어야 합니다.',
            ],
            'island_extra_fee' => [
                'integer' => '도서지역 추가배송비는 정수여야 합니다.',
                'min' => '도서지역 추가배송비는 0원 이상이어야 합니다.',
            ],
            'free_shipping_threshold' => [
                'integer' => '무료배송 기준금액은 정수여야 합니다.',
                'min' => '무료배송 기준금액은 0원 이상이어야 합니다.',
            ],
            'free_shipping_enabled' => [
                'boolean' => '무료배송 사용여부는 참/거짓 값이어야 합니다.',
            ],
            'address_validation_enabled' => [
                'boolean' => '주소검증 사용여부는 참/거짓 값이어야 합니다.',
            ],
            'address_api_provider' => [
                'string' => '주소검증 API 제공자는 문자열이어야 합니다.',
                'max' => '주소검증 API 제공자는 최대 50자까지 입력 가능합니다.',
            ],
            'types' => [
                'duplicate_code' => '중복된 배송유형 코드가 있습니다.',
                'code_swap' => '다른 배송유형이 아직 쓰고 있는 코드입니다. 코드를 서로 맞바꾸려면 한쪽을 먼저 다른 코드로 저장한 뒤 바꿔 주세요.',
                'name_required' => '배송유형명은 필수 항목입니다.',
                'code' => [
                    'required_with' => '배송유형 코드는 필수입니다.',
                    'string' => '배송유형 코드는 문자열이어야 합니다.',
                    'max' => '배송유형 코드는 최대 50자까지 입력 가능합니다.',
                    'regex' => '배송유형 코드는 영문 소문자, 숫자, 하이픈, 언더스코어만 사용 가능합니다.',
                ],
                'name' => [
                    'required_with' => '배송유형명은 필수입니다.',
                    'array' => '배송유형명은 다국어 배열 형식이어야 합니다.',
                ],
                'category' => [
                    'required_with' => '배송유형 카테고리는 필수입니다.',
                    'in' => '배송유형 카테고리는 국내배송, 해외배송, 기타 중 하나여야 합니다.',
                ],
                'is_active' => [
                    'boolean' => '배송유형 사용여부는 참/거짓 값이어야 합니다.',
                ],
            ],
            'carriers' => [
                'duplicate_code' => '중복된 배송사 코드가 있습니다.',
                'code_swap' => '다른 배송사가 아직 쓰고 있는 코드입니다. 코드를 서로 맞바꾸려면 한쪽을 먼저 다른 코드로 저장한 뒤 바꿔 주세요.',
                'name_required' => '배송사명은 필수 항목입니다.',
                'code' => [
                    'required_with' => '배송사 코드는 필수입니다.',
                    'string' => '배송사 코드는 문자열이어야 합니다.',
                    'max' => '배송사 코드는 최대 50자까지 입력 가능합니다.',
                    'regex' => '배송사 코드는 영문 소문자, 숫자, 하이픈, 언더스코어만 사용 가능합니다.',
                ],
                'name' => [
                    'required_with' => '배송사명은 필수입니다.',
                    'array' => '배송사명은 다국어 배열 형식이어야 합니다.',
                    'string' => '배송사명은 문자열이어야 합니다.',
                    'max' => '배송사명은 최대 100자까지 입력 가능합니다.',
                ],
                'name_ko' => [
                    'required_with' => '배송사명(한국어)은 필수입니다.',
                    'string' => '배송사명(한국어)은 문자열이어야 합니다.',
                    'max' => '배송사명(한국어)은 최대 100자까지 입력 가능합니다.',
                ],
                'type' => [
                    'required_with' => '배송사 유형은 필수입니다.',
                    'in' => '배송사 유형은 국내배송 또는 국제배송 중 하나여야 합니다.',
                ],
                'tracking_url' => [
                    'string' => '배송추적 URL은 문자열이어야 합니다.',
                    'max' => '배송추적 URL은 최대 500자까지 입력 가능합니다.',
                ],
                'is_active' => [
                    'boolean' => '배송사 사용여부는 참/거짓 값이어야 합니다.',
                ],
            ],
        ],
    ],

    // 클레임(교환/반품) 신청 검증 메시지
    'claims' => [
        'items' => [
            'required' => '신청할 상품을 선택해 주세요.',
            'array' => '신청 상품은 배열 형태여야 합니다.',
            'max' => '한 번에 신청할 수 있는 상품은 최대 :max개입니다.',
            'order_option_id_required' => '신청 대상 주문 상품을 지정해 주세요.',
            'quantity_required' => '신청 수량을 입력해 주세요.',
            'quantity_min' => '신청 수량은 1 이상이어야 합니다.',
            'new_product_option_id_integer' => '교환할 옵션 ID 는 숫자여야 합니다.',
            'custom_options_array' => '커스텀 옵션 입력값은 배열 형태여야 합니다.',
            'id_integer' => '신청 상품 행 ID 는 숫자여야 합니다.',
            'not_in_claim' => '이 신청서에 속하지 않은 상품이 포함되어 있습니다.',
            'entry_object' => '신청 상품 항목은 {id, quantity} 형태여야 합니다.',
            'duplicate_id' => '같은 상품을 두 번 지정했습니다. 한 상품은 한 줄로 보내 주세요.',
            'not_in_order' => '이 주문에 속하지 않은 상품이 포함되어 있습니다.',
            'prohibited_with_refund' => '환불신청서 기준과 상품 선택 기준은 함께 지정할 수 없습니다. 하나만 보내 주세요.',
        ],
        // 관리자 금액 수정 (D18)
        'amounts' => [
            'numeric' => '금액은 숫자여야 합니다.',
            'min' => '금액은 0 이상이어야 합니다.',
            'prohibited' => '이 클레임 유형에서 수정할 수 없는 항목이 포함되어 있습니다.',
        ],
        'refund_method' => [
            'in' => '선택한 환불 수단을 사용할 수 없습니다.',
        ],
        // 완료 처리 모달 미리보기 (D26)
        'preview_claims' => [
            'required' => '미리보기할 신청서를 선택해 주세요.',
            'array' => '신청서 목록은 배열 형태여야 합니다.',
            'max' => '한 번에 미리보기할 수 있는 신청서는 최대 :max건입니다.',
            'id_required' => '신청서 ID 를 지정해 주세요.',
            'id_integer' => '신청서 ID 는 숫자여야 합니다.',
        ],
        'reason_code' => [
            'required' => '신청 사유를 선택해 주세요.',
            'exists' => '선택한 사유를 사용할 수 없습니다.',
        ],
        'reason_detail' => [
            'max' => '상세 사유는 :max자 이하로 입력해 주세요.',
        ],
        'charge_method' => [
            'in' => '선택한 청구 방식을 사용할 수 없습니다.',
        ],
        'redelivery_address' => [
            'array' => '재발송지는 주소 항목을 담은 객체여야 합니다.',
            'country_code_size' => '재발송지 국가 코드는 2자리여야 합니다.',
            'country_code_unchanged' => '교환 재발송지는 주문 배송지와 같은 국가만 지정할 수 있습니다. (교환 해외배송 미지원)',
            'changed_by_user_boolean' => '재발송지 수정 여부는 true/false 여야 합니다.',
        ],
        'type' => [
            'required' => '클레임 유형을 지정해 주세요.',
            'in' => '지원하지 않는 클레임 유형입니다.',
        ],
        // 신청내역 목록 — 상한은 규칙이 정하므로 문구에 숫자를 적지 않는다.
        // 주문 목록의 문구를 빌려 쓰던 시절에는 실제 상한(50)과 다른 수(100)를 안내했다.
        'per_page' => [
            'integer' => '페이지당 항목 수는 숫자여야 합니다.',
            'min' => '페이지당 항목 수는 :min 이상이어야 합니다.',
            'max' => '페이지당 항목 수는 :max 이하여야 합니다.',
        ],
        'page' => [
            'integer' => '페이지 번호는 숫자여야 합니다.',
            'min' => '페이지 번호는 :min 이상이어야 합니다.',
            'max' => '페이지 번호는 :max 이하여야 합니다.',
        ],
        'status' => [
            'in' => '선택한 유형에 없는 신청 상태입니다.',
        ],
        'payment_method' => [
            'in' => '선택한 결제 수단은 클레임 추가결제에 사용할 수 없습니다.',
        ],
        'image_ids' => [
            'array' => '첨부 이미지 목록은 배열 형태여야 합니다.',
            'max' => '첨부 이미지는 한 번에 :max장까지 지정할 수 있습니다.',
            'integer' => '첨부 이미지 ID 는 숫자여야 합니다.',
        ],
        'refund_bank' => [
            'array' => '환불 계좌는 은행·계좌번호·예금주를 담은 객체여야 합니다.',
            'incomplete' => '환불 계좌는 은행, 계좌번호, 예금주를 모두 입력해 주세요.',
            'masked' => '가려진 계좌번호나 예금주는 저장할 수 없습니다. 환불 계좌를 다시 입력해 주세요.',
        ],
        'image' => [
            'required' => '첨부할 이미지를 선택해 주세요.',
            'file' => '올바른 파일이 아닙니다.',
            'image' => '이미지 파일만 첨부할 수 있습니다.',
            'mimes' => '지원하는 이미지 형식: jpeg, png, jpg, gif, webp',
            'max' => '이미지 용량은 :maxKB 이하여야 합니다.',
        ],
        'callback' => [
            'required' => '결제 승인 정보가 없습니다.',
            'array' => '결제 승인 정보 형식이 올바르지 않습니다.',
        ],
        // 관리자 클레임 API (목록 필터·전이·송장)
        'statuses' => [
            'array' => '상태 필터는 배열 형태여야 합니다.',
        ],
        'search_field' => [
            'in' => '지원하지 않는 검색 항목입니다.',
        ],
        'search_keyword' => [
            'max' => '검색어는 :max자 이하로 입력해 주세요.',
        ],
        'fault_types' => [
            'array' => '귀책 필터는 배열 형태여야 합니다.',
            'prohibited' => '이 유형에는 귀책 필터를 사용할 수 없습니다.',
            'in' => '지원하지 않는 귀책 구분입니다.',
        ],
        'charge_methods' => [
            'array' => '청구 방식 필터는 배열 형태여야 합니다.',
            'prohibited' => '이 유형에는 청구 방식 필터를 사용할 수 없습니다.',
            'in' => '지원하지 않는 청구 방식입니다.',
        ],
        'requester_type' => [
            'in' => '회원구분은 member 또는 guest 여야 합니다.',
        ],
        'min_amount' => [
            'numeric' => '최소 금액은 숫자여야 합니다.',
        ],
        'max_amount' => [
            'numeric' => '최대 금액은 숫자여야 합니다.',
            'gte' => '최대 금액은 최소 금액보다 크거나 같아야 합니다.',
        ],
        'currency_code' => [
            'in' => '사용된 적 없는 통화입니다.',
        ],
        'charge_payment_methods' => [
            'array' => '청구 결제수단 필터는 배열이어야 합니다.',
            'prohibited' => '이 유형에는 청구 결제수단 필터를 사용할 수 없습니다.',
            'in' => '사용된 적 없는 청구 결제수단입니다.',
        ],
        'admin_status' => [
            'required' => '변경할 상태를 선택해 주세요.',
            'in' => '이 유형에서 지정할 수 없는 상태입니다.',
        ],
        'reject_reason' => [
            'required' => '반려 사유를 입력해 주세요. 구매자 화면에 그대로 안내됩니다.',
            'max' => '반려 사유는 :max자 이하로 입력해 주세요.',
        ],
        'admin_memo' => [
            'max' => '관리자 메모는 :max자 이하로 입력해 주세요.',
        ],
        'ids' => [
            'required' => '대상 신청서를 선택해 주세요.',
            'array' => '신청서 ID 목록은 배열 형태여야 합니다.',
            'min' => '대상 신청서를 1건 이상 선택해 주세요.',
            'max' => '한 번에 처리할 수 있는 신청서는 최대 :max건입니다.',
            'integer' => '신청서 ID 는 숫자여야 합니다.',
        ],
        'completion' => [
            'required_if' => '완료 처리에는 완료 옵션(재고 복구·환불신청서 접수)이 필요합니다.',
            'array' => '완료 옵션은 객체 형태여야 합니다.',
            'restore_stock_by_item_array' => '재고 복구 선택은 상품 행별 맵이어야 합니다.',
            'restore_stock_by_item_boolean' => '재고 복구 선택 값은 true/false 여야 합니다.',
            'create_refund_request_required' => '환불신청서 접수 여부를 선택해 주세요.',
            'create_refund_request_boolean' => '환불신청서 접수 여부는 true/false 여야 합니다.',
        ],
        'tracking' => [
            'carrier_id_required' => '회수중·발송중으로 바꾸려면 택배사를 선택해 주세요.',
            'tracking_number_required' => '회수중·발송중으로 바꾸려면 운송장 번호를 입력해 주세요.',
            'per_claim_required' => '회수중·발송중으로 바꾸려면 선택한 신청서마다 택배사와 운송장 번호를 입력해 주세요.',
            'carrier_id_integer' => '택배사 ID 는 숫자여야 합니다.',
            'carrier_id_exists' => '등록되지 않은 택배사입니다.',
            'tracking_number_max' => '운송장 번호는 :max자 이하로 입력해 주세요.',
            'direction_required' => '송장 방향(회수·재발송)을 선택해 주세요.',
            'direction_in' => '송장 방향은 회수·재발송 중 하나여야 합니다.',
            'deliver_carrier_required' => '도착 처리할 운송장의 택배사를 알 수 없습니다.',
            'deliver_tracking_required' => '도착 처리할 운송장 번호를 알 수 없습니다.',
        ],
        'withdraw_reason' => [
            'required' => '철회 사유를 입력해 주세요.',
            'max' => '철회 사유는 2000자 이하로 입력해 주세요.',
        ],
        'request_source' => [
            'in' => '접수 출처는 판매자 접수·고객 접수 중 하나여야 합니다.',
            'prohibited' => '취소 목록은 접수 출처로 거를 수 없습니다.',
        ],
        'collection_overdue' => [
            'prohibited' => '회수 지연 필터는 교환 목록에서만 쓸 수 있습니다.',
        ],
        'pending_refund' => [
            'prohibited' => '환불 미접수 필터는 교환·반품 목록에서만 쓸 수 있습니다.',
        ],
        'exchange_mode' => [
            'required' => '진행 방식을 선택해 주세요.',
            'in' => '진행 방식은 일반 교환·선교환 중 하나여야 합니다.',
        ],
        'unreturned' => [
            'array' => '미회수 완료 지시 형식이 올바르지 않습니다.',
            'reason_string' => '미회수 사유는 문자열이어야 합니다.',
            'reason_max' => '미회수 사유는 :max자 이하로 입력해 주세요.',
        ],
    ],

    // 사용자 배송지 검증 메시지
    'user_address' => [
        'name_required' => '배송지명은 필수입니다.',
        'name_string' => '배송지명은 문자열이어야 합니다.',
        'recipient_name_required' => '수령인 이름은 필수입니다.',
        'recipient_name_string' => '수령인 이름은 문자열이어야 합니다.',
        'recipient_phone_required' => '수령인 연락처는 필수입니다.',
        'recipient_phone_string' => '수령인 연락처는 문자열이어야 합니다.',
        'zipcode_required' => '우편번호는 필수입니다.',
        'address_required' => '주소는 필수입니다.',
        'address_line_1_required' => '해외 주소(Address Line 1)는 필수입니다.',
        'intl_city_required' => '해외 도시명은 필수입니다.',
        'intl_postal_code_required' => '해외 우편번호는 필수입니다.',
    ],

    // 리뷰 이미지 업로드 검증
    'review_image' => [
        'image_required' => '이미지를 선택해주세요.',
        'image_file' => '유효한 파일 형식이 아닙니다.',
        'image_image' => '이미지 파일만 업로드 가능합니다.',
        'image_max' => '이미지 크기는 :maxMB를 초과할 수 없습니다.',
    ],

    // 마일리지 수동 지급/차감 + 설정 검증
    'mileage' => [
        'user_required' => '대상 회원을 선택해주세요.',
        'amount_min' => '금액은 1점 이상이어야 합니다.',
        'action_invalid' => '지급 또는 차감만 가능합니다.',
        'expires_at_invalid' => '유효기간은 올바른 날짜여야 합니다.',
        'duplicate_currency' => '통화 코드가 중복되었습니다.',
        'first_must_be_default' => '첫 번째 통화는 기본 통화(:currency)여야 합니다.',
        'currency_not_registered' => '등록되지 않은 통화(:currency)입니다. 언어/통화 설정에 먼저 추가하세요.',
        'earn_rate_required_when_enabled' => '마일리지를 사용하려면 기본 적립률은 0보다 커야 합니다.',
    ],

    // 상품 옵션 배치 조회 검증
    'options_list' => [
        'product_ids' => [
            'required' => '조회할 상품을 선택해주세요.',
            'array' => '상품 ID 목록은 배열이어야 합니다.',
            'min' => '조회할 상품을 1개 이상 지정해주세요.',
            'max' => '한 번에 조회할 수 있는 상품은 최대 :max개입니다.',
            'integer' => '상품 ID는 숫자여야 합니다.',
            'item_min' => '상품 ID는 1 이상이어야 합니다.',
        ],
    ],
    // 개인정보 보유기간 설정 필드 라벨 (이슈 #636) — StoreEcommerceSettingsRequest::attributes() 가 키별로 읽는다
    'privacy_retention_attributes' => [
        'section' => '개인정보 보유기간 설정',
        'enabled' => '주문 개인정보 보유기간 자동 파기',
        'retention_years' => '주문 개인정보 보유기간',
        'mode' => '보유기간 경과 주문 처리 방식',
    ],
    // 요청 필드 라벨 (이슈 #599) — 클레임·주문 목록·배송정책·주문서 FormRequest 의 attributes() 가 키별로 읽는다
    'request_attributes' => [
        'claim_type' => '클레임 유형',
        'items' => '대상 상품',
        'claim_item' => '클레임 상품',
        'order_option' => '주문 상품',
        'quantity' => '수량',
        'cancel_quantity' => '취소 수량',
        'new_product_option' => '교환할 옵션',
        'custom_options' => '추가 입력 옵션',
        'reason_code' => '사유',
        'reason_detail' => '상세 사유',
        'reject_reason' => '거부 사유',
        'withdraw_reason' => '철회 사유',
        'admin_memo' => '관리자 메모',
        'charge_method' => '청구 방식',
        'payment_method' => '결제 수단',
        'refund_priority' => '환불 우선순위',
        'refund_bank' => '환불 계좌',
        'refund_bank_code' => '환불 은행',
        'refund_bank_account' => '환불 계좌번호',
        'refund_bank_holder' => '예금주',
        'image_ids' => '첨부 이미지',
        'image_id' => '첨부 이미지',
        'add_image_ids' => '추가할 첨부 이미지',
        'remove_image_ids' => '삭제할 첨부 이미지',
        'image' => '첨부 이미지',
        'redelivery_address' => '교환 상품 배송지',
        'redelivery_recipient_name' => '받는 분',
        'redelivery_phone' => '받는 분 연락처',
        'redelivery_zipcode' => '우편번호',
        'redelivery_address1' => '주소',
        'redelivery_address2' => '상세 주소',
        'redelivery_city' => '도시',
        'redelivery_state' => '주/도',
        'redelivery_country_code' => '배송 국가',
        'redelivery_changed_by_user' => '배송지 변경 여부',
        'full_cancel' => '전체 취소 여부',
        'auto_advance' => '자동 진행 여부',
        'claim_id' => '클레임',
        'trackings' => '운송장 목록',
        'tracking' => '운송장 항목',
        'carrier' => '택배사',
        'tracking_number' => '운송장번호',
        'tracking_direction' => '운송장 구분',
        'refund_id' => '환불',
        'claims' => '완료할 클레임',
        'completion_item_ids' => '완료할 상품',
        'completion_item_id' => '완료할 상품',
        'restore_stock_by_item' => '상품별 재고 복구',
        'restore_stock_item' => '재고 복구 여부',
        'unreturned_item_ids' => '미회수 상품',
        'unreturned_item_id' => '미회수 상품',
        'create_refund_request' => '환불신청서 접수 여부',
        'statuses' => '상태',
        'status_item' => '상태',
        'date_from' => '시작일',
        'date_to' => '종료일',
        'search_field' => '검색 항목',
        'search_keyword' => '검색어',
        'fault_types' => '귀책 구분',
        'fault_type' => '귀책 구분',
        'charge_methods' => '청구 방식',
        'requester_type' => '신청자 구분',
        'request_source' => '접수 경로',
        'collection_overdue' => '회수 지연',
        'pending_refund' => '환불 대기',
        'min_amount' => '최소 금액',
        'max_amount' => '최대 금액',
        'currency_code' => '통화',
        'charge_payment_methods' => '추가결제 수단',
        'charge_payment_method' => '추가결제 수단',
        'sort_order' => '정렬 순서',
        'refund_amount' => '환불 금액',
        'refund_points_amount' => '환불 마일리지',
        'refund_method' => '환불 수단',
        'force_without_refund_bank' => '환불 계좌 없이 진행',
        'claim_shipping_fee' => '클레임 배송비',
        'exchange_mode' => '교환 진행 방식',
        'claim_addresses' => '반품·교환 회수지',
        'country_settings' => '국가별 설정',
        'country_code' => '국가',
        'claim_fee_mode' => '반품·교환 배송비 방식',
        'return_fixed_fee' => '반품 고정 배송비',
        'exchange_fixed_fee' => '교환 고정 배송비',
        'save_shipping_address' => '저장할 배송지',
        'payment_callback' => '결제 승인 응답',
        'claim_number' => '클레임 번호',
        'claim_presence' => '클레임 유무',
        'claim_exchange_statuses' => '교환 상태',
        'claim_exchange_status' => '교환 상태',
        'claim_return_statuses' => '반품 상태',
        'claim_return_status' => '반품 상태',
        'claim_refund_statuses' => '환불 상태',
        'claim_refund_status' => '환불 상태',
        'claim_cancel_types' => '취소 구분',
        'claim_cancel_type' => '취소 구분',
        'claim_requested_from' => '클레임 신청 시작일',
        'claim_requested_to' => '클레임 신청 종료일',
        'claim_ids' => '대상 클레임',
        'claim_status' => '변경할 상태',
        'approve_on_receipt' => '접수와 함께 승인',
        'completion' => '완료 처리 옵션',
        'unreturned_reasons' => '미회수 사유',
    ],
    // 클레임 정책 설정 필드 라벨 (이슈 #599) — StoreEcommerceSettingsRequest::attributes() 가 키별로 읽는다
    'claim_settings_attributes' => [
        'cancellable_statuses' => '취소 가능 주문 상태',
        'exchange_requestable_statuses' => '교환 신청 가능 상태',
        'return_requestable_statuses' => '반품 신청 가능 상태',
        'instant_cancel_statuses' => '자동 전이 대상 상태',
        'refund_request_statuses' => '환불신청 가능 상태',
        'allowed_charge_methods' => '허용 청구 방식',
        'charge_payment_methods' => '추가결제 수단',
        'auto_approve_zero_charge' => '추가 청구 없음 자동 승인',
        'auto_approve_after_payment' => '추가결제 완료 후 자동 승인',
        'auto_approve_refund_request' => '환불신청 자동 승인',
        'auto_approve_refund_statuses' => '자동승인 대상 상태',
        'auto_approve_refund_payment_methods' => '환불 자동 승인 결제수단',
        'auto_approve_refund_payment_methods_decided' => '환불 자동 승인 결제수단 확정 여부',
        'max_images' => '최대 첨부 장수',
        'image_max_size_kb' => '이미지 최대 용량(KB)',
        'request_period_days' => '신청 가능 기간(일)',
        'advance_exchange_enabled' => '선교환 사용',
        'advance_exchange_collection_due_days' => '기존 상품 반송 기한(일)',
        'complete_defaults' => '완료 처리 기본값',
        'complete_defaults_create_refund_request' => '완료 처리 기본값 - 환불신청서 접수',
        'complete_defaults_restore_stock' => '완료 처리 기본값 - 재고 복구',
    ],

    // 본인인증 정책 탭 검증 메시지
    'identity_policy' => [
        'target_not_owned' => '이커머스 본인인증 정책은 이커머스 지점만 대상으로 할 수 있습니다. 훅·사용자 지정 범위는 「sirsoft-ecommerce.」, 경로 범위는 「api.modules.sirsoft-ecommerce.」로 시작해야 합니다.',
        'nothing_to_update' => '변경할 항목이 없습니다.',
    ],
];
