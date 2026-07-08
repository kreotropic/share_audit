# Plano de correção — Revisão de segurança (2026-07-08)

Este documento regista o plano de correção para os pontos identificados numa
revisão de código à app, cobrindo os itens **High** (H1–H3) e **Medium**
(M1–M9). Serve como checklist de execução — cada item tem localização exata,
o porquê, a correção proposta e critério de "feito".

> Convenção: cada item tem uma checkbox. Ao implementar, marcar `[x]` e
> referenciar o commit/PR que o resolveu.

---

## HIGH

### [x] H1 — Revogações em massa contornam o `IShareManager`

**Onde:** `lib/Service/OrphanShareService.php:120-139` (`revoke()`),
`lib/Service/RecipientLookupService.php:112-136` (`revokeAll()`)

**Problema:** ambos fazem `DELETE FROM oc_share` diretamente na BD.
Consequências:
- Partilhas federadas (tipos `TYPE_REMOTE` / `TYPE_REMOTE_GROUP`): o servidor
  remoto nunca recebe o unshare OCM → o destinatário fica com uma montagem
  morta.
- Nenhum `ShareDeletedEvent` é disparado → sem Activity, sem limpeza de
  notificações, sem hooks de outras apps.
- Partilhas por email (`TYPE_EMAIL`): tabelas auxiliares do `sharebymail`
  ficam órfãs.

**Correção:** substituir o delete direto por iteração via `IShareManager`,
resolvendo o provider correto por `share_type`:

```php
private const PROVIDER_BY_TYPE = [
    IShare::TYPE_USER => 'ocinternal', IShare::TYPE_GROUP => 'ocinternal',
    IShare::TYPE_LINK => 'ocinternal', IShare::TYPE_EMAIL => 'ocMailShare',
    IShare::TYPE_REMOTE => 'ocFederatedSharing',
    IShare::TYPE_REMOTE_GROUP => 'ocFederatedSharing',
    IShare::TYPE_ROOM => 'ocRoomShare', IShare::TYPE_CIRCLE => 'ocCircleShare',
];

public function revoke(array $ids): int {
    $deleted = 0;
    foreach ($this->loadRows($ids) as $row) { // id + share_type numa query
        $provider = self::PROVIDER_BY_TYPE[(int)$row['share_type']] ?? 'ocinternal';
        try {
            $share = $this->shareManager->getShareById($provider . ':' . $row['id']);
            $this->shareManager->deleteShare($share); // trata children, eventos, OCM
            $deleted++;
        } catch (ShareNotFound) {
            // provider indisponível (app desativada) → fallback DB + log
        }
    }
    return $deleted;
}
```

Notas de implementação:
- `deleteShare()` já apaga as linhas filhas (USERGROUP/USERROOM) — o delete
  manual de `parent` deixa de ser necessário em ambos os serviços.
- Aplicar o mesmo padrão a `RecipientLookupService::revokeAll()` (carrega os
  ids do recipient, depois delega ao manager em vez do `DELETE` direto).
- Precisa de `IShareManager` injetado em ambos os serviços (constructor).
- Fallback: se o provider não estiver disponível (app desativada), manter o
  comportamento antigo (delete direto) mas registar em log (`ILogger`/`LoggerInterface`)
  para o admin saber que o cleanup OCM/eventos não ocorreu.

**Critério de aceitação:**
- Revogar uma partilha federada dispara o pedido OCM de unshare ao servidor remoto.
- Revogar qualquer tipo dispara `ShareDeletedEvent` (verificável via listener de teste
  ou entrada na Activity).
- Revogar uma partilha por email não deixa linhas órfãs na tabela do `sharebymail`.
- Testes existentes de `OrphanShareService`/`RecipientLookupService` continuam a passar.

---

### [x] H2 — `POST /api/orphans/revoke` aceita qualquer ID sem validar que é órfã

**Onde:** `lib/Controller/OrphanShareController.php:47-53` (`revoke()`)

**Problema:** o endpoint apaga qualquer lista de IDs de partilhas, não apenas
órfãs. É admin-only (não há escalação de privilégio), mas:
- Combinado com H1, é um "delete arbitrário a nível de DB" exposto por HTTP.
- Race real: se a conta for reativada entre o load da lista e o clique em
  "Confirm", a app revoga partilhas de um utilizador ativo.

