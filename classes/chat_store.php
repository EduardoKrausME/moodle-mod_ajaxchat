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
 * File-backed chat store.
 *
 * @package mod_ajaxchat
 * @copyright 2026 Eduardo Kraus
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_ajaxchat;

use DirectoryIterator;
use FilesystemIterator;
use Random\RandomException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Class chat_store
 */
class chat_store {
    /** @var int */
    private int $chatid;
    /** @var string */
    private string $basepath;

    /**
     * __construct
     *
     * @param int $chatid
     */
    public function __construct(int $chatid) {
        $this->chatid = $chatid;
        $this->basepath = self::instances_root() . "/" . $chatid;
        $this->ensure_directories();
    }

    /**
     * data_root
     *
     * @return string
     */
    public static function data_root(): string {
        global $CFG;
        return rtrim($CFG->dataroot, "/\\") . "/mod_ajaxchat";
    }

    /**
     * instances_root
     *
     * @return string
     */
    public static function instances_root(): string {
        return self::data_root() . "/chats";
    }

    /**
     * runtime_root
     *
     * @return string
     */
    public static function runtime_root(): string {
        return self::data_root() . "/runtime";
    }

    /**
     * instance_path
     *
     * @param int $chatid
     * @return string
     */
    public static function instance_path(int $chatid): string {
        return self::instances_root() . "/" . $chatid;
    }

    /**
     * path
     *
     * @return string
     */
    public function path(): string {
        return $this->basepath;
    }

    /**
     * initialise_state
     *
     * @param array $settings
     * @return void
     */
    public function initialise_state(array $settings): void {
        $state = [
            "chatid" => $this->chatid,
            "enabled" => true,
            "allowaudio" => true,
            "allowattachments" => true,
            "openfrom" => 0,
            "blocked" => [],
            "activepolls" => [],
            "updated" => time(),
        ];
        $state = array_replace($state, $settings);
        $this->write_json_atomic($this->basepath . "/state.json", $state);
    }

    /**
     * get_state
     *
     * @return array
     */
    public function get_state(): array {
        $state = $this->read_json($this->basepath . "/state.json");
        if (!$state) {
            $this->initialise_state([]);
            $state = $this->read_json($this->basepath . "/state.json");
        }
        return $state;
    }

    /**
     * merge_state
     *
     * @param array $changes
     * @return array
     */
    public function merge_state(array $changes): array {
        return $this->mutate_json($this->basepath . "/state.json", function (array $state) use ($changes): array {
            if (!$state) {
                $state = [
                    "chatid" => $this->chatid,
                    "enabled" => true,
                    "allowaudio" => true,
                    "allowattachments" => true,
                    "openfrom" => 0,
                    "blocked" => [],
                    "activepolls" => [],
                ];
            }
            foreach ($changes as $key => $value) {
                $state[$key] = $value;
            }
            $state["updated"] = time();
            return $state;
        });
    }

    /**
     * mutate_state
     *
     * @param callable $callback
     * @return array
     */
    public function mutate_state(callable $callback): array {
        return $this->mutate_json($this->basepath . "/state.json", function (array $state) use ($callback): array {
            $state = $callback($state);
            $state["updated"] = time();
            return $state;
        });
    }

    /**
     * write_message
     *
     * @param array $message
     * @return void
     */
    public function write_message(array $message): void {
        $id = (int)$message["id"];
        $this->write_json_atomic($this->message_path($id), $message);
        $this->remember_message($id);
    }

    /**
     * get_message
     *
     * @param int $messageid
     * @return array
     */
    public function get_message(int $messageid): array {
        return $this->read_json($this->message_path($messageid));
    }

    /**
     * mutate_message
     *
     * @param int $messageid
     * @param callable $callback
     * @return array
     */
    public function mutate_message(int $messageid, callable $callback): array {
        return $this->mutate_json($this->message_path($messageid), $callback);
    }

    /**
     * write_poll
     *
     * @param int $pollid
     * @param array $poll
     * @return void
     */
    public function write_poll(int $pollid, array $poll): void {
        $this->write_json_atomic($this->poll_path($pollid), $poll);
    }

    /**
     * get_poll
     *
     * @param int $pollid
     * @return array
     */
    public function get_poll(int $pollid): array {
        return $this->read_json($this->poll_path($pollid));
    }

    /**
     * mutate_poll
     *
     * @param int $pollid
     * @param callable $callback
     * @return array
     */
    public function mutate_poll(int $pollid, callable $callback): array {
        return $this->mutate_json($this->poll_path($pollid), $callback);
    }

