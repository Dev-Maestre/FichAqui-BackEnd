# Cartão de crédito via Orders API (Mercado Pago)

Decisões do grilling (jun/2026) para checkout com cartão tokenizado no FichAqui. PIX e cartão passam a usar a mesma **Orders API** (`POST /v1/orders`, `type: online`, `processing_mode: automatic`). O front continua tokenizando com MercadoPago.js (`cardToken`); o backend nunca recebe PAN/CVV.

## Integração

- **Endpoint:** `POST /v1/orders` com `transactions.payments[].payment_method.type` (`credit_card` ou `debit_card`), `token`, `id` (bandeira), `installments`.
- **Payload:** payer (email, nome, CPF) + `items[]` do pedido ? **sem** `shipment` (específico do PIX online).
- **Parcelas:** front expõe seletor via `getInstallments` (Core Methods); backend repassa `installments` ao `POST /v1/orders`. Débito força `installments: 1`.
- **Tipo de cartão:** front envia `paymentMethodType` do `getPaymentMethods`; backend repassa ao MP.
- **Idempotência:** `X-Idempotency-Key` = id do Pedido.

## Fulfillment

- **Aprovado na hora** (`processed` / `accredited`): `payment_status: paid`, Fichas no mesmo `POST /pedidos`.
- **Pendente** (`in_process`, `action_required`, etc.): `payment_status: pending`, Pedido `pending_payment`, sem Fichas ? mesmo fluxo do PIX (webhook + `GET /api/payments/{id}/status`).
- **Recusado na hora:** **422** sem criar Pedido (transação revertida).

## Escopo desta entrega

- **In:** `cardToken` + `paymentMethodId` + `paymentMethodType` no checkout.
- **Fora:** cartão salvo (`cardId`) com MP configurado retorna 422; stub `cardId ? paid` permanece só sem `MP_ACCESS_TOKEN` (dev). Ver [FichAqui-FrontEnd#1](https://github.com/lilrau/FichAqui-FrontEnd/issues/1) e [FichAqui-BackEnd#3](https://github.com/Dev-Maestre/FichAqui-BackEnd/issues/3).

## Código

| Artefato | Papel |
|----------|--------|
| `CardOnlineOrderRequest` | DTO do checkout cartão |
| `MercadoPagoGateway::createOnlineCardOrder()` | `POST /v1/orders` para cartão |
| `PedidoCheckoutService::processCreditCard()` | Orquestra order + mapeamento de status |
| `createCardPayment()` (`/v1/payments`) | Legado; mantido na interface, não usado no checkout de Pedido |

## Alternativas rejeitadas

- **Payments API só para cartão:** dois modelos de reconciliação (PIX em orders, cartão em payments) ? rejeitado em favor de gateway unificado.
- **Pedido `payment_failed` em recusa síncrona:** poluiria histórico; 422 é suficiente no MVP.
- **Cartão salvo nesta fase:** depende de Customer/Card API ? adiado.

## Consequências

- ADR-0003 permanece válido para tokenização client-side; a cobrança server-side migrou de Payments API para Orders API no checkout de Pedido.
- ADR-0004 estende-se a cartão pendente (poll/webhook), não só PIX; front usa `PendingPaymentPanel` + `usePaymentStatusPoll` para cartão `pending`.
- Front: cartão salvo em [FrontEnd#1](https://github.com/lilrau/FichAqui-FrontEnd/issues/1) + [BackEnd#3](https://github.com/Dev-Maestre/FichAqui-BackEnd/issues/3). Painel de espera cartão `pending`: `PendingPaymentPanel` (fechado [FrontEnd#2](https://github.com/lilrau/FichAqui-FrontEnd/issues/2)).