**Correção:** no `OrphanShareService::revoke()`, intersetar os IDs recebidos
com o conjunto atual de partilhas órfãs antes de apagar:

```php
$orphanOwners = array_keys($this->getOrphanOwners());
$rows = /* SELECT id, share_type FROM share WHERE id IN (:ids) AND uid_owner IN (:orphanOwners) */;
```

Só os IDs que sobrevivem a este filtro passam para a lógica do H1.

**Critério de aceitação:**
- Chamar `revoke()` com um ID de partilha de um utilizador ativo não apaga nada
  (e o contador `deleted` devolvido reflete isso).
- Teste: reativar a conta entre o `index()` e o `revoke()` não revoga a partilha.

---

### [x] H3 — `/api/stats` faz ~20 queries + N+1 no user backend, sem cache

**Onde:** `lib/Service/ShareCollectorService.php:41-66`,
`lib/Service/OrphanShareService.php:34-57` (`getOrphanOwners()`),
`lib/Service/SecurityAnalyzerService.php:26-28`

**Problema:** `getOrphanOwners()` faz um `userManager->get()` por cada owner
distinto — em backend LDAP isto passa de ~200ms para vários segundos, e corre
no ecrã de entrada da app (dashboard/stats).

**Correção proposta:**
1. Cache de curto prazo (ex.: `ICache` / `ICacheFactory` com TTL de 60–120s)
   para `getOrphanOwners()` — o resultado não muda a cada request.
2. Reduzir o N+1: se o `IUserManager` do backend em causa suportar
   `getDisabledUsers()` ou equivalente em lote, usar isso em vez de um `get()`
   por uid. Caso não seja viável de forma genérica, documentar o cache como
   mitigação principal.
3. Consolidar as ~20 queries de `/api/stats` onde possível (agrupar contagens
   por tipo/categoria numa única query com `GROUP BY` em vez de uma query por
   métrica), começando pelas que já partilham a mesma tabela/filtro base.

**Critério de aceitação:**
- Medir tempo de resposta de `/api/stats` antes/depois em instância com LDAP
  (ou simulação) — redução mensurável.
- Cache não esconde alterações por mais de ~2 min (aceitável para um dashboard).

---

## MEDIUM

### [x] M1 — Injeção de fórmulas no CSV (CSV injection)

**Onde:** `lib/Service/ReportService.php:31-43` (`buildCsv()`)

**Correção:** prefixar com `'` qualquer célula que comece por `=`, `+`, `-`,
`@`, tab ou `\r`:

```php
private function sanitizeCell(string $v): string {
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}
```

Aplicar a todos os campos que vêm de dados do utilizador (path, owner,
initiator, recipient) antes do `fputcsv`.

---

### [x] M2 — Export CSV ignora filtros de coluna e ordenação

**Onde:** `lib/Controller/ShareApiController.php:87-105` (`export()`) vs
`src/.../ShareList.vue:179` (frontend envia `pathSearch`/`ownerSearch`/`recipientSearch`)

**Correção:** extrair um método partilhado entre `index()` e `export()` que
aceite os mesmos parâmetros de filtro (`pathSearch`, `ownerSearch`,
`recipientSearch`, etc.) e também `sort`/`sortDir`, para que "Export CSV"
exporte exatamente a vista filtrada apresentada ao admin.

---

### [x] M3 — Tokens de links públicos expostos no CSV e na API

**Onde:** `lib/Service/ReportService.php:16-19` (coluna `Token` no CSV),
`lib/Service/ShareCollectorService.php:161`

**Correção:**
- Omitir a coluna `Token` do CSV por omissão; se necessário, torná-la opt-in
  com aviso explícito no export ("este ficheiro conterá credenciais de acesso").
- No JSON da API, só devolver o token onde é efetivamente consumido pela UI
  (não em listagens genéricas).

---

### [x] M4 — Vista pessoal cega a partilhas iniciadas pelo utilizador em ficheiros de terceiros

**Onde:** `lib/Controller/PersonalController.php:56-73`,
`lib/Db/ShareMapper.php:223-249` (`ownerOf()`)

**Problema:** tudo filtra por `uid_owner`; quando o utilizador B cria um link
público numa pasta partilhada por A, a linha fica com `uid_owner = A`,
`uid_initiator = B`. B não vê nem pode corrigir o próprio link inseguro.

