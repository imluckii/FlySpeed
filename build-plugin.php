<?php

declare(strict_types=1);

const WORKSPACE_DIRECTORY = "out";
const COMPOSER_DIRECTORY = "vendor";
const COMPOSER_FILE = "composer.json";
const SOURCES_DIRECTORY = "src";
const RESOURCES_DIRECTORY = "resources";
const PLUGIN_DESCRIPTION_FILE = "plugin.yml";

if (in_array("--metadata", $argv ?? [], true)) {
	outputMetadata();
	return;
}

if (is_dir(WORKSPACE_DIRECTORY)) {
	out("Cleaning workspace...");
	cleanDirectory(WORKSPACE_DIRECTORY);
}

out("Building phar from sources...");

$startTime = microtime(true);

if (!is_file(PLUGIN_DESCRIPTION_FILE)) {
	out("Plugin description file not found. Cancelling the process..");
	return;
}

$pluginMetadata = getPluginMetadata(readPluginDescription(PLUGIN_DESCRIPTION_FILE));
$pluginName = $pluginMetadata["name"];
$mainClass = $pluginMetadata["mainClass"];
if (!is_string($mainClass) || $mainClass === "") {
	out("Plugin main class not found in plugin.yml. Cancelling the process..");
	return;
}

$pluginNamespace = getNamespace($mainClass);
$librariesRoot = ($pluginNamespace !== "" ? $pluginNamespace . "\\" : "") . "libs";
$outputFile = $pluginMetadata["pharPath"];

@mkdir(WORKSPACE_DIRECTORY);
@mkdir(WORKSPACE_DIRECTORY . "/" . SOURCES_DIRECTORY, 0777, true);

$libraries = discoverLibraries(COMPOSER_DIRECTORY);
$replacements = buildNamespaceReplacements($librariesRoot, array_column($libraries, "namespace"));

copy(PLUGIN_DESCRIPTION_FILE, WORKSPACE_DIRECTORY . "/" . PLUGIN_DESCRIPTION_FILE);
copyDirectory(
	SOURCES_DIRECTORY,
	WORKSPACE_DIRECTORY . "/" . SOURCES_DIRECTORY,
	fn(string $file) => replaceNamespaces($file, $replacements)
);

if (is_dir(RESOURCES_DIRECTORY)) {
	copyDirectory(RESOURCES_DIRECTORY, WORKSPACE_DIRECTORY . "/" . RESOURCES_DIRECTORY);
}

if (count($libraries) > 0) {
	out("Including libraries used...");
	foreach ($libraries as $library) {
		out("Adding " . $library["package"]);
		copyDirectory(
			$library["sourceDirectory"],
			WORKSPACE_DIRECTORY . "/" . SOURCES_DIRECTORY . "/" . str_replace("\\", "/", $librariesRoot . "\\" . $library["namespace"]),
			fn(string $file) => replaceNamespaces($file, $replacements)
		);
	}
}

if (file_exists($outputFile)) {
	unlink($outputFile);
}

out("Packing phar file...");
$phar = new Phar($outputFile);
$phar->buildFromDirectory(WORKSPACE_DIRECTORY);
$phar->compressFiles(Phar::GZ);

out("Done (took " . round(microtime(true) - $startTime, 3) . " seconds)");

/**
 * @return array<string, mixed>
 */
function readPluginDescription(string $path): array
{
	if (function_exists("yaml_parse_file")) {
		$pluginYml = yaml_parse_file($path);
		if (is_array($pluginYml)) {
			return $pluginYml;
		}
	}

	$content = file_get_contents($path);
	if ($content === false) {
		return [];
	}

	$pluginYml = [];
	foreach (explode("\n", $content) as $line) {
		$line = trim($line);
		if ($line === "" || str_starts_with($line, "#") || !str_contains($line, ":")) {
			continue;
		}

		[$key, $value] = explode(":", $line, 2);
		$key = trim($key);
		$value = trim($value);

		if (
			(substr($value, 0, 1) === "\"" && substr($value, -1) === "\"") ||
			(substr($value, 0, 1) === "'" && substr($value, -1) === "'")
		) {
			$value = substr($value, 1, -1);
		}

		$pluginYml[$key] = $value;
	}

	return $pluginYml;
}

/**
 * @param array<string, mixed> $pluginYml
 * @return array{name: string, version: string, normalizedVersion: string, releaseTag: string, mainClass: ?string, pharName: string, pharPath: string}
 */
