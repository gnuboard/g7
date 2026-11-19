<?php

namespace App\Http\Requests\Concerns;

use App\Fields\FieldContext;
use App\Fields\FieldRuleFactory;
use App\Fields\FieldSet;
use App\Fields\LegacyScreenFields;
use Illuminate\Support\Facades\Log;

/**
 * 회원 폼 요청(가입, 관리자 추가·수정, 내 정보 수정)이 회원 항목 규칙을 팩토리에서 받아 쓰는 공통부.
 *
 * 요청 클래스는 「고정 규칙(이메일, 비밀번호, 약관, 역할 등) + 회원 항목 규칙」을 만든 뒤 자기 필터 훅을 그대로 태운다.
 * 회원 항목의 필수 여부와 사용 여부는 운영자 설정(환경설정 > 회원)에서 오므로 요청 클래스에 적지 않는다.
 *
 * 옛 화면 호환(7.1.x 템플릿 — 회원 항목 조각이 없는 화면): 회원 항목을 그리는 화면은 항목 응답의 정의 버전 `fields_version` 을
 * 요청에 늘 싣는다(소셜 로그인, SSO 가입 창과 같은 표식). {@see self::acceptsLegacyMemberFieldScreen()} 을 켠 요청이
 * 이 표식 없이 오면 옛 화면으로 보고 그 화면에 입력칸이 없던 항목(운영자 추가 항목, 확장 선언 항목, 7.1.x 화면에 없던 코어 칸 —
 * {@see LegacyScreenFields})의 필수 규칙을 「값을 보냈을 때만」으로 늦춘다. 형식 규칙과 7.1.x 화면에 있던 코어 칸 규칙은
 * 그대로다. 추가 정보 입력 대기 표시는 남기지 않고 로그만 남긴다. 「항목 값이 비어 있음」으로 가르지 않는 까닭은
 * 새 화면에서 빈 값을 낸 경우와 섞이기 때문이다.
 *
 * @since 7.2.0
 */
trait UsesMemberFields
{
    /** @var array<string, string>|null 옛 화면 문맥에서 필수 규칙이 늦춰진 항목 (키 => 요청 속성 경로). 요청당 한 번 계산한다 */
    private ?array $legacyRelaxedMemberFields = null;

    /** 옛 화면 호환 기록을 이 요청에서 이미 남겼는지 */
    private bool $legacyMemberFieldScreenLogged = false;

    /**
     * 이 요청의 회원 항목 문맥(표면, 대상 회원, 요청자)을 반환합니다.
     *
     * @return FieldContext 문맥
     */
    abstract protected function memberFieldContext(): FieldContext;

    /**
     * 고정 규칙에 회원 항목 규칙을 합칩니다.
     *
     * 규칙 순서는 종전 요청 클래스의 순서를 따른다 — 오류가 여럿일 때 응답 `message` 에 실리는 첫 문구가
     * 종전과 달라지지 않게 하려는 것이다. 종전에 없던 항목(운영자 추가 항목 등)은 뒤에 붙는다.
     *
     * @param  array<string, mixed>  $fixed  요청이 직접 정한 고정 규칙
     * @param  array<int, string>  $legacyOrder  종전 규칙의 키 순서
     * @return array<string, mixed> 합친 규칙
     */
    protected function withMemberFieldRules(array $fixed, array $legacyOrder): array
    {
        // 고정 규칙이 같은 키를 가지면 고정 규칙이 이긴다(이메일처럼 항목이 아닌 칸을 항목 선언이 덮지 못하게).
        $merged = $fixed + app(FieldRuleFactory::class)->rules(FieldSet::CORE_USER, $this->memberFieldRuleContext());

        $ordered = [];
        foreach ($legacyOrder as $key) {
            if (array_key_exists($key, $merged)) {
                $ordered[$key] = $merged[$key];
            }
        }

        return $ordered + $merged;
    }

    /**
     * 이 요청이 회원 항목을 그리는 화면에서 왔는지 반환합니다 (`fields_version` 을 실었는지).
     *
     * @return bool 표식이 있으면 true
     */
    public function usesMemberFieldSet(): bool
    {
        $version = $this->input('fields_version');

        return is_string($version) && $version !== '';
    }

    /**
     * 이 요청이 옛 화면 호환을 받는지 반환합니다 (요청 클래스가 켠다).
     *
     * 끈 요청(기본)은 표식이 없어도 운영자 설정 규칙을 그대로 건다. 코어의 회원 폼 요청(가입, 관리자 추가와 수정, 내 정보 수정)이 켠다.
     *
     * @return bool 옛 화면 호환을 받으면 true
     */
    protected function acceptsLegacyMemberFieldScreen(): bool
    {
        return false;
    }

