<?php

namespace Drupal\Tests\tripal\Unit\Plugin\Field\FieldType;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\tripal_chado\TripalStorage\ChadoIntStoragePropertyType;
use Drupal\tripal_chado\TripalStorage\ChadoTextStoragePropertyType;
use Drupal\tripal\Entity\TripalEntityType;
use Drupal\tripal\TripalVocabTerms\Interfaces\TripalIdSpaceInterface;
use Drupal\tripal\TripalVocabTerms\PluginManagers\TripalCollectionPluginManager;
use Drupal\tripal_analysis_blast\Plugin\Field\FieldType\ChadoBlastResultsTypeDefault;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Prophecy\Argument;

/**
 * Tests ChadoBlastResultsTypeDefault.
 */
#[CoversClass(ChadoBlastResultsTypeDefault::class)]
#[Group('tripal-field')]
#[Group('tripal-analysis-blast')]
class ChadoBlastResultsTypeDefaultTest extends UnitTestCase {

  /**
   * Test that defaultFieldSettings() returns an array and merges parent.
   */
  public function testDefaultFieldSettings(): void {
    $settings = ChadoBlastResultsTypeDefault::defaultFieldSettings();
    $this->assertIsArray($settings);
    // Test for the known keys.
    $this->assertArrayHasKey('termIdSpace', $settings);
    $this->assertArrayHasKey('termAccession', $settings);
    $this->assertArrayHasKey('debug', $settings);
  }

  /**
   * Test that defaultStorageSettings() sets storage_plugin_id.
   *
   * It should be set to chado_storage.
   */
  public function testDefaultStorageSettings(): void {
    $settings = ChadoBlastResultsTypeDefault::defaultStorageSettings();
    $this->assertIsArray($settings);
    $this->assertSame('chado_storage', $settings['storage_plugin_id']);
    // Keys inherited from TripalFieldItemBase.
    $this->assertArrayHasKey('termIdSpace', $settings);
    $this->assertArrayHasKey('termAccession', $settings);
    $this->assertArrayHasKey('storage_plugin_settings', $settings);
  }

  /**
   * Tests that tripalTypes() returns a RealStoragePropertyType.
   *
   * This should have been set with the term from settings.
   */
  public function testTripalTypesWithExplicitTerm(): void {
    $this->setUpIdspaceContainer();

    $field_def = $this->createMock(FieldDefinitionInterface::class);
    $field_def->method('getTargetEntityTypeId')->willReturn('tripal_entity');
    $field_def->method('getSettings')->willReturn([
      'termIdSpace' => 'format',
      'termAccession' => '1333',
    ]);

    $types = ChadoBlastResultsTypeDefault::tripalTypes($field_def);
    $this->assertCount(2, $types, 'Expect two types in the field definition');
    $this->assertInstanceOf(ChadoIntStoragePropertyType::class, $types[0], 'Type [0] holds the record_id so should be int type');
    $this->assertInstanceOf(ChadoTextStoragePropertyType::class, $types[1], 'Type [1] holds the serialized data so should be text type');
    $this->assertSame('SIO', $types[0]->getTermIdSpace());
    $this->assertSame('000729', $types[0]->getTermAccession());
    $this->assertSame('chado_blast_results', $types[0]->getFieldType());
    $this->assertSame('record_id', $types[0]->getKey());
    $this->assertSame('format', $types[1]->getTermIdSpace());
    $this->assertSame('1333', $types[1]->getTermAccession());
    $this->assertSame('chado_blast_results', $types[1]->getFieldType());
    $this->assertSame('hit_data', $types[1]->getKey());
  }

  /**
   * Tests that tripalTypes() falls back to format:1333 for empty termIdSpace.
   */
  public function testTripalTypesFallsBackToDefaultTerm(): void {
    $this->setUpIdspaceContainer();

    $field_def = $this->createMock(FieldDefinitionInterface::class);
    $field_def->method('getTargetEntityTypeId')->willReturn('tripal_entity');
    $field_def->method('getSettings')->willReturn([
      'termIdSpace' => '',
      'termAccession' => '',
    ]);

    $types = ChadoBlastResultsTypeDefault::tripalTypes($field_def);

    $this->assertCount(2, $types, 'Expect two types in the field definition');
    $this->assertInstanceOf(ChadoIntStoragePropertyType::class, $types[0], 'Type [0] holds the record_id so should be int type');
    $this->assertInstanceOf(ChadoTextStoragePropertyType::class, $types[1], 'Type [1] holds the serialized data so should be text type');
    $this->assertSame('SIO', $types[0]->getTermIdSpace());
    $this->assertSame('000729', $types[0]->getTermAccession());
    $this->assertSame('format', $types[1]->getTermIdSpace());
    $this->assertSame('1333', $types[1]->getTermAccession());
  }