function getPluginMetadata(array $pluginYml): array
{
	$pluginName = (string) ($pluginYml["name"] ?? basename(getcwd()));
	$pluginVersion = trim((string) ($pluginYml["version"] ?? ""));
	$normalizedVersion = ltrim($pluginVersion, "vV");
	$pharName = sanitizeFileName($pluginName) . ".phar";

	return [
		"name" => $pluginName,
		"version" => $pluginVersion,
		"normalizedVersion" => $normalizedVersion,
		"releaseTag" => "v" . $normalizedVersion,
		"mainClass" => is_string($pluginYml["main"] ?? null) ? $pluginYml["main"] : null,
		"pharName" => $pharName,
		"pharPath" => WORKSPACE_DIRECTORY . "/" . $pharName
	];
}

function outputMetadata(): void
{
	if (!is_file(PLUGIN_DESCRIPTION_FILE)) {
		fwrite(STDERR, "Plugin description file not found.\n");
		exit(1);
	}

	$pluginMetadata = getPluginMetadata(readPluginDescription(PLUGIN_DESCRIPTION_FILE));
	echo json_encode(
		[
			"name" => $pluginMetadata["name"],
			"version" => $pluginMetadata["version"],
			"normalizedVersion" => $pluginMetadata["normalizedVersion"],
			"releaseTag" => $pluginMetadata["releaseTag"],
			"mainClass" => $pluginMetadata["mainClass"],
			"pharName" => $pluginMetadata["pharName"],
			"pharPath" => $pluginMetadata["pharPath"]
		],
		JSON_THROW_ON_ERROR
	);
}

/**
 * @return list<array{package: string, namespace: string, sourceDirectory: string}>
 */
function discoverLibraries(string $vendorDirectory): array
{
	if (!is_dir($vendorDirectory)) {
		return [];
	}

	$bundledPackages = discoverBundledPackages(COMPOSER_FILE);
	if (count($bundledPackages) === 0) {
		return [];
	}

	$libraries = [];
	foreach (glob($vendorDirectory . "/*/*/composer.json") ?: [] as $composerFile) {
		$composerContents = file_get_contents($composerFile);
		if ($composerContents === false) {
			continue;
		}

		$composer = json_decode($composerContents, true);
		if (!is_array($composer)) {
			continue;
		}

		$packageDirectory = dirname($composerFile);
		$packageName = (string) ($composer["name"] ?? basename(dirname($packageDirectory)) . "/" . basename($packageDirectory));
		if (!isset($bundledPackages[$packageName])) {
			continue;
		}

		foreach (discoverPackageLibraries($packageDirectory, $composer) as $library) {
			$libraries[] = [
				"package" => $packageName,
				"namespace" => $library["namespace"],
				"sourceDirectory" => $library["sourceDirectory"]
			];
		}
	}

	usort(
		$libraries,
		static fn(array $left, array $right): int => [$left["package"], $left["namespace"]] <=> [$right["package"], $right["namespace"]]
	);

	return $libraries;
}

/**
 * @return array<string, true>
 */
function discoverBundledPackages(string $composerFile): array
{
	$composerContents = file_get_contents($composerFile);
	if ($composerContents === false) {
		return [];
	}

	$composer = json_decode($composerContents, true);
	if (!is_array($composer)) {
		return [];
	}

	$lockContents = file_get_contents(dirname($composerFile) . "/composer.lock");
	if ($lockContents === false) {
		return [];
	}

	$lock = json_decode($lockContents, true);
	if (!is_array($lock)) {
		return [];
	}

	$packagesByName = [];
	foreach (($lock["packages"] ?? []) as $package) {
		if (!is_array($package)) {
			continue;
		}

		$packageName = (string) ($package["name"] ?? "");
		if ($packageName === "") {
			continue;
		}

		$packagesByName[$packageName] = $package;
	}

	$bundledPackages = [];
	$queue = [];
	foreach (array_keys($composer["require"] ?? []) as $packageName) {
		if (shouldBundlePackage((string) $packageName)) {
			$queue[] = (string) $packageName;
		}
	}

	while (($packageName = array_shift($queue)) !== null) {
		if (isset($bundledPackages[$packageName])) {
			continue;
		}

		$bundledPackages[$packageName] = true;
		$package = $packagesByName[$packageName] ?? null;
		if (!is_array($package)) {
			continue;
		}

		foreach (array_keys($package["require"] ?? []) as $requiredPackageName) {
			$requiredPackageName = (string) $requiredPackageName;
			if (shouldBundlePackage($requiredPackageName) && !isset($bundledPackages[$requiredPackageName])) {
				$queue[] = $requiredPackageName;
			}
		}
	}

	return $bundledPackages;
}

