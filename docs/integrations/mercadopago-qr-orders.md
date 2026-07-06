# Integracao Mercado Pago ? PIX (Orders API)

Este documento descreve como o **FichAqui BackEnd** cria pagamentos PIX via Mercado Pago.

## Visao geral

| Aspecto | Implementacao |
|--------|----------------|
| Driver padrao | `MP_PIX_DRIVER=online` ? `POST /v1/orders` `type: online` (PIX in-app) |
| Cartao (checkout) | `POST /v1/orders` `type: online` + `credit_card` + `cardToken` (mesma Orders API) |
| Driver legado | `MP_PIX_DRIVER=payments` ? `POST /v1/payments` |
| Driver POS | `MP_PIX_DRIVER=orders` + `MP_QR_EXTERNAL_POS_ID` ? `POST /v1/orders` `type: qr` |
| Idempotencia | Header `X-Idempotency-Key` |
| Confirmacao | Webhook + poll `GET /api/payments/{id}/status` |

Quando o cliente escolhe **PIX** no checkout:

1. Valida itens, CPF e e-mail sandbox (`@testuser.com` se `MP_SANDBOX=true`).
2. Cria ordem online no MP (`createOnlinePixOrder` por padrao).
3. Persiste `gateway_order_id`, `gateway_payment_id`, `pix_copy_paste`, `pix_qr_code`.
4. Retorna 201 com QR e **sem fichas** ate pagamento confirmado.

Quando o cliente escolhe **cartao** (`cardToken`):

1. Valida itens, CPF e e-mail sandbox (mesmas regras do PIX).
2. Cria ordem online no MP (`createOnlineCardOrder`) com payer + items, sem `shipment`.
3. Persiste `gateway_order_id` e `gateway_payment_id`.
4. Aprovado na hora gera fichas; pendente segue webhook/poll (igual PIX). Recusa imediata retorna 422 sem Pedido.

## Variaveis de ambiente

```env
MP_PIX_DRIVER=online
MP_SANDBOX=true
# Somente driver orders (caixa fisico):
# MP_QR_EXTERNAL_POS_ID=
```

## Sandbox: compradores de teste

Com `MP_SANDBOX=true` (padrão em `.env.example`), o checkout via **cartão** ou **PIX** valida o e-mail do usuário autenticado antes de chamar o Mercado Pago. O e-mail deve terminar em `@testuser.com` ? exigência do ambiente de testes do MP para compradores.

| Cenário | Comportamento |
|---------|----------------|
| `MP_SANDBOX=true` + e-mail `@testuser.com` | Checkout permitido |
| `MP_SANDBOX=true` + e-mail real (ex.: `gmail.com`) | **422** com mensagem orientando criar conta de teste |
| `MP_SANDBOX=false` (produção) | Qualquer e-mail válido do usuário |

### Como testar localmente

