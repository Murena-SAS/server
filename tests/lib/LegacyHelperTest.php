<?php
/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Test;

use OC\Files\View;
use OC_Helper;

class LegacyHelperTest extends \Test\TestCase {
	/** @var string */
	private $originalWebRoot;

	protected function setUp(): void {
		$this->originalWebRoot = \OC::$WEBROOT;
	}

	protected function tearDown(): void {
		// Reset webRoot
		\OC::$WEBROOT = $this->originalWebRoot;
	}

	/**
	 * @dataProvider humanFileSizeProvider
	 */
	public function testHumanFileSize($expected, $input): void {
		$result = OC_Helper::humanFileSize($input);
		$this->assertEquals($expected, $result);
	}

	public static function humanFileSizeProvider(): array {
		return [
			['0 B', 0],
			['1 KB', 1024],
			['9.5 MB', 10000000],
			['1.3 GB', 1395864371],
			['465.7 GB', 500000000000],
			['454.7 TB', 500000000000000],
			['444.1 PB', 500000000000000000],
		];
	}

	/**
	 * @dataProvider providesComputerFileSize
	 */
	public function testComputerFileSize($expected, $input): void {
		$result = OC_Helper::computerFileSize($input);
		$this->assertEquals($expected, $result);
	}

	public static function providesComputerFileSize(): array {
		return [
			[0.0, '0 B'],
			[1024.0, '1 KB'],
			[1395864371.0, '1.3 GB'],
			[9961472.0, '9.5 MB'],
			[500041567437.0, '465.7 GB'],
			[false, '12 GB etfrhzui']
		];
	}

	public function testMb_array_change_key_case(): void {
		$arrayStart = [
			'Foo' => 'bar',
			'Bar' => 'foo',
		];
		$arrayResult = [
			'foo' => 'bar',
			'bar' => 'foo',
		];
		$result = OC_Helper::mb_array_change_key_case($arrayStart);
		$expected = $arrayResult;
		$this->assertEquals($result, $expected);

		$arrayStart = [
			'foo' => 'bar',
			'bar' => 'foo',
		];
		$arrayResult = [
			'FOO' => 'bar',
			'BAR' => 'foo',
		];
		$result = OC_Helper::mb_array_change_key_case($arrayStart, MB_CASE_UPPER);
		$expected = $arrayResult;
		$this->assertEquals($result, $expected);
	}

	public function testRecursiveArraySearch(): void {
		$haystack = [
			'Foo' => 'own',
			'Bar' => 'Cloud',
		];

		$result = OC_Helper::recursiveArraySearch($haystack, 'own');
		$expected = 'Foo';
		$this->assertEquals($result, $expected);

		$result = OC_Helper::recursiveArraySearch($haystack, 'NotFound');
		$this->assertFalse($result);
	}

