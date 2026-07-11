# Share Audit Dashboard — Roadmap

> Este é o único documento de planeamento do projeto. O histórico do que já
> foi feito (incluindo as três rondas de revisão de segurança/qualidade de
> julho de 2026) está no [CHANGELOG.md](CHANGELOG.md) e no histórico git.

## Estado atual (v0.3.0)

**Desenvolvimento congelado para lançamento.** A app está funcionalmente
completa, passou três rondas de revisão (segurança, pré-submissão e
auditoria de qualidade linha a linha) com todos os itens resolvidos, e tem
suite de testes + CI. Não há bloqueios de código conhecidos para a submissão
à App Store.

### Entregue

**Dashboard (Painel)**
- Contadores por tipo de partilha (cards clicáveis → abrem "All shares" já
  filtrado), tendência de criação (12 meses), donut Interno vs Externo,
  top sharers
- Secção **Exposure**: score 0–100, exposição por alcance (interno /
  externo / público / other) com drill-down e ranking de exposição pública

**All shares**
- Tabela server-side (filtros nos cabeçalhos, ordenação, paginação) de todas
  as partilhas da instância, com link "abrir no Files" por linha
- Exportação **CSV** da vista filtrada; tokens de links públicos só entram
  no ficheiro com opt-in explícito e aviso (são credenciais)

**Security alerts**
- Cinco regras configuráveis: link sem password, sem expiração, ficheiro
  sensível, **upload anónimo sem password** (file drop) e **partilha de
  grupo com edit/reshare acima de N membros** (default 20)
- Categorias "expira em breve" / "já expirado"; breakdown clicável por
  categoria (filtra a lista); copy link e open-in-Files por alerta
- Ações individuais e em bulk (com chunking): gerar password, definir
  expiração (7/30/90d), revogar — passwords geradas mostradas uma única vez

**Lookup & Orphans**
- **Access lookup** (drill-down reversa, paginada): tudo o que um
  utilizador/grupo/email consegue alcançar, com *revoke all access* em lotes
- **Orphan shares**: partilhas de contas desativadas/eliminadas, bulk revoke
  validado no servidor contra o conjunto real de órfãs

**Vista pessoal + widget**
- Cada utilizador audita e corrige as próprias partilhas (owner **ou**
  initiator); widget "Share alerts" no dashboard do Nextcloud
- Toggle de admin para desativar a vista pessoal e o widget em toda a
  instância (a entrada desaparece da sidebar, não fica uma página morta)

**Robustez e integridade**
- Toda a revogação passa pelo `IShareManager` (OCM/eventos/cleanup corretos;
  fallback DB documentado e logado apenas para owner inexistente/provider
  indisponível) e fica registada no **audit log** (`admin_audit`)
- Caches com invalidação nas mutações; contagens pesadas em SQL puro;
  rate limiting nos endpoints pessoais e de lookup
- Suite `phpunit` (`tests/Unit/`) + CI (l10n, lint, testes, build frontend)
- i18n EN + pt-PT; `build/l10n.py --check` gates o `krankerl package`

