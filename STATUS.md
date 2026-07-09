# Checklist global — estado dos planos (2026-07-09)

Ponto da situação combinando [SECURITY_REVIEW_PLAN.md](SECURITY_REVIEW_PLAN.md)
(High/Medium), [SECURITY_REVIEW_PLAN_LOW.md](SECURITY_REVIEW_PLAN_LOW.md)
(Low/Info/Performance), [FEATURE_GAPS_PLAN.md](FEATURE_GAPS_PLAN.md),
[PRE_RELEASE_PLAN.md](PRE_RELEASE_PLAN.md) (2ª revisão, pré-submissão) e
[ROADMAP.md](ROADMAP.md). Cada documento continua a ser a fonte de verdade
para o detalhe de cada item — isto é só o resumo para saber rapidamente o que
falta.

**Total: 40 feitos · 2 adiados · 18 por fazer**

> ✅ A 2ª revisão (2026-07-09) validou a execução dos planos de 2026-07-08;
> os 6 itens que resultaram (R1–R6, incluindo os 3 que bloqueavam a
> submissão à App Store) estão todos feitos — ver PRE_RELEASE_PLAN.md.
> Nada resta a bloquear a submissão.

---

## SECURITY_REVIEW_PLAN.md (High/Medium) — 12/12 ✅ completo

Todos os itens H1–H3 e M1–M9 feitos.

---

## SECURITY_REVIEW_PLAN_LOW.md (Low/Info/Performance) — 17/18

Todos os L1–L12 e P1–P5 feitos.

- [ ] **P6** — export materializa até 100k linhas em memória (streaming).
      Adiado: maior esforço, sem evidência de instâncias grandes que
      justifiquem.

INFO (sem checkbox no documento original): sem testes, sem CI, sem
cabeçalhos SPDX — o próprio plano diz que não são bugs e não bloqueiam nada.

---

## PRE_RELEASE_PLAN.md (2ª revisão, 2026-07-09) — 6/6 ✅ completo

Regressões e pendências encontradas ao validar a execução dos planos acima —
todas corrigidas.

**Bloqueavam submissão:**

- [x] **R1** — 11 strings (+1 órfã de sessão anterior) adicionadas a
      `en.json`/`pt_PT.json`; `--check` adicionado ao `before_cmds` do
      `krankerl.toml`.
- [x] **R2** — `SecurityAnalyzerService::invalidate()` chamado no fim de
      cada mutação (`ShareRemediationService`, `ShareDeletionService`),
      limpando `__admin__` + owner/initiator afetados.
- [x] **R3** — confirmado contra as tags do `nextcloud/server` que
      `$onlyValid` só existe a partir do NC 31; `min-version` subido para 31.

**Recomendados na mesma leva:**

- [x] **R4** — `LockedException`/`StorageNotAvailableException` tratadas à
      parte em `ShareDeletionService::deleteRows()`; reportadas como
      `failed`, nunca forçadas pelo fallback de delete direto.
- [x] **R5** — `RecipientLookupService::revokeAll()` processa em lotes de
      500 no servidor e devolve `{deleted, failed, remaining}`; o frontend
      repete o pedido enquanto `remaining > 0`.
- [x] **R6** — versão 0.3.0 em `info.xml`/`package.json`, CHANGELOG
      atualizado, planos internos excluídos via `.nextcloudignore`, caption
      + `scope="col"` adicionados às tabelas de Orphans/Personal/Recipient.

---

## FEATURE_GAPS_PLAN.md — 5/13

Feitos: G1 (audit log), Q1 (= M2, export respeita filtros), Q2 (link "abrir
no Files"), Q3 (copiar URL do link público), Q4 (alerta "expira em
breve/já expirado").

- [ ] **G2** — acknowledge/exceção nos alertas. Prioridade nº 1 do
      documento; é a primeira migration própria da app.
- [ ] **G3** — notificar o dono ao remediar (estende `ROADMAP.md` #3;
      cobre toda ação de `ShareActionController`, não só a de notificar).
- [ ] **G4** — novas regras de alerta: partilhas de grupo com edit/share
      para grupos grandes, e links públicos com upload sem password.
- [ ] **G5** — edge cases: `hasExpiration` a contar links já expirados como
      "com expiração"; tooltip/legenda na categoria "other". (O 1º subitem,
      circles inconsistentes, já está feito via M9.)
- [ ] Digest semanal por email para admins.
- [ ] Snapshot histórico do exposure score (= `ROADMAP.md` #4).
- [ ] Políticas por grupo (ex.: "grupo Finance nunca sem password") — maior
      esforço, nova tabela + resolução de precedência grupo vs. global.
- [ ] Relatório PDF/HTML assinado para auditorias externas — maior
      esforço.

---

## ROADMAP.md — 0/11

### Pós-lançamento (só se houver tração)

- [ ] **#1** Soft delete de partilhas (reciclagem) — 4-5 dias, impacto alto,
      primeira migration da app.
- [ ] **#2** Transferir ownership de partilhas órfãs — 2-3 dias.
- [ ] **#3** Notificar o owner (ação nos alertas) — 1-2 dias
      (↔ `FEATURE_GAPS_PLAN.md` G3).
- [ ] **#4** Histórico/trend de exposição — 2-3 dias
      (↔ `FEATURE_GAPS_PLAN.md` "Snapshot histórico").
- [ ] **#5** Relatórios de compliance por email — 3-4 dias, depende do #4.

### Backlog menor

- [ ] Paginação/seletor "Por página" em Orphans e Lookup.
- [ ] Encurtar o título do widget (trunca no painel estreito).
- [ ] Screenshots com dados de demonstração limpos.
- [ ] Suite de testes (`phpunit`) — maior retorno em
      `SecurityAnalyzerService` e `ShareCollectorService`.
- [ ] Truncagem do label "Hiperligação pública" no gráfico "Partilhas por
      tipo".
- [x] Índice em `share_with`/`path` (= P5) — decisão já tomada: adiar até
      haver instâncias maiores.

---

## Sugestão de próximo passo

1. **PRE_RELEASE_PLAN.md está fechado (R1–R6)** — nada resta a bloquear a
   submissão à App Store; `l10n.py --check` está verde e faz parte do
   `krankerl package`.
2. Rever o diff (nada foi commitado) e cortar o **0.3.0**.
3. Depois do lançamento: **G2 (acknowledge)** continua a ser o maior impacto
   isolado que resta, mas implica a primeira migration da app — vale a pena
   decidir se entra na mesma leva que o soft delete do roadmap (#1), já que
   ambos precisam de migration.
