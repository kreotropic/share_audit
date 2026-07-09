# Plano de correção — Lacunas funcionais (perspetiva de admin empresarial) (2026-07-08)

Terceiro documento da mesma revisão, ao lado de
[SECURITY_REVIEW_PLAN.md](SECURITY_REVIEW_PLAN.md) (High/Medium) e
[SECURITY_REVIEW_PLAN_LOW.md](SECURITY_REVIEW_PLAN_LOW.md) (Low/Info/Performance).
Este cobre lacunas de **produto/feature**, não bugs — o que falta para um
admin empresarial confiar na app a longo prazo.

Várias destas features já estavam identificadas no
[ROADMAP.md](ROADMAP.md) ("Pós-lançamento — só se houver tração"). Onde há
sobreposição, este documento **referencia** o roadmap em vez de duplicar, e
só acrescenta o que é novo ou diferente face ao que lá está escrito.

> Convenção: checkbox por item; marcar `[x]` e referenciar o commit/PR.

---

## Lacunas identificadas

### [x] G1 — Sem undo: revogações são irreversíveis e sem rasto de auditoria

**Implementado (2026-07-09):** `ShareAuditLogger` novo, injetado em
`ShareDeletionService` (usado por `OrphanShareService`/`RecipientLookupService`)
e em `ShareRemediationService` (usado por `ShareActionController` e
`PersonalController`) — os dois caminhos de revogação existentes. Despacha
`OCP\Log\Audit\CriticalActionPerformedEvent` com quem revogou, quantas, ids,
tipos e owners originais. **Requer a app `admin_audit` ativa** para ter
qualquer efeito (estava desativada neste ambiente dev; foi ativada). Verificado
em `data/audit.log`.

