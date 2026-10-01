# mod_ajaxchat

Chat activity for Moodle with a file-backed read path designed to keep polling away from the database.

## Architecture

The normal Moodle page (`view.php`) performs the standard login, capability and activity checks once. It then creates a
short-lived runtime with:

- a random runtime id;
- a random read token;
- a custom CSRF token saved in the Moodle session;
- the absolute activity path in moodledata saved in the Moodle session;
- scalar capability flags and participant ids.

A second runtime file is stored under `moodledata/mod_ajaxchat/runtime`. It contains only the data needed by the
read-only endpoints.

`ajax/history.php`, `ajax/poll.php` and `ajax/file.php` define `ABORT_AFTER_CONFIG` before loading
Moodle's `config.php`. They obtain `$CFG->dataroot` without starting the full Moodle framework or a database connection.
They validate the opaque runtime/read token against the runtime JSON file and read only files under moodledata.

`ajax/action.php` loads Moodle normally because mutations need the authenticated Moodle session. The plugin mutation
service does not perform plugin SELECT queries. It uses the runtime/session and moodledata JSON as the read source, and
only executes INSERT, UPDATE and DELETE operations for persistent database writes.

## moodledata layout

```text
moodledata/mod_ajaxchat/
├── runtime/
│   └── <runtime-id>.json
└── chats/
    └── <ajaxchat-id>/
        ├── state.json
        ├── events/
        │   └── events-YYYYMMDD.jsonl
        ├── recent.json
        ├── messages/
        │   └── <message-id>.json
        ├── polls/
        │   └── <poll-id>.json
        ├── files/
        │   └── <random-file-key>.<ext>
        └── locks/
```

The event stream is append-only JSONL and stores only lightweight event metadata/IDs, not message bodies or attachment
data. Each browser keeps its own byte cursor, so two tabs from the same user do not share an offset. Current message and
poll state is maintained in small JSON files, while `recent.json` keeps the latest message IDs, so opening a chat does
not scan or replay the entire event history.

## Features

Students can send text, audio recorded with MediaRecorder, and attachments. They can edit or delete their own message
for 60 seconds, and can react with Like/emoji.

Teachers with `mod/ajaxchat:manage` can delete any message, enable or disable the chat, and block/unblock participants.
Teachers with `mod/ajaxchat:createpoll` can create polls pinned at the top or in a side panel; the poll remains pinned
until it is closed.

The activity form can enable/disable audio, enable/disable attachments, and define an availability date. Before that
date students do not receive the history and cannot use the composer; managers can still access the chat.

## Security notes

The browser never receives the moodledata path. File access uses opaque runtime/read tokens and opaque random file keys.
Paths are derived server-side. Attachments are outside the web root. Active-content MIME types such as HTML, JavaScript
and SVG are rejected, and non-media files are served as downloads.

The lightweight read runtime is intentionally independent of the Moodle session so polling can avoid the database. Its
lifetime is four hours. Logging out of Moodle does not immediately revoke an already-issued lightweight read token; it
expires automatically or when its runtime file is removed. If immediate logout revocation is required, the read
endpoints must bootstrap the Moodle session, which removes the database-free polling advantage.

## Backup

`FEATURE_BACKUP_MOODLE2` is currently disabled because binary attachments are intentionally stored outside Moodle's File
API. A future backup implementation should explicitly package the chat data directory and restore it with remapped
instance/message/poll ids.
