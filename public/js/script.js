document.addEventListener('DOMContentLoaded', () => {
    // Add confirmation to specific forms
    const confirmForms = document.querySelectorAll('form[data-confirm]');
    confirmForms.forEach(form => {
        form.addEventListener('submit', (e) => {
            if (!confirm(form.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        });
    });

    // Initialize WYSIWYG Editor
    const initWysiwyg = () => {
        const textareas = document.querySelectorAll('.wysiwyg');

        textareas.forEach(textarea => {
            if (textarea.dataset.editorInitialized === "true") return;
            textarea.dataset.editorInitialized = "true";

            const wrapper = document.createElement('div');
            wrapper.className = 'wysiwyg-wrapper';
            textarea.parentNode.insertBefore(wrapper, textarea);

            const toolbar = document.createElement('div');
            toolbar.className = 'wysiwyg-toolbar';

            const buttons = [
                {command: 'formatBlock', value: 'H2', label: 'H2', icon: 'H2'},
                {command: 'formatBlock', value: 'H3', label: 'H3', icon: 'H3'},
                {command: 'bold', label: 'Fett', icon: '<strong>B</strong>'},
                {command: 'italic', label: 'Kursiv', icon: '<em>I</em>'},
                {command: 'insertUnorderedList', label: 'Liste (Punkte)', icon: '• Liste'},
                {command: 'insertOrderedList', label: 'Liste (Zahlen)', icon: '1. Liste'}
            ];

            buttons.forEach(btn => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'wysiwyg-btn';
                button.innerHTML = btn.icon;
                button.title = btn.label;

                button.addEventListener('click', (e) => {
                    e.preventDefault();
                    let value = btn.value || null;
                    document.execCommand(btn.command, false, value);
                    editor.focus();
                    updateTextarea();
                });
                toolbar.appendChild(button);
            });

            const editor = document.createElement('div');
            editor.className = 'wysiwyg-editor form-control';
            editor.contentEditable = true;
            editor.innerHTML = textarea.value;

            wrapper.appendChild(toolbar);
            wrapper.appendChild(editor);
            wrapper.appendChild(textarea);

            textarea.style.display = 'none';

            const updateTextarea = () => {
                textarea.value = editor.innerHTML;
            };

            editor.addEventListener('input', updateTextarea);
            editor.addEventListener('blur', updateTextarea);
            editor.addEventListener('keyup', updateTextarea);
        });
    };

    // (Re)start the rotation interval for the slider, continuing from whichever
    // slide is currently active so a background content refresh doesn't jump.
    const startSliderRotation = (viewport) => {
        if (window.tvSliderInterval) {
            clearInterval(window.tvSliderInterval);
        }

        const slides = Array.from(viewport.querySelectorAll('.tv-slide'));
        if (slides.length <= 1) return; // No need to rotate 0 or 1 item

        const durationMs = parseInt(viewport.getAttribute('data-duration'), 10) || 10000;

        let currentIndex = slides.findIndex(slide => slide.classList.contains('is-active'));
        if (currentIndex < 0) {
            currentIndex = 0;
            slides[0].classList.add('is-active');
        }

        window.tvSliderInterval = setInterval(() => {
            slides[currentIndex].classList.remove('is-active');
            currentIndex = (currentIndex + 1) % slides.length;
            slides[currentIndex].classList.add('is-active');
        }, durationMs);
    };

    // Initialize TV Slider logic
    const initTvSlider = () => {
        const viewport = document.getElementById('tv-slider');
        if (!viewport) return;

        startSliderRotation(viewport);
    };

    // Seamlessly swap the slide set. The slide currently on screen is preserved
    // (matched by its data-slide-key), so the viewer sees no flash when the
    // content set changes; added/removed items just affect the ongoing rotation.
    const applySlides = (viewport, html) => {
        const currentActive = viewport.querySelector('.tv-slide.is-active');
        const currentKey = currentActive ? currentActive.dataset.slideKey : null;

        const temp = document.createElement('div');
        temp.innerHTML = html;

        const newSlides = Array.from(temp.querySelectorAll('.tv-slide'));
        newSlides.forEach(slide => slide.classList.remove('is-active'));

        if (newSlides.length > 0) {
            let activeIndex = 0;
            if (currentKey) {
                const matchIndex = newSlides.findIndex(slide => slide.dataset.slideKey === currentKey);
                if (matchIndex !== -1) {
                    activeIndex = matchIndex;
                }
            }
            newSlides[activeIndex].classList.add('is-active');
        }

        viewport.innerHTML = temp.innerHTML;
        startSliderRotation(viewport);
    };

    // Poll the slider content endpoint and apply changes in the background.
    const initSliderAutoRefresh = () => {
        const viewport = document.getElementById('tv-slider');
        if (!viewport) return;

        const url = viewport.dataset.refreshUrl;
        if (!url) return;

        if (window.tvSliderRefreshTimer) {
            clearInterval(window.tvSliderRefreshTimer);
        }

        const intervalMs = parseInt(viewport.dataset.refreshInterval, 10) || 30000;
        let knownSignature = viewport.dataset.contentSignature || null;

        const poll = async () => {
            if (document.hidden) return; // skip while tab/screen is not visible

            try {
                const response = await fetch(url, {
                    headers: {'X-Requested-With': 'XMLHttpRequest'},
                    cache: 'no-store',
                });
                if (!response.ok) return;

                const data = await response.json();
                const newDuration = String(data.duration);
                const durationChanged = newDuration !== viewport.getAttribute('data-duration');

                if (data.signature === knownSignature) {
                    // Content unchanged; only restart rotation if the duration changed.
                    if (durationChanged) {
                        viewport.setAttribute('data-duration', newDuration);
                        startSliderRotation(viewport);
                    }
                    return;
                }

                knownSignature = data.signature;
                viewport.dataset.contentSignature = data.signature;
                viewport.setAttribute('data-duration', newDuration);
                applySlides(viewport, data.html);
            } catch (e) {
                // Ignore transient network errors; the next poll will retry.
            }
        };

        window.tvSliderRefreshTimer = setInterval(poll, intervalMs);
    };

    // Initialize bulk-delete selection
    const initBulkDelete = () => {
        const submitButton = document.querySelector('[data-bulk-submit]');
        if (!submitButton) return;

        const checkboxes = Array.from(document.querySelectorAll('.bulk-checkbox'));
        const selectAll = document.querySelector('[data-bulk-select-all]');

        const refresh = () => {
            const anyChecked = checkboxes.some(cb => cb.checked);
            submitButton.disabled = !anyChecked;

            if (selectAll) {
                const allChecked = checkboxes.length > 0 && checkboxes.every(cb => cb.checked);
                selectAll.checked = allChecked;
                selectAll.indeterminate = anyChecked && !allChecked;
            }
        };

        if (selectAll) {
            selectAll.addEventListener('change', () => {
                checkboxes.forEach(cb => {
                    cb.checked = selectAll.checked;
                });
                refresh();
            });
        }

        checkboxes.forEach(cb => cb.addEventListener('change', refresh));
        refresh();
    };

    // Initialize multi-image upload preview
    const initImagePreview = () => {
        const inputs = document.querySelectorAll('.image-multi-upload');

        inputs.forEach(input => {
            if (input.dataset.previewInitialized === "true") return;
            input.dataset.previewInitialized = "true";

            const preview = document.createElement('div');
            preview.className = 'image-preview-grid';
            input.parentNode.insertBefore(preview, input.nextSibling);

            input.addEventListener('change', () => {
                // Release any previously created object URLs to avoid leaks.
                preview.querySelectorAll('img').forEach(img => URL.revokeObjectURL(img.src));
                preview.innerHTML = '';

                Array.from(input.files).forEach(file => {
                    if (!file.type.startsWith('image/')) return;

                    const img = document.createElement('img');
                    img.className = 'thumbnail';
                    img.src = URL.createObjectURL(file);
                    preview.appendChild(img);
                });
            });
        });
    };

    const syncWysiwygFields = (form) => {
        form.querySelectorAll('.wysiwyg').forEach(textarea => {
            const editor = textarea.closest('.wysiwyg-wrapper')?.querySelector('.wysiwyg-editor');
            if (editor) {
                textarea.value = editor.innerHTML;
            }
        });
    };

    const formHasSelectedFiles = (form) => {
        return Array.from(form.querySelectorAll('input[type="file"]'))
            .some(input => input.files && input.files.length > 0);
    };

    const createUploadProgressUi = (form) => {
        const existing = form.querySelector('.upload-progress');
        if (existing) {
            return {
                root: existing,
                bar: existing.querySelector('.upload-progress-bar'),
                label: existing.querySelector('.upload-progress-label'),
                percent: existing.querySelector('.upload-progress-percent'),
            };
        }

        const root = document.createElement('div');
        root.className = 'upload-progress is-hidden';
        root.setAttribute('role', 'status');
        root.setAttribute('aria-live', 'polite');
        root.innerHTML = `
            <p class="upload-progress-label">Wird hochgeladen…</p>
            <div class="upload-progress-track">
                <div class="upload-progress-bar"></div>
            </div>
            <p class="upload-progress-percent">0%</p>
        `;

        const submitButton = form.querySelector('[type="submit"]');
        if (submitButton) {
            submitButton.insertAdjacentElement('afterend', root);
        } else {
            form.appendChild(root);
        }

        return {
            root,
            bar: root.querySelector('.upload-progress-bar'),
            label: root.querySelector('.upload-progress-label'),
            percent: root.querySelector('.upload-progress-percent'),
        };
    };

    const showUploadProgressError = (form, message) => {
        form.querySelector('.upload-progress-error')?.remove();

        const alert = document.createElement('div');
        alert.className = 'alert alert-danger upload-progress-error';
        alert.textContent = message;

        const progress = form.querySelector('.upload-progress');
        if (progress) {
            progress.insertAdjacentElement('afterend', alert);
        } else {
            form.appendChild(alert);
        }
    };

    const initUploadProgress = () => {
        document.querySelectorAll('form[data-upload-with-progress]').forEach(form => {
            if (form.dataset.uploadProgressInitialized === 'true') {
                return;
            }
            form.dataset.uploadProgressInitialized = 'true';

            const ui = createUploadProgressUi(form);

            const resetUi = () => {
                ui.root.classList.add('is-hidden');
                ui.bar.classList.remove('is-indeterminate');
                ui.bar.style.width = '0%';
                ui.percent.textContent = '0%';
                ui.label.textContent = 'Wird hochgeladen…';
                form.querySelector('.upload-progress-error')?.remove();
            };

            const showUi = () => {
                ui.root.classList.remove('is-hidden');
                form.querySelector('.upload-progress-error')?.remove();
            };

            form.addEventListener('submit', (event) => {
                if (!formHasSelectedFiles(form)) {
                    return;
                }

                event.preventDefault();

                syncWysiwygFields(form);

                const submitButton = form.querySelector('[type="submit"]');
                if (submitButton) {
                    submitButton.disabled = true;
                }

                showUi();
                ui.label.textContent = 'Dateien werden hochgeladen…';

                const formData = new FormData(form);
                const xhr = new XMLHttpRequest();
                xhr.open(form.method || 'POST', form.action);

                xhr.upload.addEventListener('progress', (e) => {
                    if (!e.lengthComputable) {
                        ui.bar.classList.add('is-indeterminate');
                        ui.percent.textContent = 'Upload läuft…';
                        return;
                    }

                    ui.bar.classList.remove('is-indeterminate');
                    const percent = Math.min(100, Math.round((e.loaded / e.total) * 100));
                    ui.bar.style.width = percent + '%';
                    ui.percent.textContent = percent + '%';

                    if (percent >= 100) {
                        ui.label.textContent = 'Wird auf dem Server verarbeitet…';
                    }
                });

                xhr.addEventListener('load', () => {
                    resetUi();
                    if (submitButton) {
                        submitButton.disabled = false;
                    }

                    if (xhr.status === 413) {
                        showUploadProgressError(
                            form,
                            'Der Upload war zu groß. Bitte kleinere Dateien wählen (max. 10 MB pro Bild).'
                        );
                        return;
                    }

                    if (xhr.status === 0) {
                        showUploadProgressError(
                            form,
                            'Verbindung unterbrochen. Bitte erneut versuchen.'
                        );
                        return;
                    }

                    if (xhr.status >= 500) {
                        showUploadProgressError(
                            form,
                            'Serverfehler beim Hochladen. Bitte später erneut versuchen.'
                        );
                        return;
                    }

                    const responsePath = new URL(xhr.responseURL).pathname;
                    const formPath = new URL(form.action, window.location.origin).pathname;

                    if (responsePath !== formPath) {
                        window.location.href = xhr.responseURL;
                        return;
                    }

                    // Validation errors or flash on the same page — replace document.
                    document.open();
                    document.write(xhr.responseText);
                    document.close();
                });

                xhr.addEventListener('error', () => {
                    resetUi();
                    if (submitButton) {
                        submitButton.disabled = false;
                    }
                    showUploadProgressError(
                        form,
                        'Upload fehlgeschlagen. Bitte prüfen Sie Ihre Verbindung und versuchen Sie es erneut.'
                    );
                });

                xhr.addEventListener('abort', () => {
                    resetUi();
                    if (submitButton) {
                        submitButton.disabled = false;
                    }
                });

                xhr.send(formData);
            });
        });
    };

    // Run initializations
    initWysiwyg();
    initTvSlider();
    initSliderAutoRefresh();
    initBulkDelete();
    initImagePreview();
    initUploadProgress();

    // Re-initialize on Turbo render if applicable
    document.addEventListener('turbo:render', () => {
        initWysiwyg();
        initTvSlider();
        initSliderAutoRefresh();
        initBulkDelete();
        initImagePreview();
        initUploadProgress();
    });
});