> ⚠️ **Limitação conhecida:** as revogações são **permanentes** — a partilha
> desaparece da `oc_share`. O soft delete (item #1 abaixo) é o que resolve
> isto, e está documentado no README como limitação.

---

## Antes da submissão à App Store

A única pendência de *conteúdo* é a primeira; as restantes são a mecânica de
publicação.

- [ ] **Screenshots com dados de demonstração limpos.** Os atuais
  (2026-07-08) têm paths de teste e são anteriores às features novas (regras
  de alerta novas, filtro por categoria, copy link, vista pessoal
  restilizada). Refazer os 7 com dados de demonstração plausíveis
  (`_seed.php` ajuda) e repor em `screenshots/` com os mesmos nomes — o
  README e o `info.xml` já apontam para eles.
- [ ] Push do repositório para `github.com/kreotropic/share_audit` — os URLs
  de screenshots do `info.xml` apontam para `raw.githubusercontent.com/...
  /master/screenshots/`; a App Store valida-os no upload.
- [ ] Registar a app em [apps.nextcloud.com](https://apps.nextcloud.com) e
  obter o **certificado de assinatura** (gerar CSR, submeter, guardar a key).
- [ ] `krankerl package` (corre `npm ci`, `l10n.py --check` e o build) e
  assinar o tarball (`openssl dgst -sha512 -sign ...`).
- [ ] Teste de instalação limpa do tarball em **NC 31** e **NC 33** (o range
  declarado) — dashboard, alerts, orphans, vista pessoal, widget.
- [ ] Upload da release + tag `v0.3.0` no GitHub.

---

## Pós-lançamento — só se houver tração

Ordenado por impacto. As specs ficam registadas aqui para não se perder o
raciocínio já feito.

| # | Feature | Depende de | Esforço | Impacto |
|---|---------|-----------|---------|---------|
| 1 | Acknowledge/exceções nos alertas | migration | 2-3 dias | Alto |
| 2 | Soft delete de partilhas | migration | 4-5 dias | Alto |
| 3 | Notificar o owner nas remediações do admin | — | 1-2 dias | Médio+ |
| 4 | Transferir ownership (órfãs) | — | 2-3 dias | Médio+ |
| 5 | Digest semanal por email para admins | — | 2 dias | Médio |
| 6 | Histórico/trend de exposição | — | 2-3 dias | Médio |
| 7 | Relatórios de compliance por email | (6) | 3-4 dias | Médio |
| 8 | Políticas por grupo | migration | 4-5 dias | Médio |
| 9 | Relatório PDF/HTML para auditorias externas | — | 3-4 dias | Médio- |

> **Nota sobre migrations:** #1, #2 e #8 precisam todos da primeira
> migration da app (`lib/Migration/`). Se dois deles avançarem próximos um
> do outro, coordenar numa só leva para não multiplicar migrations.

### 1. Acknowledge / exceções nos alertas

A lacuna funcional nº 1 identificada em revisão: todas as instâncias têm
links públicos *intencionalmente* sem password (página pública, newsletter).
Sem forma de marcar "isto é aceite", o contador de alertas nunca chega a
zero — e um contador permanentemente vermelho deixa de ser olhado ao fim de
duas semanas.

- Tabela `oc_shareaudit_ack` (`share_id`, `rule_code`, `acknowledged_by`,
  `acknowledged_at`, `note` opcional)
- `getAlerts()` exclui (ou marca como "aceite", com filtro mostrar/esconder)
  os pares `(share_id, rule_code)` presentes na tabela — não desaparecem,
  saem da contagem ativa
- `AckController` admin-only: `POST /api/alerts/{id}/ack`, `DELETE` para
  remover a exceção
- UI: botão "Aceitar" por alerta e filtro "mostrar aceites" (auditável)

### 2. Soft delete de partilhas (reciclagem)

Revogar é irreversível — desaparece da `oc_share`. O issue upstream #50734
descreve um utilizador a editar a BD à mão para recuperar links.

- Tabela `oc_shareaudit_deleted` com todos os campos da partilha +
  `deleted_at`, `deleted_by`, `purge_after`, `note`; TTL configurável
  (30/60/90 dias) e `PurgeDeletedSharesJob` (TimedJob diário)
- `SoftDeleteService` (softDelete / restore / permanentDelete /
  purgeExpired) + `SoftDeleteController` + vista "Recently deleted shares"
  com countdown e bulk restore/purge
- **Desafios registados:** preservar o token ao restaurar (o
  `createShare()` gera token novo — criar via API e fazer `UPDATE` do token;
  fallback: aceitar token novo e notificar o owner); registar um listener de
  `BeforeShareDeletedEvent` para capturar também as eliminações feitas pela
  interface nativa do Nextcloud; monitorizar o crescimento da tabela em
  instâncias grandes.

### 3. Notificar o owner nas remediações do admin

Hoje, qualquer remediação do admin (`setPassword` / `setExpiration` /
`revoke`) muda a partilha de outra pessoa sem aviso — o dono ganha uma
password que não conhece ou perde o link sem explicação.

- `INotificationManager::notify()` ao `uid_owner` em **toda** a ação de
  `ShareActionController`, com mensagem específica por ação
- Ação alternativa **"pedir ao dono para corrigir"**: notificação com
  deep-link para a vista pessoal do próprio dono — é o que muda a app de
  "ferramenta de polícia" para "ferramenta de governança"
- "Notify all owners" nas bulk actions

### 4. Transferir ownership de partilhas órfãs

A alternativa **não destrutiva** ao bulk revoke de órfãs: reatribuir a
partilha quando alguém sai e um colega assume o trabalho.

- `OrphanShareService::transferShare(shareId, newOwnerId)` — atualiza
  `uid_owner`/`uid_initiator`, verificando antes que o novo owner tem acesso
  ao ficheiro (filecache, grupo ou external storage)
- `POST /api/orphans/transfer` + modal de seleção de utilizador
- **LDAP/AD:** utilizadores desativados no AD podem aparecer *enabled* no
  Nextcloud se o sync não mapear o estado — documentar
- UX de referência: `occ files:transfer-ownership`

### 5. Digest semanal por email para admins

Distinto do #7 (mais formal, depende de histórico): um digest leve —
`TimedJob` semanal + `IMailer` com **novos** links inseguros, **novas**
órfãs e a evolução do score desde o digest anterior (basta guardar o
snapshot da semana anterior, não precisa da série temporal do #6). É o que
mantém a app usada depois da segunda semana; implementar antes ou em
paralelo com o #7, não depois.

### 6. Histórico / trend de exposição

- Tabela `oc_shareaudit_exposure_history`, background job diário a gravar os
  contadores por categoria, `getExposureTrend(days)` + gráfico de linha
- Não é reconstruível retroativamente (partilhas revogadas desaparecem da
  `oc_share`) — daí os snapshots
- Justificação de negócio: "estamos a melhorar" é o argumento que o admin
  mostra à gestão — vale mais cedo do que a tabela de esforço sugere

### 7. Relatórios de compliance por email

Resumo periódico agendado (links inseguros, órfãs, score) aos
administradores; estende o `ReportService` + `TimedJob`. Beneficia do #6
para mostrar deltas ("+12 links públicos desde o último relatório").

### 8. Políticas por grupo

Regras/exceções por grupo em vez de só globais — ex.: o grupo `Finance`
nunca pode ter links públicos sem password, independentemente da regra
global. Nenhuma ferramenta NC nativa faz isto visualmente.

- Tabela `oc_shareaudit_group_policy` (`group_id`, `rule_code`, `mode`:
  `enforce`/`forbid`/`inherit`)
- `SecurityAnalyzerService::issuesFor()` resolve a regra efetiva cruzando
  owner/initiator com `IGroupManager::getUserGroupIds()` antes do default
  global
- UI: secção "Políticas por grupo" em Settings

### 9. Relatório PDF/HTML para auditorias externas

O CSV é para o admin trabalhar os dados; um relatório formatado — cabeçalho
com instância, data/hora, período, resumo executivo e hash simples de
integridade — é para entregar a um auditor. Gerar HTML server-side com os
agregados já existentes; decidir antes de implementar se um HTML standalone
com print stylesheet chega ou se vale a pena uma dependência de PDF.
Aplicar o mesmo critério do CSV: **sem tokens** no relatório.

---

## Adiado por decisão (revisitar com evidência de instâncias maiores)

- **Streaming no export CSV** — hoje materializa até 100k linhas
  normalizadas em memória; um `StreamResponse` com fetch por chunks
  eliminaria o pico. Adiado: sem evidência de instâncias desse tamanho.
- **Índices em `share_with` (autocomplete/recipient search, `ILIKE %…%`) e
  `path` (ordenação)** — tolerável a ~300 utilizadores. Quando justificar,
  adicionar via migration — coordenar com a primeira migration da app
  (#1/#2/#8 acima).
