# Checklist global — estado dos planos (2026-07-10)

Ponto da situação combinando [SECURITY_REVIEW_PLAN.md](SECURITY_REVIEW_PLAN.md)
(High/Medium), [SECURITY_REVIEW_PLAN_LOW.md](SECURITY_REVIEW_PLAN_LOW.md)
(Low/Info/Performance), [FEATURE_GAPS_PLAN.md](FEATURE_GAPS_PLAN.md),
[PRE_RELEASE_PLAN.md](PRE_RELEASE_PLAN.md) (2ª revisão, pré-submissão),
[QUALITY_REVIEW_PLAN.md](QUALITY_REVIEW_PLAN.md) (3ª revisão, auditoria de
qualidade) e [ROADMAP.md](ROADMAP.md). Cada documento continua a ser a fonte
de verdade para o detalhe de cada item — isto é só o resumo para saber
rapidamente o que falta.

**Total: 42 feitos · 2 adiados · 21 por fazer**

> ✅ A 2ª revisão (2026-07-09) validou a execução dos planos de 2026-07-08;
> os 6 itens que resultaram (R1–R6, incluindo os 3 que bloqueavam a
> submissão à App Store) estão todos feitos — ver PRE_RELEASE_PLAN.md.
> Nada resta a bloquear a submissão.
>
> ✅ A 3ª revisão (2026-07-10) auditou a implementação linha a linha (não só
> os planos) — confirma alta qualidade, sem regressões, e identifica 1 bug
> novo (C1, exposure score subestima tipos não mapeados) + 2 melhorias de
> robustez — ver QUALITY_REVIEW_PLAN.md. **C1 e C2 já corrigidos** no mesmo
> dia (ver secção abaixo); M-Q1–M-Q3 continuam por fazer.

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

## QUALITY_REVIEW_PLAN.md (3ª revisão, 2026-07-10) — 2/5

Achados novos de uma auditoria linha a linha à implementação (não apenas aos
planos); nenhum bloqueia nada, mas C1 é um bug de verdade.

- [x] **C1** — `ExposureMapService` conta tipos de partilha não mapeados como
      "internal" (peso 0) em vez de "other"; o score de exposição subestima
      silenciosamente tipos futuros/desconhecidos. Corrigido: fallback passou
      a `'other'` (peso 1); frontend mostra a fatia "Other" (só quando > 0)
      com tooltip explicativo, sem botão "View" (o filtro atual não suporta
      NOT-IN).
- [x] **C2** — cabeçalhos SPDX em falta em 26/26 ficheiros `lib/*.php` (já
      conhecido como INFO; reconfirmado, correção é trivial). Corrigido: bloco
      SPDX (AGPL-3.0-or-later, Ricardo Ferreira) em todos os ficheiros.
- [ ] **M-Q1** — sem testes para `SecurityAnalyzerService::issuesFor()` nem
      para a invariante `findInsecureLinks()`/`countInsecureLinks()` do
      `ShareMapper` (o próprio código documenta que não podem divergir).
- [ ] **M-Q2** — `RecipientController::shares`/`revokeAll` sem
      `#[UserRateLimit]` (inconsistente com `search()` no mesmo controller).
- [ ] **M-Q3** — sem CI a correr `l10n.py --check` (e, no futuro, phpunit)
      automaticamente em cada push.

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
3. ~~**C1** (exposure score) é o único item novo com urgência própria~~ —
   feito, junto com C2 (SPDX), no mesmo dia da 3ª revisão; entram no 0.3.0.
4. Depois do lançamento: **G2 (acknowledge)** continua a ser o maior impacto
   isolado que resta. `QUALITY_REVIEW_PLAN.md` já decide a pergunta em aberto
   desta secção — G2 **não espera** pelo soft delete do roadmap (#1), que
   está condicional a tração e não tem previsão; ver a sequência de fases
   completa em `QUALITY_REVIEW_PLAN.md`.