	public function testBuildNotExistingFileNameForView(): void {
		$viewMock = $this->createMock(View::class);
		$this->assertEquals('/filename', OC_Helper::buildNotExistingFileNameForView('/', 'filename', $viewMock));
		$this->assertEquals('dir/filename.ext', OC_Helper::buildNotExistingFileNameForView('dir', 'filename.ext', $viewMock));

		$viewMock = $this->createMock(View::class);
		$viewMock->expects($this->exactly(2))
			->method('file_exists')
			->willReturnMap([
				// Conflict on filename.ext
				['dir/filename.ext', true],
				['dir/filename (2).ext', false],
			]);
		$this->assertEquals('dir/filename (2).ext', OC_Helper::buildNotExistingFileNameForView('dir', 'filename.ext', $viewMock));

		$viewMock = $this->createMock(View::class);
		$viewMock->expects($this->exactly(3))
			->method('file_exists')
			->willReturnMap([
				// Conflict on filename.ext
				['dir/filename.ext', true],
				['dir/filename (2).ext', true],
				['dir/filename (3).ext', false],
			]);
		$this->assertEquals('dir/filename (3).ext', OC_Helper::buildNotExistingFileNameForView('dir', 'filename.ext', $viewMock));

		$viewMock = $this->createMock(View::class);
		$viewMock->expects($this->exactly(2))
			->method('file_exists')
			->willReturnMap([
				['dir/filename (1).ext', true],
				['dir/filename (2).ext', false],
			]);
		$this->assertEquals('dir/filename (2).ext', OC_Helper::buildNotExistingFileNameForView('dir', 'filename (1).ext', $viewMock));

		$viewMock = $this->createMock(View::class);
		$viewMock->expects($this->exactly(2))
			->method('file_exists')
			->willReturnMap([
				['dir/filename (2).ext', true],
				['dir/filename (3).ext', false],
			]);
		$this->assertEquals('dir/filename (3).ext', OC_Helper::buildNotExistingFileNameForView('dir', 'filename (2).ext', $viewMock));

		$viewMock = $this->createMock(View::class);
		$viewMock->expects($this->exactly(3))
			->method('file_exists')
			->willReturnMap([
				['dir/filename (2).ext', true],
				['dir/filename (3).ext', true],
				['dir/filename (4).ext', false],
			]);
		$this->assertEquals('dir/filename (4).ext', OC_Helper::buildNotExistingFileNameForView('dir', 'filename (2).ext', $viewMock));

		$viewMock = $this->createMock(View::class);
		$viewMock->expects($this->exactly(2))
			->method('file_exists')
			->willReturnMap([
				['dir/filename(1).ext', true],
				['dir/filename(2).ext', false],
			]);
		$this->assertEquals('dir/filename(2).ext', OC_Helper::buildNotExistingFileNameForView('dir', 'filename(1).ext', $viewMock));

		$viewMock = $this->createMock(View::class);
		$viewMock->expects($this->exactly(2))
			->method('file_exists')
			->willReturnMap([
				['dir/filename(1) (1).ext', true],
				['dir/filename(1) (2).ext', false],
			]);
		$this->assertEquals('dir/filename(1) (2).ext', OC_Helper::buildNotExistingFileNameForView('dir', 'filename(1) (1).ext', $viewMock));

		$viewMock = $this->createMock(View::class);
		$viewMock->expects($this->exactly(3))
			->method('file_exists')
			->willReturnMap([
				['dir/filename(1) (1).ext', true],
				['dir/filename(1) (2).ext', true],
				['dir/filename(1) (3).ext', false],
			]);
		$this->assertEquals('dir/filename(1) (3).ext', OC_Helper::buildNotExistingFileNameForView('dir', 'filename(1) (1).ext', $viewMock));

		$viewMock = $this->createMock(View::class);
		$viewMock->expects($this->exactly(2))
			->method('file_exists')
			->willReturnMap([
				['dir/filename(1) (2) (3).ext', true],
				['dir/filename(1) (2) (4).ext', false],
			]);
		$this->assertEquals('dir/filename(1) (2) (4).ext', OC_Helper::buildNotExistingFileNameForView('dir', 'filename(1) (2) (3).ext', $viewMock));
	}

	/**
	 * @dataProvider streamCopyDataProvider
	 */
	public function testStreamCopy($expectedCount, $expectedResult, $source, $target): void {
		if (is_string($source)) {
			$source = fopen($source, 'r');
		}
		if (is_string($target)) {
			$target = fopen($target, 'w');
		}

		[$count, $result] = \OC_Helper::streamCopy($source, $target);

		if (is_resource($source)) {
			fclose($source);
		}
		if (is_resource($target)) {
			fclose($target);
		}

		$this->assertSame($expectedCount, $count);
		$this->assertSame($expectedResult, $result);
	}


	public static function streamCopyDataProvider(): array {
		return [
			[0, false, false, false],
			[0, false, \OC::$SERVERROOT . '/tests/data/lorem.txt', false],
			[filesize(\OC::$SERVERROOT . '/tests/data/lorem.txt'), true, \OC::$SERVERROOT . '/tests/data/lorem.txt', \OC::$SERVERROOT . '/tests/data/lorem-copy.txt'],
			[3670, true, \OC::$SERVERROOT . '/tests/data/testimage.png', \OC::$SERVERROOT . '/tests/data/testimage-copy.png'],
		];
	}

	/**
	 * Tests recursive folder deletion with rmdirr()
	 */
	public function testRecursiveFolderDeletion(): void {
		$baseDir = \OC::$server->getTempManager()->getTemporaryFolder() . '/';
		mkdir($baseDir . 'a/b/c/d/e', 0777, true);
		mkdir($baseDir . 'a/b/c1/d/e', 0777, true);
		mkdir($baseDir . 'a/b/c2/d/e', 0777, true);
		mkdir($baseDir . 'a/b1/c1/d/e', 0777, true);
		mkdir($baseDir . 'a/b2/c1/d/e', 0777, true);
		mkdir($baseDir . 'a/b3/c1/d/e', 0777, true);
		mkdir($baseDir . 'a1/b', 0777, true);
		mkdir($baseDir . 'a1/c', 0777, true);
		file_put_contents($baseDir . 'a/test.txt', 'Hello file!');
		file_put_contents($baseDir . 'a/b1/c1/test one.txt', 'Hello file one!');
		file_put_contents($baseDir . 'a1/b/test two.txt', 'Hello file two!');
		\OC_Helper::rmdirr($baseDir . 'a');

		$this->assertFalse(file_exists($baseDir . 'a'));
		$this->assertTrue(file_exists($baseDir . 'a1'));

		\OC_Helper::rmdirr($baseDir);
		$this->assertFalse(file_exists($baseDir));
	}
}
