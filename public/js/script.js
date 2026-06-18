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

    // Initialize TV Slider logic
    const initTvSlider = () => {
        const sliderContainer = document.getElementById('tv-slider');
        if (!sliderContainer) return;

        // Prevent multiple intervals if Turbo re-renders
        if (window.tvSliderInterval) {
            clearInterval(window.tvSliderInterval);
        }

        const slides = sliderContainer.querySelectorAll('.tv-slide');
        if (slides.length <= 1) return; // No need to slide if 0 or 1 item

        const durationMs = parseInt(sliderContainer.getAttribute('data-duration'), 10) || 10000;
        let currentIndex = 0;

        window.tvSliderInterval = setInterval(() => {
            // Remove active class from current
            slides[currentIndex].classList.remove('is-active');

            // Move to next index, looping back to 0
            currentIndex = (currentIndex + 1) % slides.length;

            // Add active class to new current
            slides[currentIndex].classList.add('is-active');
        }, durationMs);
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

    // Run initializations
    initWysiwyg();
    initTvSlider();
    initBulkDelete();
    initImagePreview();

    // Re-initialize on Turbo render if applicable
    document.addEventListener('turbo:render', () => {
        initWysiwyg();
        initTvSlider();
        initBulkDelete();
        initImagePreview();
    });
});
