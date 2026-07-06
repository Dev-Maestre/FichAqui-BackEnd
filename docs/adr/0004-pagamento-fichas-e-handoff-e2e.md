# Pagamento confirmado antes das Fichas + handoff E2E

Decisões do grilling sobre `BACKEND-TODO.md` (jun/2026). O backend só emite **Fichas** após **pagamento confirmado**. Carteira e cartão aprovado na hora geram fichas no mesmo `POST /pedidos`; PIX pendente cria pedido sem fichas até aprovação via webhook ou poll idempotente.

## Checkout e pagamento

- Resposta do checkout: **objeto plano** (`id`, `status`, `paymentStatus`, `paymentId`, `pixQrCode`, `fichas`, ?) ? não envelope `{ order, payment }`.
- PIX/cartão tokenizado sem MP configurado: **422**; Carteira e `cardId` stub seguem funcionando em dev.
- CPF obrigatório no primeiro checkout via gateway (cartão/PIX); Carteira dispensa CPF.
- Status do pedido: `pending_payment` (PIX pendente), `payment_failed` (recusado/expirado), `available` (fichas emitidas), `delivered` (todas retiradas).

## Fulfillment assíncrono (PIX)

1. **Webhook** `POST /api/webhooks/mercadopago` ? caminho primário; ao `approved`, `PedidoFulfillmentService` gera fichas (idempotente).
2. **Poll** `GET /api/payments/{paymentId}/status` ? fallback: se MP retorna `approved` e ainda não há fichas, fulfillment na mesma requisição.

## Retirada

- **Atendente** (`stall_manager`): só consome fichas da barraca em `user.stall_id`.
- **Organizador** do evento: pode consumir em qualquer barraca do evento.

## Evento, cidade e assets

- Tabela `cidades` + `GET /api/cities`.
- `eventos`: `city_id`, `cidade`, `estado`, `latitude`, `longitude` (denormalizado); troca de `city_id` recopia `cidade`/`estado`.
- Coordenadas obrigatórias para eventos com data; opcionais para estabelecimentos.
- Imagens na API: `ASSET_URL` com fallback `APP_URL` no presenter.

## Relatórios

- **Receita do evento**: pedidos com pagamento confirmado, independente de retirada das fichas.

## Consequências

- ADR-0003 permanece válido para tokenização MP; este ADR fixa **quando** as fichas nascem e o contrato E2E com o front.
- Testes de consumo usam Carteira/cartão salvo ou simulam aprovação PIX; `POST` com PIX sem MP retorna 422.
