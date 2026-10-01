<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Database-free protected attachment endpoint for mod_ajaxchat.
 *
 * @package mod_ajaxchat
 * @copyright 2026 Eduardo Kraus
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define("NO_DEBUG_DISPLAY", true);
define("ABORT_AFTER_CONFIG", true);
require_once(__DIR__ . "/../../../config.php");
require_once(__DIR__ . "/../classes/local/chat_store.php");
require_once(__DIR__ . "/minimal.php");

$runtime = mod_ajaxchat_minimal_runtime();
$filekey = isset($_GET["file"]) ? (string)$_GET["file"] : "";
$store = mod_ajaxchat_minimal_store($runtime);
$state = mod_ajaxchat_minimal_state($store, $runtime);
if (!empty($state["locked"])) {
    http_response_code(403);
    exit;
}
$path = $store->attachment_path($filekey);
if (!$path) {
    http_response_code(404);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimetype = (string)$finfo->file($path);
$size = (int)filesize($path);
$start = 0;
$end = max(0, $size - 1);
$status = 200;

if (!empty($_SERVER["HTTP_RANGE"]) && preg_match('/bytes=(\d*)-(\d*)/', (string)$_SERVER["HTTP_RANGE"], $matches)) {
    if ($matches[1] !== "") {
        $start = min((int)$matches[1], $end);
    }
    if ($matches[2] !== "") {
        $end = min((int)$matches[2], $end);
    }
    if ($start <= $end) {
        $status = 206;
    }
}

$length = $end - $start + 1;
http_response_code($status);
header("Content-Type: " . ($mimetype ?: "application/octet-stream"));
header("Accept-Ranges: bytes");
header("Content-Length: " . $length);
header("Cache-Control: private, max-age=300");
header("X-Content-Type-Options: nosniff");
$inline = str_starts_with($mimetype, "audio/") || in_array($mimetype, ["video/webm", "image/jpeg", "image/png", "image/gif", "image/webp"], true);
header("Content-Disposition: " . ($inline ? "inline" : "attachment") . "; filename=\"file\"");
if ($status === 206) {
    header("Content-Range: bytes {$start}-{$end}/{$size}");
}

$handle = fopen($path, "rb");
if (!$handle) {
    http_response_code(404);
    exit;
}
fseek($handle, $start);
$remaining = $length;
while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, min(65536, $remaining));
    if ($chunk === false || $chunk === "") {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
}
fclose($handle);
