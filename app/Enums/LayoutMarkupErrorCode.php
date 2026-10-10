<?php

namespace App\Enums;

use App\Support\LayoutMarkup\PartialReferenceNotation;

/**
 * `.g7.html` 마크업 형식 컴파일 실패 사유 코드 Enum.
 *
 * `CompileError` 가 이 코드로 오류를 식별하며, 코드 값이 그대로
 * `lang/{locale}/exceptions.php` 의 `layout_markup.{value}` 메시지 키가 된다.
 *
 * 메시지가 번역되어도 코드는 불변이므로, 테스트·규정 문서·audit 룰은 번역문이 아닌
 * 이 코드로 오류를 지목한다.
 *
 * 아래 코드는 **토크나이저(S1) + 트리·노드 컴파일(S2) 층**의 실패 사유다. 상위 층(액션·문서
 * 구조 해석)이 추가되면 그 층의 사유도 여기에 누적한다.
 */
enum LayoutMarkupErrorCode: string
{
    /**
     * 요소·텍스트·주석이 아닌 마크업 (DOCTYPE·CDATA·처리 명령).
     */
    case UnsupportedMarkup = 'unsupported_markup';

    /**
     * 텍스트에 이스케이프하지 않은 `<` — 태그 시작으로 해석된다.
     */
    case UnescapedLt = 'unescaped_lt';

    /**
     * 태그가 닫히지 않은 채 파일이 끝남.
     */
    case EofInTag = 'eof_in_tag';

    /**
     * 주석이 닫히지 않은 채 파일이 끝남.
     */
    case EofInComment = 'eof_in_comment';

    /**
     * 태그명이 비어 있음 (`</>`).
     */
    case EmptyTagName = 'empty_tag_name';

    /**
     * 태그명에 대문자 사용 (`<Div>`).
     */
    case TagUppercase = 'tag_uppercase';

    /**
     * 소문자·kebab 규칙을 벗어난 태그명 (`<data_grid>`).
     */
    case InvalidTagName = 'invalid_tag_name';

    /**
     * 종료 태그에 자기닫힘 표기 (`</div/>`).
     */
    case EndTagSelfClosing = 'end_tag_self_closing';

    /**
     * 종료 태그에 속성 표기 (`</div class="a">`).
     */
    case EndTagWithAttributes = 'end_tag_with_attributes';

    /**
     * void 요소의 종료 태그 (`</input>`).
     */
    case VoidEndTag = 'void_end_tag';

    /**
     * 허용 문자를 벗어난 속성명.
     */
    case InvalidAttributeName = 'invalid_attribute_name';

    /**
     * 인용되지 않은 속성값 (`class=admin-card`).
     */
    case UnquotedAttributeValue = 'unquoted_attribute_value';

    /**
     * 속성값의 따옴표가 닫히지 않음.
     */
    case UnterminatedAttributeValue = 'unterminated_attribute_value';

    /**
     * 같은 속성이 한 태그에 중복 지정됨.
     */
    case DuplicateAttribute = 'duplicate_attribute';

    /**
     * 속성 사이의 공백 누락 (`class="a"id="b"`).
     */
    case MissingAttributeSeparator = 'missing_attribute_separator';

    /**
     * 참조 모양이지만 알려지지 않은 문자 참조 (`&foo;`).
     */
    case UnknownEntity = 'unknown_entity';

    /**
     * 문서에 실을 수 없는 코드포인트를 가리키는 문자 참조 (`&#xD800;`).
     */
    case InvalidEntity = 'invalid_entity';

    /**
     * 레지스트리(`components.json`) 파일을 읽을 수 없음.
     */
    case ManifestUnreadable = 'manifest_unreadable';

    /**
     * 레지스트리 파일이 올바른 JSON 이 아님.
     */
    case ManifestInvalidJson = 'manifest_invalid_json';

    /**
     * 레지스트리가 매니페스트 스키마를 위반함 (필수 필드 누락·형식 이탈).
     */
    case ManifestInvalid = 'manifest_invalid';

    /**
     * 두 컴포넌트가 같은 마크업 태그로 해석됨 (매니페스트 모호 등록).
     */
    case AmbiguousComponentTag = 'ambiguous_component_tag';

