# Plano de correção — Revisão de qualidade de implementação (2026-07-10)

Terceira revisão da série, depois de
[SECURITY_REVIEW_PLAN.md](SECURITY_REVIEW_PLAN.md) (2026-07-08),
[SECURITY_REVIEW_PLAN_LOW.md](SECURITY_REVIEW_PLAN_LOW.md) (2026-07-08),
[FEATURE_GAPS_PLAN.md](FEATURE_GAPS_PLAN.md) (2026-07-08) e
[PRE_RELEASE_PLAN.md](PRE_RELEASE_PLAN.md) (2026-07-09, R1–R6). Esta ronda
não parte de um code review genérico — é uma **auditoria da execução**: reli
código-fonte linha a linha (não apenas os planos) para confirmar que o que
está marcado `[x]` nos quatro documentos acima está mesmo correto, e para
avaliar a qualidade do que falta implementar.

**Ficheiros revistos integralmente nesta ronda:** `SecurityAnalyzerService`,
`ShareDeletionService`, `ShareAuditLogger`, `RecipientLookupService`,
`ShareMapper`, `ShareRemediationService`, `ShareCollectorService`,
`ExposureMapService`, `OrphanShareService`, `ReportService`,
`SettingsService`, `PasswordGeneratorService`, os 6 controllers, `AlertCard.vue`
e as 4 tabelas com caption/`scope="col"`; mais l10n (`--check` verde, 184/184
chaves em paralelo EN/pt_PT), versões (`info.xml`/`package.json` = 0.3.0
consistentes) e packaging (`.nextcloudignore`, `krankerl.toml`).

**Veredito:** a qualidade é alta. R1–R6, G1, Q1–Q4 e a amostra revista de
H1–H3/M1–M9/L1–L12/P1–P5 estão implementados como os planos descrevem, sem
regressões. Os padrões (guard `requireAdmin()` centralizado, invalidação de
cache simétrica em toda mutação, distinção retryable-vs-fallback no delete,
docblocks que explicam o trade-off em vez de só descrever o código) são
consistentes em toda a base. Não é uma revisão que "confia no relatório
anterior" — encontrou uma inconsistência nova (C1, abaixo) que nenhum dos
quatro documentos anteriores tinha identificado.

> Convenção: checkbox por item; marcar `[x]` e referenciar o commit/PR.

---

## Correções (bugs novos, encontrados nesta revisão)

### [x] C1 — `ExposureMapService` trata tipos de partilha não mapeados como "internal" (risco zero) em vez de "other" (LOW, mas security-relevant)

**Onde:** `lib/Service/ExposureMapService.php:19-28` (`CATEGORY`) e
`:45` (`$category = self::CATEGORY[$type] ?? 'internal';`).

**Problema:** `ShareCollectorService::CATEGORY_BY_TYPE` (usado no dashboard e
na tabela "Todas as partilhas") já trata qualquer `share_type` fora do mapa
como `'other'` — é o comportamento que `FEATURE_GAPS_PLAN.md` G5.3 já assume
("tipos futuros/sciencemesh caem em 'other'"). `ExposureMapService`, que
calcula o **score de exposição 0–100**, faz o oposto: um tipo não mapeado cai
no fallback `'internal'`, peso 0 — o tipo *mais seguro* possível. Se o
Nextcloud introduzir um novo `share_type` externo ou público numa versão
futura (exatamente o cenário que G5.3 já antecipa para a UI), o score de
exposição vai **subestimar silenciosamente** o risco real dessas partilhas em
vez de as tratar com cautela, até alguém atualizar o mapa — o oposto do que
se espera de uma feature de segurança.

**Correção:** bucket para tipos não mapeados consistente com
`ShareCollectorService` — criar uma categoria `'other'` no `getOverview()`
com peso conservador (sugestão: igual a `'external'`, peso 1 — nem assume
seguro nem duplica o peso de público) e mostrar essa fatia no breakdown, que
é literalmente o que G5.3 já pede para a UI do donut. Resolve as duas lacunas
(fallback errado + falta de legenda "other") na mesma alteração.

**Critério de aceitação:** um `share_type` fora de `CATEGORY_BY_TYPE`/`CATEGORY`
aparece como "Other" no breakdown de exposição, com peso > 0, não some
silenciosamente dentro de "Internal".

