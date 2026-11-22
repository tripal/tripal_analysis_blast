<?php

namespace Drupal\Tests\tripal_analysis_blast\Traits;

use Drupal\file\Entity\File;

/**
 * Tripal Cultivate Importer Test Trait.
 *
 * This is copied from the Tripal Cultivate repository at
 * https://github.com/TripalCultivate/TripalCultivate.
 *
 * @see https://github.com/TripalCultivate/TripalCultivate/blob/306fd7100b5ed5ea6afe55517f23efd854936c8a/tests/src/Traits/TripalCultivateImporterTestTrait.php
 */
trait TripalCultivateImporterTestTrait {

  /**
   * Creates a Drupal Managed file based on the details provided.
   *
   * @param array $details
   *   An array containing details about the file to create.
   *   Supported keys:
   *     - extension: the file extension to use (default txt)
   *     - mime: the file type (e.g. text/plain, text/tab-separated-values)
   *     - filename: the name including extension to create attached to the
   *         managed file.
   *     - filesize: the size of the file being created in bytes
   *     - is_temporary: either TRUE or FALSE to indicate whether to put the
   *         file in the temporary or public files directory.
   *     - content[string]: the content to copy into the file as a string
   *     - content[file]: an existing file in the fixtures directory to copy
   *         the contents from.
   *     - content[fixturepath]: the absolute path to Fixtures directory
   *         containing the test file. This should end with a '/'.
   *         Default to Fixtures directory of this module.
   *     - permissions: permissions to apply to the file using chmod.
   *         Either 'none' for unreadable or the octet (see chmod)
   *         0600: read + write for owner, nothing for everyone else
   *         0644: read + write for owner, read only for everyone else
   *         0777: read + write + execute for everyone.
   *
   * @return \Drupal\file\Entity\File
   *   The Drupal managed file object created.
   */
  protected function createTestFile($details) {

    // Set Defaults.
    $details['extension'] = @$details['extension'] ?: 'txt';
    $details['filename'] = @$details['filename'] ?: 'testFile.' . uniqid() . '.' . $details['extension'];
    $details['mime'] = @$details['mime'] ?: 'text/tab-separated-values';
    $details['is_temporary'] = @$details['is_temporary'] ?: FALSE;
    $details['content'] = @$details['content'] ?: ['string' => uniqid()];

    // Set directory.
    $directory = ($details['is_temporary']) ? 'temporary://' : 'public://';
    $file_uri = $directory . $details['filename'];

    // Create file object.
    $file = File::create([
      'filename' => $details['filename'],
      'filemime' => $details['mime'],
      'uri' => $file_uri,
      'status' => 0,
    ]);

    // Reference file attributes:
    $file_id = $file->id();
    $file_uri = $file->getFileUri();

    // If a test file fixture was provided, create a copy and set this file copy
    // as the file uri value in the file object for this test file.
    if (array_key_exists('file', $details['content']) && !empty($details['content']['file'])) {
      $path_to_fixtures = isset($details['content']['fixturepath']) && $details['content']['fixturepath']
        ? $details['content']['fixturepath'] : __DIR__ . '/../Fixtures/';

      $path_to_file_fixture = $path_to_fixtures . $details['content']['file'];

      $this->assertFileIsReadable(
        $path_to_file_fixture,
        'Unable to setup FILE ' . $file_id . ' because cannot access Fixture file at ' . $path_to_file_fixture
      );

      copy($path_to_file_fixture, $file_uri);
    }

    // Write something on file with content key set to a string.
    if (!empty($details['content']['string'])) {
      file_put_contents($file_uri, $details['content']['string']);
    }

    // Set other file attributes:
    // Set the size of the file.
    // This is usually used if the file is empty in which case this is 0.
    if (isset($details['filesize'])) {
      // File size was provided.
      $file->setSize($details['filesize']);
    }
    else {
      // File size is to be determined.
      // Get the file size.
      $file_size = @filesize($file_uri);

      // Assert that a file size was established.
      $this->assertNotFalse($file_size, 'Unable to determine size of test file: ' . $file_uri);

      // Set the file size.
      $file->setSize($file_size);
    }

    // Save all set attributes.
    $file->save();

    // Set file permissions if needed.
    if (!empty($details['permissions'])) {
      if ($details['permissions'] == 'none') {
        chmod($file_uri, octdec(0000));
      }
      elseif (is_numeric($details['permissions'])) {
        $decoded = decoct(octdec($details['permissions']));
        if ($details['permissions'] == $decoded) {
          chmod($file_uri, $details['permissions']);
        }
      }
    }

    return $file;
  }

}