    /**
     * 매니페스트가 소유자를 두 가지로 밝힘 (`templateId` 와 `identifier` 동시 선언).
     */
    case ManifestOwnerAmbiguous = 'manifest_owner_ambiguous';

    /**
     * 매니페스트가 소유자를 밝히지 않음 (`templateId`·`identifier` 둘 다 없음).
     */
    case ManifestOwnerMissing = 'manifest_owner_missing';

    /**
     * 확장이 컴포넌트 오버라이드를 선언함 (오버라이드 권한은 템플릿 전용).
     */
    case ExtensionOverridesForbidden = 'extension_overrides_forbidden';

    /**
     * 템플릿이 템플릿 전용 판본(`for`)을 선언함 (판본 선언은 확장 전용).
     */
    case TemplateForVariantForbidden = 'template_for_variant_forbidden';

    /**
     * 오버라이드가 대상 확장에 없는 컴포넌트를 지목함.
     */
    case OverrideTargetComponentUnknown = 'override_target_component_unknown';

    /**
     * 같은 대상 컴포넌트를 두 번 오버라이드함.
     */
    case DuplicateOverrideTarget = 'duplicate_override_target';

    /**
     * 템플릿 전용 판본이 그 확장에 없는 컴포넌트를 지목함.
     */
    case ForVariantComponentUnknown = 'for_variant_component_unknown';

    /**
     * 열린 태그가 없는데 종료 태그를 만남 (`</div>` 단독).
     */
    case UnexpectedEndTag = 'unexpected_end_tag';

    /**
     * 종료 태그가 열린 태그와 다름 (`<div></span>`).
     */
    case MismatchedEndTag = 'mismatched_end_tag';

    /**
     * 태그가 닫히지 않은 채 문서가 끝남 (중첩 검출 — 토크나이저의 EofInTag 와 다름).
     */
    case UnclosedTag = 'unclosed_tag';

    /**
     * 한 요소에 텍스트와 자식 요소가 공존함 (JSON 노드의 text/children 은 배타적).
     */
    case MixedTextAndChildren = 'mixed_text_and_children';

    /**
     * 요소 밖에 텍스트가 있음.
     */
    case TextOutsideElement = 'text_outside_element';

    /**
     * 한 요소 앞에 주석이 둘 이상 붙음 (노드 `comment` 는 문자열 하나).
     */
    case ConsecutiveComments = 'consecutive_comments';

    /**
     * 주석 뒤에 귀속시킬 요소가 없음.
     */
    case DanglingComment = 'dangling_comment';

    /**
     * 주석이 붙은 태그에 산출 JSON 상의 `comment` 자리가 없음 — 그대로 두면 조용히 사라진다.
     *
     * 판정은 태그 목록이 아니라 **컴파일 산출과의 대조**다(같은 태그라도 자리에 따라 갈린다 —
     * Modal 자식 `<footer>` 는 `props.footer` 로 접혀 자리가 없고 `Footer` 컴포넌트 노드는 있다).
     */
    case CommentNotAttachable = 'comment_not_attachable';

    /**
     * 컴포넌트 종류 속성(`data-type`)의 값이 basic·composite·layout 이 아니거나 바인딩으로 적힘.
     */
    case InvalidComponentType = 'invalid_component_type';

    /**
     * 문서 루트 전용 태그를 노드 위치에 사용함 (`<partial>`·`<layout>`·`<extension>`).
     */
    case RootTagInNodePosition = 'root_tag_in_node_position';

    /**
     * 다른 프레임워크 관용구를 사용함 (`v-if`·`@click`·`*ngIf`·무접두 `if`).
     */
    case ForeignIdiom = 'foreign_idiom';

    /**
     * `:attr` 타입 리터럴의 값이 JSON 이 아님.
     */
    case InvalidTypeLiteral = 'invalid_type_literal';

    /**
     * 불리언 속성에 `"false"` 를 표기함 (HTML5 는 거짓을 속성 생략으로 표기).
     */
    case BooleanAttributeFalse = 'boolean_attribute_false';

    /**
     * 값이 필요한 지시 속성에 값이 없음 (`<div data-if>`).
     */
    case DirectiveNeedsValue = 'directive_needs_value';

