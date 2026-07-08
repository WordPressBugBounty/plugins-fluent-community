/**
 * FluentCommunity — video-gated lesson completion (portal tracker).
 *
 * Listens for the FComMediaReady event the portal dispatches when a lesson
 * with FluentPlayer media mounts, tracks genuinely watched segments of the
 * lesson's feature video (seek-jumps don't count), and persists the
 * "watched" state to the server once the lesson's threshold is crossed so
 * the completion gate lets "Complete Lesson" succeed.
 */
(function () {
    'use strict';

    var VARS = window.fcomLessonVideoGate || {};
    var current = null; // active tracker for the mounted lesson

    function getRest() {
        // Prefer the SPA's live nonce — it gets refreshed via the
        // fcom_renew_rest_nonce flow while the localized snapshot goes stale
        var admin = window.fluentComAdmin;
        var live = (admin && admin.rest) || {};
        return {
            url: VARS.rest_url || live.url || '',
            nonce: live.nonce || VARS.nonce || ''
        };
    }

    function markWatched(courseId, lessonId, percent) {
        var rest = getRest();
        if (!rest.url || !rest.nonce) {
            return Promise.reject(new Error('Missing REST config'));
        }
        var url = rest.url.replace(/\/$/, '') + '/courses/' + courseId + '/lessons/' + lessonId + '/video-watched';
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json;charset=UTF-8',
                'X-WP-Nonce': rest.nonce
            },
            body: JSON.stringify({watched_percent: Math.round(percent)})
        }).then(function (res) {
            if (!res.ok) {
                return res.json().catch(function () {
                    return {};
                }).then(function (body) {
                    var error = new Error('Request failed: ' + res.status);
                    error.status = res.status;
                    error.code = body && body.code;
                    throw error;
                });
            }
            return res.json();
        });
    }

    function sprintfD(template, num) {
        return (template || '').replace(/%(?:\d+\$)?d/, String(num)).replace(/%%/g, '%');
    }

    function createNotice(playerEl, threshold) {
        var notice = document.createElement('div');
        notice.className = 'fcom_video_gate_notice';
        notice.setAttribute('role', 'status');
        notice.style.cssText = 'margin:10px 0;padding:10px 14px;border-radius:6px;font-size:14px;' +
            'background:rgba(230,162,60,.12);color:inherit;border:1px solid rgba(230,162,60,.4);';
        var i18n = VARS.i18n || {};
        notice.textContent = threshold >= 100
            ? (i18n.watch_to_end || 'Watch this video to the end to be able to complete this lesson.')
            : sprintfD(i18n.watch_notice || 'Watch at least %d%% of this video to be able to complete this lesson.', threshold);
        if (playerEl.parentNode) {
            playerEl.parentNode.insertBefore(notice, playerEl.nextSibling);
        }
        return notice;
    }

    function markNoticeDone(notice) {
        if (!notice) {
            return;
        }
        var i18n = VARS.i18n || {};
        notice.textContent = i18n.watched_done || 'Video watched — you can now complete this lesson.';
        notice.style.background = 'rgba(0,138,46,.12)';
        notice.style.borderColor = 'rgba(0,138,46,.4)';
    }

    function markNoticeFailed(notice) {
        if (!notice) {
            return;
        }
        var i18n = VARS.i18n || {};
        notice.textContent = i18n.watch_failed || 'Could not record your watch progress. Please reload the page and try again.';
        notice.style.background = 'rgba(245,108,108,.12)';
        notice.style.borderColor = 'rgba(245,108,108,.4)';
    }

    /**
     * Segment-based watch tracker on the <media-player> element.
     * Only continuous playback accumulates; seeking past content does not.
     */
    function createTracker(playerEl, opts) {
        var segments = [];
        var segStart = null;
        var lastTime = null;
        var seeking = false;
        var completed = false;
        var posting = false;
        var failed = false;
        var attempts = 0;
        var nextRetryAt = 0;
        var renewalRequested = false;
        var lastEvalSecond = -1;
        var fullyDispatched = false;
        var pendingAutoComplete = false;
        var listeners = [];

        function now() {
            return playerEl.currentTime || 0;
        }

        function duration() {
            return playerEl.duration || 0;
        }

        function closeSegment() {
            if (segStart === null || lastTime === null) {
                return;
            }
            if (lastTime - segStart > 0.1) {
                segments.push({start: segStart, end: lastTime});
                mergeSegments();
            }
            segStart = null;
        }

        function mergeSegments() {
            segments.sort(function (a, b) {
                return a.start - b.start;
            });
            var merged = [];
            segments.forEach(function (seg) {
                var last = merged[merged.length - 1];
                if (last && seg.start <= last.end + 0.5) {
                    last.end = Math.max(last.end, seg.end);
                } else {
                    merged.push({start: seg.start, end: seg.end});
                }
            });
            segments = merged;
        }

        function watchedPercent() {
            var total = duration();
            if (!total) {
                return 0;
            }
            // Include the still-running segment so the threshold is detected
            // as soon as it is crossed, not only after the segment is banked
            var all = segments.slice();
            if (segStart !== null && lastTime !== null && lastTime - segStart > 0.1) {
                all.push({start: segStart, end: lastTime});
            }
            all.sort(function (a, b) {
                return a.start - b.start;
            });
            var watched = 0;
            var cursor = -1;
            all.forEach(function (seg) {
                var start = Math.max(seg.start, cursor);
                if (seg.end > start) {
                    watched += seg.end - start;
                    cursor = seg.end;
                } else {
                    cursor = Math.max(cursor, seg.end);
                }
            });
            return Math.min(100, (watched / total) * 100);
        }

        function complete(percent) {
            if (completed || posting || failed) {
                return;
            }
            if (Date.now() < nextRetryAt) {
                return;
            }
            posting = true;
            markWatched(opts.courseId, opts.lessonId, percent)
                .then(function () {
                    completed = true;
                    markNoticeDone(opts.notice);
                    document.dispatchEvent(new CustomEvent('FComLessonVideoWatched', {
                        detail: {lessonId: opts.lessonId, courseId: opts.courseId}
                    }));
                    if (pendingAutoComplete) {
                        maybeAutoComplete();
                    }
                })
                .catch(function (error) {
                    var status = (error && error.status) || 0;

                    // Stale REST nonce: ask the SPA to renew it once, then retry
                    if (status === 403 && error.code === 'rest_cookie_invalid_nonce' && !renewalRequested) {
                        renewalRequested = true;
                        document.dispatchEvent(new CustomEvent('fcom_renew_rest_nonce'));
                        nextRetryAt = Date.now() + 3000;
                        return;
                    }

                    // Other 4xx (not enrolled, gate off, auth) will not heal — stop
                    if (status >= 400 && status < 500) {
                        failed = true;
                        markNoticeFailed(opts.notice);
                        return;
                    }

                    // Network/5xx: capped exponential backoff, then give up
                    attempts++;
                    if (attempts >= 5) {
                        failed = true;
                        markNoticeFailed(opts.notice);
                        return;
                    }
                    nextRetryAt = Date.now() + Math.min(60000, 2000 * Math.pow(2, attempts));
                })
                .finally(function () {
                    posting = false;
                });
        }

        function evaluate(isEnded) {
            if (!opts.gated || completed) {
                return;
            }
            if (isEnded) {
                complete(100);
                return;
            }
            var percent = watchedPercent();
            if (percent >= opts.threshold) {
                complete(percent);
            }
        }

        function maybeAutoComplete() {
            if (!opts.autoComplete || fullyDispatched) {
                return;
            }
            // "100%" means genuinely watched to the end — seeking to the end
            // fires ended with low coverage and must not auto-complete
            if (watchedPercent() < 99.5) {
                return;
            }
            // When gated, the watched record must land before the completion
            // request, or the gate would reject it
            if (opts.gated && !completed) {
                if (!failed) {
                    pendingAutoComplete = true;
                }
                return;
            }
            fullyDispatched = true;
            document.dispatchEvent(new CustomEvent('FComLessonVideoFullyWatched', {
                detail: {lessonId: opts.lessonId, courseId: opts.courseId}
            }));
        }

        function on(event, handler) {
            playerEl.addEventListener(event, handler);
            listeners.push([event, handler]);
        }

        on('play', function () {
            segStart = now();
            lastTime = segStart;
        });

        on('seeking', function () {
            closeSegment();
            seeking = true;
        });

        on('seeked', function () {
            seeking = false;
            segStart = now();
            lastTime = segStart;
        });

        on('pause', function () {
            closeSegment();
            evaluate(false);
        });

        on('time-update', function () {
            if (seeking) {
                return;
            }
            var t = now();
            if (segStart === null) {
                segStart = t;
                lastTime = t;
                return;
            }
            // A jump the player made without firing seeking (or a missed
            // event) must not count as watched time
            if (lastTime !== null && Math.abs(t - lastTime) > 2) {
                closeSegment();
                segStart = t;
            }
            lastTime = t;
            // Periodically bank the running segment so progress survives
            // without waiting for a pause
            if (t - segStart > 5) {
                closeSegment();
                segStart = t;
            }
            // time-update fires per animation frame — evaluating once per
            // playback second is plenty for threshold detection
            var second = Math.floor(t);
            if (second !== lastEvalSecond) {
                lastEvalSecond = second;
                evaluate(false);
            }
        });

        on('ended', function () {
            lastTime = now();
            closeSegment();
            evaluate(true);
            maybeAutoComplete();
        });

        return {
            teardown: function () {
                listeners.forEach(function (pair) {
                    playerEl.removeEventListener(pair[0], pair[1]);
                });
                listeners = [];
                if (opts.notice && opts.notice.parentNode) {
                    opts.notice.parentNode.removeChild(opts.notice);
                }
            }
        };
    }

    function findPlayer(cb) {
        var cancelled = false;
        var attempt = 0;
        var timer = null;

        function tick() {
            if (cancelled) {
                return;
            }
            var el = document.querySelector('.fcom_lesson_content media-player');
            if (el) {
                cb(el);
                return;
            }
            if (++attempt >= 40) {
                return; // ~10s, give up quietly
            }
            timer = setTimeout(tick, 250);
        }

        tick();

        return {
            cancel: function () {
                cancelled = true;
                clearTimeout(timer);
            }
        };
    }

    var lastDetail = null;

    function startGateSession(detail, ignoreWatched) {
        var lesson = detail.feed || {};
        var meta = lesson.meta || {};

        if (current) {
            current.teardown();
            current = null;
        }

        // Server-computed flags — single source of truth
        var autoComplete = !!meta.video_auto_complete;
        // The gate session is pointless once the watch is recorded, but a
        // fully-watched replay must still be able to auto-complete
        var gated = !!meta.is_video_gated && (!meta.video_watched || ignoreWatched);

        if ((!gated && !autoComplete) || !lesson.id || !lesson.course_id) {
            return;
        }

        var threshold = Math.min(100, Math.max(1, parseInt(meta.video_completion_threshold) || parseInt(VARS.default_threshold) || 80));

        var session = {
            forLesson: lesson.id,
            tracker: null,
            finder: null,
            teardown: function () {
                if (this.finder) {
                    this.finder.cancel();
                }
                if (this.tracker) {
                    this.tracker.teardown();
                }
            }
        };
        current = session;

        session.finder = findPlayer(function (playerEl) {
            if (current !== session) {
                return; // another lesson mounted while waiting
            }
            var notice = gated ? createNotice(playerEl, threshold) : null;
            session.tracker = createTracker(playerEl, {
                courseId: lesson.course_id,
                lessonId: lesson.id,
                threshold: threshold,
                gated: gated,
                autoComplete: autoComplete,
                notice: notice
            });
        });
    }

    document.addEventListener('FComMediaReady', function (event) {
        lastDetail = event.detail || {};
        startGateSession(lastDetail, false);
    });

    // The SPA dispatches this when a gated lesson is un-completed — the
    // watched record is wiped server side, so the video must be re-watched
    document.addEventListener('FComLessonVideoUnwatched', function (event) {
        var lessonId = event.detail && event.detail.lessonId;
        if (!lastDetail || !lastDetail.feed || lastDetail.feed.id != lessonId) {
            return;
        }
        startGateSession(lastDetail, true);
    });
})();