O soft delete (`ROADMAP.md` #1) resolve isto a médio prazo, mas é 4-5 dias de
esforço e ainda não está agendado. **Até lá**, um "Revoke all access" enganado
num grupo grande é irreversível **e silencioso** — nem sequer fica registado
quem revogou o quê.

**Correção mínima, imediata:** registar cada revogação (individual e bulk) no
canal de auditoria do Nextcloud — `LoggerInterface` com contexto
`['app' => Application::APP_ID]` no canal `audit` (ver
`OCP\Log\Audit\CriticalActionPerformedEvent` ou equivalente na versão-alvo),
incluindo: admin que executou, share id(s), tipo, owner original, timestamp.

Isto não substitui o soft delete — é a rede de segurança mínima enquanto ele
não existe. Não bloqueia nem depende de #1.

---

### [ ] G2 — Sem "acknowledge"/exceção nos alertas (lacuna funcional nº 1, na opinião de quem reviu)

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
- Esta é a **primeira migration própria da app** (junto com o soft delete, se
  vier primeiro — coordenar para não fazer duas migrations separadas se
  ambas avançarem próximo uma da outra).

**Esforço/impacto:** médio esforço, alto impacto — não há alternativa
nenhuma ferramenta nativa do NC oferece isto.

---

### [ ] G3 — Admin corrige partilhas de utilizadores sem os notificar

Já está no `ROADMAP.md` #3 ("Notificar o owner"), mas nesse plano é uma
**terceira ação** para o alerta "Sensitive file type". A lacuna aqui é mais
ampla: **qualquer** remediação feita pelo admin (`setPassword`, `setExpiration`,
`revoke` em `ShareActionController`) muda a partilha de outra pessoa sem
aviso — o dono ganha uma password que não conhece, ou perde o link sem
explicação.

**Correção (estende o ROADMAP #3):**
- `INotificationManager::notify()` ao `uid_owner` em **toda** ação de
  `ShareActionController` (não só na ação "notify" dedicada), com mensagem
  específica por ação ("O administrador definiu uma password na tua
  partilha X" / "...alterou a expiração..." / "...revogou...").
- Ação alternativa **"pedir ao dono para corrigir"** em vez de o admin corrigir
  diretamente — notificação com deep-link para a vista pessoal do próprio
  dono. Isto é o que muda a app de "ferramenta de polícia" para "ferramenta
  de governança" (tal como observado na análise).

---

### [ ] G4 — Alertas cobrem só links públicos; faltam permissões de edit/share em massa e uploads públicos

**Onde:** `lib/Service/SecurityAnalyzerService.php` — o docstring da classe já
assume "for the MVP this focuses on public links (type 3)".

**Problema:** partilhas com permissão de **edit/share** para grupos enormes,
ou links públicos com **upload** (file drop, permissão `create` sem `read`),
são riscos comparáveis aos links sem password/expiração e ficam completamente
fora do radar.

**Correção:** estender `issuesFor()` com novas regras (configuráveis, como as
existentes em `SettingsService::RULES`):
- `group_share_editable` — partilha tipo grupo com permissão de edit/reshare
  para um grupo acima de um tamanho configurável (requer `IGroupManager` para
  contar membros).
- `public_upload` — link público com `create` mas sem `read` (file drop) ou
  com `create + update` sem password.

Cada regra nova precisa de: predicado em `issuesFor()`, entrada em
`SettingsService::RULES` (toggle em Settings), tradução EN/pt-PT, e entrada
no breakdown por categoria.

---

### [ ] G5 — Edge cases de classificação e contagem

Agrupa três problemas menores de correção, não de feature nova:

1. **Circles inconsistentes** — já coberto como **M9** em
   [SECURITY_REVIEW_PLAN_LOW.md](SECURITY_REVIEW_PLAN_LOW.md); não duplicar
   aqui, só referenciar.
2. **Links já expirados ainda contam como "com expiração"** — `hasExpiration`
   hoje provavelmente verifica só `expiration IS NOT NULL`, sem comparar com
   `now()`. Corrigir para distinguir "tem expiração futura" vs "expirou e
   ainda não foi limpo" (ver também G-quick-win "alerta expira em breve/já
   expirado", abaixo — mesma lógica de comparação de datas, implementar
   junto).
3. **Tipos futuros/sciencemesh caem em "other" sem explicação na UI** — a
   categoria "other" no donut/breakdown devia ter um tooltip ou legenda que
   explique que agrupa tipos não mapeados explicitamente (científicos,
   federação sciencemesh, ou tipos adicionados em versões futuras do NC), em
   vez de aparecer como uma categoria "misteriosa".

*(Instância sem partilhas: confirmado como bem tratado nos empty states —
sem ação necessária.)*

---

## Quick wins (horas, não dias)

### [x] Q1 — M2: export respeita os filtros ativos

Já estava implementado (M2 no `SECURITY_REVIEW_PLAN.md`, feito numa sessão
anterior) — confirmado no código atual, sem alterações necessárias.

Já documentado como **M2** em
[SECURITY_REVIEW_PLAN.md](SECURITY_REVIEW_PLAN.md) — não duplicar a
especificação aqui, só realçar a prioridade: é a diferença entre "feature
anunciada" e "feature real", por isso deve ser tratado como quick win mesmo
estando no plano Medium.

### [x] Q2 — Link "abrir no Files" em cada linha/alerta

**Implementado (2026-07-09):** `fileId` acrescentado a `ShareCollectorService::normalizeRow()`
e a `SecurityAnalyzerService`'s alerts (a partir de `file_source`, já presente
nas queries). Frontend: `ShareTable.vue` (coluna Path) e `AlertCard.vue`
("Open in Files") linkam para `/f/{fileId}` via `generateUrl`.

`file_source` já vem na query (`ShareCollectorService`/`SecurityAnalyzerService`).
Construir o link `/f/{fileId}` (rota nativa do Files app) e mostrá-lo em cada
linha da tabela e de cada alerta — o admin quase sempre quer ver o ficheiro
antes de decidir o que fazer.

### [x] Q3 — Copiar URL do link público diretamente nos alertas

**Implementado (2026-07-09):** botão "Copy link" em `AlertCard.vue` (`token`
já vinha na API) — copia `origin + /s/{token}` via clipboard API, com
feedback "Copied!" por 2s. Sem nova dependência (`@nextcloud/dialogs` não
estava instalado; feedback inline em vez de toast).

O `token` já está disponível nas linhas de alerta (ver `ShareActionController`
e `getAlerts()`). Construir a URL pública (`/s/{token}`) e adicionar um botão
"copiar link" por alerta, para o admin testar o link antes de o revogar.

### [x] Q4 — Alerta "expira em breve / já expirado"

**Implementado (2026-07-09):** `SecurityAnalyzerService::issuesFor()` — duas
issues novas, `already_expired` (warning) e `expiring_soon` (info, janela de
7 dias), independentes do toggle `no_expiration`. **Não** inclui G5.2 (corrigir
`hasExpiration` para não contar links já expirados como "com expiração") —
fora do âmbito combinado desta sessão; ficou só a nota no código a apontar
para lá. Sem toggle em Settings (fora do âmbito de "quick win"; ao contrário
de G4, que trata de regras configuráveis). Verificado ao vivo com datas
forçadas no passado/próximas.

Os dados (`expiration`) já estão na query usada por `issuesFor()`. Acrescentar
duas novas issues (`expiring_soon`, `already_expired`) com severidade
`info`/`warning`. Implementar junto com G5.2 (mesma comparação de datas).

---

## Médio esforço, alto impacto

### [ ] G2 (acknowledge/snooze) — ver acima, é o item de maior prioridade desta secção.

### [ ] G3 (notificar o dono ao remediar) — ver acima, estende o ROADMAP #3.

### [ ] Digest semanal por email para admins

Distinto do **ROADMAP #5** ("Relatórios de compliance por email", que é mais
formal/periódico e depende do histórico do #4). Este é um digest leve e
frequente: `TimedJob` semanal + `IMailer`, resumindo **novos** links inseguros,
**novas** órfãs, e evolução do score desde o último digest. É o que faz a app
continuar a ser usada depois da segunda semana, mesmo antes do histórico
completo (ROADMAP #4) existir — pode arrancar comparando só com o snapshot da
semana anterior, sem esperar pela série temporal completa.

**Relação com o roadmap:** implementar antes ou em paralelo com ROADMAP #5,
não depois — o digest semanal é o que mantém o admin engajado enquanto o
relatório de compliance mais elaborado não chega.

### [ ] Snapshot histórico do exposure score

Já é o **ROADMAP #4** — sem alterações à especificação lá descrita. Registado
aqui só para contexto: "custa pouco e cria o argumento 'estamos a melhorar'
para mostrar à gestão" é a justificação de negócio para priorizar isto mais
cedo do que a tabela de esforço/impacto do roadmap sugere isoladamente.

---

## Diferenciadores face a ferramentas CLI (ex.: sharelisting)

### Soft delete com undo
Já é **ROADMAP #1** — mantém a prioridade. Sem alterações aqui.

### Transferência de ownership de órfãs
Já é **ROADMAP #2** — mantém-se a referência a `occ files:transfer-ownership`
como inspiração de UX (mover em vez de revogar). Sem alterações aqui.

### [ ] Políticas por grupo (ex.: "grupo Finance não pode ter links sem password")

**Novo, não está no roadmap.** Alertas hoje são regras globais
(`SettingsService::RULES` aplica-se à instância inteira). A proposta é permitir
associar regras/exceções a grupos específicos — ex.: o grupo `Finance` nunca
pode ter links públicos sem password, independentemente da regra global.

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

### [ ] Modo relatório PDF/HTML assinado com data, para compliance/auditorias externas

**Novo, não está no roadmap.** O CSV atual (`ReportService`) é para o admin
trabalhar os dados; um relatório formatado — cabeçalho com nome da instância,
data/hora de geração, período coberto, resumo executivo (contagens, score,
top exposições) e uma assinatura/hash simples de integridade — é para
entregar a um auditor externo.

Esboço mínimo: gerar HTML server-side (template dedicado) com os agregados já
calculados por `ShareCollectorService`/`SecurityAnalyzerService`/`ExposureMapService`,
e converter para PDF (avaliar se vale a pena trazer uma dependência de
PDF-rendering ou se um HTML standalone com print stylesheet é suficiente para
o caso de uso — decisão a tomar antes de implementar, não assumir biblioteca
já).

**Relação com M3:** se a decisão do M3 (omitir tokens do CSV) avançar primeiro,
aplicar o mesmo critério aqui — o relatório de compliance não deve incluir
tokens de acesso.

---

## Ordem de execução sugerida

1. **G1 (audit log)** — horas de esforço, elimina o maior risco silencioso
   imediato; não depende de nada.
2. **Quick wins Q1–Q4** — todos independentes entre si e da lista acima,
   fazer num único PR de "melhorias de UX nos alertas/tabela".
3. **G2 (acknowledge)** — maior impacto isolado; é a primeira migration da
   app, por isso vale a pena coordenar com o soft delete (ROADMAP #1) para
   decidir se saem na mesma versão ou não (evitar duas migrations em releases
   consecutivas se puderem ser uma só).
4. **G3 (notificar + "pedir para corrigir")** — depois de G2, para reutilizar
   a mesma UI de ações em alertas que G2 vai mexer.
5. **G4 (novas regras de alerta)** — depois de G2 estar pronto, para que as
   novas regras já nasçam com "acknowledge" disponível (senão repete-se a
   queixa da lacuna nº 1 para as regras novas).
6. **G5 (edge cases)** — pode ser feito em paralelo com qualquer um dos
   acima; são correções pequenas e isoladas.
7. **Digest semanal** — depois de G2/G3, para que o digest já reflita alertas
   "aceites" (não faz sentido mandar email semanal sobre algo que o admin já
   marcou como exceção).
8. **Snapshot histórico (ROADMAP #4)** e **relatórios de compliance
   (ROADMAP #5)** — seguir a ordem já definida no roadmap.
9. **Soft delete (ROADMAP #1)** e **transfer ownership (ROADMAP #2)** — seguir
   o roadmap; G1 (audit log) é o paliativo até o #1 chegar, não um substituto.
10. **Políticas por grupo** e **relatório PDF assinado** — maior esforço,
    tratar como iniciativas de versão futura ("se houver tração"), não
    entram nesta ronda de correções.