    /**
     * 타입이 고정된 지시 속성에 `:` 타입 리터럴을 사용함.
     */
    case DirectiveRejectsTypeLiteral = 'directive_rejects_type_literal';

    /**
     * 지시 속성 값이 정수가 아님 (`data-slot-order="첫째"`).
     */
    case DirectiveNeedsInteger = 'directive_needs_integer';

    /**
     * `data-each` 표기가 `아이템[, 인덱스] in 소스` 형식이 아님.
     */
    case InvalidEachSyntax = 'invalid_each_syntax';

    /**
     * `data-if` 가 보간 혼합형임 (`{{x}} === 'y'` — 보간 후 항상 참이 되는 함정).
     */
    case MixedIfExpression = 'mixed_if_expression';

    /**
     * 해당 부모 아래에 올 수 없는 구조 태그 (`<variant>` 가 `<class-map>` 밖에 있음).
     */
    case UnexpectedStructuralChild = 'unexpected_structural_child';

    /**
     * 태그에 필수 속성이 없음.
     */
    case MissingRequiredAttribute = 'missing_required_attribute';

    /**
     * 같은 노드 키를 두 번 지정함 (`<class-map>` 중복 등).
     */
    case DuplicateNodeKey = 'duplicate_node_key';

    /**
     * `<prop>`·`<data>` 본문이 올바른 JSON 이 아님.
     */
    case InvalidJsonBody = 'invalid_json_body';

    /**
     * 상태 초기화 `<field>` 에 값과 자식이 함께 있음.
     */
    case FieldValueAndChildren = 'field_value_and_children';

    /**
     * JSON 노드 키 이름을 무접두 속성으로 사용함 (`condition="..."`).
     */
    case NodeKeyAsAttribute = 'node_key_as_attribute';

    /**
     * G7 이 쓰지 않는 표준 속성 (`is` — customized built-in element).
     */
    case AttributeNotSupported = 'attribute_not_supported';

    /**
     * G7 태그이지만 이 컴파일 단계가 아직 다루지 않음.
     */
    case TagNotYetSupported = 'tag_not_yet_supported';

    /**
     * 조건 속성에 리터럴 불리언을 문자열로 표기함 (`data-if="false"`).
     */
    case ConditionBooleanLiteral = 'condition_boolean_literal';

    /**
     * 액션 태그를 컴포넌트(노드) 자리에 사용함 (`<on>`·`<switch>` 는 이벤트 컨텍스트 전용).
     */
    case ActionTagInNodePosition = 'action_tag_in_node_position';

    /**
     * `<on>` 에 트리거가 없음 (event·type·listen 중 하나 필요).
     */
    case ActionTriggerMissing = 'action_trigger_missing';

    /**
     * `<on>` 의 트리거 표기가 충돌함 (listen 과 event/type 병기, mount 와 type 병기 등).
     */
    case ActionTriggerConflict = 'action_trigger_conflict';

    /**
     * 액션을 담아야 할 블록이 비어 있음 (`<on>`·`<sequence>`·`<case>` 등).
     */
    case EmptyActionBlock = 'empty_action_block';

    /**
     * 액션 컨텍스트에 올 수 없는 태그 (제어 태그의 자식·핸들러 태그의 자식 규칙 위반).
     */
    case UnexpectedActionChild = 'unexpected_action_child';

    /**
     * 등록되지 않은 액션 태그 (내장 핸들러가 아님 — `<action handler>` 안내).
     */
    case UnknownActionTag = 'unknown_action_tag';

    /**
     * 등록되지 않은 액션 태그 (오타로 보이는 핸들러 후보 있음).
     */
    case UnknownActionTagWithSuggestion = 'unknown_action_tag_with_suggestion';

    /**
     * 같은 케이스·에러코드 키를 두 번 지정함 (`<case when>`·`<on-code code>` 중복).
     */
    case DuplicateCaseKey = 'duplicate_case_key';

    /**
     * 같은 액션 키를 두 번 지정함 (`<success>` 중복·`<body>` 와 속성 충돌 등).
     */
    case DuplicateActionKey = 'duplicate_action_key';

    /**
     * confirm 을 진입점이 아닌 중첩 액션에 표기함 (엔진은 진입점에서만 발화).
     */
    case ConfirmNotAtRoot = 'confirm_not_at_root';

