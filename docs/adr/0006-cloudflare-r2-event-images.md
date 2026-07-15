---
status: accepted
---

# Imagens de Evento no Cloudflare R2

Decisões do grilling (jul/2026). Imagens de **Evento** (banner e ícone — mesma imagem, ver front) passam a ser armazenadas em **Cloudflare R2** em staging/produção. O banco guarda a **chave do objeto**; a URL pública é montada na API via `AssetUrl` + `ASSET_URL`. O catálogo global de produtos fica fora desta fase.

## Escopo

- **Fase 1:** upload de capa de evento pelo organizador.
- **Fase 2 (fora deste ADR):** catálogo global; backfill das URLs Picsum atuais.

## Upload

- **Produção/staging:** presigned URL (browser faz `PUT` direto no R2; backend não proxya bytes).
- **Desenvolvimento:** disco Laravel `public` (sem credencial R2 no `docker compose up`).
- **UI:** seletor de arquivo + preview + remover; sem campo de URL manual.
- **Momento:** upload ao selecionar o arquivo; `PATCH /api/events/{id}` com a chave assim que o upload concluir (não espera “Salvar evento” para os demais campos).
- **Criação:** `POST` cria o evento sem imagem; upload na tela de edição após redirect.

## Objeto e banco

- **Chave:** `events/{eventId}/{ulid}.{ext}` (versionada; evita cache stale ao trocar capa).
- **Campos:** mesma chave em `banner` e `icon` (`EventImageSync`).
- **Legado:** URLs absolutas externas (ex. Picsum) continuam válidas — `AssetUrl` não reescreve `http(s)://`.
- **Limpeza:** ao trocar ou remover, apaga o objeto anterior se a chave for do prefixo `events/` (não apaga URLs externas).

## Arquivo aceito

JPEG, PNG, WebP, GIF; máximo **5 MB**; sem redimensionamento nem conversão no servidor nesta fase.

## Infraestrutura

- **Um bucket R2 por ambiente** (`dev` usa disco local, não bucket compartilhado).
- **Acesso público:** URL `r2.dev` do Cloudflare por enquanto (sem domínio customizado `assets.*`).
- **Laravel:** disco S3-compatible apontando para endpoint R2; `ASSET_URL` = URL pública do bucket do ambiente.

## Considered options

| Opção | Por que não |
|-------|-------------|
| Upload via backend (multipart) | Tráfego de imagem no PHP; pior para escala e timeout. |
| URL completa no banco | Troca de domínio/CDN exige migração de dados. |
| Bucket único com prefixo `dev/`/`prod/` | Risco de apagar ou expor asset errado entre ambientes. |
| Chave fixa `events/{id}/cover.ext` | Cache de browser/CDN pode servir capa antiga após troca. |
| Domínio customizado já na fase 1 | Adia setup DNS; `r2.dev` basta para MVP. |
| Otimização WebP no servidor | Complexidade extra; adiar para fase 2. |

## Consequências

- Novas rotas: presign de upload e delete condicional no update de evento; autorização = organizador dono do evento.
- `league/flysystem-aws-s3-v3` necessário se ainda não estiver no projeto.
- Fichas e snapshots de pedido **não** mudam nesta fase — continuam copiando URL/path do produto no checkout.
- ADR-0004 menciona `ASSET_URL`; este ADR define **onde** os bytes de evento vivem e **o que** persiste no banco.
- Objetos órfãos (upload abandonado antes do PATCH) podem existir; limpeza por lifecycle/cron fica como melhoria futura.

## CORS no bucket R2

O browser envia a imagem com `PUT` direto no endpoint R2 (presigned URL). O bucket precisa de política CORS permitindo o **origin do frontend** (`FRONTEND_URL`), métodos `PUT` e `GET`, e header `Content-Type`. Sem isso o browser falha com *Failed to fetch* antes do upload.

Exemplo (Cloudflare R2 → bucket → Settings → CORS):

```json
[
  {
    "AllowedOrigins": ["http://localhost:3000", "https://seu-dominio.com"],
    "AllowedMethods": ["GET", "PUT", "HEAD"],
    "AllowedHeaders": ["*"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3600
  }
]
```
