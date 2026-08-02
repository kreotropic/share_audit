<!--
  - SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

# Share Audit Dashboard — Roadmap

## Estado atual (v0.4.0)

A app está **publicada na App Store** (`min-version` 31, `max-version` 34) e
funcionalmente completa: três rondas de revisão (segurança, pré-submissão e
auditoria de qualidade linha a linha) foram executadas e fechadas antes da
0.3.0 — ver [CHANGELOG.md](CHANGELOG.md) para o detalhe do que corrigiram em
cada versão. A 0.4.0 acrescentou o soft delete (reciclagem) de partilhas e
suporte a Nextcloud 34. A app tem suite de testes (`phpunit`, `tests/Unit/`,
73 testes) e CI (`.github/workflows/ci.yml`: l10n, php, frontend). Tudo o
que se segue já está implementado e a funcionar:

### Entregue

**Dashboard (Painel)**
- Contadores por tipo de partilha (cards clicáveis → abrem "All shares" já filtrado)
- Tendência de criação de partilhas (últimos 12 meses)
- Donut Interno vs Externo + Top sharers
- Secção **Exposure** embutida: score 0–100, exposição por alcance
  (interno / externo / público) com drill-down por categoria, e ranking de
  maior exposição pública

**All shares**
- Tabela de todas as partilhas da instância
- Filtros nos cabeçalhos das colunas (tipo, path, owner, destinatário,
  password, expiração), ordenação e paginação **server-side**
- Exportação **CSV** da vista filtrada (respeita os filtros ativos)
- Ordenação determinística entre MySQL/MariaDB e PostgreSQL (0.4.0)

**Security alerts**
- Deteção de links públicos sem password, sem expiração, a expor tipos de
  ficheiro sensíveis, já expirados / a expirar em breve, com upload anónimo
  sem password (file drop), e partilhas de grupo com edit/reshare para grupos
  grandes — com **regras configuráveis** (tab Settings)
- Breakdown por categoria (gráfico de barras)
- Ações individuais e em **bulk**: gerar password, definir expiração (7/30/90d),
  revogar. Passwords geradas mostradas uma única vez.
- Copiar URL do link público e "Open in Files" em cada alerta
- Todas as revogações e remediações ficam registadas no canal de auditoria do
  Nextcloud (requer a app `admin_audit` ativa)

**Lookup & Orphans**
- **Orphan shares**: partilhas cujo owner está desativado ou eliminado, com
  bulk revoke e badge no dashboard
- **Access lookup** (drill-down reversa): pesquisa por utilizador, grupo ou
  email e lista **todos os ficheiros/pastas a que esse destinatário acede**,
  com *revoke all access* (em lotes server-side de 500)

**Deleted shares — reciclagem (0.4.0)**
- Revogar (nesta app, ou nativamente via Files/`occ`/OCS API) já não é
  irreversível: a partilha fica retida (TTL configurável em Settings, 30
  dias por defeito) numa tab "Deleted shares" antes do purge definitivo
- **Restaurar** (recria a partilha e preserva, best-effort, o token/URL do
  link público original) ou **eliminar permanentemente**, individualmente
  ou em bulk
- Purge diário automático (`TimedJob`) das entradas expiradas
- Primeira migration de base de dados da app (`oc_shareaudit_deleted`)

**Vista pessoal (Personal settings → My shares audit)**
- Cada utilizador audita e corrige as suas próprias partilhas de risco
- **Widget** no dashboard do Nextcloud com os links que precisam de atenção
- Toggle no admin (tab Settings) para desativar esta vista e o widget em toda
  a instância, para quem prefere manter a auditoria de partilhas como
  assunto exclusivo de administração

**Publicação**
- i18n **EN + pt‑PT** (com `build/l10n.py` para gerar os bundles frontend;
  `--check` corre no CI e como parte do `krankerl package`, falha o build em
  vez de depender de disciplina)
- README, screenshots, `krankerl.toml` + `.nextcloudignore` para packaging
- `min-version` 31 (NC 30 já não é suportado — o revoke de órfãs depende de
  um parâmetro que só existe a partir do NC 31), `max-version` 34

---

## Próxima iteração — G2: acknowledge/exceção nos alertas

O item de maior impacto que resta, e **não** depende de tração na App Store.

**Problema:** na prática, todas as instâncias têm links públicos
intencionalmente sem password (página pública, newsletter). Sem forma de
marcar "isto é aceite", o contador de alertas nunca chega a zero — e um
contador permanentemente vermelho deixa de ser olhado ao fim de ~2 semanas.

**Correção:** nova tabela `oc_shareaudit_ack` (`share_id`, `rule_code`,
`acknowledged_by`, `acknowledged_at`, `note` opcional). `getAlerts()` passa a
excluir (ou a marcar como "aceite", com filtro para mostrar/esconder) os pares
`(share_id, rule_code)` presentes na tabela. Precisa de:
- `AckController` (`POST /api/alerts/{id}/ack`, `DELETE` para remover a
  exceção), admin-only.
