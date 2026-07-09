# Plano de correção — 2ª revisão, pré-submissão (2026-07-09)

Resultado da revisão à implementação dos planos de 2026-07-08
([SECURITY_REVIEW_PLAN.md](SECURITY_REVIEW_PLAN.md),
[SECURITY_REVIEW_PLAN_LOW.md](SECURITY_REVIEW_PLAN_LOW.md) e os quick wins do
[FEATURE_GAPS_PLAN.md](FEATURE_GAPS_PLAN.md)). A execução desses planos foi
validada item a item; este documento cobre apenas o que essa ronda
**introduziu ou deixou pendente**. R1–R3 bloqueiam a submissão à App Store;
R4–R6 são recomendados na mesma leva por serem baratos.

> Convenção: checkbox por item; marcar `[x]` e referenciar o commit/PR.

---

## BLOQUEIA SUBMISSÃO

### [x] R1 — 11 strings novas fora do l10n; `l10n.py --check` falha (MEDIUM)

**Onde:** `l10n/en.json` e `l10n/pt_PT.json`; deteção via
`python3 build/l10n.py --check` (falha neste momento).

**Problema:** as strings introduzidas pelos quick wins não foram adicionadas
aos JSON de tradução, e o bundle `js/` já foi reconstruído com elas — em
produção, um utilizador pt_PT vê inglês exatamente nas features novas.
Em falta:

- 'Already expired'
- 'Clear the filter to see the other insecure links.'
- 'Copied!'
- 'Copy link'
- 'Expiring soon'
- 'Include link tokens'
- 'No alerts in this category'
- 'Open in Files'
- 'Showing the first {shown} of {total} shares.'
- 'Showing: {label} — clear filter'
- 'This file will contain public link tokens — bare credentials that let anyone open the linked file without logging in. Handle it accordingly.'

Órfã (remover): 'How far your shared data reaches: internal, external, or public.'

**Correção:** adicionar as 11 chaves a `en.json` + tradução em `pt_PT.json`,
remover a órfã, correr `python3 build/l10n.py`, `npm run build`.

**Critério de aceitação:** `python3 build/l10n.py --check` sai com código 0;
a UI pt_PT mostra as strings novas traduzidas. Para não voltar a acontecer:
acrescentar o `--check` ao `before_cmds` do `krankerl.toml` (falha o
packaging em vez de depender de disciplina).

---

### [x] R2 — Cache de alertas nunca é invalidada em mutações (MEDIUM)

**Onde:** `lib/Service/SecurityAnalyzerService.php:27-75` (cache 60s em
`getAlerts()`); mutações em `lib/Service/ShareRemediationService.php`
(`applyPassword` / `applyExpiration` / `revoke`) e
`lib/Service/ShareDeletionService.php::deleteRows()`.

**Problema:** o fluxo da UI é ação → `load()` imediato → a lista é re-servida
da cache **ainda com o item corrigido**. O admin clica "Add password" e nada
muda no ecrã durante até 60s. Traiçoeiro: sem Redis/memcached,
`createDistributed` degrada para cache por-request e o bug **nunca aparece em
dev** — só em produção com caching real. O comentário no código declara o
trade-off aceitável; a revisão discorda: o reload é imediato e o utilizador
vê estado errado logo a seguir a agir.

**Correção:** expor invalidação no analyzer (ou extrair uma pequena
`AlertsCache` com `invalidate(?string $owner)`) e chamá-la no fim de cada
mutação. Invalidar a chave `'__admin__'` e a chave do owner/initiator do
share afetado. O padrão já existe na app — é o simétrico do `fresh=true` do
gate de órfãs (`OrphanShareService::revoke()`).

**Critério de aceitação:** com Redis ativo, corrigir/revogar um link e
recarregar a lista mostra imediatamente o estado novo (manual); o TTL de 60s
continua a absorver Previous/Next.

---

### [x] R3 — `getShareById(..., onlyValid: false)` não existe no NC 30 (MEDIUM)

**Onde:** `lib/Service/ShareDeletionService.php:65`;
`appinfo/info.xml` declara `min-version="30"`.

**Problema:** verificado contra o código do servidor: no stable30 a
assinatura é `getShareById($id, $recipient = null)` — o 3º parâmetro não
existe (no NC 32 existe). Não crasha (o PHP ignora argumentos extra), mas a
semântica de que o código depende — "não rejeitar shares órfãs/inválidas" —
não existe lá: no NC 30 um share inválido pode ser auto-eliminado +
`ShareNotFound` dentro do manager, caindo no fallback DB por um caminho
diferente e não testado.

