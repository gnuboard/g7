<?php

/**
 * 이커머스 모듈 검증 메시지
 *
 * FormRequest 검증 실패 시 사용되는 메시지
 */
return [
    // 상품 1:1 문의 검증 메시지
    'inquiries' => [
        'content' => [
            'required' => '문의 내용을 입력해주세요.',
            'min'      => '문의 내용은 최소 :min자 이상 입력해주세요.',
            'max'      => '문의 내용은 최대 :max자까지 입력 가능합니다.',
        ],
        'reply_content' => [
            'required' => '답변 내용을 입력해주세요.',
            'min'      => '답변 내용은 최소 1자 이상 입력해주세요.',
            'max'      => '답변 내용은 최대 5000자까지 입력 가능합니다.',
        ],
    ],

    // 커스텀 검증 메시지
    'settings' => [
        'key_required' => '설정 키는 필수입니다.',
        'value_present' => '설정 값은 필수입니다.',
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

    // 상품 검증 메시지
    'product' => [
        'name' => [
            'required' => '상품명을 입력해주세요.',
        ],
        'name_primary' => [
            'required' => '기본 언어 상품명은 필수입니다.',
        ],
        'product_code' => [
            'required' => '상품코드를 입력해주세요.',
            'unique' => '이미 사용중인 상품코드입니다.',
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
        // 일괄 처리 관련
        'bulk_update' => [
            'at_least_one' => '주문 상태, 택배사, 운송장 번호 중 하나 이상을 입력해주세요.',
        ],
        // 검색/목록 관련
        'search_field' => [
            'in' => '올바른 검색 필드를 선택해주세요.',
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
        'admin_memo' => [
            'max' => '관리자 메모는 최대 1000자까지 입력 가능합니다.',
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
        'product_id_required' => '상품을 선택해주세요.',
        'product_not_found' => '존재하지 않는 상품입니다.',
        'option_id_required' => '상품 옵션을 선택해주세요.',
        'option_not_found' => '존재하지 않는 상품 옵션입니다.',
        'quantity_required' => '수량을 입력해주세요.',
        'quantity_min' => '수량은 1개 이상이어야 합니다.',
        'quantity_max' => '수량은 9999개 이하여야 합니다.',
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
        'quantity_max' => '수량은 최대 9,999개까지 가능합니다.',
        'items_required' => '장바구니에 담을 상품을 선택해주세요.',
        'items_min' => '최소 1개 이상의 상품을 선택해주세요.',
        'option_values_not_found' => '해당 옵션 조합을 찾을 수 없습니다.',
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
        'country_code_size' => '국가 코드는 2자리여야 합니다.',
        'zipcode_max' => '우편번호는 최대 20자까지 입력 가능합니다.',
        'region_max' => '지역은 최대 100자까지 입력 가능합니다.',
        'city_max' => '도시는 최대 100자까지 입력 가능합니다.',
        'address_max' => '주소는 최대 255자까지 입력 가능합니다.',
    ],

    // 배송정책 검증 메시지
    'shipping_policy' => [
        'ids_required' => '변경할 배송정책을 선택해주세요.',
        'ids_array' => '배송정책 ID는 배열 형태여야 합니다.',
        'ids_min' => '최소 1개 이상의 배송정책을 선택해주세요.',
        'id_integer' => '배송정책 ID는 숫자여야 합니다.',
        'id_exists' => '존재하지 않는 배송정책입니다.',
        'is_active_required' => '사용여부를 선택해주세요.',
        'is_active_boolean' => '사용여부는 true 또는 false여야 합니다.',
        'base_fee_zero_not_allowed' => '무료배송이 아닌 정책은 배송비를 0원으로 설정할 수 없습니다.',
        'ranges' => [
            'first_min_zero' => '첫 구간의 시작값은 0이어야 합니다.',
            'last_max_unlimited' => '마지막 구간의 종료값은 비워야 합니다.',
            'continuity' => '구간이 연속적이지 않습니다.',
            'min_less_than_max' => '시작값이 종료값보다 작아야 합니다.',
            'fee_non_negative' => '배송비는 0 이상이어야 합니다.',
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
        ],
        'reply_status' => [
            'in' => '올바른 답글 상태를 선택해주세요.',
        ],
        'has_photo' => [
            'boolean' => '포토 리뷰 필터는 true 또는 false여야 합니다.',
        ],
        'status' => [
            'in' => '올바른 리뷰 상태를 선택해주세요.',
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

    // 필드명 한국어 변환 (Laravel 표준)
    'attributes' => [
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
        'language_currency.default_language' => '기본 언어',
        'language_currency.default_currency' => '기본 통화',
        'language_currency.currencies' => '통화 목록',
        'language_currency.currencies.*.code' => '통화 코드',
        'language_currency.currencies.*.name' => '통화명',
        'language_currency.currencies.*.name.*' => '통화명',
        'language_currency.currencies.*.exchange_rate' => '환율',
        'language_currency.currencies.*.rounding_unit' => '반올림 단위',
        'language_currency.currencies.*.rounding_method' => '반올림 방식',
        'language_currency.currencies.*.decimal_places' => '소수 자릿수',

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
        'order_settings.vbank_due_days' => '가상계좌 입금기한(일)',
        'order_settings.dbank_due_days' => '무통장입금 입금기한(일)',
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
        'language_currency' => [
            'default_language' => [
                'string' => '기본 언어는 문자열이어야 합니다.',
                'max' => '기본 언어는 최대 10자까지 입력 가능합니다.',
            ],
            'default_currency' => [
                'string' => '기본 통화는 문자열이어야 합니다.',
                'max' => '기본 통화는 최대 10자까지 입력 가능합니다.',
            ],
            'currencies' => [
                'duplicate_code' => '중복된 통화 코드가 있습니다.',
                'name_required' => '통화명은 최소 하나의 언어로 입력해야 합니다.',
                'code' => [
                    'required_with' => '통화 코드는 필수입니다.',
                    'string' => '통화 코드는 문자열이어야 합니다.',
                    'max' => '통화 코드는 최대 10자까지 입력 가능합니다.',
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
                'integer' => '자동취소 기한은 정수여야 합니다.',
                'min' => '자동취소 기한은 0일 이상이어야 합니다.',
                'max' => '자동취소 기한은 최대 30일까지 설정 가능합니다.',
            ],
            'vbank_due_days' => [
                'integer' => '가상계좌 입금기한은 정수여야 합니다.',
                'min' => '가상계좌 입금기한은 1일 이상이어야 합니다.',
                'max' => '가상계좌 입금기한은 최대 30일까지 설정 가능합니다.',
            ],
            'dbank_due_days' => [
                'integer' => '무통장입금 입금기한은 정수여야 합니다.',
                'min' => '무통장입금 입금기한은 1일 이상이어야 합니다.',
                'max' => '무통장입금 입금기한은 최대 30일까지 설정 가능합니다.',
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
        'shipping' => [
            'default_country' => [
                'string' => '기본 배송국가는 문자열이어야 합니다.',
                'max' => '기본 배송국가는 최대 10자까지 입력 가능합니다.',
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
            'default_country' => [
                'must_exist_in_countries' => '기본 배송국가는 배송가능국가 목록에 존재해야 합니다.',
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
            'carriers' => [
                'duplicate_code' => '중복된 배송사 코드가 있습니다.',
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
        'image_max' => '이미지 크기는 10MB를 초과할 수 없습니다.',
    ],
];