- UI: botão "Aceitar" por alerta + linha, e um filtro "mostrar aceites" na
  vista de alertas (para auditoria — não desaparecem, só saem da contagem
  ativa).
- Precisa de migration própria (`lib/Migration/`) — será a **segunda**
  migration da app (a primeira, `oc_shareaudit_deleted`, já foi entregue em
  0.4.0 com o soft delete).
- Tem de cobrir **todas** as regras atuais, incluindo as duas mais recentes
  (`group_share_editable`, `public_upload`), não só as três originais.
- Reutilizar o padrão de testes já existente em `tests/Unit/` para a nova
  lógica de `acknowledged`.

**Esforço/impacto:** médio esforço, alto impacto — nenhuma ferramenta nativa
do NC oferece isto.

---

## Pós-lançamento — só se houver tração

Estas features ficam **em espera até a app ganhar tração na App Store**. Estão
ordenadas por impacto. As specs técnicas ficam registadas para não se perder o
raciocínio já feito.

| # | Feature | Depende de | Esforço | Impacto |
|---|---------|-----------|---------|---------|
| 1 | Transferir ownership (órfãs) | — | 2-3 dias | Médio+ |
| 2 | Notificar o owner (alertas e remediações) | — | 1-2 dias | Médio |
| 3 | Histórico/trend de exposição | — | 2-3 dias | Médio |
| 4 | Digest semanal por email para admins | — | 2-3 dias | Médio |
| 5 | Relatórios de compliance por email | (3) | 3-4 dias | Médio |
| 6 | Políticas por grupo | — | 4-5 dias | Médio |
| 7 | Relatório PDF/HTML assinado (auditorias externas) | — | 3-4 dias | Médio- |

---

### 1. Transferir ownership de partilhas órfãs

Já existe a deteção e o bulk revoke; falta a alternativa **não destrutiva**:
reatribuir a partilha a outro utilizador quando alguém sai e um colega assume
o trabalho (inspiração de UX: `occ files:transfer-ownership`).

- `OrphanShareService::transferShare(shareId, newOwnerId)` — atualiza
  `uid_owner` e `uid_initiator` na `oc_share`
- Verificar que o novo owner tem acesso ao ficheiro (via `filecache`, grupo,
  ou external storage)
- `POST /api/orphans/transfer` + modal de seleção de utilizador destino
- **LDAP/AD:** utilizadores desativados no AD podem aparecer como *enabled* no
  Nextcloud se o sync não mapear o estado — documentar e considerar dupla
  verificação
- **Performance:** em instâncias com muitos utilizadores eliminados, considerar
  um background job diário a popular uma tabela de cache de órfãs

---

### 2. Notificar o owner (alertas e remediações)

Duas vertentes, a fazer juntas:

**a) Ação "Notify" nos alertas.** Terceira ação para o alerta *"Sensitive
file type"*, onde revogar ou pôr password pode ser demasiado agressivo:
avisar quem partilhou.
- `POST /api/shares/{id}/notify` → `INotificationManager::notify()` ao `uid_owner`
- Adicionar `"Notify all owners"` às bulk actions
- Usar a API nativa de notificações (aparece na interface do Nextcloud, não só
  por email)

**b) Notificar automaticamente em qualquer remediação de admin.** Hoje,
**qualquer** remediação feita pelo admin (`setPassword`, `setExpiration`,
`revoke` em `ShareActionController`) muda a partilha de outra pessoa sem
aviso — o dono ganha uma password que não conhece, ou perde o link sem
explicação.
- `INotificationManager::notify()` ao `uid_owner` em **toda** ação de
  `ShareActionController`, com mensagem específica por ação ("O administrador
  definiu uma password na tua partilha X" / "...alterou a expiração..." /
  "...revogou...").
- Ação alternativa **"pedir ao dono para corrigir"** em vez de o admin corrigir
  diretamente — notificação com deep-link para a vista pessoal do próprio
  dono. É o que muda a app de "ferramenta de polícia" para "ferramenta de
  governança".

Fazer depois do G2 (acknowledge), para reutilizar a mesma UI de ações em
alertas que o G2 vai mexer.

---

### 3. Histórico / trend de exposição

A secção Exposure mostra o estado **atual**. Falta a evolução ao longo do tempo.

- Tabela `oc_shareaudit_exposure_history` com snapshots diários
- Background job a gravar os contadores por categoria
- `ExposureMapService::getExposureTrend(days)` + gráfico de linha na vista

> Não é possível reconstruir retroativamente a partir da `oc_share`: as
> partilhas revogadas desaparecem (ou, desde 0.4.0, vão para a reciclagem —
> mas essa não é uma série temporal agregada). Por isso os snapshots são
> necessários.

Justificação de negócio para priorizar isto cedo: custa pouco e cria o
argumento "estamos a melhorar" para mostrar à gestão.

---

### 4. Digest semanal por email para admins

