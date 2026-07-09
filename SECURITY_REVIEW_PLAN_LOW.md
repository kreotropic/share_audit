# Plano de correção — Itens Low, Info e Performance (2026-07-08)

Complementa o [SECURITY_REVIEW_PLAN.md](SECURITY_REVIEW_PLAN.md) (High/Medium).
Aqui ficam os itens **Low**, **Info** e a secção **Performance** da mesma
revisão. Nenhum destes bloqueia lançamento, mas valem a pena antes de a app
ganhar tração (quanto mais utilizadores/instâncias, mais caro fica corrigir
duplicação e falta de índices).

> Convenção: checkbox por item; marcar `[x]` e referenciar o commit/PR.

---

## LOW

### [x] L1 — Duplicação: `requireAdmin()`, `EXCLUDED_TYPES`, `prettyPath()`

**Onde:**
- `requireAdmin()` repetido em `RecipientController.php:60`,
  `ShareApiController.php:190`, `OrphanShareController.php:55`,
  `ShareActionController.php:122`, e inline (sem extração) em
  `ExposureController.php:34-40`.
- `EXCLUDED_TYPES = [2, 11, 13]` duplicado em `ShareMapper.php:27` e
  `OrphanShareService.php:19`.
- `prettyPath()` duplicado em `ShareCollectorService.php:213` e
  `SecurityAnalyzerService.php:123`.

**Correção:**
- Criar `Controller/AdminController.php` (abstract, extends `Controller`) com
  `requireAdmin(): ?JSONResponse` protegido, e migrar os 5 controllers para
  estender essa base em vez de `Controller` diretamente.
- Mover `EXCLUDED_TYPES` para uma única fonte — ex.: constante pública em
  `ShareMapper` (`ShareMapper::EXCLUDED_TYPES`) e `OrphanShareService` passa a
  referenciá-la, ou extrair para uma classe `ShareTypes` partilhada se mais
  serviços vierem a precisar.
- Mover `prettyPath()` para um local partilhado (ex.: método público em
  `ShareCollectorService` injetado em `SecurityAnalyzerService`, ou uma
  classe utilitária sem estado `PathFormatter`).

**Critério de aceitação:** os 5 controllers admin-only chamam o mesmo
`requireAdmin()` herdado; `EXCLUDED_TYPES` e `prettyPath()` têm uma única
definição, todos os usos atuais continuam a passar nos testes manuais.

---

### [x] L2 — `SettingsService` usa `IConfig::getAppValue/setAppValue` (deprecated desde NC29)

**Onde:** `lib/Service/SettingsService.php:32,44,69,76`

**Correção:** migrar para `OCP\IAppConfig` (`getValueString`/`setValueString`
ou equivalente conforme o tipo), injetando `IAppConfig` em vez de `IConfig`
no constructor.

---

### [x] L3 — Ordenar por coluna `password` ordena pelo hash (semanticamente aleatório)

**Onde:** `lib/Db/ShareMapper.php:37` (mapa de colunas ordenáveis)

**Correção:** quando a coluna pedida for `password`, ordenar por
`CASE WHEN s.password IS NULL THEN 0 ELSE 1 END` (com/sem password) em vez da
coluna crua.

---

### [x] L4 — `package.json` (0.1.0) e `info.xml` (0.2.1) dessincronizados

**Onde:** `package.json:3`, `appinfo/info.xml:31`

**Correção:** atualizar `package.json` para `0.2.1`; considerar um passo no
processo de release (script ou nota no `CHANGELOG.md`) que impeça voltar a
divergir.

---

### [x] L5 — `\PDO::FETCH_COLUMN` direto depende de internals do DBAL

**Onde:** `lib/Service/RecipientLookupService.php:119`

**Correção:** substituir por iteração com `fetchOne()` do `IResult`:

```php
$ids = [];
while (($id = $result->fetchOne()) !== false) {
    $ids[] = (int)$id;
}
```

(ou o helper equivalente do OCP, se existir, em vez de `fetchAll(\PDO::FETCH_COLUMN)`).

---

### [x] L6 — Sem rate limiting nos endpoints pessoais e no recipients/search

**Onde:** endpoints de `PersonalController` e `RecipientController::search()`

**Correção:** adicionar `#[UserRateLimit(limit: X, period: Y)]` (namespace
`OCP\AppFramework\Http\Attribute`) aos métodos relevantes — search é o mais
sensível por poder ser chamado a cada tecla no autocomplete.

---

### [x] L7 — Recipient search aceita `q` de 1 carácter no backend (frontend impõe 2)

**Onde:** `lib/Controller/RecipientController.php:33` /
`lib/Service/RecipientLookupService.php:52-56`

**Correção:** validar `mb_strlen(trim($query)) >= 2` no servidor (não só no
frontend) antes de construir o `LIKE %...%`, devolvendo lista vazia caso
contrário — evita full scans disparados por chamadas diretas à API.

