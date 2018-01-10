<?php
namespace HalimonAlexander\Router;

class RouterLite
{
  private $allowedMethods = [
    'DELETE',
    'GET',
    'PATCH',
    'POST',
    'PUT',
  ];

  protected $routes = [];

  private function validateMethods($methods)
  {
    if ( empty($methods) )
      return [];

    if ( is_string($methods) )
      $methods = explode("|", $methods);

    $return = [];
    foreach ($methods as $key => $method){
      $method = strtoupper($method);
      if (in_array($methods[$key], $this->allowedMethods))
        $return[] = $method;
    }

    return $return;
  }

  public function route($methods, string $pattern, $callback)
  {
    $methods = $this->validateMethods($methods);
    if (empty($methods))
      throw new \RuntimeException('Invalid methods provided');

    foreach ($methods as $method)
      $this->routes[$method][] = [
        "pattern"  => $pattern,
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
}
