# Share Audit Dashboard — Roadmap

## Status atual

**Fase 1 (MVP) — concluída.** Dashboard com gráficos (tendência mensal, tipos,
Interno vs Externo), lista de partilhas filtrável/pesquisável com paginação e
ordenação server-side, alertas de segurança com regras configuráveis e breakdown
por categoria, exportação CSV.

**Fase 2 / 3 — próximas features (este documento).**

---

# Próximas Features

## Feature 1: Orphan Shares (Partilhas Órfãs)

### O Problema
Quando um utilizador é desativado (saída da empresa, fim de contrato, etc.), as
partilhas que esse utilizador criou continuam ativas e acessíveis. No Nextcloud
core, não existe mecanismo automático para lidar com isto (GitHub issue #31444,
aberto desde 2022, sem implementação).

Na prática, o admin descobre partilhas abandonadas por acidente — ou nunca
descobre. Com ~300 users como na Jofebar, offboardings são regulares e o risco
acumula-se silenciosamente.

### Solução

**Nova tab/secção: "Orphan shares"**

Vista dedicada que cruza a tabela `oc_share` com o estado dos utilizadores
(via `IUserManager`) e lista todas as partilhas cujo `uid_owner` corresponde
a um utilizador desativado ou eliminado.

**Informação por partilha órfã:**
- Owner (desativado/eliminado)
- Ficheiro/pasta partilhado (path)
- Destinatário(s) da partilha
- Tipo (user, grupo, link público, email, federada)
- Data de criação da partilha
- Data de desativação do owner (se disponível via log)
- Permissões concedidas

**Ações disponíveis:**
- **Transferir ownership**: reassignar a partilha a outro utilizador
  (útil quando alguém sai e o colega assume o trabalho)
- **Revogar**: eliminar a partilha (com soft delete — ver Feature 4)
- **Bulk select**: checkbox para selecionar múltiplas e aplicar ação em massa

**Alerta no dashboard principal:**
Badge/contador "X orphan shares" no dashboard, com link direto para a vista.

### Implementação Técnica

```
Backend:
├── lib/Service/OrphanShareService.php
│   ├── findOrphanShares(): cruza oc_share com IUserManager
│   │   - Query: SELECT * FROM oc_share WHERE uid_owner NOT IN
│   │     (SELECT uid FROM oc_accounts WHERE state = 1)
│   │   - Alternativa: IUserManager::userExists() + isEnabled()
│   │     (mais seguro mas mais lento em instâncias grandes)
│   │   - Abordagem recomendada: query DB para listar, validar
│   │     com IUserManager apenas no drill-down
│   ├── transferShare(shareId, newOwnerId)
│   │   - Atualiza uid_owner e uid_initiator na oc_share
│   │   - Verifica se newOwner tem acesso ao ficheiro (filecache)
│   └── revokeOrphanShares(shareIds[])
│       - Usa IShareManager::deleteShare() para cada
│       - Com soft delete (ver Feature 4)
│
├── lib/Controller/OrphanShareController.php
│   ├── GET /api/orphans         — lista paginada
│   ├── POST /api/orphans/transfer — transferir ownership
│   └── POST /api/orphans/revoke   — revogar (bulk)

Frontend:
├── src/views/OrphanShares.vue
│   ├── Tabela com checkbox bulk select
│   ├── Filtros: por tipo de partilha, por data
│   ├── Dropdown de ação: Transferir / Revogar
│   └── Modal de transferência (selector de user destino)
```

### Considerações
- **Performance**: a query de órfãs pode ser pesada se houver muitos users
  eliminados. Considerar um background job diário que popula uma tabela
  de cache `oc_shareaudit_orphans`.
- **LDAP/AD**: utilizadores desativados no AD podem aparecer como "enabled"
  no Nextcloud se o sync LDAP não estiver configurado para mapear o estado.
  Documentar este cenário e considerar verificação dupla.
- **Ownership transfer**: precisa de verificar se o ficheiro existe no
  storage do novo owner (ou se está em grupo/external storage acessível).

---

## Feature 2: Bulk Actions nos Alertas de Segurança

### O Problema
O issue #59293 (março 2026) e #15142 (2019) do Nextcloud server pedem
exatamente isto: gerir partilhas acumuladas "é muito tedioso" — abrir cada
ficheiro, navegar ao painel de partilha, remover cada partilha individualmente.
Com dezenas de alertas, é trabalho manual repetitivo que ninguém quer fazer.