    /**
     * init 루트 액션에 축소 화이트리스트 밖 키를 사용함 (executeInitActions 가 침묵 탈락).
     */
    case InitActionKeyForbidden = 'init_action_key_forbidden';

    // 문서 구조 (S4)

    /**
     * 문서 최상위 섹션 태그를 노드 위치에 사용함 (`<data-source>`·`<meta>` 는 문서 직속 전용).
     */
    case SectionTagInNodePosition = 'section_tag_in_node_position';

    /**
     * 파일에 요소가 하나도 없음 (빈 문서).
     */
    case EmptyDocument = 'empty_document';

    /**
     * 문서 루트가 둘 이상임 (한 파일에는 봉투 하나).
     */
    case MultipleDocumentRoots = 'multiple_document_roots';

    /**
     * 루트 태그가 문서 봉투가 아님 (`<layout>`·`<partial>`·`<extension>`·`<override>`).
     */
    case UnknownDocumentRoot = 'unknown_document_root';

    /**
     * 문서 봉투에 알 수 없는 속성을 사용함 (`<layout foo>`).
     */
    case UnknownRootAttribute = 'unknown_root_attribute';

    /**
     * 한 번만 올 수 있는 섹션을 두 번 지정함 (`<meta>`·`<computed name>` 중복 등).
     */
    case DuplicateSection = 'duplicate_section';

    /**
     * 문서 봉투에 올 수 없는 자식을 사용함 (`<override>` 안의 비허용 섹션 등).
     */
    case UnexpectedDocumentChild = 'unexpected_document_child';

    /**
     * `<partial>` 의 루트 노드가 하나가 아님 (조각은 루트가 노드 하나).
     */
    case PartialRootNotSingleNode = 'partial_root_not_single_node';

    /**
     * `<partial>` 루트 노드 안에 `<meta>` 를 씀 (조각의 meta 는 `<partial>` 속성이 SSoT — 안쪽 meta 는 덮여 사라진다).
     */
    case PartialRootMetaNotAllowed = 'partial_root_meta_not_allowed';

    /**
     * `<modal src>` 에 본문을 함께 씀 (참조와 인라인은 배타적).
     */
    case ModalSrcWithBody = 'modal_src_with_body';

    /**
     * `<modal src>` 에 `name` 을 함께 씀 — `name` 은 설치가 조각을 인라인할 때 붙는 `layout_name` 의
     * 표현이라 인라인 본문에만 자리가 있다(참조 항목은 조각 파일이 이름을 갖는다).
     */
    case ModalNameWithSrc = 'modal_name_with_src';

    /**
     * 조각 참조(`<include src>`·`<modal src>`)가 소스 확장자(`.g7.html`)를 쓰지 않음.
     *
     * 소스는 소스를 가리키고, 산출 JSON 의 `.json` 표기는 컴파일러가 경계에서 만든다
     * ({@see PartialReferenceNotation}). 규약 밖 표기를 통과시키면
     * 그 값이 산출물 안에서 정상처럼 보이므로 여기서 세운다.
     */
    case PartialSrcNotAuthoring = 'partial_src_not_authoring';

    /**
     * `<modal src>` 를 노드 자리에 씀 (조각 참조는 문서의 modals 선언 자리 전용).
     *
     * 노드 자리의 `<modal>` 은 Modal 컴포넌트라 거기 붙은 `src` 는 아무도 읽지 않는 prop 이 된다 —
     * 표기가 규약에 맞아도 창이 뜨지 않는다. 조용한 소실이라 위치로 세운다.
     */
    case ModalSrcInNodePosition = 'modal_src_in_node_position';

    /**
     * 인라인 `<modal>` 의 루트 노드가 하나가 아님.
     */
    case ModalInlineNotSingleNode = 'modal_inline_not_single_node';

    /**
     * `<extension>` 이 target-layout 과 extension-point 를 함께 선언함 (둘은 배타적).
     */
    case FragmentTargetConflict = 'fragment_target_conflict';

    /**
     * `<extension>` 이 target-layout·extension-point 를 둘 다 선언하지 않음.
     */
    case FragmentTargetMissing = 'fragment_target_missing';

