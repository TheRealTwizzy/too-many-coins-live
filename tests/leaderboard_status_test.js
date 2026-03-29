/**
 * Tests for leaderboard status indicator rendering.
 * Run with: node tests/leaderboard_status_test.js
 */

'use strict';

const assert = require('assert');

// Minimal stub of the TMC object with only the methods under test
const TMC = {
    getPlayerStatus(entry) {
        if (!entry.online_current) return 'Offline';
        return entry.activity_state || 'Offline';
    },

    renderPlayerStatusBadge(entry) {
        const status = this.getPlayerStatus(entry);
        const key = status.toLowerCase().replace(/[^a-z]+/g, '-');
        let badge = `<span class="status-dot status-dot-${key}" title="${status}"></span>`;
        if (entry.lock_in_effect_tick != null) {
            badge += ` <span class="status-dot status-dot-locked-in" title="Locked-In"></span>`;
        }
        return badge;
    },
};

// ---------------------------------------------------------------------------
// getPlayerStatus tests
// ---------------------------------------------------------------------------

// Offline (online_current false), no lock-in
assert.strictEqual(
    TMC.getPlayerStatus({ online_current: false, activity_state: 'Active', lock_in_effect_tick: null }),
    'Offline',
    'offline player without lock-in should return Offline'
);

// Offline (online_current false), WITH lock-in — still Offline
assert.strictEqual(
    TMC.getPlayerStatus({ online_current: false, activity_state: 'Active', lock_in_effect_tick: 42 }),
    'Offline',
    'offline player with lock-in should still return Offline (not Locked-In)'
);

// Active, no lock-in
assert.strictEqual(
    TMC.getPlayerStatus({ online_current: true, activity_state: 'Active', lock_in_effect_tick: null }),
    'Active',
    'online active player without lock-in should return Active'
);

// Active, WITH lock-in — still Active (lock-in is a secondary indicator)
assert.strictEqual(
    TMC.getPlayerStatus({ online_current: true, activity_state: 'Active', lock_in_effect_tick: 10 }),
    'Active',
    'online active player with lock-in should return Active (not Locked-In)'
);

// Idle, no lock-in
assert.strictEqual(
    TMC.getPlayerStatus({ online_current: true, activity_state: 'Idle', lock_in_effect_tick: null }),
    'Idle',
    'online idle player without lock-in should return Idle'
);

// Idle, WITH lock-in
assert.strictEqual(
    TMC.getPlayerStatus({ online_current: true, activity_state: 'Idle', lock_in_effect_tick: 5 }),
    'Idle',
    'online idle player with lock-in should return Idle (not Locked-In)'
);

// online_current true but no activity_state — falls back to Offline
assert.strictEqual(
    TMC.getPlayerStatus({ online_current: true, activity_state: null, lock_in_effect_tick: null }),
    'Offline',
    'online player with no activity_state should fall back to Offline'
);

// ---------------------------------------------------------------------------
// renderPlayerStatusBadge tests
// ---------------------------------------------------------------------------