---

### [x] L8 — `fputcsv()` sem `$escape` explícito → deprecation no PHP 8.4

**Onde:** `lib/Service/ReportService.php:29,32`

**Correção:** passar `escape: ''` explicitamente nas duas chamadas
(`fputcsv($fh, [...], escape: '')`), já que o NC 33 suporta PHP 8.4.

---

### [x] L9 — `prettyPath()` não trata paths de groupfolders

**Onde:** `ShareCollectorService::prettyPath()` /
`SecurityAnalyzerService::prettyPath()` (mesma lógica duplicada — ver L1)

**Correção:** detetar o prefixo `__groupfolders/<id>/...` e resolver para o
nome do groupfolder (via `OCA\GroupFolders` se disponível, com fallback
gracioso se a app não estiver instalada) em vez de mostrar o path cru.
Fazer esta correção **depois** de L1 (para não duplicar a lógica nova em dois
sítios).

---

### [x] L10 — `format.js:81` usa `toLocaleDateString()` em vez de `getCanonicalLocale()`

**Onde:** `src/.../format.js:81`

**Correção:** usar `getCanonicalLocale()` de `@nextcloud/l10n` (já usado
noutros pontos da app, dado o i18n EN+pt-PT) para que as datas sigam a mesma
locale que o resto do Nextcloud, em vez da locale do browser.

---

### [x] L11 — `countTotal()` é redundante (soma de `countByType()`)

**Onde:** `lib/Db/ShareMapper.php:50` (`countByType()`) e `:71` (`countTotal()`)

**Correção:** remover a query de `countTotal()` e calcular
`array_sum($this->countByType())` no chamador (ou dentro do próprio mapper,
reaproveitando o resultado já obtido de `countByType()` sem nova query).

---

### [x] L12 — Confirmar que os screenshots resolvem no repo `share_audit` (não `share_audit_dashboard`)

**Onde:** `appinfo/info.xml:37-45` — `website`/`repository` apontam para
`github.com/kreotropic/share_audit`, mas a pasta local/app id é
`share_audit_dashboard`.

**Correção:** antes de submeter à App Store, confirmar manualmente que os 5
URLs `raw.githubusercontent.com/kreotropic/share_audit/master/screenshots/*.png`
resolvem (200 OK) — a App Store valida-os no momento da submissão e falha
silenciosamente a app se algum screenshot não carregar.

---

## INFO (sem checkbox de correção — decisões a tomar)

- **Sem testes:** `phpunit` está em `require-dev` e `autoload-dev` aponta
  para `tests/`, mas a pasta não existe. Ver nota no
  [SECURITY_REVIEW_PLAN.md](SECURITY_REVIEW_PLAN.md) — os primeiros testes
  a escrever, quando H1/H2 forem corrigidos, deviam cobrir
  `OrphanShareService` e `RecipientLookupService`.
- **Sem eslint / phpstan / psalm / php-cs-fixer / CI.** Para uma app pública,
  um workflow mínimo de GitHub Actions (lint JS + `occ app:check-code`)
  evita regressões de contribuidores externos. Sugestão de primeiro passo,
  não implementado aqui: `.github/workflows/lint.yml`.
- **Sem cabeçalhos SPDX (REUSE compliance).** O Nextcloud está a migrar para
  isto; recomendado para apps novas mas não bloqueia a App Store hoje.
- **Arquitetura geral:** confirmado — `info.xml` completo e válido, rotas
  RESTful coerentes, bootstrap correto via `IBootstrap`, PSR-4 correto, sem
  migrations (correto, a app não tem tabelas próprias, usa appconfig).
  Controllers magros, lógica nos services, DI por autowiring. Nenhuma ação
  necessária aqui — mantido como referência do estado atual.

---

## PERFORMANCE

### [x] P1 — `/api/stats`: trend mensal faz 12 queries (`countCreatedBetween` × 12) + 3×`countCreatedSince`

**Onde:** `lib/Service/ShareCollectorService.php:88-100`

**Correção:** substituir os 12 `countCreatedBetween()` por uma única query
agrupada por dia (`FLOOR(stime / 86400)`, portável entre MySQL/PostgreSQL/
SQLite) e agregar em PHP por mês:

```php
$qb->select($qb->createFunction('FLOOR(stime / 86400)'))
   ->selectAlias($qb->func()->count('*'), 'cnt')
   ->from('share')
   ->where($qb->expr()->gte('stime', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)))
   ->groupBy(...);
```

Fazer isto **em conjunto com L11** (remover `countTotal()` redundante) e com
a limpeza geral de `countByType`/`countTotal`, já que mexem na mesma zona do
`ShareCollectorService`.

---

### [x] P2 — `countAlerts()` = `count(getAlerts())` — carrega tudo só para um número

**Onde:** `lib/Service/SecurityAnalyzerService.php:26-28`

