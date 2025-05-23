<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OC\IntegrityCheck;

use OC\Core\Command\Maintenance\Mimetype\GenerateMimetypeFileBuilder;
use OC\IntegrityCheck\Exceptions\InvalidSignatureException;
use OC\IntegrityCheck\Helpers\AppLocator;
use OC\IntegrityCheck\Helpers\EnvironmentHelper;
use OC\IntegrityCheck\Helpers\FileAccessHelper;
use OC\IntegrityCheck\Iterator\ExcludeFileByNameFilterIterator;
use OC\IntegrityCheck\Iterator\ExcludeFoldersByPathFilterIterator;
use OCP\App\IAppManager;
use OCP\Files\IMimeTypeDetector;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\ServerVersion;
use phpseclib\Crypt\RSA;
use phpseclib\File\X509;

/**
 * Class Checker handles the code signing using X.509 and RSA. ownCloud ships with
 * a public root certificate certificate that allows to issue new certificates that
 * will be trusted for signing code. The CN will be used to verify that a certificate
 * given to a third-party developer may not be used for other applications. For
 * example the author of the application "calendar" would only receive a certificate
 * only valid for this application.
 *
 * @package OC\IntegrityCheck
 */
class Checker {
	public const CACHE_KEY = 'oc.integritycheck.checker';

	private ICache $cache;

	public function __construct(
		private ServerVersion $serverVersion,
		private EnvironmentHelper $environmentHelper,
		private FileAccessHelper $fileAccessHelper,
		private AppLocator $appLocator,
		private ?IConfig $config,
		private ?IAppConfig $appConfig,
		ICacheFactory $cacheFactory,
		private IAppManager $appManager,
		private IMimeTypeDetector $mimeTypeDetector,
	) {
		$this->cache = $cacheFactory->createDistributed(self::CACHE_KEY);
	}

	/**
	 * Whether code signing is enforced or not.
	 *
	 * @return bool
	 */
	public function isCodeCheckEnforced(): bool {
		$notSignedChannels = [ '', 'git'];
		if (\in_array($this->serverVersion->getChannel(), $notSignedChannels, true)) {
			return false;
		}

		/**
		 * This config option is undocumented and supposed to be so, it's only
		 * applicable for very specific scenarios and we should not advertise it
		 * too prominent. So please do not add it to config.sample.php.
		 */
		return !($this->config?->getSystemValueBool('integrity.check.disabled', false) ?? false);
	}

	/**
	 * Enumerates all files belonging to the folder. Sensible defaults are excluded.
	 *
	 * @param string $folderToIterate
	 * @param string $root
	 * @return \RecursiveIteratorIterator
	 * @throws \Exception
	 */
	private function getFolderIterator(string $folderToIterate, string $root = ''): \RecursiveIteratorIterator {
		$dirItr = new \RecursiveDirectoryIterator(
			$folderToIterate,
			\RecursiveDirectoryIterator::SKIP_DOTS
		);
		if ($root === '') {
			$root = \OC::$SERVERROOT;
		}
		$root = rtrim($root, '/');

		$excludeGenericFilesIterator = new ExcludeFileByNameFilterIterator($dirItr);
		$excludeFoldersIterator = new ExcludeFoldersByPathFilterIterator($excludeGenericFilesIterator, $root);

		return new \RecursiveIteratorIterator(
			$excludeFoldersIterator,
			\RecursiveIteratorIterator::SELF_FIRST
		);
	}

	/**
	 * Returns an array of ['filename' => 'SHA512-hash-of-file'] for all files found
	 * in the iterator.
	 *
	 * @param \RecursiveIteratorIterator $iterator
	 * @param string $path
	 * @return array Array of hashes.
	 */
	private function generateHashes(\RecursiveIteratorIterator $iterator,
		string $path): array {
		$hashes = [];

		$baseDirectoryLength = \strlen($path);
		foreach ($iterator as $filename => $data) {
			/** @var \DirectoryIterator $data */
			if ($data->isDir()) {
				continue;
			}

			$relativeFileName = substr($filename, $baseDirectoryLength);
			$relativeFileName = ltrim($relativeFileName, '/');

			// Exclude signature.json files in the appinfo and root folder
			if ($relativeFileName === 'appinfo/signature.json') {
				continue;
			}
			// Exclude signature.json files in the appinfo and core folder
			if ($relativeFileName === 'core/signature.json') {
				continue;
			}

			// The .htaccess file in the root folder of ownCloud can contain
			// custom content after the installation due to the fact that dynamic
			// content is written into it at installation time as well. This
			// includes for example the 404 and 403 instructions.
			// Thus we ignore everything below the first occurrence of
			// "#### DO NOT CHANGE ANYTHING ABOVE THIS LINE ####" and have the
			// hash generated based on this.
			if ($filename === $this->environmentHelper->getServerRoot() . '/.htaccess') {
				$fileContent = file_get_contents($filename);
				$explodedArray = explode('#### DO NOT CHANGE ANYTHING ABOVE THIS LINE ####', $fileContent);
				if (\count($explodedArray) === 2) {
					$hashes[$relativeFileName] = hash('sha512', $explodedArray[0]);
					continue;
				}
			}
			if ($filename === $this->environmentHelper->getServerRoot() . '/core/js/mimetypelist.js') {
				$oldMimetypeList = new GenerateMimetypeFileBuilder();
				$newFile = $oldMimetypeList->generateFile($this->mimeTypeDetector->getAllAliases(), $this->mimeTypeDetector->getAllNamings());
				$oldFile = $this->fileAccessHelper->file_get_contents($filename);
				if ($newFile === $oldFile) {
					$hashes[$relativeFileName] = hash('sha512', $oldMimetypeList->generateFile($this->mimeTypeDetector->getOnlyDefaultAliases(), $this->mimeTypeDetector->getAllNamings()));
					continue;
				}
			}

			$hashes[$relativeFileName] = hash_file('sha512', $filename);
		}

		return $hashes;
	}

