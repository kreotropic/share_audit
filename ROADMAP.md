# Share Audit Dashboard — Roadmap

## Estado atual (v0.2.1)

**Fases 1–3 concluídas.** A app está funcionalmente completa para lançamento na
App Store. Tudo o que se segue já está implementado e a funcionar:

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
- Exportação **CSV** da vista filtrada

**Security alerts**
- Deteção de links públicos sem password, sem expiração, ou a expor tipos de
  ficheiro sensíveis — com **regras configuráveis** (tab Settings)
- Breakdown por categoria (gráfico de barras)
- Ações individuais e em **bulk**: gerar password, definir expiração (7/30/90d),
  revogar. Passwords geradas mostradas uma única vez.

**Lookup & Orphans**
- **Orphan shares**: partilhas cujo owner está desativado ou eliminado, com
  bulk revoke e badge no dashboard
- **Access lookup** (drill-down reversa): pesquisa por utilizador, grupo ou
  email e lista **todos os ficheiros/pastas a que esse destinatário acede**,
  com *revoke all access*

**Vista pessoal (Personal settings → My shares audit)**
- Cada utilizador audita e corrige as suas próprias partilhas de risco
- **Widget** no dashboard do Nextcloud com os links que precisam de atenção

**Publicação**
- i18n **EN + pt‑PT** (com `build/l10n.py` para gerar os bundles frontend)
- README, screenshots, `krankerl.toml` + `.nextcloudignore` para packaging

> ⚠️ **Nota importante:** as revogações são **permanentes** — a partilha
> desaparece da `oc_share`. O soft delete (abaixo) é o que resolve isto.

---

## Pós-lançamento — só se houver tração

Estas features ficam **em espera até a app ganhar tração na App Store**. Estão
ordenadas por impacto. As specs técnicas ficam registadas para não se perder o
raciocínio já feito.

| # | Feature | Depende de | Esforço | Impacto |
|---|---------|-----------|---------|---------|
| 1 | Soft delete de partilhas | — | 4-5 dias | Alto |
| 2 | Transferir ownership (órfãs) | — | 2-3 dias | Médio+ |
| 3 | Notificar owner (alertas) | — | 1-2 dias | Médio |
| 4 | Histórico/trend de exposição | — | 2-3 dias | Médio |
| 5 | Relatórios de compliance por email | (4) | 3-4 dias | Médio |

---

### 1. Soft delete de partilhas (reciclagem)

**Problema.** Revogar uma partilha é irreversível — desaparece da `oc_share`.
Se o admin ou o utilizador se arrepende, tem de recriar manualmente. O issue
#50734 descreve um utilizador a editar a base de dados diretamente para
recuperar links expirados.

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
Requer a primeira **migration** da app (`lib/Migration/`).

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
o trabalho.

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

### 3. Notificar o owner (ação nos alertas)

Terceira ação para o alerta *"Sensitive file type"*, onde revogar ou pôr
password pode ser demasiado agressivo: avisar quem partilhou.

- `POST /api/shares/{id}/notify` → `INotificationManager::notify()` ao `uid_owner`
- Adicionar `"Notify all owners"` às bulk actions
- Usar a API nativa de notificações (aparece na interface do Nextcloud, não só
  por email)

---

### 4. Histórico / trend de exposição

A secção Exposure mostra o estado **atual**. Falta a evolução ao longo do tempo.

- Tabela `oc_shareaudit_exposure_history` com snapshots diários
- Background job a gravar os contadores por categoria
- `ExposureMapService::getExposureTrend(days)` + gráfico de linha na vista

> Não é possível reconstruir retroativamente a partir da `oc_share`: as
> partilhas revogadas desaparecem. Por isso os snapshots são necessários.

---

### 5. Relatórios de compliance por email

Envio agendado de um resumo periódico (links inseguros, órfãs, score de
exposição) aos administradores. O `ReportService` atual só gera o CSV da lista —
seria estendido para produzir o relatório e um `TimedJob` para o enviar.
Beneficia do histórico da feature 4 para mostrar deltas ("+12 links públicos
desde o último relatório").

---

## Backlog menor

- Paginação/seletor "Por página" nas restantes vistas (Orphans, Lookup)
- Encurtar o título do widget — trunca no painel estreito do dashboard
  ("Shares needing a…")
- Screenshots com dados de demonstração limpos (atualmente há paths de teste)
- Testes: a app não tem suite de testes (`phpunit`) — adicionar cobertura ao
  `SecurityAnalyzerService` e `ShareCollectorService` seria o maior retorno
- Truncagem do label "Hiperligação pública" no gráfico "Partilhas por tipo"
  (a coluna de labels do `HBarChart` está a 120px nessa vista)
- Falta índice em `share_with` (autocomplete/recipient search, `ILIKE %...%`)
  e em `path` (ordenação). Numa instância de ~300 users é tolerável (dezenas
  de milhares de linhas); decisão adiada até haver evidência de instâncias
  maiores. Quando justificar, adicionar via migration — coordenar com a
  feature "Soft delete" acima, que já vai precisar da primeira migration da
  app de qualquer forma.