Distinto do #5 (que é mais formal/periódico e depende do histórico do #3).
Este é um digest leve e frequente: `TimedJob` semanal + `IMailer`, resumindo
**novos** links inseguros, **novas** órfãs, e evolução do score desde o último
digest. É o que faz a app continuar a ser usada depois da segunda semana,
mesmo antes do histórico completo (#3) existir — pode arrancar comparando só
com o snapshot da semana anterior, sem esperar pela série temporal completa.

Fazer depois do G2/G3, para que o digest já reflita alertas "aceites" (não
faz sentido mandar email semanal sobre algo que o admin já marcou como
exceção). Implementar antes ou em paralelo com o #5, não depois.

---

### 5. Relatórios de compliance por email

Envio agendado de um resumo periódico (links inseguros, órfãs, score de
exposição) aos administradores. O `ReportService` atual só gera o CSV da lista —
seria estendido para produzir o relatório e um `TimedJob` para o enviar.
Beneficia do histórico da feature 3 para mostrar deltas ("+12 links públicos
desde o último relatório").

---

### 6. Políticas por grupo

Alertas hoje são regras globais (`SettingsService::RULES` aplica-se à
instância inteira). A proposta é permitir associar regras/exceções a grupos
específicos — ex.: o grupo `Finance` nunca pode ter links públicos sem
password, independentemente da regra global.

Esboço:
- Tabela `oc_shareaudit_group_policy` (`group_id`, `rule_code`, `mode`:
  `enforce`/`forbid`/`inherit`).
- `SecurityAnalyzerService::issuesFor()` passa a resolver a regra efetiva
  cruzando `owner`/`uid_initiator` com os grupos do utilizador (via
  `IGroupManager::getUserGroupIds()`) antes de cair no default global.
- UI: nova secção em Settings, "Políticas por grupo", com seletor de grupo +
  regras.

**Esforço:** maior que os itens acima (nova tabela + resolução de precedência
grupo vs. global + UI de gestão). Nenhuma ferramenta NC nativa faz isto
visualmente — é um diferenciador real, mas não é quick win.

---

### 7. Relatório PDF/HTML assinado, para compliance/auditorias externas

O CSV atual (`ReportService`) é para o admin trabalhar os dados; um relatório
formatado — cabeçalho com nome da instância, data/hora de geração, período
coberto, resumo executivo (contagens, score, top exposições) e uma
assinatura/hash simples de integridade — é para entregar a um auditor externo.

Esboço mínimo: gerar HTML server-side (template dedicado) com os agregados já
calculados por `ShareCollectorService`/`SecurityAnalyzerService`/`ExposureMapService`,
e converter para PDF (avaliar se vale a pena trazer uma dependência de
PDF-rendering ou se um HTML standalone com print stylesheet é suficiente para
o caso de uso — decisão a tomar antes de implementar, não assumir biblioteca
já). Tal como no CSV, o relatório não deve incluir tokens de acesso.

---

## Backlog menor

- ~~Screenshots com dados de demonstração limpos~~ — feito (2026-08-02):
  todos os 7 screenshots refeitos contra a instância de dev real, com dados
  de partilhas realistas e a UI atual (incluindo a tab "Deleted shares" da
  0.4.0); o widget do dashboard passou a mostrar a página inteira em vez de
  um recorte isolado do cartão, para consistência com o resto do conjunto.
- **`build/l10n.py` só varre `src/` — regressão, não gap novo.** Isto tinha
  sido corrigido a 2026-07-11 (extensão a `lib/**/*.php` para apanhar
  `IL10N->t()`/`->n()` do lado do backend), mas esse fix nunca chegou a
  ficar no GitHub — era um dos commits só-locais descartados no realinhamento
  de 2026-07-15 (`git reset --hard origin/master`), e o histórico paralelo
  do GitHub não o reintroduziu. Confirmado a 2026-08-02: `lib/Settings/
  AdminSection.php`, `lib/Settings/PersonalSection.php` e `lib/Dashboard/
  MyAlertsWidget.php` continuam a usar `IL10N->t()` sem cobertura do script.
  Refazer a extensão do glob a `lib/**/*.php`.
- **Streaming do export CSV** — `ShareCollectorService::getAllForExport()`
  materializa até 100k linhas em memória antes de responder
  (`ReportService::buildCsv()`). Trocar por um `StreamResponse` (ou callback
  de streaming do AppFramework) que itera por chunks (ex.: 1000 linhas via
  `findShares($filters, 1000, $offset)` em loop) e escreve diretamente no
  output. Adiado (2026-07-09): maior esforço, sem evidência real de
  instâncias com dezenas de milhares de partilhas — revisitar quando essa
  evidência existir.
- Falta índice em `share_with` (autocomplete/recipient search, `ILIKE %...%`)
  e em `path` (ordenação). Numa instância de ~300 users é tolerável (dezenas
  de milhares de linhas); decisão adiada até haver evidência de instâncias
  maiores. Quando justificar, adicionar via migration — coordenar com o G2
  (acknowledge), que já vai precisar de uma migration de qualquer forma.
