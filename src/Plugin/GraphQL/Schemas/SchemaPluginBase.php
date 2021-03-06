<?php

namespace Drupal\graphql\Plugin\GraphQL\Schemas;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\graphql\GraphQL\Execution\ResolveContext;
use Drupal\graphql\Plugin\FieldPluginManager;
use Drupal\graphql\Plugin\MutationPluginManager;
use Drupal\graphql\Plugin\SchemaBuilderInterface;
use Drupal\graphql\Plugin\SchemaPluginInterface;
use Drupal\graphql\Plugin\TypePluginManagerAggregator;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Schema;
use GraphQL\Type\SchemaConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;

abstract class SchemaPluginBase extends PluginBase implements SchemaPluginInterface, SchemaBuilderInterface, ContainerFactoryPluginInterface, CacheableDependencyInterface {

  /**
   * The field plugin manager.
   *
   * @var \Drupal\graphql\Plugin\FieldPluginManager
   */
  protected $fieldManager;

  /**
   * The mutation plugin manager.
   *
   * @var \Drupal\graphql\Plugin\MutationPluginManager
   */
  protected $mutationManager;

  /**
   * The type manager aggregator service.
   *
   * @var \Drupal\graphql\Plugin\TypePluginManagerAggregator
   */
  protected $typeManagers;

  /**
   * Static cache of field definitions.
   *
   * @var array
   */
  protected $fields = [];

  /**
   * Static cache of mutation definitions.
   *
   * @var array
   */
  protected $mutations = [];

