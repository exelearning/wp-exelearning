<?php
/**
 * Tests for ExeLearning_Filesystem.
 *
 * @package Exelearning
 */

/**
 * Class FilesystemTest.
 *
 * @covers ExeLearning_Filesystem
 */
class FilesystemTest extends WP_UnitTestCase {

	/**
	 * Deleting a path that is not there returns early and, crucially, does not
	 * walk up and take a sibling with it.
	 */
	public function test_recursive_delete_handles_missing_path_gracefully() {
		$bystander = sys_get_temp_dir() . '/deltree-keep-' . uniqid();
		mkdir( $bystander, 0755, true );
		file_put_contents( $bystander . '/keep.txt', 'keep' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$missing = sys_get_temp_dir() . '/does-not-exist-' . uniqid();
		$this->assertDirectoryDoesNotExist( $missing, 'Precondition: the path must be absent.' );

		ExeLearning_Filesystem::recursive_delete( $missing );

		$this->assertDirectoryDoesNotExist( $missing );
		$this->assertFileExists( $bystander . '/keep.txt', 'A sibling directory was deleted.' );

		ExeLearning_Filesystem::recursive_delete( $bystander );
	}

	public function test_recursive_delete_removes_nested_files() {
		$root = sys_get_temp_dir() . '/deltree-' . uniqid();
		mkdir( $root . '/inner/deep', 0755, true );
		file_put_contents( $root . '/a.txt', 'a' );
		file_put_contents( $root . '/inner/b.txt', 'b' );
		file_put_contents( $root . '/inner/deep/c.txt', 'c' );
		$this->assertDirectoryExists( $root );
		ExeLearning_Filesystem::recursive_delete( $root );
		$this->assertDirectoryDoesNotExist( $root );
	}
}