    /**
     * 이 요청이 옛 화면(회원 항목 표식 없음)에서 왔고 호환을 받는지 반환합니다.
     *
     * @return bool 옛 화면 호환 경로면 true
     */
    public function fromLegacyMemberFieldScreen(): bool
    {
        return $this->acceptsLegacyMemberFieldScreen() && ! $this->usesMemberFieldSet();
    }

    /**
     * 규칙을 만들 회원 항목 문맥을 반환합니다 — 옛 화면 호환 경로면 옛 화면 표시를 단다.
     *
     * @return FieldContext 문맥
     */
    protected function memberFieldRuleContext(): FieldContext
    {
        $context = $this->memberFieldContext();

        return $this->fromLegacyMemberFieldScreen() ? $context->onLegacyScreen() : $context;
    }

    /**
     * 옛 화면이라 보내지 않아 건너뛴 필수 항목 키를 반환합니다 (검증을 마친 뒤에 부른다).
     *
     * 필수 규칙이 늦춰진 항목 가운데 요청에 값이 없는 것이다. 값을 보낸 항목은 종전대로 필수와 형식을 통과했다.
     *
     * @return array<int, string> 항목 키 목록 (옛 화면 호환 경로가 아니거나 건너뛴 항목이 없으면 빈 배열)
     */
    public function skippedLegacyMemberFieldKeys(): array
    {
        if (! $this->fromLegacyMemberFieldScreen()) {
            return [];
        }

        $this->legacyRelaxedMemberFields ??= app(FieldRuleFactory::class)
            ->relaxedOnLegacyScreen(FieldSet::CORE_USER, $this->memberFieldRuleContext());

        $skipped = [];
        foreach ($this->legacyRelaxedMemberFields as $key => $attribute) {
            if (! $this->has($attribute)) {
                $skipped[] = (string) $key;
            }
        }

        return $skipped;
    }

    /**
     * 옛 화면이라 필수 항목을 건너뛴 요청을 로그에 남기고 건너뛴 키를 반환합니다 (요청의 passedValidation 에서 부른다).
     *
     * 요청 하나에 한 번만 남긴다. 회원 이메일 같은 개인정보는 싣지 않는다.
     *
     * @return array<int, string> 건너뛴 항목 키 목록
     */
    protected function noteLegacyMemberFieldScreen(): array
    {
        $skipped = $this->skippedLegacyMemberFieldKeys();

        if ($skipped !== [] && ! $this->legacyMemberFieldScreenLogged) {
            $this->legacyMemberFieldScreenLogged = true;
            $context = $this->memberFieldContext();

            Log::info('옛 화면 호환 — 회원 항목 표식(fields_version)이 없는 요청이라 옛 화면에 없던 필수 항목을 건너뛰었습니다', [
                'surface' => $context->surface,
                'skipped' => $skipped,
                'owner_id' => $context->owner?->getKey(),
                'actor_id' => $context->actor?->getKey(),
                'route' => $this->route()?->getName(),
            ]);
        }

        return $skipped;
    }

    /**
     * 회원 항목의 오류 문구용 이름을 반환합니다 (요청의 attributes() 에 합친다).
     *
     * 이름은 항목 정의의 이름(운영자가 고친 이름 포함)이다. 언어 파일이 그 요청 키의 검증 라벨을 따로 가진 칸은
     * 넣지 않는다 — 종전 문구가 그 라벨로 나가던 칸의 문구를 바꾸지 않기 위해서다. 요청이 직접 정한 이름이 있으면 그것이 이긴다.
     *
     * @param  array<string, string>  $own  요청이 직접 정한 속성 이름
     * @return array<string, string> 합친 속성 이름
     */
    protected function withMemberFieldAttributes(array $own = []): array
    {
        $fromFields = array_filter(
            app(FieldRuleFactory::class)->attributes(FieldSet::CORE_USER, $this->memberFieldContext()),
            fn (string $path) => ! trans()->has('validation.attributes.'.$path),
            ARRAY_FILTER_USE_KEY,
        );

        return $own + $fromFields;
    }

    /**
     * 운영자가 적은 형식 오류 문구를 요청의 문구에 합칩니다.
     *
     * @param  array<string, string>  $own  요청이 직접 정한 문구
     * @return array<string, string> 합친 문구
     */
    protected function withMemberFieldMessages(array $own = []): array
    {
        return $own + app(FieldRuleFactory::class)->messages(FieldSet::CORE_USER, $this->memberFieldContext());
    }
}
