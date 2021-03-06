<?php

namespace Drupal\graphql\GraphQL\Execution;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;
use GraphQL\Type\Definition\ResolveInfo;

class ResolveContext implements RefinableCacheableDependencyInterface {
  use RefinableCacheableDependencyTrait;

  /**
   * Read-only list of global values.
   *
   * @var array
   */
  protected $globals;

  /**
   * The context stack.
   *
   * @var array
   */
  protected $contexts = [];

  /**
   * ResolveContext constructor.
   *
   * @param array $globals
   *   List of global values to expose to field resolvers.
   */
  public function __construct(array $globals = []) {
    $this->globals = $globals;
  }

  /**
   * Get a contextual value for the current field.
   *
   * Allows field resolvers to inherit contextual values from their ancestors.
   *
   * @param string $name
   *   The name of the context.
   * @param \GraphQL\Type\Definition\ResolveInfo $info
   *   The resolve info object.
   * @param mixed $default
   *   An arbitrary default value in case the context is not set.
   *
   * @return mixed
   *   The current value of the given context or the given default value if the
   *   context wasn't set.
   */
  public function getContext($name, ResolveInfo $info, $default = NULL) {
    $operation = isset($info->operation->name->value) ? $info->operation->name->value : $info->operation->operation;
    $path = $info->path;

    do {
      $key = implode('.', $path);
      if (isset($this->contexts[$operation][$key][$name])) {
        return $this->contexts[$operation][$key][$name];
      }
    } while (array_pop($path) && !empty($path));

    return $default;
  }

  /**
   * Sets a contextual value for the current field and its decendents.
   *
   * Allows field resolvers to set contextual values which can be inherited by
   * their descendents.
   *
   * @param string $name
   *   The name of the context.
   * @param $value
   *   The value of the context.
   * @param \GraphQL\Type\Definition\ResolveInfo $info
   *   The resolve info object.
   *
   * @return $this
   */
  public function setContext($name, $value, ResolveInfo $info) {
    $operation = isset($info->operation->name->value) ? $info->operation->name->value : $info->operation->operation;
    $key = implode('.', $info->path);
    $this->contexts[$operation][$key][$name] = $value;

    return $this;
  }

  /**
   * Retrieve a global/static parameter value.
   *
   * @param string $name
   *   The name of the global parameter.
   * @param mixed $default
   *   An arbitrary default value in case the context is not set.
   *
   * @return mixed|null
   *   The requested global parameter value or the given default value if the
   *   parameter is not set.
   */
  public function getGlobal($name, $default = NULL) {
    if (isset($this->globals[$name])) {
      return $this->globals[$name];
    }

    return $default;
  }

}