# FichAqui

Plataforma de fichas e cardápio para festas juninas, quermesses e estabelecimentos paroquiais.

## Language

**Evento**:
Um local ou ocasião com data e horário definidos (festa, quermesse, celebração). Referencia uma Cidade (`city_id`) e guarda `cidade`, `estado` e Coordenadas denormalizados para exibição e mapa.
_Avoid_: Festa (quando for genérico), show, appointment

**Estabelecimento**:
Um ponto fixo de operação sem data de início/fim ? o mesmo registro de Evento, sem `date`, `start_time` e `end_time`.
_Avoid_: Loja, venue, local fixo

**Barraca**:
Ponto de venda dentro de um Evento ou Estabelecimento, operado por um responsável.
_Avoid_: Stall, loja, ponto

**Atendente**:
Usuário autorizado a operar a retirada numa Barraca específica. Só pode consumir Fichas da sua Barraca; o organizador do Evento pode consumir em qualquer Barraca do evento.
_Avoid_: stall_manager (no vocabulário de negócio), operador

**Produto**:
Item ofertado no cardápio (Pastel, Ingresso, Pescaria).
_Avoid_: MenuProduct, item, SKU

**Produto de catálogo**:
Cadastro base da plataforma (nome, imagem, categoria, templates de variante). O organizador não cria produtos ? apenas seleciona do catálogo.
_Avoid_: CatalogProduct (no vocabulário de negócio), SKU global

**Categoria**:
Agrupamento visual do catálogo global (Comidas, Doces, Bebidas).
_Avoid_: Category, tag, grupo

**Template de variante**:
Variação pré-definida de um Produto de catálogo (Carne, Queijo, Unidade). O preço e a disponibilidade são definidos na Oferta.
_Avoid_: Variant, SKU filho

**Oferta**:
Precificação e disponibilização de um Produto de catálogo em uma Barraca durante um Evento. Referencia o catálogo global; não duplica nome ou imagem do produto.
_Avoid_: Offering, menu item, SKU por evento

**Ficha**:
Unidade individual de retirada criada somente após confirmação do pagamento do Pedido. `quantity: N` gera N fichas com QR único; consumo marca uma ficha por vez.
_Avoid_: Ticket, voucher, comprovante

**Carteira**:
Saldo pré-pago do Usuário na plataforma, usado como método de pagamento no checkout. Débito bem-sucedido no checkout equivale a pagamento confirmado na hora.
_Avoid_: Wallet, conta, créditos

A**Cartão salvo**:
Cartão tokenizado do Usuário, reutilizável em checkout e recarga sem redigitar número completo. O backend persiste metadados de exibição (bandeira, últimos 4, titular) e a referência do gateway (`gateway_token` = `card_id` do Customer no Mercado Pago). Na API autenticada do próprio Usuário, a referência do gateway pode ser exposta como `mercadoPagoCardId` para re-tokenização no browser — não é dado de cartão nem segredo de pagamento. **Nunca** persistir ou expor: PAN, CVV, validade completa, `cardToken` one-shot, `ACCESS_TOKEN` do MP.
_Avoid_: Cartão cadastrado, payment method, wallet card

**Pagamento confirmado**:
Momento em que o valor foi liquidado ? débito de Carteira, aprovação imediata do gateway ou confirmação assíncrona (ex.: PIX). É o gatilho para emissão das Fichas.
_Avoid_: Approved, paid, settled

**Sub-produto** *(removido)*:
Modelo legado por evento (`produtos`/`sub_produtos`). Substituído por Oferta + template de variante. Ver ADR-0002.

**Usuário**:
Pessoa que acessa o sistema como cliente e/ou organizador. CPF é exigido antes do primeiro checkout via gateway (cartão/PIX); compra com Carteira não exige CPF.
_Avoid_: User (no vocabulário de negócio), account

**Pedido**:
Compra em um Evento ou Estabelecimento, identificada por número. Enquanto o pagamento não é confirmado, o Pedido existe sem Fichas; após confirmação, gera as Fichas para retirada independente.
_Avoid_: Order, compra, transação

**Pagamento pendente**:
Estado em que o Pedido foi criado mas o gateway ainda não confirmou o pagamento — PIX aguardando transferência ou cartão em análise/processamento no Mercado Pago. Nenhuma Ficha é emitida até **pagamento confirmado** (webhook ou poll).
_Avoid_: Pending order, aguardando

**Pagamento recusado**:
Estado em que o gateway negou ou expirou o pagamento. O Pedido não gera Fichas e não fica disponível para retirada.
_Avoid_: Payment failed, falha de checkout

**Arquivado**:
Estado de um Evento ou Estabelecimento retirado da operação ativa; o registro permanece no sistema para histórico, mas não aparece para o público.
_Avoid_: Excluído, deletado, removido

**Cidade**:
Município de referência de um Evento, usado para filtrar a listagem na home. Catálogo fixo servido por `GET /api/cities`. Ao alterar a Cidade de um Evento, `cidade` e `estado` são recopiados do catálogo; rótulos customizados só valem até a próxima troca de Cidade.
_Avoid_: City, município (quando genérico fora do domínio)

**Coordenadas**:
Par latitude e longitude do ponto do Evento no mapa (Google Maps), associado ao endereço em `location`. Obrigatórias para Eventos com data; opcionais para Estabelecimentos.
_Avoid_: Lat/lng, geolocation, pin

**Receita do evento**:
Soma dos Pedidos com pagamento confirmado (exclui pagamento pendente e recusado), independente de as Fichas já terem sido retiradas.
_Avoid_: Revenue, faturamento líquido, GMV
