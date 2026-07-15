# Estoque por variante (não por barraca)

O estoque deixou de ser um saldo único por Barraca (`barracas.stock`) e passou a ser quantidade por variante ativa em cada Oferta (`oferta_variantes.stock`). O organizador configura preço e estoque no cardápio da barraca; o consumidor só vê **Esgotado** quando o estoque zera ? sem contagem de unidades restantes.

O estoque diminui somente após **pagamento confirmado** (mesmo gatilho da emissão de Fichas); pedidos com pagamento pendente não consomem estoque. Checkout com quantidade acima do disponível rejeita o pedido inteiro. Variante ativa exige preço e estoque maiores que zero; produto novo entra com todas as variantes inativas.

Na migração, variantes existentes começam com estoque **0** (forçando configuração explícita pelo organizador) em vez de um valor alto padrão que mascararia a transição. A coluna `stock` da barraca é removida.

**Considered options:** estoque por barraca (status quo); reserva no checkout para PIX pendente; migração com estoque alto (9999) para evitar ruptura.

**Consequences:** após deploy, organizadores precisam repor estoque item a item antes das vendas retomarem; API de offerings e checkout passam a validar e decrementar estoque com lock na confirmação de pagamento. No front do consumidor, o seletor de quantidade respeita o estoque disponível com aviso ao atingir o limite.
