# Evento e Estabelecimento na mesma tabela

Evento e Estabelecimento compartilham a tabela `eventos`. Um registro sem `date`, `start_time` e `end_time` é um Estabelecimento; com esses campos preenchidos, é um Evento. A API transforma o registro para o formato que o front-end espera (`Event` com datas), omitindo ou enviando strings vazias quando for Estabelecimento. Isso evita duplicar entidades e reduz o número de tabelas.
