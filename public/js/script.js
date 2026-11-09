document.addEventListener('DOMContentLoaded', () => {
    // Per-image limit stored on the server (TVs cannot handle larger files).
    // Images above this are NOT rejected: the server downscales them to fit.
    const MAX_IMAGE_BYTES = 3 * 1000 * 1000;

    // Hard cap for the original file; anything above this is rejected
    // (matches the server-side "25M" constraint, 10^6 bytes per "M").
    const MAX_ORIGINAL_BYTES = 25 * 1000 * 1000;

    const initThemeToggle = () => {
        const toggle = document.querySelector('[data-theme-toggle]');
        if (!toggle) return;

        toggle.addEventListener('click', () => {
            const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
            document.documentElement.dataset.theme = theme;
            try {
                localStorage.setItem('km-theme', theme);
            } catch {
                // Private mode: the choice then lasts for this page only.
            }
        });
    };

    // Reorder "Mein Slider": drag a row by its handle (mouse, touch, pen) or
    // move it with the arrow keys while the handle has focus.
    const initSortableList = () => {
        const list = document.querySelector('[data-sortable-list]');
        if (!list) return;

        const EDGE_SCROLL_ZONE = 72;
        const EDGE_SCROLL_MAX_STEP = 32;

        const rows = () => Array.from(list.children);
        const currentOrder = () => rows().map(row => row.dataset.itemId);
        const middleOf = (row) => {
            const box = row.getBoundingClientRect();
            return box.top + box.height / 2;
        };

        const renumber = () => {
            rows().forEach((row, index) => {
                row.querySelector('[data-slide-position]').textContent = index + 1;
            });
        };

        const saveOrder = async () => {
            const body = new FormData();
            body.append('_token', list.dataset.reorderToken);
            currentOrder().forEach(id => body.append('ids[]', id));

            try {
                const response = await fetch(list.dataset.reorderUrl, {method: 'POST', body});
                if (!response.ok) throw new Error(String(response.status));
            } catch {
                alert('Die Reihenfolge konnte nicht gespeichert werden. Die Seite wird neu geladen.');
                window.location.reload();
            }
        };

        // A long list scrolls inside its panel, a short one with the page.
        // The closer the pointer is to the edge, the faster it scrolls.
        const scrollNearEdge = (pointerY) => {
            const listScrolls = list.scrollHeight > list.clientHeight;
            const box = list.getBoundingClientRect();
            const top = listScrolls ? Math.max(box.top, 0) : 0;
            const bottom = listScrolls ? Math.min(box.bottom, window.innerHeight) : window.innerHeight;
            const overTop = top + EDGE_SCROLL_ZONE - pointerY;
            const overBottom = pointerY - (bottom - EDGE_SCROLL_ZONE);
            const depth = Math.min(1, Math.max(overTop, overBottom) / EDGE_SCROLL_ZONE);
            if (depth <= 0) return;

            const distance = Math.ceil(depth * EDGE_SCROLL_MAX_STEP) * (overTop > overBottom ? -1 : 1);
            if (listScrolls) {
                list.scrollTop += distance;
            } else {
                window.scrollBy(0, distance);
            }
        };

        // The dragged row itself never leaves the DOM (its neighbours are moved
        // around it), otherwise the browser would end the drag.
        const moveTowards = (row, pointerY) => {
            const previous = row.previousElementSibling;
            const next = row.nextElementSibling;

            if (previous && pointerY < middleOf(previous)) {
                list.insertBefore(previous, row.nextElementSibling);
            } else if (next && pointerY > middleOf(next)) {
                list.insertBefore(next, row);
            }
        };

        list.addEventListener('pointerdown', (event) => {
            const handle = event.target.closest('[data-drag-handle]');
            if (!handle || event.button !== 0) return;

            const row = handle.closest('[data-item-id]');
            const orderBefore = currentOrder().join();
            let pointerY = event.clientY;
            let frame = null;

            const step = () => {
                scrollNearEdge(pointerY);
                moveTowards(row, pointerY);
                frame = requestAnimationFrame(step);
            };

            const onMove = (moveEvent) => {
                pointerY = moveEvent.clientY;
            };

            const onEnd = () => {
                cancelAnimationFrame(frame);
                document.removeEventListener('pointermove', onMove);
                document.removeEventListener('pointerup', onEnd);
                document.removeEventListener('pointercancel', onEnd);
                row.classList.remove('is-dragging');
                list.classList.remove('is-sorting');
                renumber();

                if (currentOrder().join() !== orderBefore) {
                    saveOrder();
                }
            };

            event.preventDefault();
            row.classList.add('is-dragging');
            list.classList.add('is-sorting');
            document.addEventListener('pointermove', onMove);
            document.addEventListener('pointerup', onEnd);
            document.addEventListener('pointercancel', onEnd);
            frame = requestAnimationFrame(step);
        });

        list.addEventListener('keydown', (event) => {
            const handle = event.target.closest('[data-drag-handle]');
            if (!handle || !['ArrowUp', 'ArrowDown'].includes(event.key)) return;

            const row = handle.closest('[data-item-id]');
            const neighbour = event.key === 'ArrowUp' ? row.previousElementSibling : row.nextElementSibling;
            if (!neighbour) return;

            event.preventDefault();
            list.insertBefore(row, event.key === 'ArrowUp' ? neighbour : neighbour.nextElementSibling);
            handle.focus();
            row.scrollIntoView({block: 'nearest'});
            renumber();
            saveOrder();
        });
    };

    // Search field above a long list: hides the rows that do not match.
    const initListFilters = () => {
        document.querySelectorAll('[data-list-filter]').forEach(input => {
            const rows = Array.from(document.querySelectorAll(`${input.dataset.listFilter} > [data-filter-text]`));
            const count = input.parentNode.querySelector('[data-list-filter-count]');

            const apply = () => {
                const query = input.value.trim().toLowerCase();
                let visible = 0;
                rows.forEach(row => {
                    const matches = row.dataset.filterText.toLowerCase().includes(query);
                    row.hidden = !matches;
                    if (matches) visible++;
                });
                count.textContent = query ? `${visible} von ${rows.length}` : `${rows.length}`;
            };

            input.addEventListener('input', apply);
            apply();
        });
    };

    // Lists that scroll inside their panel get a fade at the bottom while
    // there is more than fits (see .is-scrollable).
    const initScrollCues = () => {
        const lists = document.querySelectorAll('.slide-list, .content-grid, .user-list');
        const mark = () => lists.forEach(list => {
            list.classList.toggle('is-scrollable', list.scrollHeight > list.clientHeight + 1);
        });

        mark();
        window.addEventListener('resize', mark);
        document.querySelectorAll('[data-list-filter]').forEach(input => input.addEventListener('input', mark));
    };

    // The question sits on the form or, when a form has several actions, on
    // the button that was pressed.
    const initConfirmForms = () => {
        document.querySelectorAll('form[data-confirm], form:has(button[data-confirm])').forEach(form => {
            form.addEventListener('submit', (e) => {
                const question = (e.submitter && e.submitter.dataset.confirm) || form.dataset.confirm;
                if (question && !confirm(question)) {
                    e.preventDefault();
                }
            });
        });
    };

    // Initialize WYSIWYG Editor
    const initWysiwyg = () => {
        const textareas = document.querySelectorAll('.wysiwyg');

        textareas.forEach(textarea => {
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

    // Bulk actions (delete contents, reset PINs, delete Kitas): the entries'
    // checkboxes belong to the bulk form through their "form" attribute; the
    // form's buttons wake up once something is ticked. Entries hidden by a
    // list filter are never part of the selection.
    const initBulkActions = () => {
        document.querySelectorAll('.bulk-bar form').forEach(form => {
            const actions = Array.from(form.querySelectorAll('[data-bulk-submit]'));
            const checkboxes = Array.from(document.querySelectorAll(`.bulk-checkbox[form="${form.id}"]`));
            const selectAll = form.closest('.bulk-bar').querySelector('[data-bulk-select-all]');
            const visibleCheckboxes = () => checkboxes.filter(cb => !cb.closest('[hidden]'));

            const refresh = () => {
                const visible = visibleCheckboxes();
                const anyChecked = visible.some(cb => cb.checked);
                const allChecked = visible.length > 0 && visible.every(cb => cb.checked);
                actions.forEach(action => {
                    action.disabled = !anyChecked;
                });
                selectAll.checked = allChecked;
                selectAll.indeterminate = anyChecked && !allChecked;
            };

            selectAll.addEventListener('change', () => {
                visibleCheckboxes().forEach(cb => {
                    cb.checked = selectAll.checked;
                });
                refresh();
            });
            checkboxes.forEach(cb => cb.addEventListener('change', refresh));
            document.querySelectorAll('[data-list-filter]').forEach(input => input.addEventListener('input', () => {
                checkboxes.filter(cb => cb.closest('[hidden]')).forEach(cb => {
                    cb.checked = false;
                });
                refresh();
            }));
            refresh();
        });
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
                const form = input.closest('form') || document;
                const target = form.querySelector(list);
                if (!target) return;

                // Hide the whole field (label, help text, list), not just the list.
                const field = target.closest('.form-row') || target;
                const apply = () => {
                    field.style.display = input.checked ? 'none' : '';
                };

                input.addEventListener('change', apply);
                apply();
            });
        });
    };

    // Choice picker for long Kita/audience lists: search, counts, bulk select.
    const initChoiceFilter = () => {
        document.querySelectorAll('.choice-list').forEach(list => {
            const items = Array.from(list.querySelectorAll('.form-check'));

            if (items.length < 6) return;

            const shell = document.createElement('div');
            shell.className = 'choice-picker';
            list.parentNode.insertBefore(shell, list);

            const toolbar = document.createElement('div');
            toolbar.className = 'choice-picker-toolbar';

            const filter = document.createElement('input');
            filter.type = 'search';
            filter.className = 'choice-filter';
            filter.placeholder = 'Kita suchen…';
            filter.setAttribute('aria-label', 'Kita suchen');
            filter.autocomplete = 'off';

            const meta = document.createElement('p');
            meta.className = 'choice-picker-meta';

            const actions = document.createElement('div');
            actions.className = 'choice-picker-actions';

            const selectVisible = document.createElement('button');
            selectVisible.type = 'button';
            selectVisible.className = 'btn-link';
            selectVisible.textContent = 'Sichtbare auswählen';

            const clearSelection = document.createElement('button');
            clearSelection.type = 'button';
            clearSelection.className = 'btn-link';
            clearSelection.textContent = 'Auswahl leeren';

            actions.append(selectVisible, clearSelection);
            toolbar.append(filter, actions, meta);
            shell.append(toolbar, list);

            const visibleItems = () => items.filter(item => item.style.display !== 'none');

            const refreshMeta = () => {
                const visible = visibleItems();
                const selected = items.filter(item => {
                    const input = item.querySelector('input[type="checkbox"], input[type="radio"]');
                    return input && input.checked;
                }).length;
                const query = filter.value.trim();
                meta.textContent = query
                    ? `${visible.length} von ${items.length} angezeigt · ${selected} ausgewählt`
                    : `${items.length} Kitas · ${selected} ausgewählt`;
            };

            filter.addEventListener('input', () => {
                const query = filter.value.trim().toLowerCase();
                items.forEach(item => {
                    const matches = !query || item.textContent.trim().toLowerCase().includes(query);
                    item.style.display = matches ? '' : 'none';
                });
                refreshMeta();
            });

            selectVisible.addEventListener('click', () => {
                visibleItems().forEach(item => {
                    const input = item.querySelector('input[type="checkbox"]');
                    if (input && !input.disabled) {
                        input.checked = true;
                        input.dispatchEvent(new Event('change', {bubbles: true}));
                    }
                });
                refreshMeta();
            });

            clearSelection.addEventListener('click', () => {
                items.forEach(item => {
                    const input = item.querySelector('input[type="checkbox"]');
                    if (input && !input.disabled) {
                        input.checked = false;
                        input.dispatchEvent(new Event('change', {bubbles: true}));
                    }
                });
                refreshMeta();
            });

            list.addEventListener('change', refreshMeta);
            refreshMeta();
        });
    };

    // Inline management actions (slide switch, settings) submit a real form and reload
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
            form.addEventListener('submit', () => {
                const row = form.closest('[id]');
                if (row && row.id) {
                    sessionStorage.setItem('km-scroll-target', row.id);
                }
            });
        });
    };

    // Initialize multi-image upload preview. Every selected image gets a
    // removable tile; images above the stored limit get a neutral hint that
    // they will be downscaled on the server, originals above the hard cap
    // are flagged as rejected.
    const initImagePreview = () => {
        const inputs = document.querySelectorAll('.image-multi-upload');

        inputs.forEach(input => {
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

                // Every file gets a tile, also one that is no image: the upload
                // loop reports its result on the tile with the same index.
                Array.from(input.files).forEach((file, index) => {
                    const isImage = file.type.startsWith('image/');
                    const tile = document.createElement('div');
                    tile.className = 'image-preview-tile';

                    // Image + overlay badge share one box so the badge never
                    // sits on top of the status line below the thumbnail.
                    const media = document.createElement('div');
                    media.className = 'image-preview-media';

                    if (isImage) {
                        const img = document.createElement('img');
                        img.className = 'thumbnail';
                        img.loading = 'lazy';
                        img.decoding = 'async';
                        img.src = URL.createObjectURL(file);
                        media.appendChild(img);
                    }

                    const removeButton = document.createElement('button');
                    removeButton.type = 'button';
                    removeButton.className = 'image-preview-remove';
                    removeButton.innerHTML = '&times;';
                    removeButton.title = `${file.name} entfernen`;
                    removeButton.setAttribute('aria-label', `${file.name} entfernen`);
                    removeButton.addEventListener('click', () => removeFileAt(index));
                    media.appendChild(removeButton);

                    if (!isImage) {
                        tile.classList.add('is-rejected');
                        tile.title = `${file.name} ist kein Bild`;

                        const badge = document.createElement('span');
                        badge.className = 'image-preview-badge is-error';
                        badge.textContent = 'kein Bild';
                        media.appendChild(badge);
                    } else if (file.size > MAX_ORIGINAL_BYTES) {
                        tile.classList.add('is-rejected');
                        tile.title = `${file.name} ist größer als 25 MB und kann nicht hochgeladen werden`;

                        const badge = document.createElement('span');
                        badge.className = 'image-preview-badge is-error';
                        badge.textContent = 'zu groß (max. 25 MB)';
                        media.appendChild(badge);
                    } else if (file.size > MAX_IMAGE_BYTES) {
                        tile.title = `${file.name} wird beim Hochladen automatisch verkleinert`;

                        const badge = document.createElement('span');
                        badge.className = 'image-preview-badge';
                        badge.textContent = 'wird verkleinert';
                        media.appendChild(badge);
                    }

                    tile.appendChild(media);
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

    // Per-tile status during the sequential bulk upload.
    // Success is visual only (green border) — no "Fertig" label that would
    // sit under / over the "wird verkleinert" badge. Errors keep a text line.
    const setTileStatus = (tile, text, state) => {
        if (!tile) return;

        tile.classList.remove('is-uploading', 'is-done', 'is-failed');
        if (state) {
            tile.classList.add(state);
        }

        let status = tile.querySelector('.image-preview-status');

        // Done: drop any status text — the green frame is enough.
        if (state === 'is-done' || !text) {
            status?.remove();
            return;
        }

        if (!status) {
            status = document.createElement('span');
            status.className = 'image-preview-status';
            tile.appendChild(status);
        }
        status.textContent = text;
    };

    // Audience selection of the bulk form, read from the (unmapped) form
    // fields so the AJAX endpoint can apply the same visibility rules.
    const collectAudience = (form) => {
        const allInput = form.querySelector('[data-audience-all]');
        const ids = Array.from(form.querySelectorAll('[data-audience-list] input[type="checkbox"]:checked'))
            .map(cb => cb.value);

        return {all: !!(allInput && allInput.checked), ids};
    };

    // Upload ONE image to the JSON endpoint. Always resolves (never rejects)
    // with {ok, downscaled} or {ok: false, error}.
    const uploadSingleImage = (endpoint, token, file, audience, onProgress) => new Promise(resolve => {
        const formData = new FormData();
        formData.append('_token', token);
        formData.append('image', file);
        if (audience.all) {
            formData.append('audienceAll', '1');
        }
        audience.ids.forEach(id => formData.append('audience[]', id));

        const xhr = new XMLHttpRequest();
        xhr.open('POST', endpoint);
        xhr.responseType = 'json';

        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                onProgress(e.loaded / e.total);
            }
        });

        xhr.addEventListener('load', () => {
            const data = xhr.response || {};
            if (xhr.status === 200 && data.ok) {
                resolve({ok: true, downscaled: !!data.downscaled});
            } else if (xhr.status === 413) {
                resolve({ok: false, error: 'Das Bild ist zu groß (max. 25 MB).'});
            } else {
                resolve({ok: false, error: data.error || 'Unerwarteter Fehler beim Hochladen.'});
            }
        });

        xhr.addEventListener('error', () => {
            resolve({ok: false, error: 'Verbindung unterbrochen. Bitte erneut versuchen.'});
        });

        xhr.send(formData);
    });

    // Append ?uploaded=N while preserving an optional #fragment on the success URL.
    const buildUploadSuccessUrl = (successUrl, uploadedCount) => {
        const hashIndex = successUrl.indexOf('#');
        const hash = hashIndex >= 0 ? successUrl.slice(hashIndex) : '';
        const base = hashIndex >= 0 ? successUrl.slice(0, hashIndex) : successUrl;
        const separator = base.includes('?') ? '&' : '?';

        return base + separator + 'uploaded=' + uploadedCount + hash;
    };

    // Bulk upload: send the images ONE BY ONE to the JSON endpoint so the
    // server only ever processes a single image per request (OOM protection)
    // and each image can be downscaled with visible per-image feedback.
    const runSequentialUpload = async (form, input, ui) => {
        const endpoint = form.dataset.uploadEndpoint;
        const token = form.dataset.uploadToken;
        const successUrl = form.dataset.uploadSuccessUrl;

        const files = Array.from(input.files);
        const audience = collectAudience(form);
        const grid = form.querySelector('.image-preview-grid');
        const tiles = grid ? Array.from(grid.querySelectorAll('.image-preview-tile')) : [];
        const submitButton = form.querySelector('[type="submit"]');

        // Lock the selection while uploading.
        if (submitButton) submitButton.disabled = true;
        input.disabled = true;
        tiles.forEach(tile => {
            tile.querySelector('.image-preview-remove')?.remove();
            setTileStatus(tile, 'Wartet…');
        });

        form.querySelector('.upload-progress-error')?.remove();
        ui.root.classList.remove('is-hidden');
        ui.bar.classList.remove('is-indeterminate');
        ui.bar.style.width = '0%';
        ui.percent.textContent = '0%';

        const failures = [];
        let uploadedCount = 0;

        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            const tile = tiles[i];
            ui.label.textContent = `Bild ${i + 1} von ${files.length}: ${file.name}`;

            if (file.size > MAX_ORIGINAL_BYTES) {
                failures.push({file, error: 'Das Bild ist zu groß (max. 25 MB).'});
                setTileStatus(tile, 'Zu groß (max. 25 MB)', 'is-failed');
                continue;
            }

            const willDownscale = file.size > MAX_IMAGE_BYTES;
            setTileStatus(tile, 'Wird hochgeladen… 0%', 'is-uploading');

            const result = await uploadSingleImage(endpoint, token, file, audience, (fraction) => {
                const percent = Math.min(100, Math.round(fraction * 100));
                setTileStatus(
                    tile,
                    percent >= 100
                        ? (willDownscale ? 'Wird verkleinert…' : 'Wird gespeichert…')
                        : `Wird hochgeladen… ${percent}%`,
                    'is-uploading'
                );

                const overall = Math.min(100, Math.round(((i + fraction) / files.length) * 100));
                ui.bar.style.width = overall + '%';
                ui.percent.textContent = overall + '%';
            });

            if (result.ok) {
                uploadedCount++;
                tile.title = result.downscaled
                    ? `${file.name}: hochgeladen und verkleinert`
                    : `${file.name}: hochgeladen`;
                setTileStatus(tile, '', 'is-done');
            } else {
                failures.push({file, error: result.error});
                setTileStatus(tile, result.error, 'is-failed');
                tile.title = `${file.name}: ${result.error}`;
            }
        }

        ui.bar.style.width = '100%';
        ui.percent.textContent = '100%';

        if (failures.length === 0) {
            ui.label.textContent = 'Fertig – Sie werden weitergeleitet…';
            window.location.href = buildUploadSuccessUrl(successUrl, uploadedCount);
            return;
        }

        // Keep only the failed files selected so a resubmit retries just them.
        const transfer = new DataTransfer();
        failures.forEach(({file}) => transfer.items.add(file));
        input.disabled = false;
        input.files = transfer.files;
        input.dispatchEvent(new Event('change'));

        // renderPreview rebuilt the tiles for the remaining (failed) files;
        // re-attach the error message to each fresh tile.
        const retryTiles = grid ? Array.from(grid.querySelectorAll('.image-preview-tile')) : [];
        failures.forEach(({file, error}, index) => {
            setTileStatus(retryTiles[index], error, 'is-failed');
            if (retryTiles[index]) {
                retryTiles[index].title = `${file.name}: ${error}`;
            }
        });

        ui.root.classList.add('is-hidden');
        if (submitButton) submitButton.disabled = false;

        const summary = uploadedCount > 0
            ? `${uploadedCount} Bild(er) hochgeladen, ${failures.length} fehlgeschlagen. Die fehlgeschlagenen Bilder bleiben ausgewählt – klicken Sie erneut auf „Speichern“, um es noch einmal zu versuchen.`
            : `${failures.length} Bild(er) konnten nicht hochgeladen werden. Sie bleiben ausgewählt – klicken Sie erneut auf „Speichern“, um es noch einmal zu versuchen.`;
        showUploadProgressError(form, summary);
    };

    const initUploadProgress = () => {
        document.querySelectorAll('form[data-upload-with-progress]').forEach(form => {
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

                // Bulk image form: hand over to the sequential per-image
                // AJAX loop (one request per image, server-side downscaling).
                const multiInput = form.querySelector('input[type="file"].image-multi-upload');
                if (multiInput && form.dataset.uploadEndpoint) {
                    runSequentialUpload(form, multiInput, ui);
                    return;
                }

                // Originals above the hard cap cannot be stored at all; the
                // server would reject them, so fail fast before uploading.
                const tooBig = Array.from(form.querySelectorAll('input[type="file"]'))
                    .some(input => Array.from(input.files || []).some(file => file.size > MAX_ORIGINAL_BYTES));
                if (tooBig) {
                    showUploadProgressError(
                        form,
                        'Das ausgewählte Bild ist größer als 25 MB und kann nicht hochgeladen werden. Bitte verkleinern Sie es.'
                    );
                    return;
                }

                // Whether the selected image exceeds the stored limit and will
                // therefore be downscaled on the server after the upload.
                const willDownscale = Array.from(form.querySelectorAll('input[type="file"]'))
                    .some(input => Array.from(input.files || []).some(file => file.size > MAX_IMAGE_BYTES));

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
                        ui.label.textContent = willDownscale
                            ? 'Wird verkleinert…'
                            : 'Wird auf dem Server verarbeitet…';
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
                            'Der Upload war zu groß. Bitte ein kleineres Bild wählen (max. 25 MB).'
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
    initThemeToggle();
    initSortableList();
    initListFilters();
    initScrollCues();
    initConfirmForms();
    initWysiwyg();
    initBulkActions();
    initImagePreview();
    initUploadProgress();
    initChoiceFilter();
    initAllToggles();
    initScrollPreservation();
});
