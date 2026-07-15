# Staging demo seeder

Popula o ambiente de **staging** com pedidos, fichas e recargas em estados fixos para testar todas as telas do app sem depender do Mercado Pago a cada deploy.

> **Aviso:** nunca rode `StagingDemoSeeder` em producao com dados reais de clientes.

## Pre-requisitos

O deploy ja executa `php artisan db:seed` (usuarios, eventos, ofertas, carteira base). O snapshot demo e um passo **manual** adicional.

## Comandos

```bash
# 1. Estrutura base (automatico no deploy, ou manual)
php artisan db:seed

# 2. Snapshot transacional demo (manual, idempotente)
php artisan db:seed --class=StagingDemoSeeder
```

Rodar o passo 2 de novo reseta o snapshot demo ao estado conhecido (fichas, status, saldo coerente com os debitos seedados).

## Contas de teste

| Email | Senha | Papel |
|-------|-------|-------|
| `maria@testuser.com` | `123456` | Cliente |
| `raul@paroquia.com` | `123456` | Organizador |
| `atendente@email.com` | `123456` | Atendente (`stall-1`) |

## Inventario demo

Todos os pedidos pertencem a Maria no evento `1` (Festa de Sao Joao).

| ID | Pagamento | Status pedido | Fichas | Feature coberta |
|----|-----------|---------------|--------|-----------------|
| `pedido-demo-wallet` | Carteira (`paid`) | `available` | 2× `available` | Scanner, `/user/fichas` |
| `pedido-demo-parcial` | Carteira (`paid`) | `available` | 1× `available`, 1× `delivered` | Admin `fichaCounts` misto |
| `pedido-demo-entregue` | Carteira (`paid`) | `delivered` | 2× `delivered` | Historico completo |
| `pedido-demo-card` | Cartao (`paid`) | `available` | 1× `available` | Historico com cartao |
| `pedido-demo-pix` | PIX (`pending`) | `pending_payment` | 0 | Tela PIX + poll |
| `pedido-demo-recusado` | Cartao (`failed`) | `payment_failed` | 0 | Pedido recusado |
| `recarga-demo-pix` | PIX top-up (`pending`) | — | — | Recarga carteira pendente |

Credito inicial na carteira: `recarga-demo-inicial` (R$ 80 via ledger, idempotente).

## QRs para o scanner (atendente)

| QR | Pedido | Estado da ficha |
|----|--------|-----------------|
| `QR-DEMO-PASTEL-01` | `pedido-demo-wallet` | `available` |
| `QR-DEMO-PASTEL-02` | `pedido-demo-wallet` | `available` |
| `QR-DEMO-PASTEL-03` | `pedido-demo-parcial` | `available` |
| `QR-DEMO-PASTEL-04` | `pedido-demo-parcial` | `delivered` (ja consumida) |
| `QR-DEMO-PASTEL-05` | `pedido-demo-card` | `available` |

## Smoke test

1. Login Maria → `GET /api/user/fichas` → 4 fichas `available`
2. Login atendente → `GET /api/fichas?qr=QR-DEMO-PASTEL-01` → consumir
3. Login organizador → `GET /api/events/1/pedidos` → ver contagem mista em `pedido-demo-parcial`
4. Login Maria → `GET /api/user/pedidos` → ver PIX pendente e cartao recusado
5. Checkout real com MP sandbox → fluxo fresh (nao coberto pelo seed)

## Saldo da carteira

Apos o seed, o saldo da Maria reflete:

- Saldo do `WalletSeeder` (R$ 46)
- Credito demo idempotente (R$ 80)
- Debitos dos 3 pedidos com carteira (R$ 48 total)

Saldo esperado: **R$ 78,00** (pode variar se houve movimentacao manual entre execucoes).