    /**
     * remove_poll
     *
     * @param int $pollid
     * @return void
     */
    public function remove_poll(int $pollid): void {
        $path = $this->poll_path($pollid);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * append_event
     *
     * @param array $event
     * @return string
     */
    public function append_event(array $event): string {
        $event["eventtime"] = time();
        $filename = "events-" . gmdate("Ymd") . ".jsonl";
        $path = $this->basepath . "/events/" . $filename;
        $line = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

        $handle = fopen($path, "ab");
        if (!$handle) {
            throw new RuntimeException("Unable to open chat event log.");
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException("Unable to lock chat event log.");
            }
            fwrite($handle, $line);
            fflush($handle);
            $offset = ftell($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return $filename . ":" . $offset;
    }

    /**
     * current_cursor
     *
     * @return string
     */
    public function current_cursor(): string {
        $files = $this->event_files();
        if (!$files) {
            $filename = "events-" . gmdate("Ymd") . ".jsonl";
            return $filename . ":0";
        }
        $path = end($files);
        return basename($path) . ":" . (int)filesize($path);
    }


    /**
     * clamp_cursor
     *
     * @param string $cursor
     * @param string $minimum
     * @return string
     */
    public function clamp_cursor(string $cursor, string $minimum): string {
        [$minfile, $minoffset] = $this->parse_cursor($minimum);
        [$file, $offset] = $this->parse_cursor($cursor);
        [$currentfile, $currentoffset] = $this->parse_cursor($this->current_cursor());

        if ($currentfile === "") {
            return $minimum;
        }
        if ($minfile === "" || $minfile > $currentfile ||
            ($minfile === $currentfile && $minoffset > $currentoffset)) {
            $minfile = $currentfile;
            $minoffset = $currentoffset;
            $minimum = $currentfile . ":" . $currentoffset;
        }
        if ($file === "") {
            return $minimum;
        }
        if ($file < $minfile || ($file === $minfile && $offset < $minoffset)) {
            return $minimum;
        }
        if ($file > $currentfile || ($file === $currentfile && $offset > $currentoffset)) {
            return $currentfile . ":" . $currentoffset;
        }

        $paths = [];
        foreach ($this->event_files() as $path) {
            $paths[basename($path)] = $path;
        }

        // A zero-byte current cursor is valid before today's first event file exists.
        if (!isset($paths[$file])) {
            if ($file === $currentfile && $currentoffset === 0 && $offset === 0) {
                return $currentfile . ":0";
            }
            // Unknown or removed files must never cause the reader to rewind.
            return $currentfile . ":" . $currentoffset;
        }

        $size = (int)filesize($paths[$file]);
        if ($offset > $size) {
            $offset = $size;
        }
        if ($file === $minfile && $offset < $minoffset) {
            return $minimum;
        }

        return $file . ":" . $offset;
    }

    /**
     * read_events
     *
     * @param string $cursor
     * @param int $limit
     * @return array
     */
    public function read_events(string $cursor, int $limit = 200): array {
        $files = $this->event_files();
        if (!$files) {
            return ["events" => [], "cursor" => $this->current_cursor()];
        }

        [$cursorfile, $cursoroffset] = $this->parse_cursor($cursor);
        if ($cursorfile === "") {
            return ["events" => [], "cursor" => $this->current_cursor()];
        }

        $cursoroffset = max(0, $cursoroffset);
        $events = [];
        $newcursor = $cursor;
        $started = false;

        foreach ($files as $path) {
            $filename = basename($path);
            if (!$started) {
                if ($filename === $cursorfile) {
                    $started = true;
                } else {
                    continue;
                }
            }

            $offset = $filename === $cursorfile ? $cursoroffset : 0;
            $handle = fopen($path, "rb");
            if (!$handle) {
                continue;
            }
            try {
                flock($handle, LOCK_SH);
                clearstatcache(true, $path);
                $size = (int)filesize($path);
                if ($offset > $size) {
                    // Clamp to EOF. Never rewind an invalid future offset to byte zero.
                    $offset = $size;
                }
                fseek($handle, $offset);
                while (count($events) < $limit && ($line = fgets($handle)) !== false) {
                    $decoded = json_decode($line, true);
                    if (is_array($decoded)) {
                        $events[] = $decoded;
                    }
                    $newcursor = $filename . ":" . ftell($handle);
                }
                flock($handle, LOCK_UN);
            } finally {
                fclose($handle);
            }

            if (count($events) >= $limit) {
                break;
            }
            clearstatcache(true, $path);
            $newcursor = $filename . ":" . (int)filesize($path);
        }

        if (!$started) {
            // An unknown cursor must advance to the present rather than expose old events.
            return ["events" => [], "cursor" => $this->current_cursor()];
        }

        return ["events" => $events, "cursor" => $newcursor];
    }

    /**
     * get_history
     *
     * @param int $userid
     * @param int $limit
     * @return array
     */
    public function get_history(int $userid, int $limit = 100): array {
        $recent = $this->read_json($this->basepath . "/recent.json");
        $ids = array_values(array_filter(array_map("intval", $recent["ids"] ?? [])));

        // Fallback for a recovered or upgraded data directory that has no recent index yet.
        if (!$ids) {
            $directory = new DirectoryIterator($this->basepath . "/messages");
            foreach ($directory as $file) {
                if ($file->isFile() && preg_match('/^(\d+)\.json$/', $file->getFilename(), $matches)) {
                    $ids[] = (int)$matches[1];
                }
            }
        }

        $ids = array_values(array_unique($ids));
        rsort($ids, SORT_NUMERIC);
        $ids = array_slice($ids, 0, max(1, $limit));
        sort($ids, SORT_NUMERIC);

        $messages = [];
        foreach ($ids as $id) {
            $message = $this->get_message($id);
            if (!$message) {
                continue;
            }
            $messages[] = $this->prepare_message_for_client($message, $userid);
        }

        return $messages;
    }

    /**
     * prepare_message_for_client
     *
     * @param array $message
     * @param int $userid
     * @return array
     */
    public function prepare_message_for_client(array $message, int $userid): array {
        $reactions = $message["reactionusers"] ?? [];
        $counts = [];
        $myreaction = null;
        foreach ($reactions as $reactionuserid => $emoji) {
            $emoji = (string)$emoji;
            $counts[$emoji] = ($counts[$emoji] ?? 0) + 1;
            if ((int)$reactionuserid === $userid) {
                $myreaction = $emoji;
            }
        }
        unset($message["reactionusers"]);
        $message["reactions"] = $counts;
        $message["myreaction"] = $myreaction;

        if (!empty($message["pollid"])) {
            $poll = $this->get_poll((int)$message["pollid"]);
            if ($poll) {
                $message["poll"] = $this->prepare_poll_for_client($poll, $userid);
            }
        }
        return $message;
    }

    /**
     * prepare_poll_for_client
     *
     * @param array $poll
     * @param int $userid
     * @return array
     */
    public function prepare_poll_for_client(array $poll, int $userid): array {
        $votes = $poll["votes"] ?? [];
        $counts = [];
        $myvote = null;
        foreach ($votes as $voteuserid => $optionid) {
            $optionid = (int)$optionid;
            $counts[$optionid] = ($counts[$optionid] ?? 0) + 1;
            if ((int)$voteuserid === $userid) {
                $myvote = $optionid;
            }
        }
        unset($poll["votes"]);
        foreach ($poll["options"] ?? [] as &$option) {
            $option["votes"] = $counts[(int)$option["id"]] ?? 0;
        }
        unset($option);
        $poll["myvote"] = $myvote;
        $poll["totalvotes"] = array_sum($counts);
        return $poll;
    }

    /**
     * store_upload
     *
     * @param string $source
     * @param string $originalname
     * @param string $mimetype
     * @param string $kind
     * @return array
     * @throws RandomException
     */
    public function store_upload(string $source, string $originalname, string $mimetype, string $kind): array {
        $maxbytes = $kind === "audio" ? 20 * 1024 * 1024 : 25 * 1024 * 1024;
        $size = filesize($source);
        if ($size === false || $size <= 0 || $size > $maxbytes) {
            throw new RuntimeException("filetoolarge");
        }

        $extension = $this->extension_for_mimetype($mimetype, $originalname, $kind);
        $filekey = bin2hex(random_bytes(24)) . $extension;
        $destination = $this->basepath . "/files/" . $filekey;

        if (is_uploaded_file($source)) {
            $ok = move_uploaded_file($source, $destination);
        } else {
            $ok = rename($source, $destination);
        }
        if (!$ok) {
            throw new RuntimeException("Unable to store uploaded file.");
        }
        @chmod($destination, 0600);

        $displayname = trim((string)preg_replace('/[\x00-\x1F\x7F\\\/]+/u', "_", $originalname));
        if ($displayname === "") {
            $displayname = $kind === "audio" ? "audio" . $extension : "attachment" . $extension;
        }

        return [
            "filekey" => $filekey,
            "filename" => mb_substr($displayname, 0, 180),
            "mimetype" => $mimetype,
            "size" => $size,
            "kind" => $kind,
        ];
    }

    /**
     * attachment_path
     *
     * @param string $filekey
     * @return string|null
     */
    public function attachment_path(string $filekey): ?string {
        if (!preg_match('/^[a-f0-9]{48}(?:\.[a-z0-9]{1,8})?$/', $filekey)) {
            return null;
        }
        $path = $this->basepath . "/files/" . $filekey;
        return is_file($path) ? $path : null;
    }

    /**
     * remove_attachment
     *
     * @param array|null $attachment
     * @return void
     */
    public function remove_attachment(?array $attachment): void {
        if (empty($attachment["filekey"])) {
            return;
        }
        $path = $this->attachment_path((string)$attachment["filekey"]);
        if ($path) {
            @unlink($path);
        }
    }

    /**
     * write_runtime
     *
     * @param string $runtimeid
     * @param array $runtime
     * @return void
     */
    public static function write_runtime(string $runtimeid, array $runtime): void {
        self::ensure_directory(self::runtime_root());
        $path = self::runtime_root() . "/" . $runtimeid . ".json";
        $runtime["runtimeid"] = $runtimeid;
        $runtime["updated"] = time();
        self::write_static_json_atomic($path, $runtime);
    }

    /**
     * read_runtime
     *
     * @param string $runtimeid
     * @return array
     */
    public static function read_runtime(string $runtimeid): array {
        if (!preg_match('/^[a-f0-9]{64}$/', $runtimeid)) {
            return [];
        }
        $path = self::runtime_root() . "/" . $runtimeid . ".json";
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data) || empty($data["expires"]) || (int)$data["expires"] < time()) {
            @unlink($path);
            return [];
        }
        return $data;
    }