  /**
   * Static cache of type instances.
   *
   * @var array
   */
  protected $types = [];

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.graphql.field'),
      $container->get('plugin.manager.graphql.mutation'),
      $container->get('graphql.type_manager_aggregator')
    );
  }

  /**
   * SchemaPluginBase constructor.
   *
   * @param array $configuration
   *   The plugin configuration array.
   * @param string $pluginId
   *   The plugin id.
   * @param array $pluginDefinition
   *   The plugin definition array.
   * @param \Drupal\graphql\Plugin\FieldPluginManager $fieldManager
   *   The field plugin manager.
   * @param \Drupal\graphql\Plugin\MutationPluginManager $mutationManager
   *   The mutation plugin manager.
   * @param \Drupal\graphql\Plugin\TypePluginManagerAggregator $typeManagers
   *   The type manager aggregator service.
   */
  public function __construct(
    $configuration,
    $pluginId,
    $pluginDefinition,
    FieldPluginManager $fieldManager,
    MutationPluginManager $mutationManager,
    TypePluginManagerAggregator $typeManagers
  ) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
    $this->fieldManager = $fieldManager;
    $this->mutationManager = $mutationManager;
    $this->typeManagers = $typeManagers;
  }

  /**
   * {@inheritdoc}
   */
  public function getSchema() {
    $config = new SchemaConfig();

    if ($this->hasMutations()) {
      $config->setMutation(new ObjectType([
        'name' => 'MutationRoot',
        'fields' => function () {
          return $this->getMutations();
        },
      ]));
    }

    $config->setQuery(new ObjectType([
      'name' => 'QueryRoot',
      'fields' => function () {
        return $this->getFields('Root');
      },
    ]));

    $config->setTypes(function () {
      return $this->getTypes();
    });

    $config->setTypeLoader(function ($name) {
      return $this->getType($name);
    });

    return new Schema($config);
  }

  /**
   * {@inheritdoc}
   */
  public function hasFields($type) {
    return isset($this->pluginDefinition['field_association_map'][$type]);
  }

  /**
   * {@inheritdoc}
   */
  public function hasMutations() {
    return !empty($this->pluginDefinition['mutation_map']);
  }

  /**
   * {@inheritdoc}
   */
  public function hasType($name) {
    return isset($this->pluginDefinition['type_map'][$name]);
  }

  /**
   * {@inheritdoc}
   */
  public function getFields($type) {
    $association = $this->pluginDefinition['field_association_map'];
    $fields = $this->pluginDefinition['field_map'];

    if (isset($association[$type])) {
      return $this->processFields(array_map(function ($id) use ($fields) {
        return $fields[$id];
      }, $association[$type]));
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getMutations() {
    return $this->processMutations($this->pluginDefinition['mutation_map']);
  }

  /**
   * {@inheritdoc}
   */
  public function getTypes() {
    return array_map(function ($name) {
      return $this->getType($name);
    }, array_keys($this->pluginDefinition['type_map']));
  }

  /**
   * {@inheritdoc}
   */
  public function getSubTypes($name) {
    $association = $this->pluginDefinition['type_association_map'];
    return isset($association[$name]) ? $association[$name] : [];
  }

  /**
   * {@inheritdoc}
   */
  public function resolveType($name, $value, ResolveContext $context, ResolveInfo $info) {
    $association = $this->pluginDefinition['type_association_map'];
    $types = $this->pluginDefinition['type_map'];
    if (!isset($association[$name])) {
      return NULL;
    }

    foreach ($association[$name] as $type) {
      // TODO: Try to avoid loading the type for the check. Consider to make it static!
      if (isset($types[$type]) && $instance = $this->buildType($types[$type])) {
        if ($instance->isTypeOf($value, $context, $info)) {
          return $instance;
        }
      }
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getType($name) {
    $types = $this->pluginDefinition['type_map'];
    $references = $this->pluginDefinition['type_reference_map'];
    if (isset($types[$name])) {
      return $this->buildType($this->pluginDefinition['type_map'][$name]);
    }

    do {
      if (isset($references[$name])) {
        return $this->buildType($types[$references[$name]]);
      }
    } while (($pos = strpos($name, ':')) !== FALSE && $name = substr($name, 0, $pos));

    throw new \LogicException(sprintf('Missing type %s.', $name));
  }

  /**
   * {@inheritdoc}
   */
  public function processMutations($mutations) {
    return array_map([$this, 'buildMutation'], $mutations);
  }

  /**
   * {@inheritdoc}
   */
  public function processFields($fields) {
    return array_map([$this, 'buildField'], $fields);
  }

  /**
   * {@inheritdoc}
   */
  public function processArguments($args) {
    return array_map(function ($arg) {
      return [
        'type' => $this->processType($arg['type']),
      ] + $arg;
    }, $args);
  }

  /**
   * {@inheritdoc}
   */
  public function processType($type) {
    list($type, $decorators) = $type;

    return array_reduce($decorators, function ($type, $decorator) {
      return $decorator($type);
    }, $this->getType($type));
  }

  /**
   * Retrieves the type instance for the given reference.
   *
   * @param array $type
   *   The type reference.
   *
   * @return \GraphQL\Type\Definition\Type
   *   The type instance.
   */
  protected function buildType($type) {
    if (!isset($this->types[$type['id']])) {
      $creator = [$type['class'], 'createInstance'];
      $manager = $this->typeManagers->getTypeManager($type['type']);
      $this->types[$type['id']] = $creator($this, $manager, $type['definition'], $type['id']);
    }

    return $this->types[$type['id']];
  }

  /**
   * Retrieves the field definition for a given field reference.
   *
   * @param array $field
   *   The type reference.
   *
   * @return array
   *   The field definition.
   */
  protected function buildField($field) {
    if (!isset($this->fields[$field['id']])) {
      $creator = [$field['class'], 'createInstance'];
      $this->fields[$field['id']] = $creator($this, $this->fieldManager, $field['definition'], $field['id']);
    }

    return $this->fields[$field['id']];
  }

  /**
   * Retrieves the mutation definition for a given field reference.
   *
   * @param array $mutation
   *   The mutation reference.
   *
   * @return array
   *   The mutation definition.
   */
  protected function buildMutation($mutation) {
    if (!isset($this->mutations[$mutation['id']])) {
      $creator = [$mutation['class'], 'createInstance'];
      $this->mutations[$mutation['id']] = $creator($this, $this->mutationManager, $mutation['definition'], $mutation['id']);
    }

    return $this->mutations[$mutation['id']];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return $this->pluginDefinition['schema_cache_tags'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return $this->pluginDefinition['schema_cache_max_age'];
  }
}
