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
 * chat.js
 *
 * @package   mod_ajaxchat
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(["jquery"], function ($) {
    "use strict";

    var config = null;
    var root = null;
    var messages = {};
    var polls = {};
    var cursor = "";
    var state = {
        enabled: true,
        blocked: false,
        locked: false,
        allowaudio: true,
        allowattachments: true,
        openfrom: 0
    };
    var pollTimer = null;
    var pendingUpload = null;
    var mediaRecorder = null;
    var mediaStream = null;
    var mediaChunks = [];
    var loadingHistory = false;

    var escAttr = function (value) {
        return String(value || "");
    };

    var fileUrl = function (filekey) {
        return config.fileUrl + "?" + $.param({
            runtime: config.runtime,
            readtoken: config.readtoken,
            file: filekey
        });
    };

    var showNotice = function (text) {
        var notice = root.find('[data-region="notice"]');
        if (!text) {
            notice.prop("hidden", true).text("");
            return;
        }
        notice.text(text).prop("hidden", false);
    };

    var setLoading = function (loading) {
        root.find('[data-region="loading"]').toggle(loading);
    };

    var canInteract = function () {
        if (!config.cansend) {
            return false;
        }
        if (config.canmanage) {
            return true;
        }
        return !state.locked && state.enabled && !state.blocked;
    };

    var updateComposer = function () {
        var enabled = canInteract();
        root.find('[data-region="message-input"]').prop("disabled", !enabled);
        root.find('[data-action="send"]').prop("disabled", !enabled);
        root.find('[data-action="record-audio"]').prop("disabled", !enabled || !state.allowaudio);
        root.find('[data-region="attachment-input"]').prop("disabled", !enabled || !state.allowattachments);

        if (!config.canmanage) {
            if (state.locked) {
                var when = state.openfrom ? new Date(state.openfrom * 1000).toLocaleString() : "";
                showNotice(when ? config.strings.availablefromprefix + " " + when + "." : config.strings.chatnotavailableyet);
            } else if (state.blocked) {
                showNotice(config.strings.blocked);
            } else if (!state.enabled) {
                showNotice(config.strings.disabled);
            } else {
                showNotice("");
            }
        } else {
            showNotice("");
        }

        var status = root.find('[data-region="chat-status"]');
        status.text(state.enabled ? config.strings.online : config.strings.disabled);
        var toggle = root.find('[data-action="toggle-chat"]');
        if (toggle.length) {
            toggle.attr("data-enabled", state.enabled ? "1" : "0");
            toggle.text(state.enabled ? config.strings.disablechat : config.strings.enablechat);
        }
    };

    var applyState = function (nextState) {
        if (!nextState) {
            return;
        }
        var wasLocked = state.locked;
        state.enabled = Boolean(nextState.enabled);
        state.blocked = Boolean(nextState.blocked);
        state.locked = Boolean(nextState.locked);
        state.allowaudio = Boolean(nextState.allowaudio);
        state.allowattachments = Boolean(nextState.allowattachments);
        state.openfrom = parseInt(nextState.openfrom || 0, 10);

        if (config.canmanage && Array.isArray(nextState.blockeduserids)) {
            root.find('[data-action="toggle-block"]').each(function () {
                var button = $(this);
                var userid = parseInt(button.attr("data-userid"), 10);
                setParticipantBlocked(userid, nextState.blockeduserids.indexOf(userid) !== -1);
            });
        }
        updateComposer();

        if (wasLocked && !state.locked && !loadingHistory) {
            loadHistory(true);
        }
    };

    var formatTime = function (timestamp) {
        if (!timestamp) {
            return "";
        }
        return new Date(timestamp * 1000).toLocaleTimeString([], {hour: "2-digit", minute: "2-digit"});
    };

    var createReactionChip = function (messageid, emoji, count, mine) {
        var button = $("<button>", {
            type: "button",
            class: "ajaxchat-reaction-chip" + (mine ? " is-mine" : ""),
            text: emoji + " " + count,
            "data-action": "reaction",
            "data-messageid": messageid,
            "data-reaction": emoji
        });
        return button;
    };

    var renderReactions = function (message, container) {
        container.empty();
        var reactions = message.reactions || {};
        Object.keys(reactions).forEach(function (emoji) {
            if (parseInt(reactions[emoji], 10) > 0) {
                container.append(createReactionChip(message.id, emoji, reactions[emoji], message.myreaction === emoji));
            }
        });
    };

    var renderPollCard = function (poll, pinned) {
        var card = $("<div>", {class: "ajaxchat-poll-card", "data-pollid": poll.id});
        card.append($("<h4>").text(poll.question));
        var total = parseInt(poll.totalvotes || 0, 10);

        (poll.options || []).forEach(function (option) {
            var votes = parseInt(option.votes || 0, 10);
            var percent = total > 0 ? Math.round((votes / total) * 100) : 0;
            var row = $("<button>", {
                type: "button",
                class: "ajaxchat-poll-option",
                "data-action": "vote",
                "data-pollid": poll.id,
                "data-optionid": option.id
            });
            row.prop("disabled", Boolean(poll.closed) || !canInteract());
            row.append($("<span>").text(parseInt(poll.myvote, 10) === parseInt(option.id, 10) ? "●" : "○"));
            row.append($("<span>").text(option.text));
            row.append($("<span>").text(votes + " · " + percent + "%"));
            var bar = $("<span>", {class: "ajaxchat-poll-bar"});
            bar.append($("<span>").css("width", percent + "%"));
            row.append(bar);
            card.append(row);
        });

        var footer = $("<div>", {class: "mt-2 small text-muted"});
        footer.text(total + (total === 1 ? " voto" : " votos"));
        if (poll.closed) {
            footer.append(" · " + config.strings.pollclosed);
        }
        card.append(footer);

        if (config.cancreatepoll && !poll.closed) {
            card.append($("<button>", {
                type: "button",
                class: "btn btn-sm btn-outline-secondary mt-2",
                text: config.strings.closepoll,
                "data-action": "close-poll",
                "data-pollid": poll.id
            }));
        }

        if (pinned) {
            card.addClass("is-pinned");
        }
        return card;
    };

    var messageCanEdit = function (message) {
        return !message.deleted && parseInt(message.userid, 10) === config.userid &&
            Math.floor(Date.now() / 1000) - parseInt(message.timecreated, 10) <= 60 && message.type !== "poll";
    };

    var messageCanDelete = function (message) {
        if (message.deleted) {
            return false;
        }
        if (config.canmanage) {
            return true;
        }
        return parseInt(message.userid, 10) === config.userid &&
            Math.floor(Date.now() / 1000) - parseInt(message.timecreated, 10) <= 60;
    };

    var renderMessage = function (message) {
        var own = parseInt(message.userid, 10) === config.userid;
        var wrapper = $("<div>", {
            class: "ajaxchat-message" + (own ? " is-own" : "") + (message.deleted ? " is-deleted" : ""),
            "data-messageid": message.id
        });
        wrapper.append($("<img>", {class: "ajaxchat-avatar", src: escAttr(message.avatar), alt: ""}));

        var body = $("<div>", {class: "ajaxchat-message-body"});
        var meta = $("<div>", {class: "ajaxchat-message-meta"});
        meta.text((own ? config.strings.you : (message.author || "")) + " · " + formatTime(message.timecreated));
        if (message.timemodified && !message.deleted) {
            meta.append(" · " + config.strings.edited);
        }
        body.append(meta);

        var bubble = $("<div>", {class: "ajaxchat-bubble"});
        if (message.deleted) {
            bubble.text(config.strings.deleted);
        } else {
            if (message.message) {
                var text = $("<div>", {class: "ajaxchat-text"});
                text.text(message.message);
                bubble.append(text);
            }
            if (message.attachment && message.attachment.filekey) {
                if (message.attachment.kind === "audio") {
                    var audio = $("<audio>", {controls: true, preload: "metadata", class: "ajaxchat-audio"});
                    audio.attr("src", fileUrl(message.attachment.filekey));
                    bubble.append(audio);
                } else {
                    var link = $("<a>", {
                        class: "ajaxchat-attachment",
                        href: fileUrl(message.attachment.filekey),
                        target: "_blank",
                        rel: "noopener"
                    });
                    link.append($("<span>").text("📎"));
                    link.append($("<span>").text(message.attachment.filename || config.strings.attachment));
                    bubble.append(link);
                }
            }
            if (message.poll) {
                polls[message.poll.id] = message.poll;
                bubble.append(renderPollCard(message.poll, false));
            }
        }
        body.append(bubble);

        if (!message.deleted) {
            var reactions = $("<div>", {class: "ajaxchat-reactions", "data-region": "reactions"});
            renderReactions(message, reactions);
            body.append(reactions);

            var actions = $("<div>", {class: "ajaxchat-message-actions"});
            if (canInteract()) {
                var picker = $("<span>", {class: "ajaxchat-reaction-picker"});
                ["👍", "❤️", "😂", "😮", "😢", "👏"].forEach(function (emoji) {
                    picker.append($("<button>", {
                        type: "button",
                        class: "ajaxchat-mini-button",
                        text: emoji,
                        "data-action": "reaction",
                        "data-messageid": message.id,
                        "data-reaction": emoji
                    }));
                });
                actions.append(picker);
            }
            if (messageCanEdit(message)) {
                actions.append($("<button>", {
                    type: "button",
                    class: "ajaxchat-mini-button",
                    text: config.strings.edit,
                    "data-action": "edit",
                    "data-messageid": message.id
                }));
            }
            if (messageCanDelete(message)) {
                actions.append($("<button>", {
                    type: "button",
                    class: "ajaxchat-mini-button",
                    text: config.strings.delete,
                    "data-action": "delete",
                    "data-messageid": message.id
                }));
            }
            body.append(actions);
        }
        wrapper.append(body);
        return wrapper;
    };

    var upsertMessage = function (message, keepScroll) {
        messages[message.id] = message;
        if (message.poll) {
            polls[message.poll.id] = message.poll;
        }
        var list = root.find('[data-region="messages"]');
        var existing = list.find('[data-messageid="' + parseInt(message.id, 10) + '"]');
        var rendered = renderMessage(message);
        if (existing.length) {
            existing.replaceWith(rendered);
        } else {
            list.append(rendered);
        }
        renderPinnedPolls();
        if (!keepScroll) {
            list.scrollTop(list[0].scrollHeight);
        }
    };

    var renderPinnedPolls = function () {
        var top = root.find('[data-region="pinned-top"]').empty();
        var side = root.find('[data-region="pinned-side"]').empty();
        Object.keys(polls).forEach(function (id) {
            var poll = polls[id];
            if (!poll || poll.closed) {
                return;
            }
            var target = poll.position === "side" ? side : top;
            target.append(renderPollCard(poll, true));
        });
    };

    var setParticipantBlocked = function (userid, blocked) {
        var row = root.find('.ajaxchat-participant[data-userid="' + userid + '"]');
        var button = row.find('[data-action="toggle-block"]');
        button.attr("data-blocked", blocked ? "1" : "0");
        button.text(blocked ? "✓" : "⊘");
        button.attr("title", blocked ? "Desbloquear" : "Bloquear");
        row.find('[data-region="blocked-label"]').remove();
        if (blocked) {
            row.find(".ajaxchat-participant-name").append($("<small>", {
                "data-region": "blocked-label",
                text: "Bloqueado"
            }));
        }
    };

    var applyReactionEvent = function (event) {
        var message = messages[event.messageid];
        if (!message) {
            return;
        }
        message.reactions = message.reactions || {};
        if (event.oldreaction) {
            message.reactions[event.oldreaction] = Math.max(0, parseInt(message.reactions[event.oldreaction] || 0, 10) - 1);
            if (message.reactions[event.oldreaction] === 0) {
                delete message.reactions[event.oldreaction];
            }
        }
        if (event.reaction) {
            message.reactions[event.reaction] = parseInt(message.reactions[event.reaction] || 0, 10) + 1;
        }
        if (parseInt(event.userid, 10) === config.userid) {
            message.myreaction = event.reaction || null;
        }
        upsertMessage(message, true);
    };

    var applyEvent = function (event) {
        switch (event.type) {
            case "message":
                if (event.message) {
                    upsertMessage(event.message, false);
                }
                break;
            case "edit":
                if (event.currentmessage) {
                    upsertMessage(event.currentmessage, true);
                } else if (messages[event.messageid]) {
                    messages[event.messageid].timemodified = event.timemodified;
                    upsertMessage(messages[event.messageid], true);
                }
                break;
            case "delete":
                if (event.currentmessage) {
                    var currentDeletedPollId = parseInt(event.pollid || 0, 10);
                    if (currentDeletedPollId && polls[currentDeletedPollId]) {
                        delete polls[currentDeletedPollId];
                    }
                    upsertMessage(event.currentmessage, true);
                    renderPinnedPolls();
                } else if (messages[event.messageid]) {
                    var deleted = messages[event.messageid];
                    deleted.deleted = true;
                    deleted.message = "";
                    deleted.attachment = null;
                    deleted.reactions = {};
                    var deletedPollId = parseInt(event.pollid || deleted.pollid || 0, 10);
                    if (deletedPollId && polls[deletedPollId]) {
                        delete polls[deletedPollId];
                    }
                    deleted.pollid = null;
                    deleted.poll = null;
                    upsertMessage(deleted, true);
                }
                break;
            case "reaction":
                applyReactionEvent(event);
                break;
            case "poll_changed":
                if (event.poll) {
                    polls[event.poll.id] = event.poll;
                    if (messages[event.poll.messageid]) {
                        messages[event.poll.messageid].poll = event.poll;
                        upsertMessage(messages[event.poll.messageid], true);
                    }
                    renderPinnedPolls();
                }
                break;
            case "state":
                state.enabled = Boolean(event.enabled);
                updateComposer();
                break;
            case "block":
                if (config.canmanage) {
                    setParticipantBlocked(parseInt(event.userid, 10), Boolean(event.blocked));
                }
                if (parseInt(event.userid, 10) === config.userid) {
                    state.blocked = Boolean(event.blocked);
                    updateComposer();
                }
                break;
        }
    };

    var loadHistory = function (reload) {
        if (loadingHistory) {
            return $.Deferred().resolve().promise();
        }
        loadingHistory = true;
        setLoading(true);
        return $.ajax({
            url: config.historyUrl,
            method: "GET",
            dataType: "json",
            data: {runtime: config.runtime, readtoken: config.readtoken}
        }).done(function (response) {
            if (!response.ok) {
                showNotice(response.error || config.strings.errorloadchat);
                return;
            }
            if (reload) {
                messages = {};
                polls = {};
                root.find('[data-region="messages"]').children().not('[data-region="loading"]').remove();
            }
            applyState(response.state);
            (response.messages || []).forEach(function (message) {
                upsertMessage(message, true);
            });
            (response.activepolls || []).forEach(function (poll) {
                polls[poll.id] = poll;
            });
            renderPinnedPolls();
            cursor = response.cursor || cursor;
            var list = root.find('[data-region="messages"]');
            if (list.length && list[0]) {
                list.scrollTop(list[0].scrollHeight);
            }
        }).fail(function (xhr) {
            if (xhr.status === 403) {
                showNotice(config.strings.errorsessionexpired);
            } else {
                showNotice(config.strings.errorloadhistory);
            }
        }).always(function () {
            loadingHistory = false;
            setLoading(false);
        });
    };

    var schedulePoll = function (delay) {
        clearTimeout(pollTimer);
        pollTimer = setTimeout(runPoll, delay || 1500);
    };

    var runPoll = function () {
        $.ajax({
            url: config.pollUrl,
            method: "GET",
            dataType: "json",
            cache: false,
            data: {
                runtime: config.runtime,
                readtoken: config.readtoken,
                cursor: cursor
            }
        }).done(function (response) {
            if (!response.ok) {
                schedulePoll(3000);
                return;
            }
            applyState(response.state);
            (response.events || []).forEach(applyEvent);
            cursor = response.cursor || cursor;
            schedulePoll((response.events || []).length ? 700 : 1500);
        }).fail(function (xhr) {
            if (xhr.status === 403) {
                showNotice(config.strings.errorsessionexpired);
                return;
            }
            schedulePoll(4000);
        });
    };

    var postAction = function (action, data, file) {
        var form = new FormData();
        form.append("action", action);
        form.append("runtime", config.runtime);
        form.append("csrf_token", config.csrfToken);
        form.append("sesskey", config.sesskey);
        Object.keys(data || {}).forEach(function (key) {
            var value = data[key];
            if (Array.isArray(value)) {
                value.forEach(function (item) {
                    form.append(key + "[]", item);
                });
            } else {
                form.append(key, value);
            }
        });
        if (file) {
            form.append("uploadkind", file.kind);
            form.append("upload", file.blob, file.filename);
        }
        return $.ajax({
            url: config.actionUrl,
            method: "POST",
            dataType: "json",
            data: form,
            processData: false,
            contentType: false
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            showNotice(response.error || config.strings.erroraction);
        });
    };

    var clearPendingUpload = function () {
        pendingUpload = null;
        root.find('[data-region="attachment-input"]').val("");
        root.find('[data-region="upload-preview"]').prop("hidden", true).empty();
    };

    var showPendingUpload = function (label, audioBlob) {
        var preview = root.find('[data-region="upload-preview"]').empty().prop("hidden", false);
        preview.append($("<span>").text(label + " "));
        if (audioBlob) {
            var audio = $("<audio>", {controls: true, class: "ajaxchat-audio"});
            audio.attr("src", URL.createObjectURL(audioBlob));
            preview.append(audio);
        }
        preview.append($("<button>", {
            type: "button",
            class: "btn btn-sm btn-link",
            text: "×",
            "data-action": "clear-upload"
        }));
    };

    var send = function () {
        if (!canInteract()) {
            updateComposer();
            return;
        }
        var input = root.find('[data-region="message-input"]');
        var text = $.trim(input.val());
        if (!text && !pendingUpload) {
            return;
        }
        var button = root.find('[data-action="send"]').prop("disabled", true);
        postAction("send", {message: text}, pendingUpload).done(function (response) {
            if (!response.ok) {
                showNotice(response.error || config.strings.errorsend);
                return;
            }
            input.val("").trigger("input");
            clearPendingUpload();
            if (response.message) {
                upsertMessage(response.message, false);
            }
        }).always(function () {
            button.prop("disabled", !canInteract());
        });
    };

    var editMessage = function (messageid) {
        var message = messages[messageid];
        if (!message || !messageCanEdit(message)) {
            return;
        }
        var value = window.prompt(config.strings.edit, message.message || "");
        if (value === null || $.trim(value) === "") {
            return;
        }
        postAction("edit", {messageid: messageid, message: value}).done(function (response) {
            if (response.ok && response.message) {
                upsertMessage(response.message, true);
            }
        });
    };

    var deleteMessage = function (messageid) {
        var message = messages[messageid];
        if (!message || !messageCanDelete(message) || !window.confirm(config.strings.delete + "?")) {
            return;
        }
        postAction("delete", {messageid: messageid}).done(function (response) {
            if (response.ok && response.message) {
                if (response.pollid && polls[response.pollid]) {
                    delete polls[response.pollid];
                }
                upsertMessage(response.message, true);
                renderPinnedPolls();
            }
        });
    };

    var react = function (messageid, reaction) {
        if (!canInteract()) {
            return;
        }
        postAction("reaction", {messageid: messageid, reaction: reaction}).done(function (response) {
            if (response.ok && response.message) {
                upsertMessage(response.message, true);
            }
        });
    };

    var toggleChat = function (button) {
        var enabled = button.attr("data-enabled") !== "1";
        button.prop("disabled", true);
        postAction("togglechat", {enabled: enabled ? 1 : 0}).done(function (response) {
            if (response.ok) {
                state.enabled = Boolean(response.enabled);
                updateComposer();
            }
        }).always(function () {
            button.prop("disabled", false);
        });
    };

    var toggleBlock = function (button) {
        var userid = parseInt(button.attr("data-userid"), 10);
        var blocked = button.attr("data-blocked") !== "1";
        button.prop("disabled", true);
        postAction("block", {userid: userid, blocked: blocked ? 1 : 0}).done(function (response) {
            if (response.ok) {
                setParticipantBlocked(userid, Boolean(response.blocked));
            }
        }).always(function () {
            button.prop("disabled", false);
        });
    };

    var vote = function (pollid, optionid) {
        if (!canInteract()) {
            return;
        }
        postAction("vote", {pollid: pollid, optionid: optionid}).done(function (response) {
            if (response.ok && response.poll) {
                polls[pollid] = response.poll;
                if (messages[response.poll.messageid]) {
                    messages[response.poll.messageid].poll = response.poll;
                    upsertMessage(messages[response.poll.messageid], true);
                }
                renderPinnedPolls();
            }
        });
    };

    var closePoll = function (pollid) {
        postAction("closepoll", {pollid: pollid}).done(function (response) {
            if (response.ok && response.poll) {
                polls[pollid] = response.poll;
                if (messages[response.poll.messageid]) {
                    messages[response.poll.messageid].poll = response.poll;
                    upsertMessage(messages[response.poll.messageid], true);
                }
                renderPinnedPolls();
            }
        });
    };

    var createPoll = function () {
        var dialog = root.find('[data-region="poll-dialog"]');
        var question = $.trim(dialog.find('[data-region="poll-question"]').val());
        var options = [];
        dialog.find("[data-poll-option]").each(function () {
            var value = $.trim($(this).val());
            if (value) {
                options.push(value);
            }
        });
        if (!question || options.length < 2) {
            return;
        }
        var position = dialog.find('[data-region="poll-position"]').val();
        postAction("createpoll", {question: question, options: options, position: position}).done(function (response) {
            if (response.ok && response.message) {
                upsertMessage(response.message, false);
                if (response.message.poll) {
                    polls[response.message.poll.id] = response.message.poll;
                    renderPinnedPolls();
                }
                dialog[0].close();
                dialog.find('[data-region="poll-question"]').val("");
                dialog.find("[data-poll-option]").val("");
            }
        });
    };

    var startOrStopRecording = function (button) {
        if (mediaRecorder && mediaRecorder.state === "recording") {
            mediaRecorder.stop();
            button.removeClass("is-recording").text("●");
            return;
        }
        if (!navigator.mediaDevices || !window.MediaRecorder) {
            showNotice(config.strings.audionotsupported);
            return;
        }

        navigator.mediaDevices.getUserMedia({audio: true}).then(function (stream) {
            mediaStream = stream;
            var candidates = ["audio/webm;codecs=opus", "audio/ogg;codecs=opus", "audio/mp4"];
            var mime = "";
            candidates.some(function (candidate) {
                if (MediaRecorder.isTypeSupported(candidate)) {
                    mime = candidate;
                    return true;
                }
                return false;
            });
            mediaChunks = [];
            mediaRecorder = mime ? new MediaRecorder(stream, {mimeType: mime}) : new MediaRecorder(stream);
            mediaRecorder.ondataavailable = function (event) {
                if (event.data && event.data.size > 0) {
                    mediaChunks.push(event.data);
                }
            };
            mediaRecorder.onstop = function () {
                var type = mediaRecorder.mimeType || "audio/webm";
                var blob = new Blob(mediaChunks, {type: type});
                var extension = type.indexOf("mp4") !== -1 ? ".m4a" : (type.indexOf("ogg") !== -1 ? ".ogg" : ".webm");
                pendingUpload = {
                    kind: "audio",
                    blob: blob,
                    filename: "audio-" + Date.now() + extension
                };
                showPendingUpload(config.strings.audioready, blob);
                if (mediaStream) {
                    mediaStream.getTracks().forEach(function (track) {
                        track.stop();
                    });
                }
                mediaStream = null;
                mediaRecorder = null;
                mediaChunks = [];
            };
            mediaRecorder.start(250);
            button.addClass("is-recording").text("■");
        }).catch(function () {
            showNotice(config.strings.microphoneerror);
        });
    };

    var bindEvents = function () {
        root.on("click", '[data-action="send"]', send);
        root.on("click", '[data-action="edit"]', function () {
            editMessage(parseInt($(this).attr("data-messageid"), 10));
        });
        root.on("click", '[data-action="delete"]', function () {
            deleteMessage(parseInt($(this).attr("data-messageid"), 10));
        });
        root.on("click", '[data-action="reaction"]', function () {
            react(parseInt($(this).attr("data-messageid"), 10), $(this).attr("data-reaction"));
        });
        root.on("click", '[data-action="toggle-chat"]', function () {
            toggleChat($(this));
        });
        root.on("click", '[data-action="toggle-block"]', function () {
            toggleBlock($(this));
        });
        root.on("click", '[data-action="vote"]', function () {
            vote(parseInt($(this).attr("data-pollid"), 10), parseInt($(this).attr("data-optionid"), 10));
        });
        root.on("click", '[data-action="close-poll"]', function () {
            closePoll(parseInt($(this).attr("data-pollid"), 10));
        });
        root.on("click", '[data-action="open-poll"]', function () {
            var dialog = root.find('[data-region="poll-dialog"]')[0];
            if (dialog.showModal) {
                dialog.showModal();
            } else {
                dialog.setAttribute("open", "open");
            }
        });
        root.on("click", '[data-action="close-poll-dialog"]', function () {
            var dialog = root.find('[data-region="poll-dialog"]')[0];
            if (dialog.close) {
                dialog.close();
            } else {
                dialog.removeAttribute("open");
            }
        });
        root.on("click", '[data-action="add-poll-option"]', function () {
            var container = root.find('[data-region="poll-options"]');
            if (container.find("[data-poll-option]").length < 10) {
                container.append($("<input>", {
                    type: "text",
                    maxlength: 250,
                    class: "form-control mb-2",
                    "data-poll-option": ""
                }));
            }
        });
        root.on("click", '[data-action="create-poll"]', createPoll);
        root.on("click", '[data-action="clear-upload"]', clearPendingUpload);
        root.on("click", '[data-action="record-audio"]', function () {
            startOrStopRecording($(this));
        });
        root.on("change", '[data-region="attachment-input"]', function () {
            if (!this.files || !this.files.length) {
                return;
            }
            var file = this.files[0];
            pendingUpload = {kind: "attachment", blob: file, filename: file.name};
            showPendingUpload(file.name, null);
        });
        root.on("keydown", '[data-region="message-input"]', function (event) {
            if (event.key === "Enter" && !event.shiftKey) {
                event.preventDefault();
                send();
            }
        });
        root.on("input", '[data-region="message-input"]', function () {
            this.style.height = "auto";
            this.style.height = Math.min(this.scrollHeight, 120) + "px";
        });
    };

    var init = function (options) {
        config = options;
        root = $('[data-region="ajaxchat-root"]').first();
        if (!root.length) {
            return;
        }
        state.allowaudio = Boolean(config.allowaudio);
        state.allowattachments = Boolean(config.allowattachments);
        bindEvents();
        updateComposer();
        loadHistory(false).always(function () {
            schedulePoll(900);
        });
    };

    return {init: init};
});
