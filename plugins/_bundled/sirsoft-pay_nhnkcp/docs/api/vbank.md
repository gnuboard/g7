# Vbank / 통보 수신 API 레퍼런스

> **소유**: 플러그인 `sirsoft-pay_nhnkcp`. 이 문서는 결제대행사(NHN KCP) 서버가 직접 POST 로 보내는 통보 수신 경로를 서술한다. 브라우저가 접속하는 결제 콜백과는 접근 제어가 다르다.

---

## TL;DR (5초 요약)

```text
1. NHN KCP 서버가 직접 보내는 통보(가상계좌 입금·에스크로 공통)를 받는 경로다
2. 통보 수신 경로는 KCP 공식 발신 IP 만 허용한다 (위변조·재처리 방어)
3. IP 화이트리스트는 코어 확장 미들웨어 self-gate 로 통보 라우트명에만 적용된다
4. 브라우저 결제 콜백(payment.callback)에는 IP 확인이 적용되지 않는다
5. 검사 동작·허용 범위는 라우트 파일 직접 부착 시절과 동일하다
```

---

## 통보 수신 경로

| 라우트명 | 메서드/URI | 용도 |
| --- | --- | --- |
| `web.plugins.sirsoft-pay_nhnkcp.payment.vbank-notify` | `POST /plugins/sirsoft-pay_nhnkcp/payment/vbank-notify` | 가상계좌 입금통보(NOTI) 수신 |
| `web.plugins.sirsoft-pay_nhnkcp.payment.escrow-common-notify` | `POST /plugins/sirsoft-pay_nhnkcp/payment/escrow-common-notify` | 에스크로 공통통보 수신 |

**설명**

위 두 경로는 NHN KCP 서버가 구매자의 가상계좌 실입금·에스크로 상태 변경을 가맹점에 알리기 위해 직접 POST 로 호출한다. 브라우저를 거치지 않는 서버 대 서버 통신이므로, 위변조·재처리 요청을 막기 위해 **NHN KCP 공식 발신 IP 만 허용**한다.

이 IP 화이트리스트 검사는 코어의 확장 미들웨어 self-gate(`Plugin::getMiddleware()` 의 `targets` 로 위 두 통보 라우트명에만 정밀 타게팅)로 수행된다. 브라우저가 접속하는 결제 콜백(`payment.callback`)에는 적용되지 않는다 — 콜백은 정상 사용자의 브라우저에서 임의 IP 로 도달하므로 IP 로 제한하면 결제가 끊긴다. 검사 자체의 동작·허용 범위는 라우트 파일에서 직접 부착하던 이전 방식과 동일하다.

상세: [docs/backend/middleware.md "확장 미들웨어 선언 (self-gate)"](../../../../../docs/backend/middleware.md).

---

## 모바일 가상계좌 세션 확인값

모바일(SmartPhone Pay) 가상계좌는 PC 와 달리 **서버-서버 승인이 없다**. KCP 가 계좌번호를 브라우저 평문 POST 로만 전달하므로, 콜백만으로는 그 값이 KCP 에서 온 것인지 확인할 수 없다.

그래서 승인키 발급(`POST /api/plugins/sirsoft-pay_nhnkcp/mobile/approval-key`, 구매자 인증 필요)이 가상계좌 요청일 때 일회성 확인값을 만들어 주문에 저장하고, 결제창 필드 `param_opt_2` 로 실어 보낸다. KCP 는 이 값을 콜백에 그대로 되돌려주므로 콜백은 저장된 값과 대조한 뒤에만 계좌를 저장한다.

| 항목 | 값 |
| --- | --- |
| 결제창 필드 | `param_opt_2` (가상계좌 요청에만 부여) |
| 콜백 수신 필드 | `param_opt_2` (string, max 50) |
| 불일치·부재 시 | 주문 상태 **무변경** + 실패 리다이렉트 `error=vbank_session_mismatch` |
| 재사용 | 불가 — 계좌 저장 시 확인값이 소멸한다 |

`param_opt_1` 은 간편결제 수단 식별자가 이미 점유하고 있으므로 `param_opt_2` 를 쓴다. PC 분기(`enc_data` 존재)는 서버 승인으로 검증되므로 이 대조 대상이 아니다.

---

## 결제 실패 리다이렉트 규약 (브라우저 콜백)

결제창에서 돌아오는 브라우저 콜백은 JSON 응답이 아니라 상점 실패 페이지로 **리다이렉트**하며, 실패 사유를 쿼리스트링으로 전달합니다.

| 쿼리 | 값 | 설명 |
| --- | --- | --- |
| `error` | 실패 코드 | 기계 판독용 고정 식별자 (`authorize_failed` · `approve_failed` 등). 화면 분기·문의 접수의 기준값 |
| `message` | 안내 문구 | 구매자에게 보여 줄 다국어 문구. 상점 실패 페이지가 그대로 출력합니다 |
| `orderId` | 주문번호 | 실패한 주문의 식별자 |

`message` 에는 예외 원문(내부 오류 메시지·SQL 상태코드·클래스명·경로)을 싣지 않습니다. 이 값은 브라우저 주소창과 참조 로그에 남고 실패 페이지에 그대로 출력되므로, 내부 정보가 구매자와 중간 경유지에 노출됩니다. 원인 파악에 필요한 원문은 서버 로그(`Log::error`)에만 기록합니다.