function countDots(html) {
    return (html.match(/class="status-dot /g) || []).length;
}

function hasClass(html, cls) {
    return html.includes(`status-dot-${cls}`);
}

// Offline, no lock-in → exactly one dot, Offline
{
    const html = TMC.renderPlayerStatusBadge({ online_current: false, activity_state: null, lock_in_effect_tick: null });
    assert.strictEqual(countDots(html), 1, 'offline/no-lockin: should render exactly 1 dot');
    assert.ok(hasClass(html, 'offline'), 'offline/no-lockin: dot should be offline');
    assert.ok(!hasClass(html, 'locked-in'), 'offline/no-lockin: must not render locked-in dot');
}

// Active, no lock-in → exactly one dot, Active
{
    const html = TMC.renderPlayerStatusBadge({ online_current: true, activity_state: 'Active', lock_in_effect_tick: null });
    assert.strictEqual(countDots(html), 1, 'active/no-lockin: should render exactly 1 dot');
    assert.ok(hasClass(html, 'active'), 'active/no-lockin: dot should be active');
    assert.ok(!hasClass(html, 'locked-in'), 'active/no-lockin: must not render locked-in dot');
}

// Idle, no lock-in → exactly one dot, Idle
{
    const html = TMC.renderPlayerStatusBadge({ online_current: true, activity_state: 'Idle', lock_in_effect_tick: null });
    assert.strictEqual(countDots(html), 1, 'idle/no-lockin: should render exactly 1 dot');
    assert.ok(hasClass(html, 'idle'), 'idle/no-lockin: dot should be idle');
    assert.ok(!hasClass(html, 'locked-in'), 'idle/no-lockin: must not render locked-in dot');
}

// Offline WITH lock-in → two dots: Offline first, Locked-In second
{
    const html = TMC.renderPlayerStatusBadge({ online_current: false, activity_state: null, lock_in_effect_tick: 7 });
    assert.strictEqual(countDots(html), 2, 'offline/lockin: should render exactly 2 dots');
    assert.ok(hasClass(html, 'offline'), 'offline/lockin: first dot should be offline');
    assert.ok(hasClass(html, 'locked-in'), 'offline/lockin: second dot should be locked-in');
    // Order check: offline dot must come before locked-in dot
    assert.ok(html.indexOf('status-dot-offline') < html.indexOf('status-dot-locked-in'),
        'offline/lockin: offline dot must appear before locked-in dot');
}

// Active WITH lock-in → two dots: Active first, Locked-In second
{
    const html = TMC.renderPlayerStatusBadge({ online_current: true, activity_state: 'Active', lock_in_effect_tick: 3 });
    assert.strictEqual(countDots(html), 2, 'active/lockin: should render exactly 2 dots');
    assert.ok(hasClass(html, 'active'), 'active/lockin: first dot should be active');
    assert.ok(hasClass(html, 'locked-in'), 'active/lockin: second dot should be locked-in');
    assert.ok(html.indexOf('status-dot-active') < html.indexOf('status-dot-locked-in'),
        'active/lockin: active dot must appear before locked-in dot');
}

// Idle WITH lock-in → two dots: Idle first, Locked-In second
{
    const html = TMC.renderPlayerStatusBadge({ online_current: true, activity_state: 'Idle', lock_in_effect_tick: 99 });
    assert.strictEqual(countDots(html), 2, 'idle/lockin: should render exactly 2 dots');
    assert.ok(hasClass(html, 'idle'), 'idle/lockin: first dot should be idle');
    assert.ok(hasClass(html, 'locked-in'), 'idle/lockin: second dot should be locked-in');
    assert.ok(html.indexOf('status-dot-idle') < html.indexOf('status-dot-locked-in'),
        'idle/lockin: idle dot must appear before locked-in dot');
}

// Locked-In must NEVER appear alone (no case has only locked-in dot)
{
    const entries = [
        { online_current: false, activity_state: null,   lock_in_effect_tick: null },
        { online_current: false, activity_state: null,   lock_in_effect_tick: 1    },
        { online_current: true,  activity_state: 'Active', lock_in_effect_tick: null },
        { online_current: true,  activity_state: 'Active', lock_in_effect_tick: 1  },
        { online_current: true,  activity_state: 'Idle',   lock_in_effect_tick: null },
        { online_current: true,  activity_state: 'Idle',   lock_in_effect_tick: 1  },
    ];
    for (const entry of entries) {
        const html = TMC.renderPlayerStatusBadge(entry);
        const lockedInOnly = hasClass(html, 'locked-in') && countDots(html) === 1;
        assert.ok(!lockedInOnly,
            `locked-in must never appear alone (entry: ${JSON.stringify(entry)})`);
    }
}

console.log('All leaderboard status tests passed.');