### Solução

**Ações individuais por card de alerta (graduadas):**

| Tipo de Alerta         | Ações Disponíveis                                    |
|------------------------|------------------------------------------------------|
| No password            | 🔒 Add password (gerar auto / definir manual)        |
| No expiration date     | 📅 Set expiration (7 / 30 / 90 dias / custom)        |
| Sensitive file type    | ⚠️ Notify owner (notificação Nextcloud ao owner)     |
| Qualquer alerta        | 🗑️ Revoke share (soft delete, com confirmação)       |

**Bulk actions (barra de topo):**
- Checkbox "Select all" + checkboxes individuais por card
- Dropdown "Bulk action":
  - "Add password to all" → gera passwords automáticas, notifica owners
  - "Set expiration (X days) to all" → aplica prazo uniforme
  - "Notify all owners" → envia notificação Nextcloud a cada owner
  - "Revoke all selected" → soft delete com confirmação + contagem
    ("Revogar 5 partilhas? Os utilizadores X, Y, Z perderão acesso.")

**Feedback visual:**
- Após ação, o card muda de estado (ex: "No password" → "Password added ✓")
  com fade-out após 3 segundos
- Toast/snackbar de confirmação: "Password adicionada a 5 partilhas"
- Se a ação falhar para alguma partilha, mostrar quais falharam e porquê

### Implementação Técnica

```
Backend:
├── lib/Controller/ShareActionController.php
│   ├── PUT  /api/shares/{id}/password
│   │   → OCS: PUT /ocs/v2.php/apps/files_sharing/api/v1/shares/{id}
│   │     body: { password: "generated_or_manual" }
│   ├── PUT  /api/shares/{id}/expiration
│   │   → OCS: PUT /ocs/v2.php/apps/files_sharing/api/v1/shares/{id}
│   │     body: { expireDate: "YYYY-MM-DD" }
│   ├── DELETE /api/shares/{id}
│   │   → Soft delete (ver Feature 4)
│   ├── POST /api/shares/{id}/notify
│   │   → INotificationManager::notify() ao uid_owner
│   └── POST /api/shares/bulk
│       body: { action: "password|expiration|notify|revoke",
│               shareIds: [...], params: {...} }
│       → Itera sobre shareIds, aplica ação, retorna resultado por share
│
├── lib/Service/PasswordGeneratorService.php
│   └── generate(): password aleatória segura (12+ chars, mixed)

Frontend:
├── src/components/AlertCard.vue
│   ├── Botões de ação inline (por tipo de alerta)
│   ├── Checkbox para bulk select
│   ├── Estado visual (pending → success / error)
│   └── Modal de confirmação para revoke
│
├── src/components/BulkActionBar.vue
│   ├── Aparece quando ≥1 alertas selecionados
│   ├── Contador: "X alertas selecionados"
│   ├── Dropdown de ações
│   └── Progress indicator durante execução bulk
```

### Considerações
- **Rate limiting**: ações bulk sobre muitas partilhas podem sobrecarregar
  a OCS API. Implementar batching (ex: 10 partilhas por request) com
  progress bar.
- **Permissões**: as ações sobre partilhas de outros users requerem
  permissões admin. Verificar `IGroupManager::isAdmin()` no controller.
- **Notificações**: usar a API nativa de notificações do Nextcloud
  (`INotificationManager`) para que o owner receba a notificação na
  interface, não apenas por email.

---

## Feature 3: Mapa de Exposição (Exposure Map)

### O Problema
O admin não tem uma visão instantânea do nível de exposição da instância.
Quantos ficheiros estão expostos publicamente? Quantos internamente? Existem
picos de partilhas externas? O SharePoint tem o "Data Access Governance" e o
Google Workspace tem o "File Exposure Report" — o Nextcloud não tem nada
equivalente.

### Solução

**Nova vista ou secção no dashboard: "Exposure Map"**

Visualização em 3 camadas da exposição dos dados da instância:

```
┌─────────────────────────────────────────────────┐
│              Exposure Overview                   │
├─────────────────────────────────────────────────┤
│                                                 │
│  🟢 Internal only     ████████████████  342     │
│  🟡 Specific people   ████████         187     │
│  🟠 Organization link ████             98      │
│  🔴 Public (anyone)   ██               41      │
│                                                 │
│  [Donut chart: % por categoria]                 │
│                                                 │
├─────────────────────────────────────────────────┤
│  Trend (últimos 90 dias)                        │
│  [Line chart: evolução por categoria]           │
│                                                 │
├─────────────────────────────────────────────────┤
│  Top 5 users com mais exposição pública         │
│  1. joao.silva    — 12 links públicos           │
│  2. helena.matos  — 8 links públicos            │
│  ...                                            │
└─────────────────────────────────────────────────┘
```