**Correção:** adicionar `countInsecureLinks()` ao mapper com `COUNT(*)` e os
mesmos predicados `no_password`/`no_expiration` traduzidos para SQL. A regra
`sensitive_file` precisa de avaliação em PHP, mas se as duas primeiras regras
já disparam o alerta, o `COUNT(*)` das duas é um limite inferior aceitável
para o badge — ou aplicar o filtro de extensão como `LIKE '%.ext'` sobre
`f.name` diretamente na query.

---

### [x] P3 — `getOrphanOwners()`: N+1 contra o user backend, sem cache

**Onde:** `lib/Service/OrphanShareService.php:34-57` — corre em `/api/stats` e
`/api/orphans`.

*(Mesmo item que H3 no plano High/Medium — implementar uma vez só.)*

**Correção:** cachear via `ICacheFactory` (distributed cache), TTL 5–15 min:

```php
$cache = $this->cacheFactory->createDistributed('share_audit_dashboard');
if (($cached = $cache->get('orphan_owners')) !== null) {
    return $cached;
}
// ... cálculo atual ...
$cache->set('orphan_owners', $orphans, 600);
```

O mesmo padrão de cache serve para o payload completo de `/api/stats`.

---

### [x] P4 — `/api/alerts`: paginação com `array_slice` sobre o conjunto completo

**Onde:** `lib/Controller/ShareApiController.php:140-154`

**Correção:** manter o recálculo completo (necessário para ranking por
severidade + breakdown), mas cachear o breakdown por ~60s (mesma
`ICacheFactory`) para não pagar o custo em cada "Previous/Next" — não vale a
pena reescrever a lógica de ranking só por isto.

---

### [x] P5 — Falta índice em `share_with`; `ORDER BY path` também sem índice

**Decisão tomada (2026-07-09):** documentado como limitação conhecida no
`ROADMAP.md` (opção 1) — sem migration por agora. Revisitar se/quando a
instância crescer, coordenado com a feature "Soft delete".

**Onde:** `RecipientLookupService::search()` (autocomplete, `ILIKE %...%`),
`recipientSearch` no filtro geral, ordenação por `path`.

**Decisão a tomar:** numa instância de ~300 users é tolerável (dezenas de
milhares de linhas). Duas opções, sem implementar já:
1. Documentar como limitação conhecida (README/ROADMAP).
2. Se a app crescer para instâncias maiores, criar a primeira **migration**
   da app (`lib/Migration/`) para adicionar índice em `share_with` —
   coordenar com o item "Soft delete" do `ROADMAP.md`, que já vai precisar da
   primeira migration de qualquer forma.

---

### [ ] P6 — Export materializa até 100k linhas em memória

**Adiado (2026-07-09):** decisão do utilizador, seguindo a recomendação do
próprio plano — maior esforço, sem evidência real de instâncias com dezenas
de milhares de partilhas. Revisitar quando essa evidência existir.

**Onde:** `ShareCollectorService::getAllForExport()` (~linhas 130-133),
consumido por `ReportService::buildCsv()`

**Correção:** trocar a resposta atual por um `StreamResponse` (ou callback de
streaming do AppFramework) que itera os resultados por chunks (ex.: 1000
linhas de cada vez via `findShares($filters, 1000, $offset)` em loop) e
escreve diretamente no output, em vez de construir a string CSV inteira em
memória antes de responder.

---

### P7 — Frontend/bundle: sem ação necessária

Confirmado como positivo: chunks de `NcColorPicker`/`NcDateTimePicker` já são
lazy-split pelo `@nextcloud/vue`, tree-shaking do preset oficial funciona,
gráficos são SVG à mão (sem dependência pesada), vistas com `v-if` descarregam
o que não está visível. Mantido aqui apenas como registo de que foi avaliado.

---

## Ordem de execução sugerida

1. **L1** primeiro — desbloqueia L9 (groupfolders) sem duplicar a correção,
   e simplifica L2/L6 ao mexer nos mesmos controllers.
2. **P3 (= H3)** — mesma implementação serve os dois planos; fazer uma vez,
   referenciar nos dois documentos.
3. **P1 + L11** juntos — mesma zona do `ShareCollectorService`/`ShareMapper`.
4. **L2, L3, L5, L8** — mudanças pequenas e independentes, podem ir num único
   PR de "manutenção/deprecations".
5. **L7, L6** — hardening do recipient search, fazer em conjunto.
6. **P2, P4** — otimizações de queries de alerts, independentes do resto.
7. **P6** — maior esforço (streaming de export); só vale a pena quando
   houver evidência real de instâncias com dezenas de milhares de partilhas.
8. **L4, L12** — checklist de pré-release, correr mesmo antes do próximo
   `info.xml` bump de versão.
9. **P5** — decisão adiada; só implementar migration se/quando crescer.
10. **INFO (CI, testes, SPDX)** — não são bugs; abrir como issues separadas
    no repo em vez de bloquear este plano.