	/**
	 * Creates the signature data
	 *
	 * @param array $hashes
	 * @param X509 $certificate
	 * @param RSA $privateKey
	 * @return array
	 */
	private function createSignatureData(array $hashes,
		X509 $certificate,
		RSA $privateKey): array {
		ksort($hashes);

		$privateKey->setSignatureMode(RSA::SIGNATURE_PSS);
		$privateKey->setMGFHash('sha512');
		// See https://tools.ietf.org/html/rfc3447#page-38
		$privateKey->setSaltLength(0);
		$signature = $privateKey->sign(json_encode($hashes));

		return [
			'hashes' => $hashes,
			'signature' => base64_encode($signature),
			'certificate' => $certificate->saveX509($certificate->currentCert),
		];
	}

	/**
	 * Write the signature of the app in the specified folder
	 *
	 * @param string $path
	 * @param X509 $certificate
	 * @param RSA $privateKey
	 * @throws \Exception
	 */
	public function writeAppSignature($path,
		X509 $certificate,
		RSA $privateKey) {
		$appInfoDir = $path . '/appinfo';
		try {
			$this->fileAccessHelper->assertDirectoryExists($appInfoDir);

			$iterator = $this->getFolderIterator($path);
			$hashes = $this->generateHashes($iterator, $path);
			$signature = $this->createSignatureData($hashes, $certificate, $privateKey);
			$this->fileAccessHelper->file_put_contents(
				$appInfoDir . '/signature.json',
				json_encode($signature, JSON_PRETTY_PRINT)
			);
		} catch (\Exception $e) {
			if (!$this->fileAccessHelper->is_writable($appInfoDir)) {
				throw new \Exception($appInfoDir . ' is not writable');
			}
			throw $e;
		}
	}

	/**
	 * Write the signature of core
	 *
	 * @param X509 $certificate
	 * @param RSA $rsa
	 * @param string $path
	 * @throws \Exception
	 */
	public function writeCoreSignature(X509 $certificate,
		RSA $rsa,
		$path) {
		$coreDir = $path . '/core';
		try {
			$this->fileAccessHelper->assertDirectoryExists($coreDir);
			$iterator = $this->getFolderIterator($path, $path);
			$hashes = $this->generateHashes($iterator, $path);
			$signatureData = $this->createSignatureData($hashes, $certificate, $rsa);
			$this->fileAccessHelper->file_put_contents(
				$coreDir . '/signature.json',
				json_encode($signatureData, JSON_PRETTY_PRINT)
			);
		} catch (\Exception $e) {
			if (!$this->fileAccessHelper->is_writable($coreDir)) {
				throw new \Exception($coreDir . ' is not writable');
			}
			throw $e;
		}
	}

	/**
	 * Split the certificate file in individual certs
	 *
	 * @param string $cert
	 * @return string[]
	 */
	private function splitCerts(string $cert): array {
		preg_match_all('([\-]{3,}[\S\ ]+?[\-]{3,}[\S\s]+?[\-]{3,}[\S\ ]+?[\-]{3,})', $cert, $matches);

		return $matches[0];
	}

