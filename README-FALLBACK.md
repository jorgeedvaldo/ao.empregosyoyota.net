# Tolerância a falhas do MySQL

O alojamento limita o utilizador MySQL a 30 ligações simultâneas. Quando esse
limite é atingido, o servidor devolve:

```
SQLSTATE[42000] [1226] User 'xxx' has exceeded the 'max_user_connections' resource (current value: 30)
```

Antes, isso dava erro 500 em todo o site. Agora as páginas públicas continuam
a ser servidas a partir da cache, e o site recupera sozinho quando o MySQL
voltar.

## Como funciona

```
Visitante → Laravel
              │
              ├── cache de HTML válida?  → devolve a página (zero consultas MySQL)
              │
              ├── MySQL disponível?      → consulta, actualiza as duas caches
              │
              └── MySQL indisponível     → 1.ª opção: cópia de emergência dos dados
                                           2.ª opção: última página guardada (stale)
                                           3.ª opção: página de manutenção, HTTP 503
```

Camadas, de fora para dentro:

| Camada | Ficheiro | Papel |
|---|---|---|
| Cache de HTML | `app/Http/Middleware/CachePublicPage.php`, `app/Services/PublicPageCache.php` | Serve a página inteira sem tocar no MySQL. Guarda uma cópia `fresh` e uma `stale`. |
| Cache de dados | `app/Services/DatabaseFallbackCache.php` | Duas cópias por consulta: `fresh` (normal) e `emergency` (só em falha). |
| Disjuntor | `app/Services/DatabaseCircuitBreaker.php` | Depois de 3 falhas deixa de tentar ligar durante 30s; só 1 pedido por 10s testa a recuperação. |
| Classificação do erro | `app/Support/DatabaseFailure.php` | Distingue "sem ligações" de um erro de SQL real, que continua a dar erro. |
| Leituras públicas | `app/Services/PublicContent.php` | Todas as consultas das páginas públicas, já com as chaves de cache. |
| Página de manutenção | `public/database-unavailable.html` | Último recurso, HTTP 503 + `Retry-After`. |

Um erro de programação (sintaxe SQL, coluna inexistente, violação de
integridade) **não** é escondido: só os códigos de falha de ligação
(1226, 1040, 1203, 2002, 2003, 2006, 2013, ...) accionam o fallback.

## Tempos de cache

Definidos em `config/fallback.php` e ajustáveis sem mexer no código.

| Perfil | Usado por | Cópia normal | Cópia de emergência |
|---|---|---|---|
| `shared` | categorias, últimas vagas, últimas notícias | 10 min | 7 dias |
| `listing` | listagens paginadas (home, /empregos, /articles, CVs) | 5 min | 2 dias |
| `detail` | páginas de vaga, notícia, CV, categoria | 15 min | 24 h |
| `feed` | `/sitemap.xml`, `/feed` | 30 min | 24 h |
| Cache de HTML | páginas públicas | 5 min | 2 dias (`stale`) |

As cópias de emergência têm chaves próprias, pelo que **não desaparecem**
quando a cópia normal expira, nem com `php artisan cache:clear` (vivem nos
stores `fallback` e `pages`, não no store por omissão).

Qualquer gravação em Job, Article, Category ou Curriculo invalida de imediato
todas as cópias `fresh` (dados e HTML), mantendo as de emergência.

## Alterações necessárias no `.env` (produção)

```dotenv
CACHE_DRIVER=file
SESSION_DRIVER=file
```

Estas duas são as importantes: com `database`, cada pedido gastaria ligações
MySQL — exactamente o recurso que falta. As restantes variáveis
(`DB_FALLBACK_*`, `PAGE_CACHE_*`) são opcionais e têm valores por omissão
seguros; ver `.env.example`.

Depois de alterar o `.env`:

```bash
php artisan config:clear
```

## Cron

A limpeza das caches precisa do agendador do Laravel. No cPanel (Cron Jobs),
uma entrada de minuto a minuto:

```
* * * * * cd /home/<utilizador>/<pasta-do-site> && php artisan schedule:run >> /dev/null 2>&1
```

Se preferir não usar o agendador, uma entrada horária directa serve:

```
0 * * * * cd /home/<utilizador>/<pasta-do-site> && php artisan cache:prune-fallback >> /dev/null 2>&1
```

Sem uma destas entradas tudo continua a funcionar, mas as entradas expiradas
só saem do disco quando o limite é atingido na execução manual do comando.

## Índices (opcional, manual)

**Nada nesta solução precisa de `php artisan migrate`.** Não são criadas
tabelas nem colunas: as caches vivem em ficheiros e o código só lê colunas
que já existem.

