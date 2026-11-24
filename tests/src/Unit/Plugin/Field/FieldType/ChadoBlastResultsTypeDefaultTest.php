<?php

namespace Drupal\Tests\tripal\Unit\Plugin\Field\FieldType;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\tripal_chado\TripalStorage\ChadoIntStoragePropertyType;
use Drupal\tripal_chado\TripalStorage\ChadoTextStoragePropertyType;
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

}