**Categorias de exposição (inspirado no SharePoint):**
- **Internal only**: partilhas user-to-user ou user-to-group dentro da instância
- **Specific external**: partilhas com emails específicos (share type 4)
  ou federadas (share type 6)
- **Organization link**: partilhas com todos os users da instância
  (se aplicável via circles/grupos "everyone")
- **Public (anyone)**: links públicos sem autenticação (share type 3)

**Drill-down**: clicar numa categoria abre a lista filtrada de partilhas
desse tipo (reutiliza a ShareList.vue já existente).

### Implementação Técnica

```
Backend:
├── lib/Service/ExposureMapService.php
│   ├── getExposureCounts(): contadores por categoria
│   │   - Query agrupada por share_type na oc_share
│   │   - Mapeia share_type → categoria de exposição
│   ├── getExposureTrend(days): série temporal
│   │   - Agrupa por stime (share creation time) e share_type
│   │   - Retorna array [{date, internal, specific, public}, ...]
│   ├── getTopExposedUsers(limit): ranking de users
│   │   - COUNT(*) GROUP BY uid_owner WHERE share_type = 3
│   └── getExposureScore(): score global 0-100
│       - Ponderação: public (peso 3) > specific_external (2) > internal (1)
│       - Normalizado pelo total de ficheiros na instância
│
├── lib/Controller/ExposureController.php
│   ├── GET /api/exposure/overview   — contadores + score
│   ├── GET /api/exposure/trend      — série temporal
│   └── GET /api/exposure/top-users  — ranking

Frontend:
├── src/views/ExposureMap.vue
│   ├── Horizontal stacked bar (contadores por categoria)
│   ├── Donut chart (percentagens)
│   ├── Line chart temporal (trend)
│   ├── Top users list (clicável → drill-down)
│   └── Exposure score badge (verde/amarelo/vermelho)
│
├── src/components/ExposureScore.vue
│   └── Indicador visual tipo "semáforo" com score numérico
```

### Considerações
- **Cache**: os contadores de exposição devem ser cacheados (background job
  a cada 6h ou configurável). A query sobre toda a oc_share é pesada.
- **Trend data**: guardar snapshots diários numa tabela própria
  `oc_shareaudit_exposure_history` para que o gráfico temporal tenha dados
  históricos (não é possível reconstruir retroativamente a partir da
  oc_share porque partilhas revogadas desaparecem).

---

## Feature 4: Soft Delete de Partilhas

### O Problema
Revogar uma partilha no Nextcloud é irreversível — desaparece da oc_share e
acabou. Se o admin ou o user se arrepende, tem de recriar manualmente. O issue
#50734 descreve um user que edita a base de dados diretamente para recuperar
links expirados. Isto é o equivalente digital de destruir um documento e
depois tentar reconstruí-lo de memória.

### Solução

**Conceito: "Reciclagem" de partilhas**

Em vez de eliminar imediatamente da `oc_share`, mover para uma tabela de
retenção com TTL configurável (30 ou 60 dias à escolha do admin).

**Fluxo:**
1. Admin/user revoga uma partilha (via app ou via interface nativa NC)
2. A partilha é marcada como "soft deleted" (movida para tabela de retenção)
3. O link/acesso fica imediatamente inativo (o destinatário perde acesso)
4. A partilha aparece na vista "Recently deleted shares" (nova tab)
5. Durante o período de retenção, o admin pode:
   - **Restaurar**: reativa a partilha original (mesmo token/link)
   - **Eliminar permanentemente**: remove definitivamente
6. Após o TTL (30/60 dias), um background job limpa automaticamente

**Settings (admin):**
- Toggle: "Enable soft delete for shares" (on/off)
- Período de retenção: dropdown 30 / 60 / 90 dias (ou custom)
- Auto-purge: toggle para limpeza automática vs. manual

**Vista "Recently deleted" (nova tab na app):**
- Lista de partilhas soft-deleted, com:
  - Path do ficheiro, owner, destinatário, tipo
  - Data da revogação
  - Countdown: "Eliminação permanente em X dias"
  - Botões: Restaurar | Eliminar agora