**Implementado (2026-07-10):** `ExposureMapService::CATEGORY`/`WEIGHT` — o
fallback de tipo desconhecido passou de `'internal'` (peso 0) para `'other'`
(peso 1, igual a `'external'`); `getOverview()` inclui sempre a chave
`'other'` em `counts`. Frontend (`ExposureMap.vue`): a fatia "Other" só é
renderizada quando `counts.other > 0` (hoje é sempre 0 — todos os tipos
conhecidos já estão mapeados — por isso não introduz ruído visual em nenhuma
instância real), com tooltip a explicar que agrupa tipos não reconhecidos por
esta versão da app; sem botão "View" nessa fatia porque o filtro
`types` do `/api/shares` é um IN, não um NOT-IN — não há forma honesta de
"ver" esse conjunto ainda (ficaria a abrir "All shares" sem filtro, o que
enganaria o admin). String nova traduzida em EN/pt_PT, `l10n.py --check`
verde (185/185).

---

### [x] C2 — Falta de cabeçalhos SPDX em 23/26 ficheiros PHP (INFO)

**Onde:** todo `lib/` (na prática, os 26 ficheiros — a contagem inicial de
23 subestimou por causa de um `head -5` truncado ao confirmar).

**Problema:** já documentado como INFO em `STATUS.md` ("sem testes, sem CI,
sem cabeçalhos SPDX — o próprio plano diz que não são bugs e não bloqueiam
nada") — não é um bug, é hygiene. Reconfirmado aqui só porque o custo de
corrigir é trivial (adicionar 2 linhas a ~23 ficheiros) e cada ronda que passa
sem o fazer é mais ficheiros nesse estado no próximo `git blame`.

**Correção:** `// SPDX-FileCopyrightText: <ano> <owner>` + `// SPDX-License-Identifier: <licença de LICENSE>` no topo de cada ficheiro em `lib/`; considerar um script (`build/`) que verifica isto tal como `l10n.py --check`, para não voltar a divergir.

**Critério de aceitação:** todos os ficheiros `.php` em `lib/` têm cabeçalho SPDX.

**Implementado (2026-07-10):** bloco `SPDX-FileCopyrightText: 2025 Ricardo
Ferreira <ricardo.ferreira@jofebar.com>` / `SPDX-License-Identifier:
AGPL-3.0-or-later` (ano e licença de `LICENSE`/`composer.json`) inserido
entre `declare(strict_types=1);` e `namespace` nos 26 ficheiros de `lib/`;
`php -l` confirmado em todos. Um script de verificação automática (tipo
`l10n.py --check`) fica fora do âmbito desta correção pontual — ver M-Q3
(CI) para essa peça, que pode cobrir isto também.

---

## Melhorias (robustez/qualidade, não bloqueiam nada)

### [x] M-Q1 — Testes automatizados inexistentes para a lógica com mais invariantes implícitas

Já no backlog do `ROADMAP.md` ("Testes: ... maior retorno em
`SecurityAnalyzerService` e `ShareCollectorService`"), mas vale reforçar o
porquê depois desta revisão: `ShareMapper::insecureLinkConditions()` tem um
comentário explícito a dizer que é "single source of truth ... para nunca
divergir" entre `findInsecureLinks()` e `countInsecureLinks()` — exatamente o
tipo de invariante que só um teste (não um comentário) garante ao longo do
tempo. Da mesma forma, `SecurityAnalyzerService::issuesFor()` tem quatro
ramos de data (`no_expiration` / `already_expired` / `expiring_soon` /
nenhum) cuja combinação com os toggles de `SettingsService::RULES` é fácil de
quebrar silenciosamente numa futura alteração.

**Correção:** cobertura mínima de `phpunit` para `SecurityAnalyzerService::issuesFor()` (casos: sem password, sem expiração, expirado, a expirar em breve, com password+expiração futura = sem issues, cada toggle desligado) e para o par `findInsecureLinks()`/`countInsecureLinks()` do `ShareMapper` (mesma contagem para o mesmo estado). Não é preciso cobrir tudo — só o que tem lógica condicional, não simples passagem de dados.

**Prioridade sugerida:** antes ou junto de G2 (abaixo) — G2 vai adicionar mais um eixo de filtragem (`acknowledged`) exatamente ao código que este item identifica como frágil; entra mais barato com um teste já a proteger o comportamento atual.

**Implementado (2026-07-10):** infraestrutura de testes criada seguindo o
padrão já usado em `folder_protection` (app irmã do mesmo autor):
`tests/bootstrap.php`, `phpunit.xml`, `tests/Unit/ArrayCache.php` (fake
`ICache` em memória). `composer.json` já tinha `nextcloud/ocp`/`phpunit`
como dev deps (nunca usadas); acrescentado `doctrine/dbal` (resolve
`Doctrine\DBAL\ParameterType`, usado pelas assinaturas de `IQueryBuilder`) e
um `classmap` para `vendor/nextcloud/ocp/OCP` em `autoload-dev` — isto foi
além do padrão da app irmã: com o classmap, os testes correm em
`vendor/bin/phpunit` **no host, sem precisar do Docker** (a app irmã só
corre dentro do container, onde o autoloader real do NC existe); confirmado
que também continua a passar dentro do container `nextcloud-app` (sem
conflito de classes duplicadas).

- `tests/Unit/SecurityAnalyzerServiceTest.php` — 15 casos: password vazio/nulo/definido,
  cada toggle de regra desligado, os quatro ramos de expiração (nenhuma, futura seguro,
  a expirar em ≤7 dias, já expirada), data não-parseável, extensão sensível
  com a regra ligada/desligada, ordenação por severidade, e os três cenários
  de cache (hit dentro do TTL, `invalidate()` força recomputo, vistas
  admin/pessoal em chaves de cache independentes).
- `tests/Unit/ShareMapperTest.php` — 4 casos focados no early-return de
  `countInsecureLinks()`: 0 sem executar a query quando nenhuma regra está
  ativa e não há cutoff; `sensitive_file=true` com lista de extensões vazia
  não deve equivaler a "aceita tudo"; executa e devolve a contagem real
  quando pelo menos uma regra está ativa; um cutoff de "a expirar em breve"
  sozinho (sem nenhuma regra) já é candidato válido e deve executar.

`vendor/bin/phpunit -c phpunit.xml` → 19 testes, 31 assertions, verde (host
e container). `vendor/` acrescentado ao `.nextcloudignore` (não estava —
sem isso, `krankerl package` incluiria os ~20MB de dependências de teste no
tarball da App Store; `phpunit.xml` e `.phpunit.result.cache` também
adicionados).

### [x] M-Q2 — `RecipientController::shares`/`revokeAll` sem `#[UserRateLimit]` (LOW)

**Onde:** `lib/Controller/RecipientController.php` — só `search()` tem `#[UserRateLimit(limit: 60, period: 60)]`.

**Problema:** inconsistência menor face ao padrão já usado no mesmo controller e em `PersonalController` (rate limit em todos os endpoints mutáveis/de leitura pesada). Sem exploração óbvia — é admin-only — mas `shares()` e `revokeAll()` são ambos potencialmente mais caros que `search()` (o segundo é uma mutação em lote).

**Correção:** aplicar o mesmo `#[UserRateLimit]` (ajustando limites ao custo de cada endpoint) para consistência.

**Implementado (2026-07-10):** `shares()` recebeu o mesmo limite que
`search()` (60/60s, é leitura); `revokeAll()` recebeu um limite mais baixo
(20/60s) por ser a única mutação do controller e já processar lotes de 500
por pedido (ver R5) — 20 pedidos/min chega para drenar praticamente
qualquer `remaining`.

### [x] M-Q3 — CI mínimo

Nenhum workflow de CI no repositório. `l10n.py --check` já corre no `krankerl package` (R1), mas só localmente/no packaging — um PR não é validado automaticamente. Quando phpunit existir (M-Q1), vale um workflow simples (GitHub Actions ou equivalente) a correr `l10n.py --check` + `phpunit` + lint de PHP/JS em cada push. Baixa prioridade isolada, mas o ROI sobe assim que houver testes para correr.

**Implementado (2026-07-10):** `.github/workflows/ci.yml`, três jobs
independentes em paralelo — `l10n` (`build/l10n.py --check`), `php`
(`php -l` a todo o `lib/`, `composer install`, `vendor/bin/phpunit`) e
`frontend` (`npm ci` + `npm run build`). Corre em push/PR para
`main`/`master`. Como o classmap do M-Q1 tirou a dependência do Docker, o
job `php` corre num runner GitHub normal, sem precisar de montar um
Nextcloud completo.

---

## O que falta — plano de implementação priorizado

Isto reorganiza `FEATURE_GAPS_PLAN.md` e `ROADMAP.md` (que já têm o detalhe
técnico de cada item — não duplicado aqui) numa sequência executável única,
com uma decisão que os dois documentos deixavam em aberto.

### Decisão: G2 não deve esperar pelo soft delete

`FEATURE_GAPS_PLAN.md` sugere "coordenar" G2 (acknowledge) com o soft delete
do roadmap (`#1`) para não fazer duas migrations em rondas próximas. Depois
de reler ambos: **não fazem sentido no mesmo lote**. O soft delete está
explicitamente sob "Pós-lançamento — só se houver tração" — é condicional.
G2 não tem essa condição; é a lacuna funcional nº 1 do próprio
`FEATURE_GAPS_PLAN.md`, independente de tração. Bloquear G2 à espera de uma
feature que só avança "se houver tração" troca o item de maior impacto
isolado por uma otimização de contagem de migrations. Recomendação: **G2
avança já, como migration própria**; se/quando o soft delete avançar, é a
migration seguinte — normal ao longo da vida de uma app, não um problema a evitar.

### Fase 1 — G2: acknowledge/exceção nos alertas
Maior impacto isolado por fazer. Primeira migration da app
(`oc_shareaudit_ack`), `AckController`, UI de "Aceitar" + filtro "mostrar
aceites". Ver `FEATURE_GAPS_PLAN.md` G2 para o esboço técnico completo.
Fazer M-Q1 (testes de `issuesFor`) antes ou junto, pelo motivo já explicado.

### Fase 2 — G5: edge cases (pode correr em paralelo com a Fase 1)
Três correções pequenas e independentes: `hasExpiration` a não distinguir
expirado de futuro (G5.2 — mesma comparação de datas já usada em Q4),
tooltip/legenda na categoria "other" do breakdown (G5.3 — resolvido em
conjunto com **C1** acima, já que ambos mexem no mesmo bucket), e circles
inconsistentes (já feito via M9, só falta o checkbox em
`SECURITY_REVIEW_PLAN_LOW.md`).

### Fase 3 — G3: notificar o dono + "pedir para corrigir"
Depois de G2, para reutilizar a UI de ações nos alertas que G2 vai alterar.
`INotificationManager::notify()` em toda ação de `ShareActionController`, não
só numa ação dedicada — ver `FEATURE_GAPS_PLAN.md` G3.

### Fase 4 — G4: novas regras de alerta
`group_share_editable` e `public_upload`. Depois de G2, para que as regras
novas já nasçam com "acknowledge" disponível.

### Fase 5 — Digest semanal por email
`TimedJob` + `IMailer`, resumindo novidades desde o último digest. Depois de
G2/G3 (não faz sentido notificar sobre algo já marcado como aceite).

### Fase 6 — Roadmap #4 + #5: histórico de exposição + relatórios de compliance
Nova tabela de snapshots diários, `TimedJob`, gráfico de trend; depois o
relatório de compliance por email que consome esse histórico para mostrar
deltas.

### Fase 7 — Pós-tração (mantém-se condicional, sem alteração à decisão do roadmap)
Soft delete (`#1`), transferência de ownership de órfãs (`#2`), políticas por
grupo, relatório PDF/HTML assinado — só avançam com tração confirmada na App
Store, como já decidido em `ROADMAP.md`.

### Contínuo, sem fase própria
C1, C2, M-Q1–M-Q3 (acima) e o backlog menor já listado em `ROADMAP.md`
(truncagem de labels, screenshots limpos) — encaixam em qualquer uma das
fases acima como custo marginal, não justificam uma ronda dedicada.
P6 (streaming do export) continua adiado — sem alteração, mantém-se sem
evidência de instâncias que o justifiquem.

---

## Resumo para o `STATUS.md`

- 2 correções novas (C1 LOW/security-relevant, C2 INFO já conhecido).
- 3 melhorias novas (M-Q1–M-Q3), nenhuma bloqueante.
- Sequência de 7 fases para o que falta, substituindo a pergunta em aberto
  "G2 espera pelo soft delete?" por uma decisão: não espera.
