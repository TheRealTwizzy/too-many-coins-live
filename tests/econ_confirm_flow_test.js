/**
 * Tests for the economic confirmation gate flow (Buy Max / runWithEconGate).
 *
 * Covers:
 *  - Buy Max confirm-required happy path and retry
 *  - Concurrent/multiple confirmation attempts (race / stale-resolver guard)
 *  - Expired/missing confirmation state handling (preview unavailable fallback)
 *
 * Run with: node tests/econ_confirm_flow_test.js
 */

'use strict';

const assert = require('assert');

// ---------------------------------------------------------------------------
// Minimal TMC stub replicating only the econ-gate logic under test.
// ---------------------------------------------------------------------------

function makeTMC() {
    const toasts = [];

    const tmc = {
        _econPendingAction: null,
        _econCancelResolver: null,

        toast(msg, level) {
            toasts.push({ msg, level });
        },

        showEconConfirm(preview, title, onConfirm) {
            this._econPendingAction = onConfirm;
        },

        closeEconConfirm(skipCancelResolve = false) {
            if (!skipCancelResolve && typeof this._econCancelResolver === 'function') {
                const resolver = this._econCancelResolver;
                this._econCancelResolver = null;
                resolver(null);
            }
            this._econPendingAction = null;
        },

        async executeEconConfirmed() {
            const cb = this._econPendingAction;
            this.closeEconConfirm(true);
            if (cb) await cb();
        },

        /**
         * Replica of the fixed runWithEconGate from app.js (trimmed to core logic;
         * no DOM interactions required).
         */
        async runWithEconGate(previewFn, executeFn, title) {
            const openConfirmFlow = async (previewPayload, resolve) => {
                const executeConfirmedAction = async () => {
                    try {
                        const confirmedResult = await executeFn(true);
                        if (confirmedResult && (confirmedResult.reason_code === 'balance_changed' || confirmedResult.error === 'balance_changed')) {
                            const refreshedPreview = confirmedResult.preview && !confirmedResult.preview.error ? confirmedResult.preview : null;
                            if (refreshedPreview) {
                                this.toast(confirmedResult.message || 'Balance changed. Please review and confirm again.', 'info');
                                openConfirmFlow(refreshedPreview, resolve);
                                return;
                            }
                        }
                        resolve(confirmedResult);
                    } catch (error) {
                        this.toast(`Failed to complete ${title || 'this action'}. Please try again.`, 'error');
                        resolve(null);
                    }
                };

                // Guard: cancel any stale pending confirmation so its Promise
                // resolves cleanly rather than leaking and causing a dead-end.
                if (typeof this._econCancelResolver === 'function') {
                    const stale = this._econCancelResolver;
                    this._econCancelResolver = null;
                    stale(null);
                }

                this._econCancelResolver = resolve;
                this.showEconConfirm(previewPayload, title, async () => {
                    this._econCancelResolver = null;
                    await executeConfirmedAction();
                });
            };

            const preview = await previewFn();
            if (!preview || preview.error) {
                this.toast(preview ? preview.error : 'Preview failed', 'error');
                return null;
            }

            if (preview.requires_explicit_confirm) {
                return new Promise((resolve) => {
                    openConfirmFlow(preview, resolve);
                });
            }

            const directResult = await executeFn(false);
            if (directResult && directResult.error === 'confirmation_required') {
                return new Promise((resolve) => {
                    const previewUnavailableMessage = 'Confirmation required but preview is unavailable. Please try again.';
                    const serverPreview = directResult.preview && !directResult.preview.error ? directResult.preview : null;
                    if (serverPreview) {
                        openConfirmFlow(serverPreview, resolve);
                        return;
                    }

                    previewFn().then((retriedPreview) => {
                        if (retriedPreview && !retriedPreview.error) {
                            openConfirmFlow(retriedPreview, resolve);
                            return;
                        }
                        // Resolve with null (not the error object) so callers see a
                        // clean "no result" rather than an unhandled confirmation_required.
                        this.toast(previewUnavailableMessage, 'error');
                        resolve(null);
                    }).catch(() => {
                        this.toast(previewUnavailableMessage, 'error');
                        resolve(null);
                    });
                });
            }

            if (directResult && (directResult.reason_code === 'balance_changed' || directResult.error === 'balance_changed')) {
                return new Promise((resolve) => {
                    const serverPreview = directResult.preview && !directResult.preview.error ? directResult.preview : null;
                    if (!serverPreview) {
                        this.toast(directResult.message || 'Balance changed. Please try again.', 'error');
                        resolve(directResult);
                        return;
                    }
                    this.toast(directResult.message || 'Balance changed. Please review and confirm again.', 'info');
                    openConfirmFlow(serverPreview, resolve);
                });
            }

            return directResult;
        },
    };

    return { tmc, toasts };
}

