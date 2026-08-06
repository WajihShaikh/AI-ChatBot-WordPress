(function($) {
    'use strict';

    $(function() {
        const $form = $('#ai-chat-settings-form');
        if (!$form.length || typeof aiChatSettings === 'undefined') {
            return;
        }

        const $notice = $('#ai-chat-settings-notice');

        function showNotice(message, type) {
            $notice
                .removeClass('is-success is-error is-info')
                .addClass(type === 'success' ? 'is-success' : (type === 'info' ? 'is-info' : 'is-error'))
                .text(message)
                .prop('hidden', false);
            window.setTimeout(function() {
                $notice[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 20);
        }

        function getErrorMessage(xhr, fallback) {
            if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
                if (xhr.responseJSON.data.message) {
                    return xhr.responseJSON.data.message;
                }
                if (typeof xhr.responseJSON.data === 'string') {
                    return xhr.responseJSON.data;
                }
            }
            return fallback;
        }

        const $tabs = $('.ai-chat-settings-tab');

        function activateTab($tab, focus) {
            const tab = $tab.data('tab');
            $tabs.removeClass('is-active').attr({ 'aria-selected': 'false', tabindex: '-1' });
            $tab.addClass('is-active').attr({ 'aria-selected': 'true', tabindex: '0' });
            $('.ai-chat-settings-panel').removeClass('is-active').prop('hidden', true);
            $('.ai-chat-settings-panel[data-panel="' + tab + '"]').addClass('is-active').prop('hidden', false);
            if (focus) $tab.trigger('focus');
        }

        $tabs.on('click', function() {
            activateTab($(this), false);
        }).on('keydown', function(event) {
            const index = $tabs.index(this);
            let next = null;
            if (event.key === 'ArrowRight') next = (index + 1) % $tabs.length;
            if (event.key === 'ArrowLeft') next = (index - 1 + $tabs.length) % $tabs.length;
            if (event.key === 'Home') next = 0;
            if (event.key === 'End') next = $tabs.length - 1;
            if (next !== null) {
                event.preventDefault();
                activateTab($tabs.eq(next), true);
            }
        });

        $('input[name="api_provider"]').on('change', function() {
            $('.ai-chat-provider-card').removeClass('is-selected');
            const $card = $(this).closest('.ai-chat-provider-card').addClass('is-selected');
            $('.ai-chat-provider-choice small').text('Available provider');
            $card.find('.ai-chat-provider-choice small').text('Active provider');
        });

        $('.ai-chat-secret-toggle').on('click', function() {
            const $button = $(this);
            const $input = $button.siblings('input');
            const show = $input.attr('type') === 'password';
            $input.attr('type', show ? 'text' : 'password');
            $button.attr('aria-pressed', show ? 'true' : 'false').attr('aria-label', show ? 'Hide API key' : 'Show API key');
            $button.find('.dashicons').toggleClass('dashicons-visibility', !show).toggleClass('dashicons-hidden', show);
        });

        function updateModelSummary($card) {
            const $select = $card.find('[data-model-select]');
            const option = $select.find('option:selected')[0];
            if (!option) return;
            const $option = $(option);
            const text = $option.text().split(' — ')[0];
            $card.find('[data-model-name]').text(text);
            $card.find('[data-model-description]').text($option.data('description') || 'Compatible text-generation model.');
            $card.find('[data-model-capability]').text($option.data('capability') || 'Text generation');
            $card.find('[data-model-status]').text(String($option.data('status') || 'stable').replace(/\b\w/g, function(c) { return c.toUpperCase(); }));
        }

        $('.ai-chat-provider-card').each(function() {
            updateModelSummary($(this));
        });
        $(document).on('change', '[data-model-select]', function() {
            updateModelSummary($(this).closest('.ai-chat-provider-card'));
        });

        function setButtonLoading($button, loading, label) {
            if (loading) {
                $button.data('original-label', $button.text()).prop('disabled', true).addClass('is-loading').text(label);
            } else {
                $button.prop('disabled', false).removeClass('is-loading').text($button.data('original-label') || $button.text());
            }
        }

        function requestData($card) {
            return {
                provider: $card.data('provider'),
                api_key: $card.find('[data-api-key]').val(),
                model: $card.find('[data-model-select]').val(),
                nonce: aiChatSettings.nonce
            };
        }

        function setStatus($card, state, text) {
            const $chip = $card.find('[data-status-chip]');
            $chip.removeClass('is-success is-error is-neutral').addClass(state === 'success' ? 'is-success' : (state === 'error' ? 'is-error' : 'is-neutral'));
            $chip.find('[data-status-text]').text(text);
        }

        $('.ai-chat-test-connection').on('click', function() {
            const $button = $(this);
            const $card = $button.closest('.ai-chat-provider-card');
            setButtonLoading($button, true, 'Testing…');
            setStatus($card, 'neutral', 'Testing');

            $.ajax({
                url: aiChatSettings.ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: $.extend({ action: 'ai_chat_test_connection' }, requestData($card))
            }).done(function(response) {
                if (response && response.success) {
                    setStatus($card, 'success', 'Connected');
                    showNotice(response.data.message, 'success');
                } else {
                    setStatus($card, 'error', 'Needs attention');
                    showNotice(response && response.data && response.data.message ? response.data.message : 'Connection test failed.', 'error');
                }
            }).fail(function(xhr) {
                setStatus($card, 'error', 'Needs attention');
                showNotice(getErrorMessage(xhr, 'Connection test failed. Check the API key and selected model.'), 'error');
            }).always(function() {
                setButtonLoading($button, false);
            });
        });

        $('.ai-chat-refresh-models').on('click', function() {
            const $button = $(this);
            const $card = $button.closest('.ai-chat-provider-card');
            const $select = $card.find('[data-model-select]');
            const current = $select.val();
            setButtonLoading($button, true, 'Refreshing…');

            $.ajax({
                url: aiChatSettings.ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: $.extend({ action: 'ai_chat_refresh_models' }, requestData($card))
            }).done(function(response) {
                if (!response || !response.success || !Array.isArray(response.data.models)) {
                    showNotice('Models could not be refreshed.', 'error');
                    return;
                }

                $select.empty();
                response.data.models.forEach(function(model) {
                    const option = document.createElement('option');
                    option.value = model.id;
                    option.textContent = (model.name || model.id) + ' — ' + model.id;
                    option.dataset.description = model.description || '';
                    option.dataset.capability = model.capability || 'Text generation';
                    option.dataset.status = model.status || 'stable';
                    $select.append(option);
                });

                var currentExists = false;
                $select.find('option').each(function() {
                    if ($(this).val() === current) currentExists = true;
                });
                if (currentExists) {
                    $select.val(current);
                }
                updateModelSummary($card);
                showNotice(response.data.message, 'success');
            }).fail(function(xhr) {
                showNotice(getErrorMessage(xhr, 'Models could not be refreshed. Check the API key.'), 'error');
            }).always(function() {
                setButtonLoading($button, false);
            });
        });

        $form.on('submit', function() {
            $(this).find('button[type="submit"]').prop('disabled', true).text('Saving…');
        });
    });
})(jQuery);