1. No [painel Mercado Pago](https://www.mercadopago.com.br/developers/panel/app), abra **Credenciais de teste** ? **Contas de teste** e crie um comprador (ou use um existente).
2. Faça login no FichAqui com um usuário cujo e-mail seja `@testuser.com`.
   - O seed padrão (`php artisan db:seed`) cria **maria@testuser.com** ? use essa conta para testes de pagamento.
3. Configure `MP_PUBLIC_KEY` e `MP_ACCESS_TOKEN` de **teste** no `.env` do backend.
4. O front obtém a Public Key via `GET /api/payments/config` ? não copie credenciais para o FrontEnd.

Implementação: `PedidoCheckoutService::assertSandboxPayerEmail()`.

## Arquitetura no código

```
POST /api/events/{id}/pedidos  (paymentMethod: pix | credit_card)
        ?
        ?
PedidoCheckoutService::processPix() | processCreditCard()
        ?
        ?? MP_PIX_DRIVER=orders ??? MercadoPagoGateway::createQrOrder()
        ?                              POST /v1/orders (type: qr)
        ?? MP_PIX_DRIVER=payments ?? MercadoPagoGateway::createPixPayment()
        ?                              POST /v1/payments
        ?? default (online)      ?? MercadoPagoGateway::createOnlinePixOrder()
        ?                              POST /v1/orders (type: online, pix)
        ?? credit_card + cardToken ? MercadoPagoGateway::createOnlineCardOrder()
                                       POST /v1/orders (type: online, credit_card)
        ?
        ?? Webhook MP ??? PaymentSyncService::syncByGatewayPaymentId()
        ?? Poll ???????? GET /api/payments/{paymentId}/status
                              PaymentSyncService::syncPedido()
        ?
        ? (approved)
PedidoFulfillmentService::fulfillIfPaid() ? gera fichas
```

### Arquivos principais

| Arquivo | Responsabilidade |
|---------|------------------|
| `app/Contracts/PaymentGateway.php` | Contrato: `createQrOrder`, `getOrder`, `createPixPayment`, ? |
| `app/Services/Payments/MercadoPagoGateway.php` | Cliente HTTP para MP |
| `app/Data/Payments/QrOrderRequest.php` | DTO da ordem QR |
| `app/Data/Payments/GatewayPaymentResult.php` | Resultado normalizado (status, PIX, IDs) |
| `app/Services/PedidoCheckoutService.php` | Orquestra checkout e escolhe driver PIX |
| `app/Services/PaymentSyncService.php` | Sincroniza status (por order ou payment id) |
| `config/mercadopago.php` | Configuração centralizada |
| `database/migrations/2026_06_18_100000_add_gateway_order_id_to_pedidos.php` | Coluna `gateway_order_id` |

## Payload enviado ao Mercado Pago

O gateway monta automaticamente:

```json
{
  "type": "qr",
  "total_amount": "32.00",
  "description": "Pedido FichAqui",
  "external_reference": "<idempotency-key>",
  "expiration_time": "PT15M",
  "config": {
    "qr": {
      "mode": "dynamic",
      "external_pos_id": "<opcional>"
    }
  },
  "transactions": {
    "payments": [
      { "amount": "32.00" }
    ]
  }
}
```

Headers:

- `Authorization: Bearer <MP_ACCESS_TOKEN>`
- `X-Idempotency-Key: <uuid>` ? reutilizar a mesma chave retorna a ordem existente (24h)

## Resposta mapeada para o front

### Checkout (`POST /api/events/{eventId}/pedidos`)

Campos relevantes na resposta (objeto plano):

| Campo | Origem MP | Uso no front |
|-------|-----------|--------------|
| `paymentStatus` | `created` / `pending` | Exibir tela de aguardo |
| `paymentId` | `transactions.payments[0].id` | Poll e webhook |
| `gatewayOrderId` | `id` da ordem | Poll alternativo |
| `pixCopyPaste` | `type_response.qr_data` | Gerar imagem QR (EMV) |
| `pixQrCode` | ? (orders não retorna base64) | Opcional; usar lib QR no front |
| `pixExpiresAt` | calculado de `created_date` + `expiration_time` | Countdown |
| `fichas` | `[]` enquanto pendente | Só após `paid` |

Exemplo (pendente):

```json
{
  "id": "pedido-uuid",
  "status": "pending_payment",
  "paymentMethod": "pix",
  "paymentStatus": "pending",
  "paymentId": "PAY01J67CQQH5904WDBVZEM4JMEP3",
  "gatewayOrderId": "ORD00001111222233334444555566",
  "pixCopyPaste": "00020101021243650016com.mercadolibre...",
  "fichas": []
}
```

### Poll (`GET /api/payments/{paymentId}/status`)

O parâmetro `{paymentId}` aceita **payment id** ou **order id** do MP.

Antes de responder, o backend consulta:

- `GET /v1/orders/{gateway_order_id}` se o pedido tiver `gateway_order_id`
- `GET /v1/payments/{gateway_payment_id}` caso contrário (driver legado)

Quando o pagamento é aprovado, `status` passa a `paid`, `orderStatus` a `available` e `fichas` é preenchido.

## Webhooks

O **webhook** é o caminho primário para confirmar PIX e cartão pendente (ADR-0004). O **poll** no front (`GET /api/payments/{id}/status`) é fallback quando o usuário permanece na tela ? não substitui webhook em produção.

### URLs aceitas pelo backend

| Rota | Exemplo |
|------|---------|
| Recomendada | `POST https://<seu-dominio>/webhook-mp` |
| Alias legado | `POST https://<seu-dominio>/api/webhooks/mercadopago` |

O valor de `MP_WEBHOOK_URL` no `.env` deve ser a URL **pública** que você cadastra no painel MP (normalmente igual à rota recomendada).

### Produção e staging (URL fixa por ambiente)

Cada ambiente tem **uma** URL de webhook no [painel Mercado Pago](https://www.mercadopago.com.br/developers/panel/app) ? **Suas integrações** ? **Webhooks**:

| Ambiente | `MP_WEBHOOK_URL` (exemplo) | Observação |
|----------|----------------------------|------------|
| Produção | `https://fichaqui.baiacubo.tech/webhook-mp` | HTTPS obrigatório; domínio estável |
| Staging | `https://api-staging.seudominio.com/webhook-mp` | App e credenciais **de teste** ou produção conforme o ambiente |

Passos:

1. Defina `MP_WEBHOOK_URL` no `.env` do ambiente (Coolify, etc.) ? ver também `docs/coolify-deploy.md`.
2. No painel MP, cadastre a mesma URL e selecione os tópicos **Pagamentos** e **Order** (Orders API).
3. Copie a **assinatura secreta** gerada pelo MP para `MP_WEBHOOK_SECRET` no `.env`.
4. Redeploy para aplicar `MP_WEBHOOK_SECRET`; sem ele o endpoint aceita notificações mas registra aviso nos logs.

`MP_WEBHOOK_URL` é referência para documentação/deploy ? o MP envia POST para a URL cadastrada no painel, não lê o `.env` automaticamente.

### Desenvolvimento local (túnel opcional)

O Mercado Pago **não** alcança `localhost`. Para testar webhook de ponta a ponta em dev:

1. Suba o backend local (`docker compose` na porta `APP_PORT`, padrão `8001`).
2. Exponha com um túnel HTTPS, por exemplo:
   - [ngrok](https://ngrok.com/): `ngrok http 8001`
   - [Cloudflare Tunnel](https://developers.cloudflare.com/cloudflare-one/connections/connect-apps/): `cloudflared tunnel --url http://localhost:8001`
3. Cadastre no painel MP a URL do túnel + `/webhook-mp`, ex.: `https://abc123.ngrok-free.app/webhook-mp`.
4. Atualize `MP_WEBHOOK_SECRET` no `.env` local com o secret do painel.
5. Faça um pagamento de teste (PIX ou cartão pendente) e confira os logs (`payments.webhook_received`).

**Sem túnel:** use apenas o **poll** no front ou simule webhook com testes (`MercadoPagoWebhookTest`) / `curl` assinado ? suficiente para a maior parte do desenvolvimento de checkout.

### Eventos suportados

| Evento no painel MP | Topico (`type`) | Acao no FichAqui |
|---------------------|-----------------|------------------|
| Pagamentos | `payment` | Sincroniza pedido e gera fichas se aprovado |
| Order (Mercado Pago) | `orders` / `order` | Idem (busca por `gateway_order_id` ou `gateway_payment_id`) |
| Alerta de fraudes | `stop_delivery_op_wh` | Registra log; responde `200` |
| Card Updater | `topic_card_id_wh` | Registra log; responde `200` |
| Pedidos comerciais | `topic_merchant_order_wh` | Registra log; responde `200` |
| Envios (Mercado Pago) | `shipment` / `shipments` | Registra log; responde `200` |

O controller valida `x-signature` quando `MP_WEBHOOK_SECRET` esta definido, extrai `data.id` (query ou body) e chama `PaymentSyncService::syncByGatewayPaymentId()` para pagamentos e orders.

Resposta sempre `200` com `{ "received": true }` (ou `401` se assinatura invalida).

## Banco de dados

Tabela `pedidos` ? campos de gateway:

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| `gateway_order_id` | string, nullable | ID da ordem MP (`ORD?`) |
| `gateway_payment_id` | string, nullable | ID do pagamento na ordem (`PAY?`) |
| `pix_copy_paste` | text, nullable | EMV / copia-e-cola do QR |
| `pix_qr_code` | text, nullable | Base64 (só driver `payments`) |
| `pix_expires_at` | timestamp, nullable | Expiração estimada |
| `payment_status` | enum | `pending`, `paid`, `failed` |

## Modos de QR (Mercado Pago)

| Modo | Comportamento | Config FichAqui |
|------|---------------|-----------------|
| `dynamic` | QR único por ordem em `type_response.qr_data` | Padrão (`MP_QR_MODE=dynamic`) |
| `static` | QR fixo do POS recebe valor da ordem | Requer `MP_QR_EXTERNAL_POS_ID` |
| `hybrid` | Static + frame dinâmico em paralelo | Requer POS + retorna `qr_data` |

Para eventos presenciais com QR impresso no caixa, use `static` ou `hybrid` e cadastre o POS no painel MP.

## Driver legado (`MP_PIX_DRIVER=payments`)

Mantido para compatibilidade. Usa `POST /v1/payments` com `payment_method_id: pix` e preenche `pix_qr_code` (base64) + `pix_copy_paste` a partir de `point_of_interaction.transaction_data`.

Poll e webhook usam apenas `gateway_payment_id` (sem `gateway_order_id`).

## Erros comuns (MP ? aplicação)

| HTTP MP | Código | Ação sugerida |
|---------|--------|---------------|
| 400 | `empty_required_header` | Garantir `X-Idempotency-Key` no gateway |
| 401 | `unauthorized` | Verificar `MP_ACCESS_TOKEN` |
| 404 | `pos_not_found` | Conferir `MP_QR_EXTERNAL_POS_ID` |
| 409 | `idempotency_key_already_used` | Usar nova chave por tentativa distinta |
| 422 | (app) MP não configurado | `MP_ACCESS_TOKEN` ausente em dev |

Erros de validação no checkout retornam `422` com mensagens em português.

## Uso no front (PIX)
Checkout: POST /api/events/{eventId}/pedidos com paymentMethod: "pix"
Exibir QR a partir de pixCopyPaste (gerar imagem com lib QR)
Poll: GET /api/payments/{paymentId}/status (aceita PAY? ou ORD?)
Quando status === "paid", exibir fichas

## Testes

```bash
cd FichAqui-BackEnd
docker compose exec api php artisan migrate --force
docker compose exec api php artisan test
```

- `tests/Unit/MercadoPagoGatewayTest.php` ? criação de ordem QR
- `tests/Feature/Adr0004HandoffTest.php` ? checkout PIX pendente, poll com aprovação

Os testes mockam `api.mercadopago.com/v1/orders` com `Http::fake()`.

## Referências

- [Create order ? Mercado Pago](https://www.mercadopago.com.br/developers/en/reference/orders/online-payments/create/post)
- ADR interno: `docs/adr/0004-pagamento-fichas-e-handoff-e2e.md`
- ADR SDK front: `docs/adr/0003-mercadopago-client-side-sdk.md`