// ---------------------------------------------------------------------------
// Helper: tick the JS event loop enough times for pending promises to settle.
// ---------------------------------------------------------------------------
function flushPromises() {
    return new Promise((r) => setImmediate(r));
}

// ===========================================================================
// Test definitions — each returns a Promise so Promise.all can wait for all.
// ===========================================================================

/**
 * 1. Happy path: preview requires confirm → user confirms → success
 */
async function testHappyPath() {
    const { tmc, toasts } = makeTMC();

    let executeCalls = [];
    const previewFn = async () => ({ requires_explicit_confirm: true, risk: { severity: 'high' } });
    const executeFn = async (confirmed) => {
        executeCalls.push(confirmed);
        if (!confirmed) return { error: 'confirmation_required', preview: { requires_explicit_confirm: true } };
        return { success: true, stars_purchased: 10, coins_spent: 1000 };
    };

    const resultPromise = tmc.runWithEconGate(previewFn, executeFn, 'Buy Stars');

    // Let the preview fetch settle and the modal to "open"
    await flushPromises();

    // Confirm is pending — simulate user clicking Confirm
    assert.ok(typeof tmc._econPendingAction === 'function', 'happy path: _econPendingAction must be set after preview');
    await tmc.executeEconConfirmed();

    const result = await resultPromise;
    assert.ok(result && result.success, 'happy path: result should be success');
    assert.strictEqual(result.stars_purchased, 10, 'happy path: correct stars_purchased');
    assert.ok(executeCalls.includes(true), 'happy path: executeFn must be called with confirmed=true');
    assert.strictEqual(toasts.length, 0, 'happy path: no error toasts expected');

    console.log('  ✓ happy path: preview→confirm→success');
}

/**
 * 2. Preview says low-risk but server returns confirmation_required (with preview)
 *    → client falls back to confirm modal → user confirms → success
 */
async function testRetryPath() {
    const { tmc } = makeTMC();

    let callCount = 0;
    const previewFn = async () => ({ requires_explicit_confirm: false });
    const executeFn = async (confirmed) => {
        callCount++;
        if (!confirmed) {
            return {
                error: 'confirmation_required',
                preview: { requires_explicit_confirm: true },
            };
        }
        return { success: true, stars_purchased: 5, coins_spent: 500 };
    };

    const resultPromise = tmc.runWithEconGate(previewFn, executeFn, 'Retry path');
    await flushPromises();

    assert.ok(typeof tmc._econPendingAction === 'function', 'retry path: modal should be pending');
    await tmc.executeEconConfirmed();
    const result = await resultPromise;

    assert.ok(result && result.success, 'retry path: result should be success after confirmation');
    assert.ok(callCount >= 2, 'retry path: executeFn called at least twice (once without, once with confirm)');

    console.log('  ✓ retry path: server confirmation_required→confirm modal→success');
}

/**
 * 3. Preview unavailable in fallback → resolves with null (no silent dead-end)
 */
async function testFallbackNoPreview() {
    const { tmc, toasts } = makeTMC();

    const executeFn = async (confirmed) => {
        if (!confirmed) {
            // Server says confirmation required but includes NO preview
            return { error: 'confirmation_required' };
        }
        return { success: true };
    };
    let previewCallCount = 0;
    const failingPreviewFn = async () => {
        previewCallCount++;
        if (previewCallCount === 1) return { requires_explicit_confirm: false };
        return { error: 'Preview unavailable' };
    };

    const result = await tmc.runWithEconGate(failingPreviewFn, executeFn, 'Fallback test');

    assert.strictEqual(result, null, 'fallback: must resolve null, not the confirmation_required error object');
    assert.ok(toasts.some(t => t.msg.includes('unavailable') || t.msg.includes('Confirmation')),
        'fallback: should show an informative toast instead of silent failure');

    console.log('  ✓ fallback: no preview available → null result + toast (no dead-end)');
}

/**
 * 4. Concurrent confirmations: second call cancels stale first resolver
 */
