/**
 * Annie's Elegant AI Scribe: browser recorder and review flow.
 * Record (MediaRecorder) -> upload -> transcribe (poll) -> generate draft ->
 * clinician edits -> Save (normal form POST). Nothing is charted before Save.
 */
(function () {
    'use strict';
    var box = document.getElementById('eas-recorder');
    if (!box) {
        return;
    }
    var endpoint = box.dataset.endpoint;
    var csrf = box.dataset.csrf;
    var maxMs = (parseInt(box.dataset.maxMinutes, 10) || 30) * 60000;
    var $ = function (id) { return document.getElementById(id); };
    var startBtn = $('eas-start'), pauseBtn = $('eas-pause'), stopBtn = $('eas-stop');
    var timerEl = $('eas-timer'), dot = $('eas-dot'), statusEl = $('eas-status');
    var transcriptEl = $('eas-transcript'), generateBtn = $('eas-generate'), sessionEl = $('eas-session');
    var recorder = null, chunks = [], stream = null, elapsed = 0, tickStart = 0, ticker = null;

    function status(msg, isError) {
        statusEl.textContent = msg || '';
        statusEl.classList.toggle('error', !!isError);
    }
    function fmt(ms) {
        var s = Math.floor(ms / 1000);
        return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
    }
    function tick() {
        var ms = elapsed + (tickStart ? Date.now() - tickStart : 0);
        timerEl.textContent = fmt(ms);
        if (ms >= maxMs && recorder && recorder.state !== 'inactive') {
            stop();
        }
    }
    function api(action, opts) {
        opts = opts || {};
        var url = endpoint + '?action=' + encodeURIComponent(action) + (sessionEl.value ? '&session=' + encodeURIComponent(sessionEl.value) : '');
        var headers = { 'X-CSRF-Token': csrf, 'Accept': 'application/json' };
        var body = opts.body;
        if (opts.json) {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(opts.json);
        }
        return fetch(url, { method: opts.method || 'POST', credentials: 'same-origin', headers: headers, body: body })
            .then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (data) {
                    if (!r.ok) {
                        throw new Error(data.message || data.error || ('HTTP ' + r.status));
                    }
                    return data;
                });
            });
    }
    function ensureSession() {
        if (sessionEl.value) {
            return Promise.resolve();
        }
        return api('start').then(function (d) { sessionEl.value = d.session; });
    }
    function pickMime() {
        var types = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
        for (var i = 0; i < types.length; i++) {
            if (window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(types[i])) {
                return types[i];
            }
        }
        return '';
    }

    function start() {
        if (!navigator.mediaDevices || !window.MediaRecorder) {
            status('Recording is not supported in this browser (use Chrome, Edge or Safari over HTTPS).', true);
            return;
        }
        navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } }).then(function (s) {
            stream = s;
            var mime = pickMime();
            recorder = new MediaRecorder(s, mime ? { mimeType: mime, audioBitsPerSecond: 32000 } : undefined);
            chunks = [];
            recorder.ondataavailable = function (e) { if (e.data && e.data.size) { chunks.push(e.data); } };
            recorder.onstop = upload;
            recorder.start(1000);
            elapsed = 0;
            tickStart = Date.now();
            ticker = setInterval(tick, 250);
            dot.hidden = false;
            dot.dataset.paused = '0';
            startBtn.disabled = true;
            pauseBtn.disabled = false;
            stopBtn.disabled = false;
            pauseBtn.textContent = 'Pause';
            status('Recording...');
        }).catch(function () {
            status('Microphone permission was denied or no microphone was found.', true);
        });
    }
    function pause() {
        if (!recorder) {
            return;
        }
        if (recorder.state === 'recording') {
            recorder.pause();
            elapsed += Date.now() - tickStart;
            tickStart = 0;
            pauseBtn.textContent = 'Resume';
            dot.dataset.paused = '1';
            status('Paused.');
        } else if (recorder.state === 'paused') {
            recorder.resume();
            tickStart = Date.now();
            pauseBtn.textContent = 'Pause';
            dot.dataset.paused = '0';
            status('Recording...');
        }
    }
    function stop() {
        if (!recorder || recorder.state === 'inactive') {
            return;
        }
        if (tickStart) {
            elapsed += Date.now() - tickStart;
            tickStart = 0;
        }
        clearInterval(ticker);
        tick();
        recorder.stop();
        stream.getTracks().forEach(function (t) { t.stop(); });
        dot.hidden = true;
        pauseBtn.disabled = true;
        stopBtn.disabled = true;
    }
    function upload() {
        var blob = new Blob(chunks, { type: recorder.mimeType || 'audio/webm' });
        chunks = [];
        status('Uploading audio (' + Math.round(blob.size / 1024) + ' KB)...');
        ensureSession().then(function () {
            var fd = new FormData();
            var ext = /mp4/.test(blob.type) ? 'mp4' : (/ogg/.test(blob.type) ? 'ogg' : 'webm');
            fd.append('audio', blob, 'visit.' + ext);
            fd.append('seconds', String(Math.round(elapsed / 1000)));
            return api('upload', { body: fd });
        }).then(transcribe).catch(fail);
    }
    function transcribe() {
        status('Transcribing...');
        return ensureSession().then(function () { return api('transcribe'); }).then(function (d) {
            if (d.status === 'done') {
                return gotTranscript(d.transcript);
            }
            return poll(0);
        });
    }
    function poll(n) {
        return new Promise(function (resolve) { setTimeout(resolve, n < 10 ? 3000 : 6000); })
            .then(function () { return api('status', { method: 'GET' }); })
            .then(function (d) {
                if (d.status === 'done') {
                    return gotTranscript(d.transcript);
                }
                if (d.status === 'failed') {
                    throw new Error(d.error || 'Transcription failed');
                }
                status('Transcribing... (' + (n + 1) + ')');
                if (n > 150) {
                    throw new Error('Transcription is taking too long; try again later.');
                }
                return poll(n + 1);
            });
    }
    function gotTranscript(text) {
        transcriptEl.value = text || '';
        generateBtn.disabled = !transcriptEl.value.trim();
        startBtn.disabled = false;
        status('Transcript ready. Review it, then click "Generate draft note".');
    }
    function generate() {
        var text = transcriptEl.value.trim();
        if (!text) {
            return;
        }
        generateBtn.disabled = true;
        status('Generating draft note...');
        ensureSession().then(function () { return api('generate', { json: { transcript: text, template: templateEl.value } }); }).then(function (d) {
            var keys = Object.keys(d.note || {});
            var filled = keys.some(function (k) { var el = $('eas-' + k); return el && el.value.trim(); });
            if (filled && !window.confirm('Replace the current note text with the new draft?')) {
                generateBtn.disabled = false;
                status('Draft discarded.');
                return;
            }
            keys.forEach(function (k) {
                var el = $('eas-' + k);
                if (el) { el.value = d.note[k] || ''; }
            });
            generateBtn.disabled = false;
            var u = d.usage ? ', ' + d.usage.input_tokens + '+' + d.usage.output_tokens + ' tokens' : '';
            status('Draft ready (' + (d.providers || '') + ', ' + ((d.ms || 0) / 1000).toFixed(1) + ' s' + u + '). Review and edit, then click Save.');
        }).catch(fail);
    }
    function fail(err) {
        startBtn.disabled = false;
        generateBtn.disabled = !transcriptEl.value.trim();
        status((err && err.message) || 'Something went wrong.', true);
    }

    var templateEl = $('eas-template');
    function showTemplate() {
        document.querySelectorAll('.eas-section').forEach(function (div) {
            div.hidden = (' ' + div.dataset.templates + ' ').indexOf(' ' + templateEl.value + ' ') < 0;
        });
    }
    templateEl.addEventListener('change', showTemplate);
    startBtn.addEventListener('click', start);
    pauseBtn.addEventListener('click', pause);
    stopBtn.addEventListener('click', stop);
    generateBtn.addEventListener('click', generate);
    transcriptEl.addEventListener('input', function () { generateBtn.disabled = !transcriptEl.value.trim(); });
    var mockBtn = $('eas-mock');
    if (mockBtn) {
        mockBtn.addEventListener('click', function () { transcribe().catch(fail); });
    }
    window.addEventListener('beforeunload', function (e) {
        if (recorder && recorder.state !== 'inactive') {
            e.preventDefault();
            e.returnValue = '';
        }
    });
})();