  /**
   * Discover() returns an empty array when base_table is empty.
   */
  public function testDiscoverReturnsEmptyArrayWithoutBaseTable(): void {
    $bundle = $this->createMock(TripalEntityType::class);
    $bundle->method('getThirdPartySetting')
      ->with('tripal', 'chado_base_table')
      ->willReturn('');

    $result = ChadoBlastResultsTypeDefault::discover($bundle, 'chado_blast_results', [], []);

    $this->assertSame([], $result, 'Expected empty array for discover() with no base table specified');
  }

  /**
   * GenerateSampleValue() returns the expected array structure.
   *
   * Record_id is always 0 (no Chado record exists yet for a sample), and
   * value is a floating point value between -5000 and 5000.
   */
  public function testGenerateSampleValue(): void {
    $field_def = $this->createMock(FieldDefinitionInterface::class);

    $result = ChadoBlastResultsTypeDefault::generateSampleValue($field_def);
    $this->assertIsArray($result);
    $this->assertArrayHasKey(0, $result, 'Real sample value supports cardinality -1, so should be nested under a delta');
    $this->assertArrayHasKey('record_id', $result[0]);
    $this->assertArrayHasKey('hit_data', $result[0]);
    $this->assertSame(1, $result[0]['record_id']);
  }

  /**
   * IsCompatible() is true when the base table is feature.
   */
  public function testIsCompatibleReturnsTrueWhenFeature(): void {
    $this->setUpChadoContainer([
      'fields' => [
        'organism_id' => ['pgsql_type' => 'bigint'],
        'uniquename' => ['pgsql_type' => 'text'],
        'type_id' => ['pgsql_type' => 'bigint'],
      ],
    ]);

    $entity_type = $this->createMock(TripalEntityType::class);
    $entity_type->method('getThirdPartySetting')
      ->with('tripal', 'chado_base_table')
      ->willReturn('feature');

    $field = (new \ReflectionClass(ChadoBlastResultsTypeDefault::class))
      ->newInstanceWithoutConstructor();

    $this->assertTrue($field->isCompatible($entity_type), 'Expected true if base table is feature');
  }

  /**
   * IsCompatible() is false when the base table is not feature.
   */
  public function testIsCompatibleReturnsFalseWhenNotFeature(): void {
    $this->setUpChadoContainer([
      'fields' => [
        'organism_id' => ['pgsql_type' => 'bigint'],
        'uniquename' => ['pgsql_type' => 'text'],
        'type_id' => ['pgsql_type' => 'bigint'],
      ],
    ]);

    $entity_type = $this->createMock(TripalEntityType::class);
    $entity_type->method('getThirdPartySetting')
      ->with('tripal', 'chado_base_table')
      ->willReturn('organism');

    $field = (new \ReflectionClass(ChadoBlastResultsTypeDefault::class))
      ->newInstanceWithoutConstructor();

    $this->assertFalse($field->isCompatible($entity_type), 'Expected false if base table is not feature');
  }

  /**
   * Sets up a minimal Drupal container with a stub idspace service.
   *
   * StoragePropertyBase::__construct() resolves
   * tripal.collection_plugin_manager.idspace from the container to validate
   * the term string. This stubs out that chain so RealStoragePropertyType
   * can be instantiated in a unit test.
   */
  private function setUpIdspaceContainer(): void {
    $idspace = $this->prophesize(TripalIdSpaceInterface::class);
    $idspace->getTerm(Argument::any())->willReturn(new \stdClass());

    $idspace_manager = $this->prophesize(TripalCollectionPluginManager::class);
    $idspace_manager->loadCollection(Argument::any())->willReturn($idspace->reveal());
    $idspace_manager->loadCollection(Argument::any(), Argument::any())->willReturn($idspace->reveal());

    $container = new ContainerBuilder();
    $container->set('tripal.collection_plugin_manager.idspace', $idspace_manager->reveal());
    \Drupal::setContainer($container);
  }

  /**
   * Sets up a Drupal container with a stub Chado database returning $table_def.
   *
   * This is because real Chado is kinda complex to mock.
   *
   * IsCompatible() → getTableColumns() → getChadoTableDef() resolves
   * tripal_chado.database from the container when no schema is provided.
   * Anonymous class stubs avoid the complex Drupal database class hierarchy.
   */
  private function setUpChadoContainer(array $table_def): void {
    $schema_stub = new class($table_def) {

      public function __construct(private array $def) {}

      /**
       * Returns the test table definition.
       */
      public function getTableDef(string $table, array $parameters): array {
        return $this->def;
      }

    };

    $chado_stub = new class($schema_stub) {

      public function __construct(private object $schema) {}

      /**
       * Returns the test schema.
       */
      public function schema(): object {
        return $this->schema;
      }

    };

    $container = new ContainerBuilder();
    $container->set('tripal_chado.database', $chado_stub);
    \Drupal::setContainer($container);
  }

}