    /**
     * delete_runtime
     *
     * @param string $runtimeid
     * @return void
     */
    public static function delete_runtime(string $runtimeid): void {
        if (preg_match('/^[a-f0-9]{64}$/', $runtimeid)) {
            @unlink(self::runtime_root() . "/" . $runtimeid . ".json");
        }
    }

    /**
     * cleanup_runtimes
     *
     * @return int
     */
    public static function cleanup_runtimes(): int {
        self::ensure_directory(self::runtime_root());
        $deleted = 0;
        foreach (glob(self::runtime_root() . "/*.json") ?: [] as $path) {
            $data = json_decode((string)@file_get_contents($path), true);
            if (!is_array($data) || empty($data["expires"]) || (int)$data["expires"] < time()) {
                if (@unlink($path)) {
                    $deleted++;
                }
            }
        }
        return $deleted;
    }

    /**
     * remove_instance_directory
     *
     * @param int $chatid
     * @return void
     */
    public static function remove_instance_directory(int $chatid): void {
        $path = self::instance_path($chatid);
        if (!is_dir($path)) {
            return;
        }
        self::remove_directory($path);
    }


    /**
     * remember_message
     *
     * @param int $messageid
     * @return void
     */
    private function remember_message(int $messageid): void {
        $this->mutate_json($this->basepath . "/recent.json", function (array $recent) use ($messageid): array {
            $ids = array_values(array_filter(array_map("intval", $recent["ids"] ?? [])));
            $ids[] = $messageid;
            $ids = array_values(array_unique($ids));
            rsort($ids, SORT_NUMERIC);
            $ids = array_slice($ids, 0, 200);
            return ["ids" => $ids, "updated" => time()];
        });
    }

