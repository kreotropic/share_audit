# Checklist global — estado dos planos (2026-07-09)

Ponto da situação combinando [SECURITY_REVIEW_PLAN.md](SECURITY_REVIEW_PLAN.md)
(High/Medium), [SECURITY_REVIEW_PLAN_LOW.md](SECURITY_REVIEW_PLAN_LOW.md)
(Low/Info/Performance), [FEATURE_GAPS_PLAN.md](FEATURE_GAPS_PLAN.md) e
[ROADMAP.md](ROADMAP.md). Cada documento continua a ser a fonte de verdade
para o detalhe de cada item — isto é só o resumo para saber rapidamente o que
falta.

**Total: 34 feitos · 2 adiados · 18 por fazer**

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

**G2 (acknowledge)** é o maior impacto isolado que resta, mas implica a
primeira migration da app — vale a pena decidir se entra na mesma leva que
o soft delete do roadmap (#1), já que ambos precisam de migration.
