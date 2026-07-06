# Checklist de integração ? FichAqui FrontEnd

Substituir mocks e dados locais pelas rotas reais da API.

## Autenticação

- [ ] `POST /api/auth/login` ? persistir token Sanctum
- [ ] `GET /api/auth/me` ? usar `role` (maior privilégio) + `roles[]`
- [ ] `POST /api/auth/logout`

## Catálogo e bootstrap

- [ ] `GET /api/bootstrap` ou `GET /api/catalog` ? `categories` + `catalogProducts`
- [ ] Remover dependência de `menuProducts` / `orders` no bootstrap

## Eventos e barracas

- [ ] `GET /api/events` ? filtros `public_only`, `organizer_id`, etc.
- [ ] `POST /api/events` ? resposta com `event`, `stalls`, `offerings` (não `menuProducts`)
- [ ] `GET /api/events/{eventId}/stalls`
- [ ] `POST/PATCH` barracas (organizador)

## Cardápio por barraca

- [ ] `GET /api/events/{eventId}/offerings`
- [ ] `PUT /api/events/{eventId}/stalls/{stallId}/offerings`
- [ ] Remover chamadas a `GET .../menu-products`

## Checkout e fichas (consumidor)

- [ ] `GET /api/payments/config` ? inicializar `MercadoPago.js` com `publicKey`
- [ ] `GET /api/user/wallet`
- [ ] `POST /api/events/{eventId}/pedidos` ? `offeringId` + `variantId`; cartão com `cardToken` (MP.js) ou `cardId`
- [ ] `GET /api/user/pedidos?include_fichas=true`
- [ ] `GET /api/user/fichas`

## Operação na barraca

- [ ] `GET /api/fichas?qr={qrCode}` ? lookup para scanner
- [ ] `PATCH /api/fichas/{fichaId}/status` ou `POST .../consume`
- [ ] Remover `PATCH /api/pedidos/{id}/status`

## Painel admin

- [ ] `GET /api/events/{eventId}/pedidos` ? auth organizador; DTO com `fichaCounts`

## Variáveis de ambiente sugeridas

```env
NEXT_PUBLIC_API_URL=http://localhost:8080/api
```

## Smoke test manual

1. Login consumidor ? wallet
2. Listar offerings do evento ? checkout com 2 unidades
3. Ver fichas em `/user/fichas`
4. Login atendente ? consumir fichas uma a uma
5. Login organizador ? listar pedidos do evento com status `delivered`