**Correção:**
- Query da vista pessoal: filtrar por `uid_owner = :uid OR uid_initiator = :uid`.
- `ownerOf()` / check de ownership: aceitar
  `getShareOwner() === $uid || getSharedBy() === $uid` (semântica nativa do NC).

---

### [x] M5 — Vista pessoal trunca silenciosamente a 200 partilhas

**Onde:** `src/.../PersonalApp.vue:214` (`fetchMyShares({ limit: 200 })`)

**Correção:** adicionar paginação real (o backend já suporta `page`/`limit`)
ou, no mínimo, mostrar o aviso "showing X of Y" já usado no
`RecipientDrilldown`.

---

### [x] M6 — Mensagens de exceção internas devolvidas ao cliente

**Onde:** `lib/Controller/ShareActionController.php:100,117`,
`lib/Controller/PersonalController.php:115`

**Correção:** manter o log completo da exceção (`$this->logger->error(...)`,
já feito), mas devolver ao cliente uma mensagem genérica ou um código de erro
traduzível — nunca `$e->getMessage()` diretamente na resposta JSON.

---

### [x] M7 — Bulk sem limite + "All" nos alertas = pedidos potencialmente enormes

**Onde:** `lib/Controller/ShareActionController.php:84-112` (`bulk()`),
`lib/Controller/ShareApiController.php:140-154`

**Correção:**
- Impor um cap por pedido (ex.: 500 IDs); o frontend faz chunking para
  seleções maiores ("All").
- Alternativa para instâncias grandes: diferir para um background job
  (`IJob`/`QueuedJob`) com progresso reportado à UI, em vez de execução
  síncrona dentro do request HTTP.

---

### [x] M8 — Acessibilidade: ordenação inacessível por teclado

**Onde:** `src/.../ShareTable.vue:10-15`

**Correção:** trocar `<span @click>` nos cabeçalhos ordenáveis por `<button>`
nativo dentro do `<th>`, com `:aria-sort` no `<th>` e `scope="col"`.
Adicionar `<caption>` à tabela.

---

### [x] M9 — Categoria "circle" inconsistente em três sítios

**Onde:** `ShareCollectorService::CATEGORY_BY_TYPE` (sem `TYPE_CIRCLE` → cai
em "other"), `ExposureMapService::CATEGORY` (classifica como "internal"),
`format.js:28-37` (`typeFilterOptions()` não permite filtrar por circle) vs
`App.vue` (drill-down inclui o tipo 7 em "internal").

**Correção:** escolher uma classificação única para `TYPE_CIRCLE` (recomendado:
"internal", visto ser equivalente a grupo dentro da instância) e alinhar as
quatro referências: `CATEGORY_BY_TYPE`, `ExposureMapService::CATEGORY`,
`typeFilterOptions()` e o drill-down do `App.vue`.

---

## Ordem de execução sugerida

1. **H1 + H2 juntos** — H2 é trivial depois de H1 estar implementado (mesma
   função `revoke()` já vai carregar `id` + `share_type`; acrescentar o filtro
   de owners órfãos é uma condição extra na mesma query). Maior risco/impacto,
   fazer primeiro e com testes.
2. **M1, M3** — mudanças pequenas e isoladas no `ReportService`, sem
   dependências.
3. **M2** — depende de refatorar `ShareApiController` para partilhar filtros;
   fazer depois de M1/M3 estarem estáveis no mesmo ficheiro.
4. **M4, M5** — vista pessoal; independentes do resto.
5. **M6, M7** — hardening de controllers; podem andar em paralelo com o acima.
6. **H3** — cache/otimização de queries; medir antes/depois, não bloqueia
   lançamento mas deve entrar antes de instâncias grandes com LDAP.
7. **M8, M9** — cosmético/consistência; sem dependências, podem ser feitos a
   qualquer momento.

## Notas

- Nenhum destes itens tem testes automatizados de regressão (a app não tem
  suite `phpunit` — ver `ROADMAP.md`, secção "Backlog menor"). Ao corrigir H1/H2,
  vale a pena criar os primeiros testes de `OrphanShareService` e
  `RecipientLookupService`, dado que passam a ter lógica não trivial
  (resolução de provider, fallback, interseção de IDs).
