<?php  
require __DIR__ . '/vendor/autoload.php';  
  
use Psr\Http\Message\ResponseInterface as Response;  
use Psr\Http\Message\ServerRequestInterface as Request;  
use Slim\Factory\AppFactory;  
  
$app = AppFactory::create();  
  
// Configurações  
define('DOMINIO', 'https://hiveos.space');  
define('USUARIO', 'VenusPlay');  
define('SENHA', '659225573');  
define('CACHE_TTL', 300); // 5 minutos de cache  
  
$GENERO_PROIBIDO = ["xxx adultos", "xxx onlyfans"];  
  
// Conexão Redis  
$redis = new Redis();  
$redis->connect('127.0.0.1', 6379);  
  
function cacheFetch($key, $callback) {  
    global $redis;  
    $cached = $redis->get($key);  
    if ($cached) return json_decode($cached, true);  
    $data = $callback();  
    $redis->set($key, json_encode($data), ['ex' => CACHE_TTL]);  
    return $data;  
}  
  
function obterDados($url) {  
    $options = ['http' => ['timeout' => 5]];  
    $context = stream_context_create($options);  
    $json = @file_get_contents($url, false, $context);  
    return $json ? json_decode($json, true) : [];  
}  
  
function ajustarNomeGenero($nome, $GENERO_PROIBIDO) {  
    $parte = strpos($nome, '|') !== false ? trim(explode('|', $nome)[1]) : $nome;  
    $parte = preg_replace('/\b\d{4}\b/', '', $parte);  
    $parte = str_replace(' e ', ' ', $parte);  
    $palavras = explode(' ', $parte);  
    $parte_limpa = implode(' ', array_reverse(array_unique($palavras)));  
    $parte_minuscula = strtolower($parte_limpa);  
    foreach ($GENERO_PROIBIDO as $proibido) {  
        if (strpos($parte_minuscula, $proibido) !== false) return null;  
    }  
    return trim($parte_limpa);  
}  
  
function filtrarConteudoAdulto($lista, $GENERO_PROIBIDO) {  
    return array_values(array_filter($lista, function($item) use ($GENERO_PROIBIDO) {  
        $nome = strtolower($item['category_name'] ?? $item['name'] ?? '');  
        foreach ($GENERO_PROIBIDO as $proibido) {  
            if (strpos($nome, $proibido) !== false) return false;  
        }  
        return true;  
    }));  
}  
  
$app->get('/api/generos', function (Request $request, Response $response) use ($GENERO_PROIBIDO) {  
    $categorias = cacheFetch('generos', function() use ($GENERO_PROIBIDO) {  
        $urls = [  
            DOMINIO."/player_api.php?username=".USUARIO."&password=".SENHA."&action=get_vod_categories",  
            DOMINIO."/player_api.php?username=".USUARIO."&password=".SENHA."&action=get_series_categories"  
        ];  
        $todas = [];  
        foreach ($urls as $url) {  
            $todas = array_merge($todas, obterDados($url));  
        }  
        $categorias = [];  
        $nomes = [];  
        foreach ($todas as $cat) {  
            $nome = ajustarNomeGenero($cat['category_name'] ?? '', $GENERO_PROIBIDO);  
            if ($nome && !in_array($nome, $nomes)) {  
                $nomes[] = $nome;  
                $categorias[] = [  
                    "category_id" => $cat['category_id'],  
                    "category_name" => $nome,  
                    "id_pai" => $cat['parent_id'] ?? 0  
                ];  
            }  
        }  
        return $categorias;  
    });  
    $response->getBody()->write(json_encode($categorias));  
    return $response->withHeader('Content-Type', 'application/json');  
});  
  
$app->get('/api/misturar-filmes-series', function (Request $request, Response $response) use ($GENERO_PROIBIDO) {  
    $combinados = cacheFetch('filmes_series', function() use ($GENERO_PROIBIDO) {  
        $filmes = filtrarConteudoAdulto(obterDados(DOMINIO."/player_api.php?username=".USUARIO."&password=".SENHA."&action=get_vod_streams"), $GENERO_PROIBIDO);  
        $series = filtrarConteudoAdulto(obterDados(DOMINIO."/player_api.php?username=".USUARIO."&password=".SENHA."&action=get_series"), $GENERO_PROIBIDO);  
        return array_merge($filmes, $series);  
    });  
  
    shuffle($combinados);  
  
    $params = $request->getQueryParams();  
    $page = isset($params['page']) ? max(1, intval($params['page'])) : 1;  
    $per_page = isset($params['per_page']) ? max(1, intval($params['per_page'])) : 27;  
    $start = ($page - 1) * $per_page;  
    $dados = array_slice($combinados, $start, $per_page);  
  
    if (!$dados) {  
        $response->getBody()->write(json_encode(['error' => 'Nenhum dado encontrado para esta página.']));  
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');  
    }  
  
    $resultado = [  
        'page' => $page,  
        'per_page' => $per_page,  
        'total' => count($combinados),  
        'data' => $dados  
    ];  
  
    $response->getBody()->write(json_encode($resultado));  
    return $response->withHeader('Content-Type', 'application/json');  
});  
  
$app->get('/api/pesquisar', function (Request $request, Response $response) use ($GENERO_PROIBIDO) {  
    $query = strtolower($request->getQueryParams()['q'] ?? '');  
    if (!$query) {  
        $response->getBody()->write(json_encode(['error' => 'Por favor, forneça um termo de pesquisa usando o parâmetro "q".']));  
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');  
    }  
  
    $combinados = cacheFetch('filmes_series', function() use ($GENERO_PROIBIDO) {  
        $filmes = filtrarConteudoAdulto(obterDados(DOMINIO."/player_api.php?username=".USUARIO."&password=".SENHA."&action=get_vod_streams"), $GENERO_PROIBIDO);  
        $series = filtrarConteudoAdulto(obterDados(DOMINIO."/player_api.php?username=".USUARIO."&password=".SENHA."&action=get_series"), $GENERO_PROIBIDO);  
        return array_merge($filmes, $series);  
    });  
  
    $resultados = array_filter($combinados, function($item) use ($query) {  
        return strpos(strtolower($item['name'] ?? ''), $query) !== false;  
    });  
  
    if (!$resultados) {  
        $response->getBody()->write(json_encode(['message' => 'Nenhum resultado encontrado para a pesquisa.']));  
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');  
    }  
  
    $response->getBody()->write(json_encode(array_values($resultados)));  
    return $response->withHeader('Content-Type', 'application/json');  
});  
  
$app->get('/api/dados-brutos', function (Request $request, Response $response) use ($GENERO_PROIBIDO) {  
    $combinados = cacheFetch('filmes_series', function() use ($GENERO_PROIBIDO) {  
        $filmes = filtrarConteudoAdulto(obterDados(DOMINIO."/player_api.php?username=".USUARIO."&password=".SENHA."&action=get_vod_streams"), $GENERO_PROIBIDO);  
        $series = filtrarConteudoAdulto(obterDados(DOMINIO."/player_api.php?username=".USUARIO."&password=".SENHA."&action=get_series"), $GENERO_PROIBIDO);  
        return array_merge($filmes, $series);  
    });  
  
    $response->getBody()->write(json_encode($combinados));  
    return $response->withHeader('Content-Type', 'application/json');  
});  
  
$app->run();