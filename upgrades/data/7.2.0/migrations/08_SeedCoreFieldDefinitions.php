<?php

namespace App\Upgrades\Data\V7_2_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 코어 회원 항목 행(`field_definitions`, 집합 `core.user`)이 비어 있으면 7.2.0 기본값으로 넣습니다 (7.2.0).
 *
 * 코어 회원 항목은 스텝 `00_`(코어 선언 재동기화)이 `config/core.php` 의 `fields` 선언으로 만든다. 이 스텝은 그 재동기화가
 * 실패했거나 건너뛰어져 코어 항목 행이 하나도 없을 때의 보정이다. 코어 항목 행이 하나라도 있으면 아무것도 하지 않는다 —
 * 재동기화가 이미 만들었고, 운영자가 환경설정의 회원 탭에서 고친 값이 그 행에 있다.
 *
 * 넣는 값은 종전 동작과 같은 기본값이다(동결): 이름은 사용, 필수, 가입 표시. 닉네임, 휴대폰, 전화번호, 언어는 사용, 선택,
 * 가입 표시. 국가, 시간대, 홈페이지, 주소, 서명, 자기소개는 사용, 선택, 가입 미표시. 그래서 이 스텝이 넣은 직후의 가입과
 * 내 정보 수정은 업그레이드 전과 같다.
 *
 * 운영자가 만든 항목(`source_type = custom`)과 확장이 선언한 항목은 읽기만 하고 고치거나 지우지 않는다. 같은 키의 행이
 * 이미 있으면(출처가 무엇이든) 그 키는 넣지 않는다.
 *
 * 멱등: 첫 실행이 행을 넣으면 두 번째 실행은 「코어 항목 행 있음」으로 끝난다.
 *
 * V-1 안전 격리: DB/Schema 파사드만 쓴다. 선언 값은 이 파일에 동결해 두고 설정 파일, 시더, 서비스를 읽지 않는다.
 * 다국어 이름은 ko, en 만 넣는다 — 다른 언어는 다음 선언 재동기화에서 활성 언어팩이 더한다.
 */
final class SeedCoreFieldDefinitions implements DataMigration
{
    /** 코어 회원 항목 집합 */
    private const FIELD_SET = 'core.user';

