# Mercado Pago: SDK client-side + Payments API server-side

## Contexto

O FichAqui precisa aceitar cartão e PIX via Mercado Pago. Por PCI, dados sensíveis de cartão não passam pelo nosso backend ? o front usa **MercadoPago.js v2** para tokenizar; o backend recebe apenas `cardToken` e chama a **Payments API** com `MP_ACCESS_TOKEN`.

## Decisão

1. **Front:** carregar `https://sdk.mercadopago.com/js/v2` e inicializar com a **Public Key** obtida de `GET /api/payments/config`.
2. **Back:** `MP_ACCESS_TOKEN` só no servidor; interface `PaymentGateway` com implementação `MercadoPagoGateway` (HTTP).
3. **Checkout cartão:** `POST /api/events/{eventId}/pedidos` aceita `cardToken` + `paymentMethodId` (gerados no browser) ou `cardId` (cartão salvo local / stub).
4. **Idempotência:** header `X-Idempotency-Key` = id do pedido ao criar pagamento no MP.
5. **PIX e webhooks:** ver ADR-0004 (fichas após `approved`; poll como fallback idempotente).

## Consequências

- Sem Public Key configurada, `payments/config` retorna `enabled: false` e o front pode usar fluxo legado (`cardId`).
- Credenciais de teste (`TEST-...`) em desenvolvimento; produção via secrets.

## Alternativas consideradas

- **Public Key só no `.env` do front:** duplicação e rotação mais difícil ? rejeitado em favor do endpoint de config.
- **Checkout Pro (redirect):** UX pior para app embutido ? rejeitado.
