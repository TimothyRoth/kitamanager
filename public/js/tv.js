/*
 * TV-only script for the slider and PIN pages (/slider/...).
 *
 * IMPORTANT: This file must stay strictly ES5-compatible. It runs on embedded
 * TV browsers (e.g. Philips B-Link SmartInfo mode) whose engines choke on
 * modern syntax - a single unsupported token (arrow function, optional
 * chaining, template literal, async/await, ...) aborts parsing of the WHOLE
 * file and the slideshow stops working. No fetch, no Promise, no classList.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function toArray(nodeList) {
        var result = [];
        for (var i = 0; i < nodeList.length; i++) {
            result.push(nodeList[i]);
        }
        return result;
    }

    function hasClass(el, name) {
        return (' ' + el.className + ' ').indexOf(' ' + name + ' ') !== -1;
    }

    function addClass(el, name) {
        if (!hasClass(el, name)) {
            el.className = el.className === '' ? name : el.className + ' ' + name;
        }
    }

    function removeClass(el, name) {
        var parts = el.className.split(/\s+/);
        var kept = [];
        for (var i = 0; i < parts.length; i++) {
            if (parts[i] !== '' && parts[i] !== name) {
                kept.push(parts[i]);
            }
        }
        el.className = kept.join(' ');
    }

    var rotationTimer = null;

    // (Re)start the rotation interval, continuing from whichever slide is
    // currently active so a background content refresh doesn't jump.
    function startRotation(viewport) {
        if (rotationTimer) {
            clearInterval(rotationTimer);
            rotationTimer = null;
        }

        var slides = toArray(viewport.querySelectorAll('.tv-slide'));
        if (slides.length <= 1) {
            return; // No need to rotate 0 or 1 item
        }

        var duration = parseInt(viewport.getAttribute('data-duration'), 10) || 10000;

        var currentIndex = -1;
        for (var i = 0; i < slides.length; i++) {
            if (hasClass(slides[i], 'is-active')) {
                currentIndex = i;
                break;
            }
        }
        if (currentIndex < 0) {
            currentIndex = 0;
            addClass(slides[0], 'is-active');
        }

        rotationTimer = setInterval(function () {
            removeClass(slides[currentIndex], 'is-active');
            currentIndex = (currentIndex + 1) % slides.length;
            addClass(slides[currentIndex], 'is-active');
        }, duration);
    }

    // Seamlessly swap the slide set. The slide currently on screen is kept
    // (matched by data-slide-key) so the viewer sees no flash.
    function applySlides(viewport, html) {
        var active = viewport.querySelector('.tv-slide.is-active');
        var currentKey = active ? active.getAttribute('data-slide-key') : null;

        var temp = document.createElement('div');
        temp.innerHTML = html;

        var newSlides = toArray(temp.querySelectorAll('.tv-slide'));
        for (var i = 0; i < newSlides.length; i++) {
            removeClass(newSlides[i], 'is-active');
        }

        if (newSlides.length > 0) {
            var activeIndex = 0;
            if (currentKey) {
                for (var j = 0; j < newSlides.length; j++) {
                    if (newSlides[j].getAttribute('data-slide-key') === currentKey) {
                        activeIndex = j;
                        break;
                    }
                }
            }
            addClass(newSlides[activeIndex], 'is-active');
        }

        viewport.innerHTML = temp.innerHTML;
        startRotation(viewport);
    }

    // Poll the content endpoint via plain XHR and apply changes in the
    // background. A cache-busting parameter is appended because embedded TV
    // browsers tend to cache aggressively.
    function initAutoRefresh(viewport) {
        var url = viewport.getAttribute('data-refresh-url');
        if (!url) {
            return;
        }

        var intervalMs = parseInt(viewport.getAttribute('data-refresh-interval'), 10) || 30000;
        var knownSignature = viewport.getAttribute('data-content-signature') || null;

        function poll() {
            try {
                var xhr = new XMLHttpRequest();
                var separator = url.indexOf('?') === -1 ? '?' : '&';
                xhr.open('GET', url + separator + '_=' + new Date().getTime(), true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.onreadystatechange = function () {
                    if (xhr.readyState !== 4 || xhr.status !== 200) {
                        return;
                    }

                    var data;
                    try {
                        data = JSON.parse(xhr.responseText);
                    } catch (e) {
                        return;
                    }

                    // The device's PIN no longer belongs to this slider
                    // (admin changed or removed it): go back to the PIN page.
                    if (data.unlinked) {
                        window.location.href = viewport.getAttribute('data-display-url') || '/slider/display';
                        return;
                    }

                    var newDuration = String(data.duration);
                    var durationChanged = newDuration !== viewport.getAttribute('data-duration');

                    if (data.signature === knownSignature) {
                        // Content unchanged; only restart rotation if the duration changed.
                        if (durationChanged) {
                            viewport.setAttribute('data-duration', newDuration);
                            startRotation(viewport);
                        }
                        return;
                    }

                    knownSignature = data.signature;
                    viewport.setAttribute('data-content-signature', data.signature);
                    viewport.setAttribute('data-duration', newDuration);
                    applySlides(viewport, data.html);
                };
                xhr.send(null);
            } catch (e) {
                // Ignore transient network errors; the next poll will retry.
            }
        }

        setInterval(poll, intervalMs);

        // Refresh immediately when the screen wakes from standby so the
        // display doesn't show stale content until the next interval tick.
        if (typeof document.hidden !== 'undefined') {
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) {
                    poll();
                }
            });
        }
    }

    // PIN entry page: submit as soon as the 4th digit is entered, so no
    // separate confirm button is needed on the device.
    function initPinAutoSubmit() {
        var inputs = document.querySelectorAll('input[data-pin-autosubmit]');
        for (var i = 0; i < inputs.length; i++) {
            (function (input) {
                var submitted = false;

                function maybeSubmit() {
                    if (submitted) {
                        return;
                    }
                    var value = input.value.replace(/^\s+|\s+$/g, '');
                    if (!/^\d{4}$/.test(value)) {
                        return;
                    }
                    if (input.form) {
                        submitted = true;
                        input.form.submit();
                    }
                }

                // 'keyup' as fallback for engines with unreliable 'input' events.
                input.addEventListener('input', maybeSubmit);
                input.addEventListener('keyup', maybeSubmit);
            })(inputs[i]);
        }
    }

    ready(function () {
        var viewport = document.getElementById('tv-slider');
        if (viewport) {
            startRotation(viewport);
            initAutoRefresh(viewport);
        }

        initPinAutoSubmit();
    });
})();
