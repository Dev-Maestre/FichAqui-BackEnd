# Deploy no Coolify

## Porta da API

O FichAqui usa **`APP_PORT=8001`** por padrao (nao usa 8000, reservada a outro servico no servidor).

No painel do servico **api** no Coolify:

- **Port Exposes / Porta exposta**: `8001` (ou o valor de `APP_PORT` no `.env`)
- **Dominio**: URL publica do backend (ex.: `https://api.seudominio.com`)

O `docker-compose.yml` nao publica porta no host (`0.0.0.0`); o proxy do Coolify encaminha para a porta interna do container.

## Variaveis no Coolify (`.env`)

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.seudominio.com
APP_PORT=8001
FRONTEND_URL=https://seudominio.com

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=fich_aqui
DB_USERNAME=fich_aqui
DB_PASSWORD=<senha-forte>

APP_KEY=base64:...

MP_ACCESS_TOKEN=...
MP_PUBLIC_KEY=...
MP_WEBHOOK_URL=https://fichaqui.baiacubo.tech/webhook-mp
MP_WEBHOOK_SECRET=<secret-do-painel-mp>
MP_SANDBOX=false
MP_PIX_DRIVER=online
```

Cadastre `MP_WEBHOOK_URL` no painel Mercado Pago (Webhooks) com os tópicos **Pagamentos** e **Order**. Uma URL fixa por ambiente ? ver `docs/integrations/mercadopago-qr-orders.md#webhooks`.

**Nao use** `DB_HOST=127.0.0.1` nem `DB_PORT=5433` no Coolify.

## Redeploy

1. Push das alteracoes (inclui `Dockerfile` com `COPY` do projeto)
2. Confirme `APP_PORT=8001` no `.env` do Coolify
3. Confirme **Port Exposes = 8001** no servico
4. **Redeploy com rebuild** (Coolify: "Force rebuild" se o erro persistir)

## Desenvolvimento local

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
```

API em `http://localhost:8001`, Postgres em `localhost:5433`.