    /**
     * message_path
     *
     * @param int $messageid
     * @return string
     */
    private function message_path(int $messageid): string {
        return $this->basepath . "/messages/" . $messageid . ".json";
    }

    /**
     * poll_path
     *
     * @param int $pollid
     * @return string
     */
    private function poll_path(int $pollid): string {
        return $this->basepath . "/polls/" . $pollid . ".json";
    }

    /**
     * event_files
     *
     * @return array
     */
    private function event_files(): array {
        $files = glob($this->basepath . "/events/events-*.jsonl") ?: [];
        sort($files, SORT_STRING);
        return $files;
    }

    /**
     * parse_cursor
     *
     * @param string $cursor
     * @return array
     */
    private function parse_cursor(string $cursor): array {
        if (!preg_match('/^(events-\d{8}\.jsonl):(\d+)$/', $cursor, $matches)) {
            return ["", 0];
        }
        return [$matches[1], (int)$matches[2]];
    }

    /**
     * ensure_directories
     *
     * @return void
     */
    private function ensure_directories(): void {
        foreach ([
                     self::data_root(),
                     self::instances_root(),
                     self::runtime_root(),
                     $this->basepath,
                     $this->basepath . "/events",
                     $this->basepath . "/messages",
                     $this->basepath . "/polls",
                     $this->basepath . "/files",
                     $this->basepath . "/locks",
                 ] as $directory) {
            self::ensure_directory($directory);
        }
    }

