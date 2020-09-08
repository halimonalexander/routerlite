<?php
namespace HalimonAlexander\RouterLite;

class Router
{
  private $allowedMethods = [
    'DELETE',
    'GET',
    'PATCH',
    'POST',
    'PUT',
  ];

  /**
   * @var array The route patterns and their handling functions
   */
  private $routes = [];

  private $errorsCallback = [];
  

  /**
   * @var string The Request Method that needs to be handled
   */
  private $requestedMethod = '';

  /**
   * @var string The Server Base Path for Router Execution
   */
  private $serverBasePath;
  
  /**
   * @var bool Allow or not to use relative routing in different subfolders
   */
  private $useRelativeRouting;
  
  public function __construct(bool $useRelativeRouting = false)
  {
      $this->useRelativeRouting = $useRelativeRouting;
  }
    
    private function validateMethods($methods)
  {
    foreach ($methods as $key=>$method)
      if (!in_array($method, $this->allowedMethods))
        unset($methods[$key]);

    return $methods;
  }

  /**
   * Get all request headers.
   *
   * @return array The request headers
   */
  private function getRequestHeaders()
  {
    $headers = [];
    // If getallheaders() is available, use that
    if (function_exists('getallheaders')) {
      $headers = getallheaders();
      // getallheaders() can return false if something went wrong
      if ($headers !== false) {
        return $headers;
      }
    }
    // Method getallheaders() not available or went wrong: manually extract 'm
    foreach ($_SERVER as $name => $value) {
      if ((substr($name, 0, 5) == 'HTTP_') || ($name == 'CONTENT_TYPE') || ($name == 'CONTENT_LENGTH')) {
        $headers[str_replace(array(' ', 'Http'), array('-', 'HTTP'), ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
      }
    }
    return $headers;
  }

  /**
   * Get the request method used, taking overrides into account.
   *
   * @return string The Request method to handle
   */
  private function getRequestMethod()
  {
    // Take the method as found in $_SERVER
    $method = $_SERVER['REQUEST_METHOD'];

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
      $headers = $this->getRequestHeaders();
      if (isset($headers['X-HTTP-Method-Override']) && in_array($headers['X-HTTP-Method-Override'], array('PUT', 'DELETE', 'PATCH'))) {
        $method = $headers['X-HTTP-Method-Override'];
      }
    }
    return $method;
  }

  /**
   * Execute the router: Loop all defined before middleware's and routes, and execute the handling function if a match was found.
   *
   * @param object|callable $callback Function to be executed after a matching route was handled (= after router middleware)
   *
   * @return bool
   */
  public function run($callback = null)
  {
    $this->requestedMethod = $this->getRequestMethod();

    $numHandled = 0;
    if (isset($this->routes[$this->requestedMethod])) {
      $numHandled = $this->handle($this->routes[$this->requestedMethod], true);
    }

    if ($numHandled != 0) {
      if ($callback && is_callable($callback)) {
        $callback();
      }
    } else {
      if (isset($this->errorsCallback['404'])) {
        $this->invoke($this->errorsCallback['404']);
      } else {
        header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
      }
    }

    return (bool)$numHandled;
  }

  /**
   * Handle a a set of routes: if a match is found, execute the relating handling function.
   *
   * @param array $routes Collection of route patterns and their handling functions
   * @param bool $quitAfterRun Does the handle function need to quit after one route was matched?
   *
   * @return int The number of routes handled
   */
  private function handle($routes, $quitAfterRun = false)
  {
    $numHandled = 0;
    $uri = $this->getCurrentUri();

    foreach ($routes as $route) {
      if (preg_match_all('#^\/' . $route['pattern'] . '$#', $uri, $matches, PREG_OFFSET_CAPTURE)) {

        $matches = array_slice($matches, 1);

        $params = array_map(function ($match, $index) use ($matches) {
          // We have a following parameter: take the substring from the current param position until the next one's position (thank you PREG_OFFSET_CAPTURE
          if (isset($matches[$index + 1]) && isset($matches[$index + 1][0]) && is_array($matches[$index + 1][0]) && $matches[$index + 1][0][1] != -1) {
            return trim(substr($match[0][0], 0, $matches[$index + 1][0][1] - $match[0][1]), '/');
          } // We have no following parameters: return the whole lot
          else {
            return isset($match[0][0]) ? trim($match[0][0], '/') : null;
          }
        }, $matches, array_keys($matches));

        // Call the handling function with the URL parameters if the desired input is callable
        $this->invoke($route['callback'], $params);
        ++$numHandled;

        if ($quitAfterRun) {
          break;
        }
      }
    }

    return $numHandled;
  }

  /**
   * Call the function
   *
   * @param $fn
   * @param array $params
   */
  private function invoke($fn, $params = [])
  {
    if (is_callable($fn)) {
      call_user_func_array($fn, $params);
    }
    elseif (preg_match('/@/', $fn) !== false) {
      list($controller, $method) = explode('@', $fn);
      if (class_exists($controller)) {
        // First check if is a static method, directly trying to invoke it.
        // If isn't a valid static method, we will try as a normal method invocation.
        if (call_user_func_array(array(new $controller(), $method), $params) === false) {
          // Try to call the method as an non-static method. (the if does nothing, only avoids the notice)
          if (forward_static_call_array(array($controller, $method), $params) === false) ;
        }
      }
    }
  }

  /**
   * Define the current relative URI.
   *
   * @return string
   */
  protected function getCurrentUri()
  {
      $uri = $_SERVER['REQUEST_URI'];
    
      if ($this->useRelativeRouting) {
          $basePath = $this->getBasePath();
          
          // Get the current Request URI and remove rewrite base path from it (= allows one to run the router in a sub folder)
          if (strstr($uri, $basePath) !== false) {
              $uri = substr($uri, strlen($basePath));
          }
      }

    // Don't take query params into account on the URL
    if (strstr($uri, '?')) {
      $uri = substr($uri, 0, strpos($uri, '?'));
    }
    // Remove trailing slash + enforce a slash at the start
    return '/' . trim($uri, '/');
  }

  /**
   * Return server base Path, and define it if isn't defined.
   *
   * @return string
   */
  protected function getBasePath()
  {
    // Check if server base path is defined, if not define it.
    if ($this->serverBasePath === null) {
      $this->serverBasePath = implode('/', array_slice(explode('/', $_SERVER['SCRIPT_NAME']), 0, -1)) . '/';
    }
    return $this->serverBasePath;
  }

  /**
   * Store a route and a handling function to be executed when accessed using one of the specified methods.
   *
   * @param string $methods Allowed methods, | delimited
   * @param string $pattern A route pattern such as /about/system
   * @param object|callable $fn The handling function to be executed
   */
  public function route($methods, string $pattern, $callback)
  {
    $methods = explode("|", $methods);
    if (empty($methods))
      throw new \RuntimeException('Invalid methods provided');

    $methods = $this->validateMethods($methods);
    foreach ($methods as $method)
      $this->routes[$method][] = [
        "pattern" => trim($pattern, '/'),
        "callback" => $callback,
      ];

    return $this;
  }

  public function delete(string $pattern, $callback)
  {
    return $this->route("DELETE", $pattern, $callback);
  }

  public function get(string $pattern, $callback)
  {
    return $this->route("GET", $pattern, $callback);
  }

  public function patch(string $pattern, $callback)
  {
    return $this->route("PATCH", $pattern, $callback);
  }

  public function post(string $pattern, $callback)
  {
    return $this->route("POST", $pattern, $callback);
  }

  public function put(string $pattern, $callback)
  {
    return $this->route("PUT", $pattern, $callback);
  }

  /**
   * Set the 404 handling function.
   *
   * @param object|callable $fn The function to be executed
   */
  public function set404($fn)
  {
    $this->errorsCallback['404'] = $fn;
  }
}