	/**
	 * Verifies the signature for the specified path.
	 *
	 * @param string $signaturePath
	 * @param string $basePath
	 * @param string $certificateCN
	 * @param bool $forceVerify
	 * @return array
	 * @throws InvalidSignatureException
	 * @throws \Exception
	 */
	private function verify(string $signaturePath, string $basePath, string $certificateCN, bool $forceVerify = false): array {
		if (!$forceVerify && !$this->isCodeCheckEnforced()) {
			return [];
		}

		$content = $this->fileAccessHelper->file_get_contents($signaturePath);
		$signatureData = null;

		if (\is_string($content)) {
			$signatureData = json_decode($content, true);
		}
		if (!\is_array($signatureData)) {
			throw new InvalidSignatureException('Signature data not found.');
		}

		$expectedHashes = $signatureData['hashes'];
		ksort($expectedHashes);
		$signature = base64_decode($signatureData['signature']);
		$certificate = $signatureData['certificate'];

		// Check if certificate is signed by Nextcloud Root Authority
		$x509 = new \phpseclib\File\X509();
		$rootCertificatePublicKey = $this->fileAccessHelper->file_get_contents($this->environmentHelper->getServerRoot() . '/resources/codesigning/root.crt');

		$rootCerts = $this->splitCerts($rootCertificatePublicKey);
		foreach ($rootCerts as $rootCert) {
			$x509->loadCA($rootCert);
		}
		$x509->loadX509($certificate);
		if (!$x509->validateSignature()) {
			throw new InvalidSignatureException('Certificate is not valid.');
		}
		// Verify if certificate has proper CN. "core" CN is always trusted.
		if ($x509->getDN(X509::DN_OPENSSL)['CN'] !== $certificateCN && $x509->getDN(X509::DN_OPENSSL)['CN'] !== 'core') {
			throw new InvalidSignatureException(
				sprintf('Certificate is not valid for required scope. (Requested: %s, current: CN=%s)', $certificateCN, $x509->getDN(true)['CN'])
			);
		}

		// Check if the signature of the files is valid
		$rsa = new \phpseclib\Crypt\RSA();
		$rsa->loadKey($x509->currentCert['tbsCertificate']['subjectPublicKeyInfo']['subjectPublicKey']);
		$rsa->setSignatureMode(RSA::SIGNATURE_PSS);
		$rsa->setMGFHash('sha512');
		// See https://tools.ietf.org/html/rfc3447#page-38
		$rsa->setSaltLength(0);
		if (!$rsa->verify(json_encode($expectedHashes), $signature)) {
			throw new InvalidSignatureException('Signature could not get verified.');
		}

		// Fixes for the updater as shipped with ownCloud 9.0.x: The updater is
		// replaced after the code integrity check is performed.
		//
		// Due to this reason we exclude the whole updater/ folder from the code
		// integrity check.
		if ($basePath === $this->environmentHelper->getServerRoot()) {
			foreach ($expectedHashes as $fileName => $hash) {
				if (str_starts_with($fileName, 'updater/')) {
					unset($expectedHashes[$fileName]);
				}
			}
		}

		// Compare the list of files which are not identical
		$currentInstanceHashes = $this->generateHashes($this->getFolderIterator($basePath), $basePath);
		$differencesA = array_diff_assoc($expectedHashes, $currentInstanceHashes);
		$differencesB = array_diff_assoc($currentInstanceHashes, $expectedHashes);
		$differences = array_unique(array_merge($differencesA, $differencesB));
		$differenceArray = [];
		foreach ($differences as $filename => $hash) {
			// Check if file should not exist in the new signature table
			if (!array_key_exists($filename, $expectedHashes)) {
				$differenceArray['EXTRA_FILE'][$filename]['expected'] = '';
				$differenceArray['EXTRA_FILE'][$filename]['current'] = $hash;
				continue;
			}

			// Check if file is missing
			if (!array_key_exists($filename, $currentInstanceHashes)) {
				$differenceArray['FILE_MISSING'][$filename]['expected'] = $expectedHashes[$filename];
				$differenceArray['FILE_MISSING'][$filename]['current'] = '';
				continue;
			}

			// Check if hash does mismatch
			if ($expectedHashes[$filename] !== $currentInstanceHashes[$filename]) {
				$differenceArray['INVALID_HASH'][$filename]['expected'] = $expectedHashes[$filename];
				$differenceArray['INVALID_HASH'][$filename]['current'] = $currentInstanceHashes[$filename];
				continue;
			}

			// Should never happen.
			throw new \Exception('Invalid behaviour in file hash comparison experienced. Please report this error to the developers.');
		}

		return $differenceArray;
	}

	/**
	 * Whether the code integrity check has passed successful or not
	 *
	 * @return bool
	 */
	public function hasPassedCheck(): bool {
		$results = $this->getResults();
		if ($results !== null && empty($results)) {
			return true;
		}

		return false;
	}

	/**
	 * @return array|null Either the results or null if no results available
	 */
	public function getResults(): ?array {
		$cachedResults = $this->cache->get(self::CACHE_KEY);
		if (!\is_null($cachedResults) and $cachedResults !== false) {
			return json_decode($cachedResults, true);
		}

		if ($this->appConfig?->hasKey('core', self::CACHE_KEY, lazy: true)) {
			return $this->appConfig->getValueArray('core', self::CACHE_KEY, lazy: true);
		}

		// No results available
		return null;
	}