- Filtros: por owner, por tipo, por data de revogação

### Implementação Técnica

```
Nova tabela: oc_shareaudit_deleted
├── id (PK)
├── original_share_id (o id que tinha na oc_share)
├── share_type
├── share_with
├── uid_owner
├── uid_initiator
├── item_type (file/folder)
├── file_source
├── file_target
├── permissions
├── token (para links públicos — permite restaurar o mesmo URL)
├── password (hash)
├── expiration (data de expiração original)
├── stime (data de criação original)
├── deleted_at (timestamp da revogação)
├── deleted_by (uid de quem revogou)
├── purge_after (timestamp = deleted_at + TTL)
├── note (nota original da partilha)

Backend:
├── lib/Migration/
│   └── Version1001Date*.php  — cria oc_shareaudit_deleted
│
├── lib/Service/SoftDeleteService.php
│   ├── softDelete(shareId, deletedBy):
│   │   1. Lê share da oc_share via IShareManager
│   │   2. Copia todos os campos para oc_shareaudit_deleted
│   │   3. Calcula purge_after = now() + TTL (config)
│   │   4. Elimina da oc_share via IShareManager::deleteShare()
│   │   → Resultado: acesso cortado imediatamente, dados preservados
│   │
│   ├── restore(deletedShareId):
│   │   1. Lê da oc_shareaudit_deleted
│   │   2. Verifica se ficheiro ainda existe (filecache)
│   │   3. Verifica se owner ainda está ativo
│   │   4. Recria via IShareManager::createShare() com mesmos params
│   │   5. Para links públicos: força o mesmo token (se possível)
│   │      → Nota: IShareManager pode não permitir definir token;
│   │        alternativa: insert direto na oc_share (menos clean)
│   │   6. Remove da oc_shareaudit_deleted
│   │
│   ├── permanentDelete(deletedShareId):
│   │   → Remove da oc_shareaudit_deleted
│   │
│   └── purgeExpired():
│       → DELETE FROM oc_shareaudit_deleted WHERE purge_after < NOW()
│       → Chamado pelo background job
│
├── lib/BackgroundJob/PurgeDeletedSharesJob.php
│   └── Registado como TimedJob (diário)
│       → Chama SoftDeleteService::purgeExpired()
│
├── lib/Controller/SoftDeleteController.php
│   ├── GET    /api/deleted-shares       — lista paginada
│   ├── POST   /api/deleted-shares/{id}/restore
│   ├── DELETE /api/deleted-shares/{id}  — permanent delete
│   └── DELETE /api/deleted-shares/purge — purge all expired

Frontend:
├── src/views/DeletedShares.vue
│   ├── Tabela com countdown visual (dias restantes)
│   ├── Botões: Restaurar / Eliminar permanentemente
│   ├── Filtros: por owner, tipo, data de revogação
│   └── Ação bulk: "Purge all expired" / "Restore selected"
```

### Considerações
- **Token preservation**: o maior desafio técnico. Quando restauras um link
  público, idealmente o URL original continua a funcionar (mesmo token). A
  `IShareManager::createShare()` gera token novo por defeito. Soluções:
  1. Insert direto na oc_share com o token original (funciona mas bypassa
     a API — risco de incompatibilidade em upgrades do NC)
  2. Criar share via API e depois UPDATE do token (mais seguro)
  3. Aceitar token novo e notificar o owner (mais limpo, menos útil)
  → Recomendação: opção 2, com fallback para 3 se falhar.
- **Hook nas ações nativas**: idealmente, o soft delete devia interceptar
  QUALQUER revogação de partilha (não só via tua app). Isto requer registar
  um event listener para `OCP\Share\Events\BeforeShareDeletedEvent` que
  copia para oc_shareaudit_deleted antes da eliminação.
  → Isto é o que torna a feature realmente poderosa: funciona mesmo
  quando o user apaga a partilha via interface nativa do Nextcloud.
- **Storage**: a tabela de soft deletes cresce. O purge job diário mantém
  controlada, mas monitorizar em instâncias grandes.

---

## Feature 5: Vista Drill-Down Reversa (por destinatário)

### O Problema
"A que ficheiros é que este email externo / user tem acesso?" — o Nextcloud
não responde a esta pergunta. O admin tem de procurar ficheiro a ficheiro.
Em cenários de offboarding de fornecedores, auditorias, ou incidentes de
segurança, isto é crítico.

### Solução

**Pesquisa por destinatário:**

