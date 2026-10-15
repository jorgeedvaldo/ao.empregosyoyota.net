<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tolerância a falhas da base de dados
    |--------------------------------------------------------------------------
    |
    | O alojamento limita o utilizador MySQL a um número reduzido de ligações
    | simultâneas (erro 1226). Quando esse limite é atingido, as páginas
    | públicas passam a ser servidas a partir da cache em vez de devolverem
    | um erro 500.
    |
    */

    'enabled' => env('DB_FALLBACK_ENABLED', true),

    /*
    | Cache de dados e cache de páginas vivem em stores próprios, para que um
    | "php artisan cache:clear" (ou a limpeza da cache pela administração) não
    | destrua a cópia de emergência que mantém o site de pé.
    */
    'stores' => [
        'data' => env('DB_FALLBACK_STORE', 'fallback'),
        'pages' => env('PAGE_CACHE_STORE', 'pages'),
    ],

    /*
    | Perfis de cache. "ttl" é a validade da cópia normal; "emergency" é a
    | validade da cópia usada apenas quando o MySQL está indisponível. A cópia
    | de emergência sobrevive à expiração da cópia normal.
    |
    | "shared" guarda poucos registos (categorias, últimas vagas/notícias) e
    | por isso pode durar muito tempo. "detail" multiplica-se por página de
    | conteúdo, logo tem uma retenção mais curta.
    */
    'profiles' => [
        'shared' => ['ttl' => 600, 'emergency' => 604800],
        'listing' => ['ttl' => 300, 'emergency' => 172800],
        'detail' => ['ttl' => 900, 'emergency' => 86400],
        'feed' => ['ttl' => 1800, 'emergency' => 86400],
    ],

    /*
    | Disjuntor: depois de "failure_threshold" falhas de ligação dentro de
    | "failure_window" segundos, deixa de se tentar ligar durante
    | "open_seconds". Durante esse período só uma requisição a cada
    | "probe_interval" segundos testa a recuperação.
    */
    'breaker' => [
        'failure_threshold' => env('DB_FALLBACK_FAILURES', 3),
        'failure_window' => 60,
        'open_seconds' => env('DB_FALLBACK_OPEN_SECONDS', 30),
        'probe_interval' => env('DB_FALLBACK_PROBE_SECONDS', 10),
    ],

    /*
    | Cache de HTML das páginas públicas. A cópia "fresh" evita consultas
    | repetidas; a cópia "stale" é servida quando o MySQL está indisponível e
    | não existe forma de regenerar a página.
    */
    'pages' => [
        'enabled' => env('PAGE_CACHE_ENABLED', true),
        'fresh_ttl' => env('PAGE_CACHE_TTL', 300),
        'stale_ttl' => env('PAGE_CACHE_STALE_TTL', 172800),

        /*
        | Parâmetros de query que fazem parte da chave de cache. Qualquer
        | outro parâmetro (utm_*, gclid, etc.) é ignorado para a chave, e um
        | pedido com parâmetros fora desta lista não é colocado em cache,
        | evitando chaves infinitas e envenenamento da cache.
        */
        'query_allowlist' => ['page'],

        /*
        | Cabeçalhos HTTP de cache pública (e LiteSpeed). Desligado por
        | omissão: só deve ser activado depois de confirmar que o alojamento
        | tem a cache do LiteSpeed disponível e que nenhuma página pública
        | devolve conteúdo personalizado. Ver README-FALLBACK.md.
        */
        'http_cache_headers' => env('PAGE_CACHE_HTTP_HEADERS', false),
        'http_cache_max_age' => env('PAGE_CACHE_HTTP_MAX_AGE', 300),
        'litespeed' => env('PAGE_CACHE_LITESPEED', false),
    ],

    /*
    | Retenção: limite de espaço por store, aplicado pelo comando
    | "cache:prune-fallback". As entradas expiradas são removidas primeiro; se
    | ainda exceder o limite, removem-se as menos recentes.
    */
    'retention' => [
        'max_megabytes' => [
            'fallback' => env('DB_FALLBACK_MAX_MB', 64),
            'pages' => env('PAGE_CACHE_MAX_MB', 128),
        ],
    ],

    'retry_after' => 60,

];