	/**
	 * Stores the results in the app config as well as cache
	 *
	 * @param string $scope
	 * @param array $result
	 */
	private function storeResults(string $scope, array $result) {
		$resultArray = $this->getResults() ?? [];
		unset($resultArray[$scope]);
		if (!empty($result)) {
			$resultArray[$scope] = $result;
		}
		$this->appConfig?->setValueArray('core', self::CACHE_KEY, $resultArray, lazy: true);
		$this->cache->set(self::CACHE_KEY, json_encode($resultArray));
	}

	/**
	 *
	 * Clean previous results for a proper rescanning. Otherwise
	 */
	private function cleanResults() {
		$this->appConfig->deleteKey('core', self::CACHE_KEY);
		$this->cache->remove(self::CACHE_KEY);
	}

	/**
	 * Verify the signature of $appId. Returns an array with the following content:
	 * [
	 * 	'FILE_MISSING' =>
	 * 	[
	 * 		'filename' => [
	 * 			'expected' => 'expectedSHA512',
	 * 			'current' => 'currentSHA512',
	 * 		],
	 * 	],
	 * 	'EXTRA_FILE' =>
	 * 	[
	 * 		'filename' => [
	 * 			'expected' => 'expectedSHA512',
	 * 			'current' => 'currentSHA512',
	 * 		],
	 * 	],
	 * 	'INVALID_HASH' =>
	 * 	[
	 * 		'filename' => [
	 * 			'expected' => 'expectedSHA512',
	 * 			'current' => 'currentSHA512',
	 * 		],
	 * 	],
	 * ]
	 *
	 * Array may be empty in case no problems have been found.
	 *
	 * @param string $appId
	 * @param string $path Optional path. If none is given it will be guessed.
	 * @param bool $forceVerify
	 * @return array
	 */
	public function verifyAppSignature(string $appId, string $path = '', bool $forceVerify = false): array {
		try {
			if ($path === '') {
				$path = $this->appLocator->getAppPath($appId);
			}
			$result = $this->verify(
				$path . '/appinfo/signature.json',
				$path,
				$appId,
				$forceVerify
			);
		} catch (\Exception $e) {
			$result = [
				'EXCEPTION' => [
					'class' => \get_class($e),
					'message' => $e->getMessage(),
				],
			];
		}
		$this->storeResults($appId, $result);

		return $result;
	}

	/**
	 * Verify the signature of core. Returns an array with the following content:
	 * [
	 * 	'FILE_MISSING' =>
	 * 	[
	 * 		'filename' => [
	 * 			'expected' => 'expectedSHA512',
	 * 			'current' => 'currentSHA512',
	 * 		],
	 * 	],
	 * 	'EXTRA_FILE' =>
	 * 	[
	 * 		'filename' => [
	 * 			'expected' => 'expectedSHA512',
	 * 			'current' => 'currentSHA512',
	 * 		],
	 * 	],
	 * 	'INVALID_HASH' =>
	 * 	[
	 * 		'filename' => [
	 * 			'expected' => 'expectedSHA512',
	 * 			'current' => 'currentSHA512',
	 * 		],
	 * 	],
	 * ]
	 *
	 * Array may be empty in case no problems have been found.
	 *
	 * @return array
	 */
	public function verifyCoreSignature(): array {
		try {
			$result = $this->verify(
				$this->environmentHelper->getServerRoot() . '/core/signature.json',
				$this->environmentHelper->getServerRoot(),
				'core'
			);
		} catch (\Exception $e) {
			$result = [
				'EXCEPTION' => [
					'class' => \get_class($e),
					'message' => $e->getMessage(),
				],
			];
		}
		$this->storeResults('core', $result);

		return $result;
	}

	/**
	 * Verify the core code of the instance as well as all applicable applications
	 * and store the results.
	 */
	public function runInstanceVerification() {
		$this->cleanResults();
		$this->verifyCoreSignature();
		$appIds = $this->appManager->getAllAppsInAppsFolders();
		foreach ($appIds as $appId) {
			// If an application is shipped a valid signature is required
			$isShipped = $this->appManager->isShipped($appId);
			$appNeedsToBeChecked = false;
			if ($isShipped) {
				$appNeedsToBeChecked = true;
			} elseif ($this->fileAccessHelper->file_exists($this->appLocator->getAppPath($appId) . '/appinfo/signature.json')) {
				// Otherwise only if the application explicitly ships a signature.json file
				$appNeedsToBeChecked = true;
			}

			if ($appNeedsToBeChecked) {
				$this->verifyAppSignature($appId);
			}
		}
	}
}