Campo de pesquisa onde o admin escreve um username, email, ou nome de grupo,
e a app retorna TODAS as partilhas que envolvem esse destinatário.

```
┌─────────────────────────────────────────────────┐
│  🔍 Search by recipient: [carlos@externo.pt  ]  │
├─────────────────────────────────────────────────┤
│                                                  │
│  carlos@externo.pt has access to 7 items:        │
│                                                  │
│  📄 /Projetos/Proposta_2026.pdf                  │
│     Shared by: joao.silva | Type: email          │
│     Permissions: Read | Since: 12/03/2026        │
│     Expiration: none ⚠️                          │
│                                                  │
│  📁 /Documentos/Specs_Técnicas/                  │
│     Shared by: ana.costa | Type: email           │
│     Permissions: Read+Write | Since: 01/05/2026  │
│     Expiration: 30/07/2026                       │
│                                                  │
│  📄 /Partilhado/Relatório_Q1.xlsx               │
│     Shared by: admin | Type: public link         │
│     Permissions: Read | Since: 15/01/2026        │
│     ⚠️ No password                               │
│  ...                                             │
│                                                  │
│  [Revoke all access for carlos@externo.pt]       │
│                                                  │
└─────────────────────────────────────────────────┘
```

**Tipos de pesquisa:**
- Por username interno (share_with = uid)
- Por email externo (share_with = email, share_type = 4)
- Por grupo (share_with = group_id, share_type = 1)
- Autocomplete com resultados mistos (users + grupos + emails da oc_share)

**Ação: "Revoke all access"**
- Revoga todas as partilhas com esse destinatário (soft delete)
- Confirmação: "Revogar 7 partilhas com carlos@externo.pt?"
- Útil para offboarding de fornecedores ou resposta a incidentes

### Implementação Técnica

```
Backend:
├── lib/Service/RecipientLookupService.php
│   ├── searchRecipients(query): autocomplete
│   │   - SELECT DISTINCT share_with FROM oc_share
│   │     WHERE share_with LIKE '%query%'
│   │   - Agrupa por tipo (user/group/email/federated)
│   │
│   ├── getSharesForRecipient(shareWith, shareType):
│   │   - SELECT * FROM oc_share
│   │     WHERE share_with = :shareWith
│   │     AND share_type = :shareType
│   │   - Join com oc_filecache para path legível
│   │
│   └── revokeAllForRecipient(shareWith, shareType):
│       - Itera e aplica softDelete a cada partilha
│
├── lib/Controller/RecipientController.php
│   ├── GET  /api/recipients/search?q=  — autocomplete
│   ├── GET  /api/recipients/{id}/shares — lista de partilhas
│   └── POST /api/recipients/{id}/revoke-all

Frontend:
├── src/views/RecipientDrilldown.vue
│   ├── Campo de pesquisa com autocomplete (NcActionInput)
│   ├── Lista de ficheiros/pastas acessíveis pelo destinatário
│   ├── Alertas inline (sem password, sem expiração)
│   └── Botão "Revoke all access" (com confirmação)
│
├── src/components/RecipientSearch.vue
│   └── Debounced autocomplete (300ms) com ícones por tipo
```

---

## Resumo: Ordem de Implementação Sugerida

| #  | Feature              | Depende de | Esforço  | Impacto |
|----|----------------------|------------|----------|---------|
| 1  | Bulk Actions         | —          | 3-4 dias | Alto    |
| 2  | Orphan Shares        | —          | 3-4 dias | Alto    |
| 3  | Exposure Map         | —          | 4-5 dias | Alto    |
| 4  | Soft Delete          | (1 e 2)    | 4-5 dias | Médio+  |
| 5  | Drill-Down Reversa   | —          | 3-4 dias | Médio+  |

**Nota**: Soft Delete beneficia de ser implementado DEPOIS de Bulk Actions
e Orphan Shares, porque ambas essas features revogam partilhas — e quando
o soft delete estiver ativo, essas revogações passam automaticamente pela
reciclagem em vez de serem permanentes.

---

## Backlog adicional (menor)

- **Widget de dashboard** do Nextcloud com resumo de alertas (Fase 3 do plano original).
- **View para utilizadores normais** (atualmente a app é só admin) — decisão em aberto.
- **Integrar os filtros junto aos seletores de ordenação** (unificar barra de filtros/ordenação na lista).
- **Polish de publicação**: README, screenshots, i18n PT/EN, packaging (krankerl/App Store).