Mais: **não corra `php artisan migrate` nesta instalação.** A base de dados é
partilhada com o domínio principal e as migrações do repositório são
anteriores ao esquema que está em produção (não têm `country_id` nem `slug`,
e criariam tabelas que já existem). O comando tentaria aplicar tudo o que
considera pendente, não apenas o que interessa.

Os índices abaixo são só desempenho — reduzem o tempo de cada consulta e,
com isso, o tempo que cada ligação fica ocupada. São opcionais e podem ser
aplicados mais tarde, um a um, no phpMyAdmin:

```sql
ALTER TABLE `jobs`          ADD INDEX `jobs_country_id_id_index` (`country_id`, `id`);
ALTER TABLE `jobs`          ADD INDEX `jobs_slug_index` (`slug`);
ALTER TABLE `articles`      ADD INDEX `articles_country_id_id_index` (`country_id`, `id`);
ALTER TABLE `articles`      ADD INDEX `articles_slug_index` (`slug`);
ALTER TABLE `curriculos`    ADD INDEX `curriculos_slug_index` (`slug`);
ALTER TABLE `category_jobs` ADD INDEX `category_jobs_job_id_index` (`job_id`);
ALTER TABLE `category_jobs` ADD INDEX `category_jobs_category_id_index` (`category_id`);
```

Notas:

- São alterações aditivas: não mexem em dados nem no que o domínio principal
  lê ou escreve, e beneficiam ambos os sites (as tabelas são as mesmas).
- Erro `1061 Duplicate key name` significa apenas que o índice já existe —
  pode ignorar essa linha.
- Em tabelas grandes um `ALTER TABLE` bloqueia escritas durante alguns
  segundos; prefira uma hora de pouco tráfego.
- Para reverter: `ALTER TABLE `jobs` DROP INDEX `jobs_slug_index`;` (e assim
  por diante).

## Como verificar que a cache está a funcionar

```bash
# 1.ª visita: miss. 2.ª visita: hit (sem consultas ao MySQL).
curl -sI https://ao.empregosyoyota.net/ | grep -i x-page-cache
curl -sI https://ao.empregosyoyota.net/ | grep -i x-page-cache

# Ficheiros em cache
ls -R storage/framework/cache/pages | head
du -sh storage/framework/cache/pages storage/framework/cache/fallback

# Espaço e limpeza
php artisan cache:prune-fallback
```

Cabeçalho `X-Page-Cache`: `miss` (gerada agora), `hit` (cache válida),
`stale` (MySQL indisponível, página antiga).

Quando o fallback entra em acção fica registo em `storage/logs/laravel.log`:

```
MySQL indisponível; a servir dados em cache. {"key":"...","driver_code":1226}
MySQL indisponível; a servir página em cache. {"path":"/empregos/..."}
```

## LiteSpeed

A cache de HTML descrita acima é feita na aplicação e funciona em qualquer
servidor. A cache do próprio LiteSpeed (que responderia sem arrancar o PHP)
está **desligada** por omissão porque depende de módulos que podem não estar
disponíveis no plano de alojamento.

Para a activar, depois de confirmar com o suporte que o LiteSpeed Cache está
disponível para o domínio:

1. `.env`: `PAGE_CACHE_HTTP_HEADERS=true` e `PAGE_CACHE_LITESPEED=true`
2. `public/.htaccess`, dentro do bloco `<IfModule LiteSpeed>`:

```apache
<IfModule LiteSpeed>
    CacheLookup on
</IfModule>
```

Atenção: a cache do LiteSpeed é partilhada por todos os visitantes. Só deve
ser activada enquanto as páginas públicas não tiverem conteúdo personalizado
nem formulários com token CSRF. A cache da aplicação já se protege disso
sozinha (não guarda respostas de utilizadores autenticados nem páginas com
token), mas a do servidor não sabe fazer essa distinção.

## Reverter

Tudo o que foi acrescentado é desligável sem remover código:

```dotenv
# Desliga a cache de HTML (as páginas voltam a ser geradas a cada pedido)
PAGE_CACHE_ENABLED=false
# Desliga a cache de dados: todas as páginas voltam a consultar o MySQL em
# cada pedido. A página de manutenção (503) continua a substituir o erro 500,
# por ser tratada no Handler e não nesta cache.
DB_FALLBACK_ENABLED=false
```

```bash
php artisan config:clear
```

Para esvaziar as caches sem desligar nada:

```bash
rm -rf storage/framework/cache/pages/* storage/framework/cache/fallback/*
```

Reversão completa do código:

```bash
git revert <commit>
php artisan config:clear
```
