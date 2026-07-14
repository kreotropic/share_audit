# Share Audit Dashboard — Roadmap

## Estado atual (v0.4.0)

**Fases 1–3 concluídas, mais a 2ª revisão pré-submissão (R1–R6) e a feature
#1 do pós-lançamento (Soft delete).** A app está funcionalmente completa e
sem bloqueios conhecidos para lançamento na App Store — ver
[PRE_RELEASE_PLAN.md](PRE_RELEASE_PLAN.md) e [CHANGELOG.md](CHANGELOG.md)
(0.3.0) para o detalhe do que essa ronda corrigiu (cache de alertas,
`min-version` 31, lotes no revoke-all, etc.). Tudo o que se segue já está
implementado e a funcionar:

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
- Toggle no admin (tab Settings) para desativar esta vista e o widget em toda
  a instância, para quem prefere manter a auditoria de partilhas como
  assunto exclusivo de administração

**Publicação**
- i18n **EN + pt‑PT** (com `build/l10n.py` para gerar os bundles frontend;
  `--check` corre como parte do `krankerl package`, falha o build em vez de
  depender de disciplina)
- README, screenshots, `krankerl.toml` + `.nextcloudignore` para packaging
  (os documentos de planeamento internos ficam fora do tarball)
- `min-version` 31 (NC 30 já não é suportado — o revoke de órfãs depende de
  um parâmetro que só existe a partir do NC 31)

**Soft delete de partilhas (reciclagem)** — DONE & verified 2026-07-14
- Nova tabela `oc_shareaudit_deleted` (primeira migration da app,
  `lib/Migration/Version0004Date...`), `DeletedShare`/`DeletedShareMapper`
  (Entity/QBMapper — único sítio da app a usar esse padrão em vez de SQL cru,
  por ser uma tabela própria da app com CRUD simples)
- `SoftDeleteService`: captura (de um `IShare` vivo ou de uma linha crua),
  listagem paginada, `restore()`, `purge()`/`purgeMany()`, `purgeExpired()`
  (chamado diariamente por `PurgeDeletedSharesJob`, registado em
  `<background-jobs>` no info.xml)
- **Captura universal via evento**: `SoftDeleteListener` ouve
  `OCP\Share\Events\BeforeShareDeletedEvent` — cobre TODAS as revogações
  desta app (todas passam por `IShareManager::deleteShare()`) e também
  revogações nativas (Files app, outras apps, `occ`, API OCS de sharing).
  Verificado ao vivo: uma partilha apagada via
  `DELETE /ocs/v2.php/apps/files_sharing/api/v1/shares/{id}` (sem tocar
  nesta app) apareceu corretamente na reciclagem. O único caminho que não
  passa por este evento é `ShareDeletionService::deleteDirect()` (fallback
  quando o provider falha) — capturado explicitamente aí, re-selecionando a
  linha completa antes do `DELETE` cru.
- **Restore()** recria a partilha via `IShareManager::createShare()`
  (permissions/provider/mount points tratados normalmente) e depois faz um
  `UPDATE` cru só às colunas `token`/`password` para repor os valores
  originais — `setPassword()`/`setToken()` num share novo tratá-los-iam como
  valor em texto simples e re-hash/gerar-novo-token, o que quebraria a
  password e mudaria sempre o URL. Se esse `UPDATE` falhar (ex.: o token
  original foi reutilizado entretanto por outro link — é `UNIQUE`), a
  partilha continua criada, só com token novo — reportado ao frontend como
  `tokenChanged` em vez de falhar.
- `FileNodeResolver`: wrapper fino sobre `IRootFolder`, existe só para
  isolar essa dependência (que estende `OC\Hooks\Emitter`, indisponível num
  ambiente `composer install` puro — mockar `IRootFolder` diretamente parte
  o PHPUnit tanto no host como na CI) do resto do código, mantendo
  `SoftDeleteService` testável sem uma instância NC real.
- Retenção configurável (`Settings` → secção "Reciclagem", default 30 dias)
- Frontend: nova tab "Deleted shares" (`DeletedShares.vue`, mesmo padrão de
  `OrphanShares.vue`), badge com contagem (populado logo no load do
  Dashboard, tal como alerts/orphans), restore individual ou em lote,
  eliminação definitiva em lote com confirmação, countdown de dias até
  purge automático
- Testes: `tests/Unit/SoftDeleteServiceTest.php` (10 testes — captura,
  restore com todos os caminhos de falha, purge)

> A nota anterior sobre revogações serem permanentes já não se aplica —
> toda a revogação (desta app ou nativa) passa primeiro pela reciclagem.

---

## Pós-lançamento — só se houver tração

Estas features ficam **em espera até a app ganhar tração na App Store**. Estão
ordenadas por impacto. As specs técnicas ficam registadas para não se perder o
raciocínio já feito.

| # | Feature | Depende de | Esforço | Impacto |
|---|---------|-----------|---------|---------|
| 1 | Transferir ownership (órfãs) | — | 2-3 dias | Médio+ |
| 2 | Notificar owner (alertas) | — | 1-2 dias | Médio |
| 3 | Histórico/trend de exposição | — | 2-3 dias | Médio |
| 4 | Relatórios de compliance por email | (3) | 3-4 dias | Médio |

---

### 1. Transferir ownership de partilhas órfãs

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

### 2. Notificar o owner (ação nos alertas)

Terceira ação para o alerta *"Sensitive file type"*, onde revogar ou pôr
password pode ser demasiado agressivo: avisar quem partilhou.

- `POST /api/shares/{id}/notify` → `INotificationManager::notify()` ao `uid_owner`
- Adicionar `"Notify all owners"` às bulk actions
- Usar a API nativa de notificações (aparece na interface do Nextcloud, não só
  por email)

---

### 3. Histórico / trend de exposição

A secção Exposure mostra o estado **atual**. Falta a evolução ao longo do tempo.

- Tabela `oc_shareaudit_exposure_history` com snapshots diários
- Background job a gravar os contadores por categoria
- `ExposureMapService::getExposureTrend(days)` + gráfico de linha na vista

> Não é possível reconstruir retroativamente a partir da `oc_share`: as
> partilhas revogadas desaparecem. Por isso os snapshots são necessários.

---

### 4. Relatórios de compliance por email

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
- ~~Testes: a app não tem suite de testes (`phpunit`)~~ — feito
  (2026-07-10, ver `QUALITY_REVIEW_PLAN.md` M-Q1): `tests/Unit/` cobre
  `SecurityAnalyzerService::issuesFor()` e o early-return de
  `ShareMapper::countInsecureLinks()`; `ShareCollectorService` continua sem
  testes próprios (é maioritariamente normalização de dados sem lógica
  condicional de peso).
- Truncagem do label "Hiperligação pública" no gráfico "Partilhas por tipo"
  (a coluna de labels do `HBarChart` está a 120px nessa vista)
- Falta índice em `share_with` (autocomplete/recipient search, `ILIKE %...%`)
  e em `path` (ordenação). Numa instância de ~300 users é tolerável (dezenas
  de milhares de linhas); decisão adiada até haver evidência de instâncias
  maiores. Quando justificar, adicionar via migration (a app já tem a
  primeira, `Version0004...`, do soft delete — a próxima seria
  `Version0005...`).
