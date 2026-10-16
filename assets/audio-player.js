/**
 * "Listen to this article" player.
 *
 * Lives in its own file rather than inline in the article: WordPress
 * rewrites some characters in article HTML (&& became &#038;&#038;), which
 * silently broke the inline version so the player never started.
 *
 * Each player's markup comes from fc_build_audio_player(); the element ids
 * it needs are on the player itself as data attributes.
 */
( function () {
    function initPlayer( root ) {
        try {
            var ICON_PLAY    = '<svg viewBox="0 0 24 24"><path fill="#0f172a" d="M8 5v14l11-7z"/></svg>';
            var ICON_PAUSE   = '<svg viewBox="0 0 24 24"><rect fill="#0f172a" x="6" y="5" width="4" height="14" rx="1"/><rect fill="#0f172a" x="14" y="5" width="4" height="14" rx="1"/></svg>';

            if (!root) return;

            var audioUrl   = root.getAttribute('data-audio-url') || '';
            var speechLang = root.getAttribute('data-lang') || 'en-US';
            var langShort  = root.getAttribute('data-lang-short') || 'en';
            var bodyEl     = document.getElementById(root.getAttribute('data-body-id') || '');

            // TTS reads ONLY the article body paragraphs. The fact card and
            // the player are placed outside this container, and anything the
            // editor wrote above the [fact_card] marker — a byline line —
            // stays outside it too, so none of that is ever read aloud.
            //
            // The card/player checks below are belt and braces: they are not
            // inside the container today, but if a future layout change ever
            // puts them there, the narrator still won't read the claim, the
            // fact, or the words "Listen to this article".
            var paras = bodyEl ? Array.prototype.slice.call(bodyEl.querySelectorAll('p')).filter(function(p) {
                if (p.textContent.replace(/\s+/g, '').length <= 8) return false;
                if (p.closest && (p.closest('.fc-hero') || p.closest('.fc-cc-wrap') || p.closest('.fc-a-wrap'))) return false;
                return true;
            }) : [];

            var playBtn  = root.querySelector('.fc-a-play');
            var speedBtn = root.querySelector('.fc-a-speed');
            var fill     = root.querySelector('.fc-a-fill');
            var timeText = root.querySelector('.fc-a-time-text');
            var track    = root.querySelector('.fc-a-track');

            var mini      = document.getElementById(root.id + '-mini');
            var miniBtn   = mini ? mini.querySelector('.fc-a-mini-play') : null;

            playBtn.innerHTML = ICON_PLAY;
            if (miniBtn) miniBtn.innerHTML = ICON_PLAY;

            var speeds = [1, 1.25, 1.5, 1.75, 0.75];
            var speedIndex = 0;
            var isPlaying = false;
            var muted = false;
            var mediaEl = null;
            var chosenVoice = null;
            var currentParaIndex = -1;
            var currentChunkIndex = -1;

            function fmt(sec) {
                if (!isFinite(sec) || sec < 0) sec = 0;
                var m = Math.floor(sec / 60), s = Math.floor(sec % 60);
                return m + ':' + (s < 10 ? '0' : '') + s;
            }
            function setPlayingUI(playing) {
                isPlaying = playing;
                playBtn.innerHTML = playing ? ICON_PAUSE : ICON_PLAY;
                if (miniBtn) miniBtn.innerHTML = playing ? ICON_PAUSE : ICON_PLAY;
            }
            function showMini() { if (mini) mini.classList.add('fc-a-visible'); }
            function hideMini() { if (mini) mini.classList.remove('fc-a-visible'); }
            function clearHighlight() { paras.forEach(function(p) { p.classList.remove('fc-reading'); }); }
            function highlightPara(i) {
                clearHighlight();
                if (paras[i]) {
                    paras[i].classList.add('fc-reading');
                    paras[i].scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                currentParaIndex = i;
                // Progress reflects chunks read (finer + smoother than
                // paragraph count), shown as a simple percentage bar.
                if (chunkList.length) {
                    var pct = Math.min(100, ((currentChunkIndex + 1) / chunkList.length) * 100);
                    fill.style.width = pct + '%';
                    timeText.textContent = Math.round(pct) + '%';
                }
            }

            // Pick a browser voice that matches the site language, so Hindi
            // and Marathi articles are read by a Hindi/Marathi voice when the
            // visitor's device has one installed.
            //
            // Critical: the browser only speaks a language if the device has
            // a voice for it. English is everywhere; most Indian-language
            // voices are not installed by default. When there is no matching
            // voice AND no premium MP3, we hide the whole player rather than
            // show a control that plays nothing (or mangles the text with an
            // English voice). English is allowed to fall back to any English
            // voice, which every device has.
            function findVoice() {
                if (!('speechSynthesis' in window)) return null;
                var voices = window.speechSynthesis.getVoices() || [];
                if (!voices.length) return null;
                return (
                    voices.find(function(v) { return v.lang && v.lang.toLowerCase() === speechLang.toLowerCase(); }) ||
                    voices.find(function(v) { return v.lang && v.lang.toLowerCase().indexOf(langShort) === 0; }) ||
                    null
                );
            }

            function hidePlayerWrap() {
                var wrap = root.closest('.fc-a-wrap');
                if (wrap) wrap.style.display = 'none';
            }

            // Decides whether the free browser player is usable. Runs once
            // voices are known. Premium MP3 mode always keeps the player.
            var voiceResolved = false;
            function resolveVoiceAndMaybeHide() {
                if (audioUrl) { voiceResolved = true; return; } // MP3: always keep
                if (voiceResolved) return;
                var voices = window.speechSynthesis ? (window.speechSynthesis.getVoices() || []) : [];
                if (!voices.length) return; // not loaded yet — wait for voiceschanged
                voiceResolved = true;
                chosenVoice = findVoice();
                if (!chosenVoice) {
                    // No voice for this language on this device — hide entirely.
                    hidePlayerWrap();
                }
            }

            if ('speechSynthesis' in window) {
                resolveVoiceAndMaybeHide();
                window.speechSynthesis.addEventListener('voiceschanged', resolveVoiceAndMaybeHide);
                // Safari sometimes never fires voiceschanged; re-check shortly after load.
                setTimeout(resolveVoiceAndMaybeHide, 800);
            } else if (!audioUrl) {
                // Browser has no speech engine at all and there's no MP3 — hide.
                hidePlayerWrap();
            }

            // ── Premium MP3 ──
            function playPremium() {
                if (!mediaEl) {
                    mediaEl = new Audio(audioUrl);
                    mediaEl.addEventListener('timeupdate', function() {
                        var pct = mediaEl.duration ? (mediaEl.currentTime / mediaEl.duration) * 100 : 0;
                        fill.style.width = pct + '%';
                        timeText.textContent = fmt(mediaEl.currentTime);
                    });
                    mediaEl.addEventListener('ended', function() { setPlayingUI(false); hideMini(); });
                }
                mediaEl.playbackRate = speeds[speedIndex];
                mediaEl.muted = muted;
                var p = mediaEl.play();
                if (p && p.catch) p.catch(function() {});
                setPlayingUI(true);
            }
            function pausePremium() { if (mediaEl) mediaEl.pause(); setPlayingUI(false); }

            // ── Browser voice, article body only ──
            //
            // Chrome cuts off any single utterance longer than ~200-250
            // characters. Article paragraphs — especially in Indian
            // languages — routinely exceed that, which is why long
            // paragraphs were going silent mid-way. We split each paragraph
            // into chunks under the limit (on sentence boundaries where
            // possible) and queue them in order. Each chunk remembers which
            // paragraph it came from so highlighting still works per-para.
            var MAX_CHUNK = 180;

            function splitIntoChunks(text) {
                text = text.trim();
                if (!text) return [];
                // Split on sentence enders shared across our languages,
                // including the Devanagari danda (। / ॥) used by Hindi/Marathi.
                //
                // Written WITHOUT a regex lookbehind on purpose. Safari below
                // 16.4 (iOS 15 and older, still common on our readers' phones)
                // treats a lookbehind as a syntax error at parse time, which
                // kills this entire script before the try/catch can catch it —
                // taking the whole player down with it. Marking the break with
                // a null character and splitting on that does the same job and
                // parses everywhere.
                var sentences = text.replace(/([.!?।॥])\s+/g, '$1\u0000').split('\u0000');
                var chunks = [];
                sentences.forEach(function(sentence) {
                    if (sentence.length <= MAX_CHUNK) {
                        if (sentence.trim()) chunks.push(sentence.trim());
                        return;
                    }
                    // Still too long (no sentence breaks) — split on spaces.
                    var words = sentence.split(/\s+/);
                    var buffer = '';
                    words.forEach(function(word) {
                        if ((buffer + ' ' + word).trim().length > MAX_CHUNK) {
                            if (buffer.trim()) chunks.push(buffer.trim());
                            buffer = word;
                        } else {
                            buffer = buffer ? buffer + ' ' + word : word;
                        }
                    });
                    if (buffer.trim()) chunks.push(buffer.trim());
                });
                return chunks;
            }

            // Pre-build the full chunk list once, each tagged with its
            // paragraph index so we can highlight the right paragraph.
            var chunkList = [];
            paras.forEach(function(p, paraIdx) {
                splitIntoChunks(p.textContent).forEach(function(chunk) {
                    chunkList.push({ text: chunk, paraIdx: paraIdx });
                });
            });

            // Maps a paragraph index to the first chunk index in that para,
            // so "resume from current paragraph" lands on a clean boundary.
            function firstChunkOfPara(paraIdx) {
                for (var i = 0; i < chunkList.length; i++) {
                    if (chunkList[i].paraIdx >= paraIdx) return i;
                }
                return 0;
            }

            function buildQueue(fromChunk) {
                if (!('speechSynthesis' in window)) return;
                window.speechSynthesis.cancel();
                var list = [];
                for (var i = Math.max(fromChunk, 0); i < chunkList.length; i++) {
                    (function(idx) {
                        var item = chunkList[idx];
                        var u = new SpeechSynthesisUtterance(item.text);
                        u.rate = speeds[speedIndex];
                        u.lang = speechLang;
                        if (chosenVoice) u.voice = chosenVoice;
                        u.onstart = function() {
                            currentChunkIndex = idx;
                            highlightPara(item.paraIdx);
                        };
                        list.push(u);
                    })(i);
                }
                if (list.length) {
                    list[list.length - 1].onend = function() {
                        setPlayingUI(false); clearHighlight(); hideMini();
                        currentChunkIndex = -1;
                    };
                }
                list.forEach(function(u) { window.speechSynthesis.speak(u); });
            }
            function playBasic(fromChunk) { buildQueue(fromChunk === undefined ? 0 : fromChunk); setPlayingUI(true); }
            function pauseBasic() { window.speechSynthesis.pause(); setPlayingUI(false); }
            function resumeBasic() { window.speechSynthesis.resume(); setPlayingUI(true); }

            function toggle() {
                if (audioUrl) {
                    isPlaying ? pausePremium() : playPremium();
                    return;
                }
                if (!('speechSynthesis' in window) || !chunkList.length) return;
                if (isPlaying) {
                    pauseBasic();
                } else if (window.speechSynthesis.paused) {
                    resumeBasic();
                } else {
                    playBasic(Math.max(currentChunkIndex, 0));
                }
            }

            playBtn.addEventListener('click', toggle);
            if (miniBtn) miniBtn.addEventListener('click', toggle);

            speedBtn.addEventListener('click', function() {
                speedIndex = (speedIndex + 1) % speeds.length;
                speedBtn.textContent = speeds[speedIndex] + 'x';
                if (audioUrl && mediaEl) {
                    mediaEl.playbackRate = speeds[speedIndex];
                } else if (!audioUrl && isPlaying) {
                    playBasic(Math.max(currentChunkIndex, 0)); // browser speech restarts to apply rate
                }
            });

            // Seek. Premium MP3 seeks to an exact time. Browser voice can't
            // seek to a time, but it CAN restart narration from a chosen
            // chunk, so a click on the bar jumps to the nearest chunk. (True
            // drag-scrubbing isn't possible with the browser speech engine —
            // this click-to-jump is the closest reliable behaviour.)
            if (track) {
                track.addEventListener('click', function(e) {
                    var rect = this.getBoundingClientRect();
                    var pct = Math.min(1, Math.max(0, (e.clientX - rect.left) / rect.width));
                    if (audioUrl && mediaEl && mediaEl.duration) {
                        mediaEl.currentTime = pct * mediaEl.duration;
                    } else if (!audioUrl && chunkList.length) {
                        var targetChunk = Math.floor(pct * chunkList.length);
                        targetChunk = Math.min(chunkList.length - 1, Math.max(0, targetChunk));
                        currentChunkIndex = targetChunk;
                        fill.style.width = (pct * 100) + '%';
                        if (isPlaying || window.speechSynthesis.paused) {
                            playBasic(targetChunk); // jump and keep reading
                        }
                    }
                });
            }

            // Floating mini player when the main bar scrolls out of view mid-play
            if (mini) {
                var observer = new IntersectionObserver(function(entries) {
                    var visible = entries[0].isIntersecting;
                    if (!visible && isPlaying) showMini(); else hideMini();
                }, { threshold: 0 });
                observer.observe(root);
            }

            // Stop speech if the visitor navigates away, so it doesn't keep talking
            window.addEventListener('beforeunload', function() {
                if ('speechSynthesis' in window) window.speechSynthesis.cancel();
            });

        } catch (err) {
            if (window.console) console.error('FC audio player failed to initialise:', err);
        }
        }

    function start() {
        var players = document.querySelectorAll( '.fc-a-pill[data-fc-player]' );
        for ( var i = 0; i < players.length; i++ ) initPlayer( players[ i ] );
    }

    if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', start );
    else start();
} )();
