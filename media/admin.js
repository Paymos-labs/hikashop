// Copyright (c) 2026 Paymos Labs
// SPDX-License-Identifier: GPL-2.0-or-later

(() => {
    'use strict';

    const panels = new WeakMap();
    const languagePrefix = 'PLG_HIKASHOPPAYMENT_PAYMOS_';
    const fallback = 'Paymos could not complete the request. Try again.';
    const message = (key) => window.Joomla?.Text?._(languagePrefix + key, fallback) ?? fallback;

    function getPanelState(panel) {
        if (!panels.has(panel)) {
            panels.set(panel, {busy: false, polling: false, interval: 16, timer: 0, failures: 0});
        }
        return panels.get(panel);
    }

    function setStatus(panel, status) {
        panel.querySelector('[data-paymos-status]').textContent = message('STATUS_' + status.toUpperCase());
    }

    function stopPolling(panel, state) {
        window.clearTimeout(state.timer);
        state.polling = false;
        const device = panel.querySelector('[data-paymos-device]');
        if (device) {
            device.hidden = true;
            device.querySelector('[data-paymos-code]').textContent = '';
            device.querySelector('[data-paymos-verification]').removeAttribute('href');
        }
    }

    function schedulePoll(panel, state, delay) {
        window.clearTimeout(state.timer);
        state.timer = window.setTimeout(() => {
            if (panel.isConnected && state.polling) {
                if (state.busy) {
                    schedulePoll(panel, state, 1);
                } else {
                    perform(panel, 'poll');
                }
            }
        }, delay * 1000);
    }

    async function request(panel, action, fields) {
        const endpoint = new URL(panel.dataset.paymosEndpoint, window.location.href);
        if (endpoint.origin !== window.location.origin || !panel.dataset.paymosToken) {
            throw new Error('Unexpected Paymos settings endpoint');
        }

        const body = new URLSearchParams({
            action,
            method_id: panel.dataset.paymosMethod,
            [panel.dataset.paymosToken]: '1',
            ...fields,
        });
        const response = await fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            redirect: 'error',
            headers: {Accept: 'application/json'},
            body,
        });
        const reply = await response.json();
        if (reply?.success !== true || !Array.isArray(reply.data) || reply.data.length !== 1
            || typeof reply.data[0]?.status !== 'string' || typeof reply.data[0]?.ok !== 'boolean') {
            throw new Error('Unexpected Paymos settings response');
        }
        if (!response.ok && reply.data[0].ok) {
            throw new Error('Unexpected Paymos settings status');
        }

        return reply.data[0];
    }

    function showDevice(panel, data) {
        const url = new URL(data.verification_url);
        if (url.protocol !== 'https:' || url.origin !== panel.dataset.paymosApprovalOrigin
            || url.pathname !== '/connect/cms/verify' || url.username || url.password || url.hash
            || typeof data.user_code !== 'string' || !/^[a-z0-9-]{4,32}$/i.test(data.user_code)) {
            throw new Error('Unexpected Paymos approval URL');
        }

        const device = panel.querySelector('[data-paymos-device]');
        device.querySelector('[data-paymos-code]').textContent = data.user_code;
        device.querySelector('[data-paymos-verification]').href = url.href;
        device.hidden = false;
    }

    async function perform(panel, action, fields = {}) {
        const state = getPanelState(panel);
        if (state.busy) {
            return;
        }
        state.busy = true;
        if (action === 'start' || action === 'disconnect') {
            stopPolling(panel, state);
            state.failures = 0;
        }
        if (action !== 'poll') {
            setStatus(panel, 'working');
        }
        panel.querySelectorAll('button[data-paymos-action]').forEach((button) => { button.disabled = true; });

        try {
            const data = await request(panel, action, fields);
            setStatus(panel, data.status);
            if (!data.ok) {
                if (action === 'poll' && data.status === 'unavailable') {
                    throw new Error('Paymos polling is temporarily unavailable');
                }
                if (action === 'poll') {
                    stopPolling(panel, state);
                }
                return;
            }

            state.failures = 0;
            if (data.status === 'waiting' && (action === 'start' || action === 'poll')) {
                if (action === 'start') {
                    showDevice(panel, data);
                    state.polling = true;
                }
                state.interval = Number.isInteger(data.interval) ? Math.max(1, Math.min(3600, data.interval)) : 16;
                schedulePoll(panel, state, state.interval);
            } else if (data.status === 'connected' || data.status === 'disconnected') {
                stopPolling(panel, state);
                const controls = panel.querySelector('[data-paymos-connection-controls]');
                controls.hidden = data.status === 'disconnected';
                if (data.status === 'connected') {
                    controls.querySelector('[data-paymos-mode]').value = 'sandbox';
                }
            } else if (action === 'refund') {
                if (typeof data.operation_id === 'string') {
                    panel.dataset.paymosOperation = data.operation_id;
                    panel.querySelector('[data-paymos-action="reconcile"]').textContent = message('CHECK_REFUND');
                }
                panel.querySelectorAll('[data-paymos-action="refund"]').forEach((button) => { button.hidden = true; });
            }
        } catch {
            setStatus(panel, 'unavailable');
            if (action === 'poll' && state.polling && ++state.failures < 3) {
                schedulePoll(panel, state, state.interval);
            } else if (action === 'poll' || action === 'start') {
                stopPolling(panel, state);
            }
        } finally {
            state.busy = false;
            panel.querySelectorAll('button[data-paymos-action]').forEach((button) => { button.disabled = false; });
        }
    }

    document.addEventListener('click', (event) => {
        const button = event.target instanceof Element ? event.target.closest('button[data-paymos-action]') : null;
        const panel = button?.closest('[data-paymos-admin]');
        if (!panel || button.disabled) {
            return;
        }

        const action = button.dataset.paymosAction;
        const fields = {};
        switch (action) {
            case 'mode':
                fields.mode = panel.querySelector('[data-paymos-mode]').value;
                break;
            case 'disconnect':
                if (!window.confirm(message('CONFIRM_DISCONNECT'))) {
                    return;
                }
                break;
            case 'refund': {
                const mode = button.dataset.paymosRefundMode;
                if (!['full', 'overpayment'].includes(mode)
                    || !window.confirm(message(mode === 'full' ? 'CONFIRM_FULL' : 'CONFIRM_OVERPAYMENT'))) {
                    return;
                }
                fields.order_id = panel.dataset.paymosOrder;
                fields.mode = mode;
                fields.request_token = panel.dataset.paymosRequestToken;
                break;
            }
            case 'reconcile':
                fields.order_id = panel.dataset.paymosOrder;
                if (panel.dataset.paymosOperation) {
                    fields.operation_id = panel.dataset.paymosOperation;
                }
                break;
            case 'start':
                break;
            default:
                return;
        }

        event.preventDefault();
        perform(panel, action, fields);
    });
})();