async function testConcurrentConfirmations() {
    const { tmc } = makeTMC();

    const previewFn = async () => ({ requires_explicit_confirm: true });
    const executeFn = async (confirmed) => confirmed ? { success: true, call: 'exec' } : { error: 'no' };

    // Start Call A
    const promiseA = tmc.runWithEconGate(previewFn, executeFn, 'Call A');
    await flushPromises();
    assert.ok(typeof tmc._econCancelResolver === 'function', 'concurrent: resolver A must be set');

    // Start Call B — this should cancel Call A's resolver
    const promiseB = tmc.runWithEconGate(previewFn, executeFn, 'Call B');
    await flushPromises();

    // Promise A should have resolved with null (stale resolver cancelled)
    const resultA = await promiseA;
    assert.strictEqual(resultA, null, 'concurrent: stale promise A must resolve to null after being superseded');

    // Now confirm B
    assert.ok(typeof tmc._econPendingAction === 'function', 'concurrent: Call B pending action must be set');
    await tmc.executeEconConfirmed();
    const resultB = await promiseB;
    assert.ok(resultB && resultB.success, 'concurrent: Call B should complete successfully');

    console.log('  ✓ concurrent: stale resolver cancelled, new confirmation completes');
}

/**
 * 5. User cancels confirm → result is null → caller early-returns cleanly
 */
async function testUserCancel() {
    const { tmc, toasts } = makeTMC();

    const previewFn = async () => ({ requires_explicit_confirm: true });
    const executeFn = async (confirmed) => confirmed ? { success: true } : { error: 'no' };

    const resultPromise = tmc.runWithEconGate(previewFn, executeFn, 'Cancel test');
    await flushPromises();

    // Simulate user closing/cancelling the modal
    tmc.closeEconConfirm(false);

    const result = await resultPromise;
    assert.strictEqual(result, null, 'cancel: result must be null when user cancels');
    assert.strictEqual(toasts.length, 0, 'cancel: no toast on clean cancel');
    assert.strictEqual(tmc._econPendingAction, null, 'cancel: _econPendingAction cleared');

    console.log('  ✓ cancel: user closes modal → clean null result');
}

/**
 * 6. Confirmed execution returns balance_changed with preview
 *    → client re-opens confirmation flow and succeeds on second confirm
 */
async function testBalanceChangedRetryFlow() {
    const { tmc, toasts } = makeTMC();

    const previewFn = async () => ({ requires_explicit_confirm: true, coins_available: 1000 });
    let confirmedCalls = 0;
    const executeFn = async (confirmed) => {
        if (!confirmed) return { error: 'confirmation_required', preview: { requires_explicit_confirm: true } };
        confirmedCalls += 1;
        if (confirmedCalls === 1) {
            return {
                error: 'balance_changed',
                reason_code: 'balance_changed',
                message: 'Your balance changed since confirmation.',
                preview: { requires_explicit_confirm: true, coins_available: 900 },
            };
        }
        return { success: true };
    };

    const resultPromise = tmc.runWithEconGate(previewFn, executeFn, 'Balance changed flow');
    await flushPromises();

    // First confirmation attempt.
    assert.ok(typeof tmc._econPendingAction === 'function', 'balance_changed: first confirmation should be pending');
    await tmc.executeEconConfirmed();
    await flushPromises();

    // Flow should reopen confirmation with refreshed preview.
    assert.ok(typeof tmc._econPendingAction === 'function', 'balance_changed: confirmation should reopen after drift');
    await tmc.executeEconConfirmed();

    const result = await resultPromise;
    assert.ok(result && result.success, 'balance_changed: second confirmation should succeed');
    assert.ok(toasts.some(t => t.level === 'info' && /balance/i.test(t.msg)),
        'balance_changed: should show informational balance-change toast');

    console.log('  ✓ balance_changed: re-review + re-confirm flow succeeds');
}

// ---------------------------------------------------------------------------
// Run all tests — await all Promises to guarantee completion before exit.
// ---------------------------------------------------------------------------
Promise.all([
    testHappyPath(),
    testRetryPath(),
    testFallbackNoPreview(),
    testConcurrentConfirmations(),
    testUserCancel(),
    testBalanceChangedRetryFlow(),
]).then(() => {
    console.log('\nAll econ confirm flow tests passed.');
}).catch((e) => {
    console.error('FAIL:', e.message);
    process.exit(1);
});
