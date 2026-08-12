<?php
declare(strict_types=1);

namespace Health;

/**
 * 아주 작은 라우터. 패턴은 '/log/session/{id}' 형태만 지원한다.
 * {name} 은 '/' 를 뺀 한 조각에 대응하고, 매칭 값이 핸들러 인자로 들어간다.
 */
final class Router
{
    /** @var array<int,array{method:string,regex:string,keys:array<int,string>,handler:callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    private function add(string $method, string $pattern, callable $handler): void
    {
        $keys  = [];
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use (&$keys): string {
                $keys[] = $m[1];
                return '([^/]+)';
            },
            $pattern
        );
        $this->routes[] = [
            'method'  => $method,
            'regex'   => '#^' . $regex . '$#u',
            'keys'    => $keys,
            'handler' => $handler,
        ];
    }

    /** 요청 경로에서 base_path 접두사와 쿼리스트링을 떼어낸다. */
    public static function pathFromRequest(string $uri, string $basePath): string
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $base = rtrim($basePath, '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . ltrim(rawurldecode($path), '/');
        return $path !== '/' ? rtrim($path, '/') : '/';
    }

    /** 매칭되면 핸들러를 실행한다. 못 찾으면 false. */
    public function dispatch(string $method, string $path): bool
    {
        $pathMatched = false;
        foreach ($this->routes as $r) {
            if (!preg_match($r['regex'], $path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($r['method'] !== $method) {
                continue;
            }
            array_shift($m);
            ($r['handler'])(...$m);
            return true;
        }
        if ($pathMatched) {
            Http::status(405);
            header('Allow: GET, POST');
            echo '405 Method Not Allowed';
            return true;
        }
        return false;
    }
}