    /**
     * ensure_directory
     *
     * @param string $directory
     * @return void
     */
    private static function ensure_directory(string $directory): void {
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create chat data directory.");
        }
    }

    /**
     * read_json
     *
     * @param string $path
     * @return array
     */
    private function read_json(string $path): array {
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /**
     * write_json_atomic
     *
     * @param string $path
     * @param array $data
     * @return void
     */
    private function write_json_atomic(string $path, array $data): void {
        self::write_static_json_atomic($path, $data);
    }

    /**
     * write_static_json_atomic
     *
     * @param string $path
     * @param array $data
     * @return void
     * @throws RandomException
     */
    private static function write_static_json_atomic(string $path, array $data): void {
        self::ensure_directory(dirname($path));
        $tmp = $path . "." . bin2hex(random_bytes(6)) . ".tmp";
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write chat JSON file.");
        }
        @chmod($tmp, 0600);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Unable to replace chat JSON file.");
        }
    }

    /**
     * mutate_json
     *
     * @param string $path
     * @param callable $callback
     * @return array
     */
    private function mutate_json(string $path, callable $callback): array {
        $lockpath = $this->basepath . "/locks/" . sha1($path) . ".lock";
        $lock = fopen($lockpath, "c+");
        if (!$lock) {
            throw new RuntimeException("Unable to open chat lock.");
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException("Unable to lock chat JSON file.");
            }
            $current = $this->read_json($path);
            $updated = $callback($current);
            if (!is_array($updated)) {
                throw new RuntimeException("Chat JSON mutation must return an array.");
            }
            $this->write_json_atomic($path, $updated);
            flock($lock, LOCK_UN);
            return $updated;
        } finally {
            fclose($lock);
        }
    }

    /**
     * extension_for_mimetype
     *
     * @param string $mimetype
     * @param string $originalname
     * @param string $kind
     * @return string
     */
    private function extension_for_mimetype(string $mimetype, string $originalname, string $kind): string {
        $blockedmimes = [
            "text/html",
            "application/xhtml+xml",
            "application/javascript",
            "text/javascript",
            "image/svg+xml",
            "application/x-httpd-php",
        ];
        if (in_array($mimetype, $blockedmimes, true)) {
            throw new RuntimeException("invalidfile");
        }

        $map = [
            "audio/webm" => ".webm",
            "video/webm" => ".webm",
            "audio/ogg" => ".ogg",
            "application/ogg" => ".ogg",
            "audio/mp4" => ".m4a",
            "video/mp4" => ".mp4",
            "audio/mpeg" => ".mp3",
            "audio/wav" => ".wav",
            "image/jpeg" => ".jpg",
            "image/png" => ".png",
            "image/gif" => ".gif",
            "image/webp" => ".webp",
            "application/pdf" => ".pdf",
            "text/plain" => ".txt",
            "text/csv" => ".csv",
            "application/zip" => ".zip",
            "application/vnd.openxmlformats-officedocument.wordprocessingml.document" => ".docx",
            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" => ".xlsx",
            "application/vnd.openxmlformats-officedocument.presentationml.presentation" => ".pptx",
        ];
        if (isset($map[$mimetype])) {
            return $map[$mimetype];
        }
        if ($kind === "audio") {
            throw new RuntimeException("invalidfile");
        }

        $extension = strtolower(pathinfo($originalname, PATHINFO_EXTENSION));
        if ($extension === "" || !preg_match('/^[a-z0-9]{1,8}$/', $extension)) {
            return ".bin";
        }
        $blocked = ["php", "phtml", "phar", "cgi", "pl", "py", "sh", "exe", "dll", "com", "bat", "cmd", "js", "html", "htm", "svg"];
        if (in_array($extension, $blocked, true)) {
            throw new RuntimeException("invalidfile");
        }
        return "." . $extension;
    }

    /**
     * remove_directory
     *
     * @param string $path
     * @return void
     */
    private static function remove_directory(string $path): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