    /**
     * 7.2.0 코어 회원 항목 선언 (동결).
     *
     * 적지 않은 속성은 7.2.0 동기화의 기본값을 쓴다: 저장 방식 `column`, 범위 `self`, 사용 true, 필수 false,
     * 가입 표시 false, 나머지 불리언 false.
     *
     * @var array<int, array<string, mixed>>
     */
    private const FIELDS = [
        [
            'key' => 'name', 'type' => 'text', 'storage_target' => 'name',
            'name' => ['ko' => '이름', 'en' => 'Name'],
            'placeholder' => ['ko' => '이름을 입력하세요', 'en' => 'Enter your name'],
            'constraints' => ['max_length' => 255],
            'required' => true, 'show_on_signup' => true, 'idv_claim' => 'name',
            'ui' => ['group' => 'basic'], 'sort_order' => 10,
        ],
        [
            'key' => 'nickname', 'type' => 'text', 'storage_target' => 'nickname',
            'name' => ['ko' => '닉네임', 'en' => 'Nickname'],
            'placeholder' => ['ko' => '닉네임을 입력하세요', 'en' => 'Enter your nickname'],
            'constraints' => ['max_length' => 50],
            'show_on_signup' => true,
            'ui' => ['group' => 'basic'], 'sort_order' => 20,
        ],
        [
            'key' => 'mobile', 'type' => 'phone', 'storage_target' => 'mobile',
            'name' => ['ko' => '휴대폰', 'en' => 'Mobile'],
            'placeholder' => ['ko' => '휴대폰번호를 입력하세요', 'en' => 'Enter your mobile number'],
            'show_on_signup' => true, 'idv_claim' => 'mobile',
            'ui' => ['group' => 'contact'], 'sort_order' => 30,
        ],
        [
            'key' => 'phone', 'type' => 'phone', 'storage_target' => 'phone',
            'name' => ['ko' => '전화번호', 'en' => 'Phone'],
            'placeholder' => ['ko' => '전화번호를 입력하세요', 'en' => 'Enter your phone number'],
            'show_on_signup' => true,
            'ui' => ['group' => 'contact'], 'sort_order' => 40,
        ],
        [
            'key' => 'language', 'type' => 'choice', 'storage_target' => 'language',
            'name' => ['ko' => '언어', 'en' => 'Language'],
            'constraints' => ['display' => 'select', 'options_source' => 'supported_locales'],
            'show_on_signup' => true,
            'ui' => ['group' => 'location'], 'sort_order' => 50,
        ],
        [
            'key' => 'country', 'type' => 'text', 'storage_target' => 'country',
            'name' => ['ko' => '국가', 'en' => 'Country'],
            'constraints' => ['min_length' => 2, 'max_length' => 2, 'regex' => '/^[\pL\pM]{2}$/u', 'widget' => 'country'],
            'ui' => ['group' => 'location'], 'sort_order' => 60,
        ],
        [
            'key' => 'timezone', 'type' => 'text', 'storage_target' => 'timezone',
            'name' => ['ko' => '시간대', 'en' => 'Timezone'],
            'constraints' => ['max_length' => 64, 'format' => 'timezone', 'widget' => 'timezone'],
            'ui' => ['group' => 'location'], 'sort_order' => 70,
        ],
        [
            'key' => 'homepage', 'type' => 'url', 'storage_target' => 'homepage',
            'name' => ['ko' => '홈페이지', 'en' => 'Homepage'],
            'constraints' => ['schemes' => '*'],
            'ui' => ['group' => 'contact'], 'sort_order' => 80,
        ],
        [
            'key' => 'address', 'type' => 'address', 'storage_target' => 'zipcode,address,address_detail',
            'name' => ['ko' => '주소', 'en' => 'Address'],
            'constraints' => ['parts' => ['zipcode' => 'zipcode', 'address' => 'address', 'address_detail' => 'address_detail']],
            'ui' => ['group' => 'location'], 'sort_order' => 90,
        ],
        [
            'key' => 'signature', 'type' => 'textarea', 'storage_target' => 'signature',
            'name' => ['ko' => '서명', 'en' => 'Signature'],
            'constraints' => ['max_length' => 1000],
            'ui' => ['group' => 'profile'], 'sort_order' => 100,
        ],
        [
            'key' => 'bio', 'type' => 'textarea', 'storage_target' => 'bio',
            'name' => ['ko' => '자기소개', 'en' => 'Bio'],
            'constraints' => ['max_length' => 5000],
            'ui' => ['group' => 'profile'], 'sort_order' => 110,
        ],
    ];

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'SeedCoreFieldDefinitions';
    }

    /**
     * 코어 회원 항목 행이 하나도 없을 때만 기본값 행을 넣습니다. idempotent.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable('field_definitions')) {
            $context->logger->warning('[7.2.0] 코어 회원 항목 넣기 건너뜀 — field_definitions 표 없음(마이그레이션 실행 여부 확인)');

            return;
        }

        $coreRows = DB::table('field_definitions')
            ->where('field_set', self::FIELD_SET)
            ->where('source_type', 'core')
            ->count();

        if ($coreRows > 0) {
            $context->logger->info('[7.2.0] 코어 회원 항목 행 있음 — 변경 없음(선언 재동기화가 만든 행과 운영자 설정 유지)', [
                'core_rows' => $coreRows,
            ]);

            return;
        }

        $taken = DB::table('field_definitions')
            ->where('field_set', self::FIELD_SET)
            ->pluck('key')
            ->all();

        $now = now();
        $inserted = [];
        $skipped = [];

        foreach (self::FIELDS as $field) {
            if (in_array($field['key'], $taken, true)) {
                $skipped[] = $field['key'];

                continue;
            }

            DB::table('field_definitions')->insert($this->rowFrom($field, $now));
            $inserted[] = $field['key'];
        }

        if ($skipped !== []) {
            $context->logger->warning('[7.2.0] 같은 키의 항목이 이미 있어 넣지 않은 코어 회원 항목이 있습니다(기존 행 유지)', [
                'skipped' => $skipped,
            ]);
        }

        $context->logger->info('[7.2.0] 코어 회원 항목 행을 기본값으로 넣었습니다(종전 동작과 같은 값)', [
            'inserted' => $inserted,
        ]);
    }

    /**
     * 동결 선언을 표의 한 행으로 바꿉니다 (7.2.0 동기화의 기본값 규칙 동결).
     *
     * @param  array<string, mixed>  $field  동결 선언
     * @param  \DateTimeInterface  $now  기록 시각
     * @return array<string, mixed> 넣을 행
     */
    private function rowFrom(array $field, \DateTimeInterface $now): array
    {
        return [
            'field_set' => self::FIELD_SET,
            'key' => $field['key'],
            'source_type' => 'core',
            'source_identifier' => 'core',
            'type' => $field['type'],
            'storage' => 'column',
            'storage_target' => $field['storage_target'],
            'name' => $this->json($field['name']),
            'description' => null,
            'placeholder' => isset($field['placeholder']) ? $this->json($field['placeholder']) : null,
            'options' => null,
            'constraints' => isset($field['constraints']) ? $this->json($field['constraints']) : null,
            'enabled' => true,
            'required' => (bool) ($field['required'] ?? false),
            'show_on_signup' => (bool) ($field['show_on_signup'] ?? false),
            'edit_scope' => 'self',
            'require_existing_members' => false,
            'public_profile' => false,
            'admin_list_column' => false,
            'admin_searchable' => false,
            'display_name_usable' => false,
            'idv_claim' => $field['idv_claim'] ?? null,
            'idv_lock' => false,
            'locks' => null,
            'legacy_keys' => null,
            'ui' => $this->json($field['ui']),
            'sort_order' => (int) $field['sort_order'],
            'user_overrides' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * 배열을 JSON 컬럼 값으로 바꿉니다.
     *
     * @param  array<string|int, mixed>  $value  저장할 배열
     * @return string JSON 문자열
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
