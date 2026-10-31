/**
 * The "Listen to this article" player (markup from fc_build_audio_player()).
 * A separate file, not inline: WordPress rewrites "&&" in article HTML.
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

            // Only the article's own paragraphs are read: never the card, the player or a byline.
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

            // A voice in the site's language. Most phones have no Indian-language voice: with
            // none and no recording, the player is hidden rather than play nothing.
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

            // ── Browser voice ──
            // Chrome stops speaking after ~200 characters, so paragraphs are read in sentence
            // chunks; each chunk knows its paragraph for the highlight.
            var MAX_CHUNK = 180;

            function splitIntoChunks(text) {
                text = text.trim();
                if (!text) return [];
                // Sentence ends, including the danda (। ॥). No regex lookbehind: older iPhones
                // (Safari < 16.4) can't parse it and the whole player would fail.
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

            // A click on the bar: a recording jumps to that time, the browser voice to the
            // nearest chunk.
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
