document.addEventListener('DOMContentLoaded', () => {
    // Per-image upload limit (TVs cannot handle larger files). Matches the
    // server-side "3M" constraint (Symfony uses 10^6 bytes per "M"). The
    // total size of a bulk upload is deliberately not limited.
    const MAX_IMAGE_BYTES = 3 * 1000 * 1000;

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

    // NOTE: The TV slider and PIN entry logic lives in tv.js (strictly ES5 for
    // embedded TV browsers); the /slider/... pages load tv.js instead of this file.

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

    // Hide the per-user selection list while an "all users" checkbox is active,
    // both for the admin's user-assignment form and the content audience form.
    const initAllToggles = () => {
        const pairs = [
            {checkbox: 'input[type="checkbox"][name$="[publishToAll]"]', list: '[data-publish-targets]'},
            {checkbox: '[data-audience-all]', list: '[data-audience-list]'},
        ];

        pairs.forEach(({checkbox, list}) => {
            document.querySelectorAll(checkbox).forEach(input => {
                if (input.dataset.allToggleInitialized === 'true') return;
                input.dataset.allToggleInitialized = 'true';

                const form = input.closest('form') || document;
                const target = form.querySelector(list);
                if (!target) return;

                const apply = () => {
                    const display = input.checked ? 'none' : '';
                    target.style.display = display;
                    // Also hide a filter input rendered right before the list.
                    const sibling = target.previousElementSibling;
                    if (sibling && sibling.classList.contains('choice-filter')) {
                        sibling.style.display = display;
                    }
                };

                input.addEventListener('change', apply);
                apply();
            });
        });
    };

    // Add a quick client-side filter above long choice lists (e.g. assigning
    // a user to hundreds of others) so they stay usable at scale.
    const initChoiceFilter = () => {
        document.querySelectorAll('.choice-list').forEach(list => {
            if (list.dataset.filterInitialized === 'true') return;

            const items = Array.from(list.querySelectorAll('.form-check'));
            if (items.length < 8) return;

            list.dataset.filterInitialized = 'true';

            const filter = document.createElement('input');
            filter.type = 'search';
            filter.className = 'choice-filter';
            filter.placeholder = 'Liste filtern…';
            filter.setAttribute('aria-label', 'Liste filtern');
            list.parentNode.insertBefore(filter, list);

            filter.addEventListener('input', () => {
                const query = filter.value.trim().toLowerCase();
                items.forEach(item => {
                    const matches = item.textContent.trim().toLowerCase().includes(query);
                    item.style.display = matches ? '' : 'none';
                });
            });
        });
    };

    // Inline management actions (reorder / toggle) submit a real form and reload
    // the page, which otherwise jumps back to the top. Remember the affected row
    // and bring it back into view after the reload so the list "stays put".
    const initScrollPreservation = () => {
        const targetId = sessionStorage.getItem('km-scroll-target');
        if (targetId) {
            sessionStorage.removeItem('km-scroll-target');
            const restore = () => {
                const el = document.getElementById(targetId);
                if (el) {
                    el.scrollIntoView({block: 'center', behavior: 'instant'});
                }
            };
            // Run after layout, and again after images finish loading (they can
            // shift the layout and move the anchor).
            requestAnimationFrame(restore);
            window.addEventListener('load', restore, {once: true});
        }

        document.querySelectorAll('form[data-preserve-scroll]').forEach(form => {
            if (form.dataset.scrollInit === 'true') return;
            form.dataset.scrollInit = 'true';

            form.addEventListener('submit', () => {
                const row = form.closest('[id]');
                if (row && row.id) {
                    sessionStorage.setItem('km-scroll-target', row.id);
                }
            });
        });
    };

    // Initialize multi-image upload preview. Every selected image gets a
    // removable tile; oversized images are flagged and will be skipped on
    // upload (they never block the rest of the batch).
    const initImagePreview = () => {
        const inputs = document.querySelectorAll('.image-multi-upload');

        inputs.forEach(input => {
            if (input.dataset.previewInitialized === "true") return;
            input.dataset.previewInitialized = "true";

            const preview = document.createElement('div');
            preview.className = 'image-preview-grid';
            input.parentNode.insertBefore(preview, input.nextSibling);

            // File inputs are read-only per file, so removing a single file
            // means rebuilding the FileList without it.
            const removeFileAt = (index) => {
                const transfer = new DataTransfer();
                Array.from(input.files).forEach((file, i) => {
                    if (i !== index) transfer.items.add(file);
                });
                input.files = transfer.files;
                renderPreview();
            };

            const renderPreview = () => {
                // Release any previously created object URLs to avoid leaks.
                preview.querySelectorAll('img').forEach(img => URL.revokeObjectURL(img.src));
                preview.innerHTML = '';

                Array.from(input.files).forEach((file, index) => {
                    if (!file.type.startsWith('image/')) return;

                    const tile = document.createElement('div');
                    tile.className = 'image-preview-tile';

                    const img = document.createElement('img');
                    img.className = 'thumbnail';
                    img.src = URL.createObjectURL(file);
                    tile.appendChild(img);

                    const removeButton = document.createElement('button');
                    removeButton.type = 'button';
                    removeButton.className = 'image-preview-remove';
                    removeButton.innerHTML = '&times;';
                    removeButton.title = `${file.name} entfernen`;
                    removeButton.setAttribute('aria-label', `${file.name} entfernen`);
                    removeButton.addEventListener('click', () => removeFileAt(index));
                    tile.appendChild(removeButton);

                    if (file.size > MAX_IMAGE_BYTES) {
                        tile.classList.add('is-oversized');
                        tile.title = `${file.name} ist größer als 3 MB und wird nicht hochgeladen`;

                        const badge = document.createElement('span');
                        badge.className = 'image-preview-badge';
                        badge.textContent = 'zu groß';
                        tile.appendChild(badge);
                    }

                    preview.appendChild(tile);
                });
            };

            input.addEventListener('change', renderPreview);
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

    // Non-blocking notice (e.g. "oversized images were skipped") that stays
    // visible while the remaining images upload.
    const showUploadSkipNotice = (form, message) => {
        form.querySelector('.upload-skip-notice')?.remove();

        const alert = document.createElement('div');
        alert.className = 'alert alert-warning upload-skip-notice';
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

                form.querySelector('.upload-skip-notice')?.remove();

                // Oversized images never block the batch: they are removed
                // from multi-upload inputs and the remaining images are
                // uploaded. Single-file inputs (article/edit forms) cannot be
                // partially submitted, so there an oversized image stops the
                // submit with an error instead. The inputs are only mutated
                // once it is certain the upload proceeds, so a fully oversized
                // selection keeps its preview and can still be corrected.
                const fileInputs = Array.from(form.querySelectorAll('input[type="file"]'));
                const skippedNames = [];
                const filterPlans = [];
                let oversizedSingleFile = false;
                let remainingCount = 0;

                fileInputs.forEach(input => {
                    const files = Array.from(input.files || []);
                    const oversized = files.filter(file => file.size > MAX_IMAGE_BYTES);

                    if (oversized.length > 0 && !input.multiple) {
                        oversizedSingleFile = true;
                        return;
                    }

                    const keep = files.filter(file => file.size <= MAX_IMAGE_BYTES);
                    remainingCount += keep.length;

                    if (oversized.length > 0) {
                        filterPlans.push({input, keep});
                        skippedNames.push(...oversized.map(file => file.name));
                    }
                });

                if (oversizedSingleFile) {
                    showUploadProgressError(
                        form,
                        'Das ausgewählte Bild ist größer als 3 MB und kann nicht hochgeladen werden. Bitte verkleinern Sie es.'
                    );
                    return;
                }

                if (remainingCount === 0) {
                    showUploadProgressError(
                        form,
                        'Alle ausgewählten Bilder sind größer als 3 MB und können daher nicht hochgeladen werden. Bitte verkleinern Sie die Bilder (max. 3 MB pro Bild).'
                    );
                    return;
                }

                filterPlans.forEach(({input, keep}) => {
                    const transfer = new DataTransfer();
                    keep.forEach(file => transfer.items.add(file));
                    input.files = transfer.files;
                    // Keep the preview tiles in sync with the filtered selection.
                    input.dispatchEvent(new Event('change'));
                });

                if (skippedNames.length > 0) {
                    showUploadSkipNotice(
                        form,
                        `${skippedNames.length} Bild(er) über 3 MB werden übersprungen: ${skippedNames.join(', ')}. Die übrigen Bilder werden hochgeladen.`
                    );
                }

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
                            'Der Upload war zu groß. Bitte kleinere Dateien wählen (max. 3 MB pro Bild).'
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
    initBulkDelete();
    initImagePreview();
    initUploadProgress();
    initChoiceFilter();
    initAllToggles();
    initScrollPreservation();

    // Re-initialize on Turbo render if applicable
    document.addEventListener('turbo:render', () => {
        initWysiwyg();
        initBulkDelete();
        initImagePreview();
        initUploadProgress();
        initChoiceFilter();
        initAllToggles();
        initScrollPreservation();
    });
});
