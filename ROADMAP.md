<!--
  - SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

# Share Audit Dashboard — Roadmap

## Estado atual (v0.3.0)

A app está **funcionalmente completa e pronta para a App Store**: três rondas
de revisão (segurança, pré-submissão e auditoria de qualidade linha a linha)
foram executadas e fechadas — ver [CHANGELOG.md](CHANGELOG.md) (0.3.0) para o
detalhe do que corrigiram (cache de alertas, `min-version` 31, lotes no
revoke-all, exposure score, rate limits, etc.). A app tem suite de testes
(`phpunit`, `tests/Unit/`) e CI (`.github/workflows/ci.yml`: l10n, php,
frontend). Tudo o que se segue já está implementado e a funcionar:

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
  um parâmetro que só existe a partir do NC 31)

> ⚠️ **Nota importante:** as revogações são **permanentes** — a partilha
> desaparece da `oc_share` (mas fica registada no audit log). O soft delete
> (abaixo) é o que resolve isto.

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
- Precisa de migration própria (`lib/Migration/`) — o branch
  `feature/soft-delete-shares` (#1 abaixo) já traz a primeira migration da
  app; coordenar para não fazer duas migrations em releases consecutivas.
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
| 1 | Soft delete de partilhas — **já implementado** no branch `feature/soft-delete-shares`, por integrar | — | feito | Alto |
| 2 | Transferir ownership (órfãs) | — | 2-3 dias | Médio+ |
| 3 | Notificar o owner (alertas e remediações) | — | 1-2 dias | Médio |
| 4 | Histórico/trend de exposição | — | 2-3 dias | Médio |
| 5 | Digest semanal por email para admins | — | 2-3 dias | Médio |
| 6 | Relatórios de compliance por email | (4) | 3-4 dias | Médio |
| 7 | Políticas por grupo | — | 4-5 dias | Médio |
| 8 | Relatório PDF/HTML assinado (auditorias externas) | — | 3-4 dias | Médio- |

---

### 1. Soft delete de partilhas (reciclagem)

> ✅ **Implementado e verificado (2026-07-14) no branch
> `feature/soft-delete-shares`** — primeira migration da app
> (`oc_shareaudit_deleted`), listener em `BeforeShareDeletedEvent` (apanha
> também as eliminações feitas fora da app), restore com preservação de
> token/password, tab "Deleted shares", purge diário (TimedJob) e retenção
> configurável em Settings. Fica fora do 0.3.0; integrar num 0.4.0 depois da
> submissão inicial à App Store.

**Problema.** Revogar uma partilha é irreversível — desaparece da `oc_share`.
Se o admin ou o utilizador se arrepende, tem de recriar manualmente. O issue
#50734 descreve um utilizador a editar a base de dados diretamente para
recuperar links expirados. O audit log já existente é a rede de segurança
mínima (fica registado quem revogou o quê), mas não permite restaurar.

**Solução.** Em vez de eliminar imediatamente, mover para uma tabela de retenção
com TTL configurável (30/60/90 dias). O acesso é cortado de imediato, mas os
dados ficam preservados numa vista "Recently deleted shares", com **Restaurar**
ou **Eliminar permanentemente**. Um background job diário faz o purge.

**Nova tabela** `oc_shareaudit_deleted`: `original_share_id`, `share_type`,
`share_with`, `uid_owner`, `uid_initiator`, `item_type`, `file_source`,
`file_target`, `permissions`, `token`, `password`, `expiration`, `stime`,
`deleted_at`, `deleted_by`, `purge_after`, `note`.

**Backend:** `SoftDeleteService` (softDelete / restore / permanentDelete /
purgeExpired), `PurgeDeletedSharesJob` (TimedJob diário),
`SoftDeleteController` (GET lista, POST restore, DELETE permanente, DELETE purge).

**Frontend:** `DeletedShares.vue` — tabela com countdown ("eliminação permanente
em X dias"), filtros, e ações bulk (restore selected / purge all expired).

**Desafios:**
- **Preservar o token.** Ao restaurar um link público, o URL original devia
  continuar a funcionar. O `IShareManager::createShare()` gera token novo.
  Recomendação: criar via API e depois fazer `UPDATE` do token; fallback para
  aceitar token novo e notificar o owner.
- **Interceptar revogações nativas.** O verdadeiro valor está em registar um
  listener para `OCP\Share\Events\BeforeShareDeletedEvent`, copiando para a
  tabela de retenção **antes** da eliminação — assim funciona mesmo quando a
  partilha é apagada pela interface nativa do Nextcloud, não só pela app.
- **Crescimento da tabela.** O purge diário controla, mas monitorizar em
  instâncias grandes.

---

### 2. Transferir ownership de partilhas órfãs

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

### 3. Notificar o owner (alertas e remediações)

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

### 4. Histórico / trend de exposição

A secção Exposure mostra o estado **atual**. Falta a evolução ao longo do tempo.

- Tabela `oc_shareaudit_exposure_history` com snapshots diários
- Background job a gravar os contadores por categoria
- `ExposureMapService::getExposureTrend(days)` + gráfico de linha na vista

> Não é possível reconstruir retroativamente a partir da `oc_share`: as
> partilhas revogadas desaparecem. Por isso os snapshots são necessários.

Justificação de negócio para priorizar isto cedo: custa pouco e cria o
argumento "estamos a melhorar" para mostrar à gestão.

---

### 5. Digest semanal por email para admins

Distinto do #6 (que é mais formal/periódico e depende do histórico do #4).
Este é um digest leve e frequente: `TimedJob` semanal + `IMailer`, resumindo
**novos** links inseguros, **novas** órfãs, e evolução do score desde o último
digest. É o que faz a app continuar a ser usada depois da segunda semana,
mesmo antes do histórico completo (#4) existir — pode arrancar comparando só
com o snapshot da semana anterior, sem esperar pela série temporal completa.

Fazer depois do G2/G3, para que o digest já reflita alertas "aceites" (não
faz sentido mandar email semanal sobre algo que o admin já marcou como
exceção). Implementar antes ou em paralelo com o #6, não depois.

---

### 6. Relatórios de compliance por email

Envio agendado de um resumo periódico (links inseguros, órfãs, score de
exposição) aos administradores. O `ReportService` atual só gera o CSV da lista —
seria estendido para produzir o relatório e um `TimedJob` para o enviar.
Beneficia do histórico da feature 4 para mostrar deltas ("+12 links públicos
desde o último relatório").

---

### 7. Políticas por grupo

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

### 8. Relatório PDF/HTML assinado, para compliance/auditorias externas

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

- Screenshots com dados de demonstração limpos (atualmente há paths de teste)
- Estender `build/l10n.py` para também analisar `lib/**/*.php` — hoje só
  varre `src/`, por isso qualquer `IL10N->t()` novo no backend fica
  silenciosamente por traduzir a menos que seja adicionado à mão aos
  `l10n/*.json`
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
  (acknowledge) ou o soft delete (#1), que vão precisar de migrations de
  qualquer forma.