**Correção (decidir uma):**
1. Testar o fluxo de revogação de órfãs num NC 30 real e documentar que o
   fallback cobre a diferença; **ou**
2. Confirmar em que versão o parâmetro entrou (existe no 32; verificar 31 em
   `nextcloud/server` stable31) e subir `min-version` em conformidade.

A opção 2 é mais barata e mais honesta se não houver instâncias NC 30 a
suportar.

**Critério de aceitação:** ou evidência de teste em NC 30, ou
`min-version` ≥ versão que introduziu o parâmetro, com nota no CHANGELOG.

**Resolução (2026-07-09):** opção 2. Confirmado contra as tags do
`nextcloud/server` que `$onlyValid` não existe em `v30.0.14` e existe desde
`v31.0.0`; `min-version` subido para 31 em `appinfo/info.xml`, nota
adicionada ao CHANGELOG.

---

## RECOMENDADO NA MESMA LEVA

### [x] R4 — Fallback do `ShareDeletionService` converte falhas transitórias em deletes rasos (LOW)

**Onde:** `lib/Service/ShareDeletionService.php:72-90` (catch `\Throwable` →
`shareRowExists()` → `$fallbackIds`).

**Problema:** se `deleteShare()` falhar *antes* de apagar (ex.:
`LockedException` de um ficheiro em uso), a linha ainda existe e vai para o
fallback DB — uma falha retryable vira bypass permanente de OCM/eventos.

**Correção:** distinguir exceções retryable (`OCP\Lock\LockedException`,
erros de conectividade) e nesses casos **reportar falha** para o resultado do
bulk em vez de forçar o delete raso; manter o fallback apenas para o caso
documentado (owner inexistente / provider indisponível).

**Critério de aceitação:** um share com lock ativo aparece como "failed" no
resultado do bulk e continua na BD; os casos órfãos continuam a ser limpos.

---

### [x] R5 — `revokeAll` de destinatário sem cap (LOW)

**Onde:** `lib/Service/RecipientLookupService.php:120-134` +
`RecipientController::revokeAll`.

**Problema:** o M7 capou o bulk por IDs (500 + chunking no cliente), mas
`revoke-all` resolve os IDs no servidor: um grupo com milhares de partilhas
gera milhares de `deleteShare` síncronos num só pedido HTTP — timeout a meio,
parcialmente aplicado, sem relatório. Improvável a ~300 utilizadores;
relevante para App Store.

**Correção:** processar em lotes de 500 dentro do serviço e devolver
`{deleted, remaining}`; o frontend repete o pedido enquanto `remaining > 0`
(mesmo padrão de chunking já usado nas outras vistas, mas conduzido pelo
servidor).

**Critério de aceitação:** revogar um destinatário com > 500 partilhas
termina sem timeout e reporta o total real revogado.

---

### [x] R6 — Higiene de release (INFO)

**Onde:** `appinfo/info.xml`, `package.json`, `CHANGELOG.md`,
`.nextcloudignore`.

**Problema/Correção, em conjunto:**
- A ronda de 2026-07-08/09 é claramente um **0.3.0** (segurança, performance,
  features novas) — bump em `info.xml` + `package.json` e secção nova no
  CHANGELOG (o topo ainda descreve o 0.2.1 antigo).
- `STATUS.md`, `SECURITY_REVIEW_PLAN*.md`, `FEATURE_GAPS_PLAN.md` e este
  ficheiro **não estão no `.nextcloudignore`** e seguem dentro do tarball da
  App Store — adicionar (README/CHANGELOG/ROADMAP podem ficar).
- Opcional, na passagem: `caption` + `scope="col"` nas tabelas de
  OrphanShares / PersonalApp / RecipientDrilldown (a ShareTable já tem; as
  outras não são ordenáveis, custo ~zero).

**Critério de aceitação:** `krankerl package` produz um tarball 0.3.0 sem os
documentos de plano; CHANGELOG descreve a ronda.

---

## Fora deste plano

Confirmado como **bem implementado** na 2ª revisão (nenhuma ação): H1–H3,
M1–M9, L1–L12, P1–P5, quick wins Q1–Q4 e G1. Destaques: `ShareDeletionService`
(verificação pós-throw + fallback logado), gate `fresh=true` nas órfãs,
`totalAll` para estabilidade do badge, trade-offs documentados no código.

O próximo passo funcional continua a ser o **G2 (acknowledge de alertas)** —
ver [STATUS.md](STATUS.md).
