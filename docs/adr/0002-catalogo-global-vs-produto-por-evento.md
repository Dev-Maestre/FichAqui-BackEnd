# Catálogo global vs produto por evento

## Contexto

A API inicial modelava cardápio como `produtos` e `sub_produtos` por evento. O front-end e o guia de integração passaram a tratar **Produto de catálogo** (plataforma) e **Oferta** (precificação por barraca/evento) como conceitos distintos.

## Decisão

1. **Catálogo global** (`categorias`, `catalogo_produtos`, `variant_templates`) é a fonte de verdade para nome, imagem e templates de variante.
2. **Ofertas** (`ofertas`, `oferta_variantes`) referenciam o catálogo e definem preço/disponibilidade por barraca.
3. Tabelas legadas `produtos` e `sub_produtos` foram removidas na Fase 11; checkout e fichas usam apenas ofertas.
4. `GET /api/bootstrap` e `GET /api/catalog` expõem somente o catálogo global; eventos, barracas e pedidos são carregados por rotas dedicadas.

## Consequências

- Organizadores montam cardápio via `PUT /api/events/{eventId}/stalls/{stallId}/offerings`, não criando produtos ad hoc.
- Seeders (`OfferingSeeder`) definem ofertas explicitamente a partir do catálogo.
- Criação de evento gera barraca padrão com oferta de boas-vindas (`item-boas-vindas`).

## Alternativas consideradas

- **Big bang**: migrar e remover legado na mesma release ? rejeitado; convivência temporária reduziu risco.
- **Manter produtos por evento**: duplicaria dados do catálogo e divergiria do contrato do front.
