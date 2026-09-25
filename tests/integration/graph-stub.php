<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A stand-in for Microsoft Graph, for the integration test that has no credentials.
 *
 * Run it as the router of the PHP development server and point the app at it:
 *
 *   php -S 127.0.0.1:8099 tests/integration/graph-stub.php
 *   occ config:app:set integration_onedrive api_base_url --value http://127.0.0.1:8099/v1.0/
 *
 * It serves a fixed drive that produces every outcome an import has to handle, which a real
 * account cannot be made to produce on demand:
 *
 *   normal.txt   downloads
 *   empty.txt    downloads, and is empty
 *   flaky.txt    fails with the URL from the listing, succeeds with a freshly fetched one
 *   broken.txt   fails both times
 *   already.txt  is never downloaded, the test puts it in the target folder beforehand
 *   sub/         a folder, holding nested.txt, which downloads
 *
 * A request to /control/break-drive makes the drive itself unreachable, which is how an
 * import that stops before it is finished is produced.
 *
 * The first listing page carries an @odata.nextLink, so paging is covered as well.
 */

// the router of a development server is the only thing this is ever meant to be
if (PHP_SAPI !== 'cli-server') {
	http_response_code(404);
	return;
}

const MODIFIED = '2026-09-01T10:00:00Z';

/** Every file of the fake drive, with what its download URLs should answer. */
const FILES = [
	'f1' => ['name' => 'normal.txt', 'content' => 'hello import', 'download' => 'always'],
	'f2' => ['name' => 'empty.txt', 'content' => '', 'download' => 'always'],
	'f3' => ['name' => 'flaky.txt', 'content' => 'retry me!', 'download' => 'fresh-url-only'],
	'f4' => ['name' => 'broken.txt', 'content' => 'never arrives', 'download' => 'never'],
	'f5' => ['name' => 'already.txt', 'content' => 'already there', 'download' => 'never'],
	'f6' => ['name' => 'nested.txt', 'content' => 'in a folder', 'download' => 'always'],
];

/**
 * @param string $id id of the file in FILES
 * @param string $source 'listing' for the URL a listing hands out, 'fresh' for a refetched one
 */
function fileItem(string $id, string $source = 'listing'): array {
	$file = FILES[$id];
	return [
		'id' => $id,
		'name' => $file['name'],
		'size' => strlen($file['content']),
		'lastModifiedDateTime' => MODIFIED,
		'file' => ['mimeType' => 'text/plain'],
		'@microsoft.graph.downloadUrl' => baseUrl() . '/download/' . $id . '?source=' . $source,
	];
}

function folderItem(string $name): array {
	return [
		'id' => 'folder-' . $name,
		'name' => $name,
		'lastModifiedDateTime' => MODIFIED,
		'folder' => ['childCount' => 1],
	];
}

function baseUrl(): string {
	return 'http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8099');
}

/**
 * The file that says the drive has been made unreachable on purpose. It carries the port
 * so that two stubs on one machine, or a later run, do not inherit it.
 */
function brokenDriveFlag(): string {
	return sys_get_temp_dir() . '/graph-stub-broken-drive-' . ($_SERVER['SERVER_PORT'] ?? 'x');
}

function respond(array $body, int $status = 200): void {
	http_response_code($status);
	header('Content-Type: application/json');
	logRequest($status);
	echo json_encode($body);
}

/** The requests the app made, so that a failing test can be read from the server log. */
function logRequest(int $status): void {
	error_log(sprintf(
		'stub %s %s -> %d',
		$_SERVER['REQUEST_METHOD'] ?? '?',
		$_SERVER['REQUEST_URI'] ?? '?',
		$status
	));
}

function serveDownload(string $id): void {
	if (!isset(FILES[$id])) {
		respond(['error' => ['code' => 'itemNotFound']], 404);
		return;
	}
	$file = FILES[$id];
	$source = $_GET['source'] ?? 'listing';
	$allowed = $file['download'] === 'always'
		|| ($file['download'] === 'fresh-url-only' && $source === 'fresh');
	if (!$allowed) {
		// what an expired or revoked download URL looks like
		respond(['error' => ['code' => 'accessDenied', 'message' => 'the download URL is no good']], 403);
		return;
	}
	header('Content-Type: text/plain');
	header('Content-Length: ' . strlen($file['content']));
	logRequest(200);
	echo $file['content'];
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rawurldecode($path);

// the test asks for the drive to be broken and repaired, no access token involved
if ($path === '/control/break-drive') {
	touch(brokenDriveFlag());
	respond(['broken' => true]);
	return;
}
if ($path === '/control/repair-drive') {
	@unlink(brokenDriveFlag());
	respond(['broken' => false]);
	return;
}

// downloads carry their own authorisation in the URL, everything else needs the access token
if (!str_starts_with($path, '/download/') && !preg_match('/^bearer .+/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '')) {
	respond(['error' => ['code' => 'unauthenticated', 'message' => 'no access token']], 401);
	return;
}

if (preg_match('#^/download/([^/?]+)$#', $path, $matches)) {
	serveDownload($matches[1]);
	return;
}

if ($path === '/v1.0/me/drive') {
	if (file_exists(brokenDriveFlag())) {
		// what a revoked consent looks like: the import cannot even read the drive
		respond(['error' => ['code' => 'accessDenied', 'message' => 'the drive is not yours any more']], 403);
		return;
	}
	respond(['id' => 'stub-drive', 'quota' => ['total' => 1073741824, 'used' => 42, 'remaining' => 1073741782]]);
	return;
}

// the folder itself, asked for to copy its modification time
if ($path === '/v1.0/me/drive/root' || preg_match('#^/v1\.0/me/drive/root:(/[^:]*):$#', $path)) {
	respond(['id' => 'root', 'name' => 'root', 'lastModifiedDateTime' => MODIFIED, 'folder' => ['childCount' => 6]]);
	return;
}

// the listing of a folder, the root one in two pages
if ($path === '/v1.0/me/drive/root/children') {
	if (($_GET['$skiptoken'] ?? '') === 'page2') {
		respond(['value' => [fileItem('f3'), fileItem('f4'), fileItem('f5'), folderItem('sub')]]);
		return;
	}
	respond([
		'value' => [fileItem('f1'), fileItem('f2')],
		'@odata.nextLink' => baseUrl() . '/v1.0/me/drive/root/children?$skiptoken=page2',
	]);
	return;
}

if (preg_match('#^/v1\.0/me/drive/root:(/[^:]*):/children$#', $path, $matches)) {
	respond(['value' => $matches[1] === '/sub' ? [fileItem('f6')] : []]);
	return;
}

// a single item, asked for when a download failed and a fresh URL is needed
if (preg_match('#^/v1\.0/me/drive/items/([^/]+)$#', $path, $matches)) {
	if (!isset(FILES[$matches[1]])) {
		respond(['error' => ['code' => 'itemNotFound']], 404);
		return;
	}
	respond(fileItem($matches[1], 'fresh'));
	return;
}

respond(['error' => ['code' => 'unknownEndpoint', 'message' => $path]], 404);