function shouldBundlePackage(string $packageName): bool
{
	return $packageName !== "php" &&
		$packageName !== "pocketmine/pocketmine-mp" &&
		!str_starts_with($packageName, "ext-") &&
		!str_starts_with($packageName, "lib-") &&
		!str_starts_with($packageName, "composer-");
}

/**
 * @param array<string, mixed> $composer
 * @return list<array{namespace: string, sourceDirectory: string}>
 */
function discoverPackageLibraries(string $packageDirectory, array $composer): array
{
	$libraries = [];
	$autoload = $composer["autoload"]["psr-4"] ?? null;
	if (is_array($autoload)) {
		foreach ($autoload as $namespace => $directories) {
			$namespace = trim((string) $namespace, "\\");
			if ($namespace === "") {
				continue;
			}

			foreach ((array) $directories as $directory) {
				$sourceDirectory = normalizePath($packageDirectory . "/" . trim((string) $directory, "/"));
				if (!is_dir($sourceDirectory)) {
					continue;
				}

				$libraries[$namespace . "\0" . $sourceDirectory] = [
					"namespace" => $namespace,
					"sourceDirectory" => $sourceDirectory
				];
			}
		}
	}

	$virionYml = $packageDirectory . "/virion.yml";
	if (is_file($virionYml)) {
		$antigen = trim((string) (readPluginDescription($virionYml)["antigen"] ?? ""), "\\");
		if ($antigen !== "") {
			$candidateDirectories = [
				normalizePath($packageDirectory . "/src/" . str_replace("\\", "/", $antigen)),
				normalizePath($packageDirectory . "/src")
			];

			foreach ($candidateDirectories as $sourceDirectory) {
				if (!is_dir($sourceDirectory)) {
					continue;
				}

				$libraries[$antigen . "\0" . $sourceDirectory] = [
					"namespace" => $antigen,
					"sourceDirectory" => $sourceDirectory
				];
				break;
			}
		}
	}

	return array_values($libraries);
}

/**
 * @param list<string> $namespaces
 * @return array<string, string>
 */
function buildNamespaceReplacements(string $librariesRoot, array $namespaces): array
{
	$replacements = [];
	foreach (array_unique(array_filter($namespaces)) as $namespace) {
		$replacements[$namespace] = $librariesRoot . "\\" . $namespace;
	}

	uksort($replacements, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));

	return $replacements;
}

/**
 * @param array<string, string> $replacements
 */
function replaceNamespaces(string $content, array $replacements): string
{
	foreach ($replacements as $namespace => $replacement) {
		$pattern = '/(^|[^A-Za-z0-9_\\\\])(\\\\?)' . preg_quote($namespace, "/") . '(?=\\\\|::|;|,|\s|\)|\(|\[|\]|\{|\}|$)/m';
		$content = preg_replace_callback(
			$pattern,
			static fn(array $matches): string => $matches[1] . $matches[2] . $replacement,
			$content
		) ?? $content;
	}

	return $content;
}

function copyDirectory(string $directory, string $targetFolder, ?Closure $modifyFileClosure = null): void
{
	if (!is_dir($directory)) {
		return;
	}

	$modifyFileClosure ??= static fn(string $file): string => $file;

	@mkdir($targetFolder, 0777, true);
	/** @var SplFileInfo $file */
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
		$relativePath = substr(normalizePath($file->getPathname()), strlen(normalizePath($directory)));
		$targetPath = normalizePath($targetFolder . "/" . ltrim($relativePath, "/"));
		if ($file->isFile()) {
			@mkdir(dirname($targetPath), 0777, true);
			file_put_contents($targetPath, $modifyFileClosure((string) file_get_contents($file->getPathname())));
		} else {
			@mkdir($targetPath, 0777, true);
		}
	}
}

function cleanDirectory(string $directory): void
{
	/** @var SplFileInfo $file */
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
		if ($file->isFile()) {
			unlink($file->getPathname());
		} else {
			rmdir($file->getPathname());
		}
	}
}

function getNamespace(string $className): string
{
	$splitNamespace = explode("\\", $className);
	array_pop($splitNamespace);

	return implode("\\", $splitNamespace);
}

function normalizePath(string $path): string
{
	return str_replace("\\", "/", $path);
}

function sanitizeFileName(string $fileName): string
{
	$fileName = preg_replace('/[^A-Za-z0-9._-]+/', "-", trim($fileName)) ?? "";
	$fileName = trim($fileName, "-.");

	return $fileName !== "" ? $fileName : "plugin";
}

function out(string $message): void
{
	echo "[" . gmdate("H:i:s") . "] " . $message . "\n";
}