    /**
     * fragment 봉투에 올 수 없는 자식을 사용함 (extension-point 형에 `<inject>` 등).
     */
    case UnexpectedFragmentChild = 'unexpected_fragment_child';

    /**
     * `<inject>` 가 `<props>` 와 컴포넌트 노드를 함께 가짐 (둘은 배타적).
     */
    case FragmentInjectAmbiguous = 'fragment_inject_ambiguous';

    // 상태 초기화 (S4)

    /**
     * `<from-source>` 를 담을 상태 키가 없음 (`<init-* key="...">` 필요).
     */
    case StateSourceNeedsKey = 'state_source_needs_key';

    /**
     * `merge` 를 `key` 없이 사용함 (병합 전략은 묶는 키가 있어야 함).
     */
    case StateMergeNeedsKey = 'state_merge_needs_key';

    /**
     * `<field>` 와 `<from-source>` 를 한 블록에 섞음 (리터럴과 소스 값은 배타적).
     */
    case StateFieldSourceMixed = 'state_field_source_mixed';

    /**
     * `merge` 를 소스 값 블록에 사용함 (병합 전략은 매핑 객체에만).
     */
    case StateMergeNeedsMapping = 'state_merge_needs_mapping';

    /**
     * `<from-source>` 에 본문과 key/path 속성을 함께 씀 (문자열 소스와 `{key,path}` 는 배타적).
     */
    case StateSourceValueAndKey = 'state_source_value_and_key';

    // 임베딩 (S5)

    /**
     * 임베딩 태그를 요구하는 부모 밖에 사용함 (`<column>` 은 `<data-grid>` 안, `<cell>` 은 `<column>` 안).
     */
    case EmbeddingTagWrongParent = 'embedding_tag_wrong_parent';

    /**
     * 노드 하나만 담아야 하는 임베딩 태그에 자식이 하나가 아님 (`<item-template>`).
     */
    case EmbeddingSingleNodeRequired = 'embedding_single_node_required';

    // 강검증 (S6) — 컴파일 산출 JSON 을 대상으로 한 의미·보안 검증

    /**
     * 완성된 레이아웃 구조가 스키마를 위반함 (version/layout_name 누락·컴포넌트 형식 이탈 등).
     * 노드 층이 잡지 못하는 문서 전역 필수 필드를 ValidLayoutStructure 상당으로 검사한다.
     */
    case LayoutStructureInvalid = 'layout_structure_invalid';

    /**
     * fragment 구조가 확장 스키마를 위반함 (ValidLayoutExtensionStructure 상당).
     */
    case FragmentStructureInvalid = 'fragment_structure_invalid';

    /**
     * data_sources·액션의 endpoint 가 화이트리스트 경로를 벗어남 (WhitelistedEndpoint 상당).
     */
    case EndpointNotWhitelisted = 'endpoint_not_whitelisted';

    /**
     * props·액션에 외부 URL·위험 스킴이 있음 (NoExternalUrls 상당 — http/https/data/javascript 등).
     */
    case ExternalUrlForbidden = 'external_url_forbidden';

    /**
     * 컴파일 산출 노드의 컴포넌트 name 이 레지스트리에 없음 (ComponentExists 상당 — 방어 그물).
     */
    case ComponentNotInRegistry = 'component_not_in_registry';

    /**
     * transition_overlay.wait_for 가 완료 신호가 오지 않는 데이터소스를 가리킴 (websocket·background).
     */
    case WaitForSourceNeverResolves = 'wait_for_source_never_resolves';

    /**
     * `<script src>` 의 호스트·스킴이 허용 목록 밖임 (외부 JS 로더 화이트리스트 — Q4).
     */
    case ScriptSrcNotAllowed = 'script_src_not_allowed';

    /**
     * 다국어 오류 본문을 반환합니다.
     *
     * @param  array<string, string|int>  $params  메시지 치환 파라미터
     * @return string 오류 본문 (lang/{locale}/exceptions.php 의 layout_markup 키)
     */
    public function message(array $params = []): string
    {
        return __('exceptions.layout_markup.'.$this->value, $params);
    }

    /**
     * 모든 에러 코드 값을 문자열 배열로 반환합니다.
     *
     * @return array<int, string> 에러 코드 문자열 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
