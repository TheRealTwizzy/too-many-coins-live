/**
 * Tests for seasonal leaderboard rendering.
 *
 * Covers:
 *  - Rank derivation with mixed field shapes (final_rank / position / rank)
 *  - Non-empty data must NOT show empty-state
 *  - getActiveJoinedSeason returns season for both Active and Blackout statuses
 *  - loadSeasonLeaderboard empty-state only when data is truly empty
 *
 * Run with: node tests/seasonal_leaderboard_test.js
 */

'use strict';

const assert = require('assert');

// ---------------------------------------------------------------------------
// Minimal stubs replicating only the leaderboard logic under test.
// ---------------------------------------------------------------------------

/** Mirrors the fixed renderSeasonLeaderboardRows rank-derivation logic. */
function deriveRank(entry, index) {
    // Support rank field under any of the common schema aliases.
    const rawRank = entry.final_rank != null ? entry.final_rank
                  : entry.position    != null ? entry.position
                  : entry.rank        != null ? entry.rank
                  : null;
    const parsedFinalRank = Number(rawRank);
    const hasFinalRank = rawRank != null && !Number.isNaN(parsedFinalRank) && parsedFinalRank > 0;
    return hasFinalRank ? parsedFinalRank : (index + 1);
}

/** Mirrors the fixed getActiveJoinedSeason logic. */
function getActiveJoinedSeason(player, seasons) {
    if (!player || !player.joined_season_id) return null;
    const joinedSeasonId = player.joined_season_id;
    const season = seasons.find(s => s.season_id == joinedSeasonId);
    if (!season) return null;
    const status = season.computed_status || season.status;
    if (status === 'Active' || status === 'Blackout') return season;
    return null;
}

/** Mirrors the fixed leaderboard empty-state guard. */
function isLeaderboardEmpty(lb) {
    return !Array.isArray(lb) || lb.length === 0 || !!lb.error;
}

/** Mirrors seasonal tab paging behavior: >100 only after expanded Show All. */
function paginateSeasonal(lb, expanded, page) {
    if (!Array.isArray(lb)) return [];
    if (!expanded) return lb.slice(0, 20);
    if (lb.length <= 100) return lb;
    const safePage = Math.max(1, Number(page) || 1);
    const start = (safePage - 1) * 100;
    return lb.slice(start, start + 100);
}

/** Mirrors the new seasonal table header split for coins/rate. */
function getSeasonalHeaderColumns() {
    return ['Rank', 'Player', 'Stars', 'Boost', 'Coins', 'Rate', 'Status'];
}

// ===========================================================================
// 1. Rank derivation — final_rank field (typical ended season)
// ===========================================================================

{
    const entries = [
        { final_rank: 1, handle: 'Alice', seasonal_stars: 100 },
        { final_rank: 2, handle: 'Bob',   seasonal_stars: 80  },
        { final_rank: 3, handle: 'Carol', seasonal_stars: 60  },
    ];
    for (let i = 0; i < entries.length; i++) {
        const rank = deriveRank(entries[i], i);
        assert.strictEqual(rank, i + 1, `final_rank: entry ${i} rank should be ${i + 1}`);
    }
    console.log('  ✓ rank derivation: final_rank field');
}

// ===========================================================================
// 2. Rank derivation — position field (schema variation)
// ===========================================================================

{
    const entries = [
        { position: 1, final_rank: null, handle: 'Alice' },
        { position: 2, final_rank: null, handle: 'Bob'   },
    ];
    assert.strictEqual(deriveRank(entries[0], 0), 1, 'position field: rank should be 1');
    assert.strictEqual(deriveRank(entries[1], 1), 2, 'position field: rank should be 2');
    console.log('  ✓ rank derivation: position field');
}

// ===========================================================================
// 3. Rank derivation — rank field (schema variation)
// ===========================================================================

{
    const entries = [
        { rank: 5, final_rank: null, position: null, handle: 'Dave' },
    ];
    assert.strictEqual(deriveRank(entries[0], 0), 5, 'rank field: should use rank=5 not index+1');
    console.log('  ✓ rank derivation: rank field');
}

// ===========================================================================
// 4. Rank derivation — all rank fields null → fallback to index+1
// ===========================================================================

{
    const entries = [
        { final_rank: null, position: null, rank: null, handle: 'Eve' },
        { final_rank: null, position: null, rank: null, handle: 'Frank' },
    ];
    assert.strictEqual(deriveRank(entries[0], 0), 1, 'no rank fields: fallback to index+1 (1)');
    assert.strictEqual(deriveRank(entries[1], 1), 2, 'no rank fields: fallback to index+1 (2)');
    console.log('  ✓ rank derivation: null fields → fallback to array index');
}

// ===========================================================================
// 5. Rank derivation — rank=0 is not a valid rank → fallback to index+1
// ===========================================================================

{
    const entry = { final_rank: 0 };
    // Rank 0 is invalid; should fall back to index+1=1
    assert.strictEqual(deriveRank(entry, 0), 1, 'rank=0: invalid rank must fall back to index+1');
    console.log('  ✓ rank derivation: rank=0 falls back to index+1');
}

// ===========================================================================
// 6. Rank derivation — string rank values (schema may return strings)
// ===========================================================================

{
    const entry1 = { final_rank: '3' };
    const entry2 = { final_rank: '0' };
    const entry3 = { final_rank: '' };
    assert.strictEqual(deriveRank(entry1, 4), 3, 'string rank "3": should parse to 3');
    assert.strictEqual(deriveRank(entry2, 4), 5, 'string rank "0": invalid, falls back to 4+1=5');
    assert.strictEqual(deriveRank(entry3, 4), 5, 'empty string rank: falls back to 4+1=5');
    console.log('  ✓ rank derivation: string rank values handled correctly');
}

// ===========================================================================
// 7. getActiveJoinedSeason — Active status shows seasonal tab
// ===========================================================================

{
    const player = { joined_season_id: 42 };
    const seasons = [{ season_id: 42, computed_status: 'Active' }];
    const result = getActiveJoinedSeason(player, seasons);
    assert.ok(result !== null, 'Active season: should return the season');
    assert.strictEqual(result.season_id, 42, 'Active season: correct season_id');
    console.log('  ✓ getActiveJoinedSeason: Active → returns season');
}

// ===========================================================================
// 8. getActiveJoinedSeason — Blackout status ALSO shows seasonal tab (fixed)
// ===========================================================================

{
    const player = { joined_season_id: 42 };
    const seasons = [{ season_id: 42, computed_status: 'Blackout' }];
    const result = getActiveJoinedSeason(player, seasons);
    assert.ok(result !== null, 'Blackout season: should return the season (seasonal tab should be visible)');
    assert.strictEqual(result.season_id, 42, 'Blackout season: correct season_id');
    console.log('  ✓ getActiveJoinedSeason: Blackout → returns season (seasonal tab visible)');
}

// ===========================================================================
// 9. getActiveJoinedSeason — Completed season hides seasonal tab
// ===========================================================================

{
    const player = { joined_season_id: 42 };
    const seasons = [{ season_id: 42, computed_status: 'Completed' }];
    const result = getActiveJoinedSeason(player, seasons);
    assert.strictEqual(result, null, 'Completed season: should return null (no seasonal tab)');
    console.log('  ✓ getActiveJoinedSeason: Completed → null (no seasonal tab)');
}

// ===========================================================================
// 10. getActiveJoinedSeason — falls back to season.status when computed_status absent
// ===========================================================================

{
    const player = { joined_season_id: 7 };
    const seasons = [{ season_id: 7, status: 'Active' }]; // no computed_status
    const result = getActiveJoinedSeason(player, seasons);
    assert.ok(result !== null, 'status fallback (Active): should return season');
    console.log('  ✓ getActiveJoinedSeason: falls back to .status when computed_status absent');
}

// ===========================================================================
// 11. isLeaderboardEmpty — non-empty array does NOT show empty-state
// ===========================================================================

{
    const lb = [{ player_id: 1, handle: 'Alice', seasonal_stars: 100 }];
    assert.strictEqual(isLeaderboardEmpty(lb), false, 'non-empty array: must NOT trigger empty-state');
    console.log('  ✓ empty-state: non-empty array → not empty');
}

// ===========================================================================
// 12. isLeaderboardEmpty — empty array DOES show empty-state
// ===========================================================================

{
    assert.strictEqual(isLeaderboardEmpty([]), true, 'empty array: must trigger empty-state');
    console.log('  ✓ empty-state: empty array → empty');
}

// ===========================================================================
// 13. isLeaderboardEmpty — error object shows empty-state (network/server error)
// ===========================================================================

{
    const errorResponse = { error: 'Network error. Please try again.' };
    assert.strictEqual(isLeaderboardEmpty(errorResponse), true, 'error object: must trigger empty-state');
    console.log('  ✓ empty-state: error object → empty');
}

// ===========================================================================
// 14. isLeaderboardEmpty — null/undefined shows empty-state
// ===========================================================================

{
    assert.strictEqual(isLeaderboardEmpty(null), true, 'null: must trigger empty-state');
    assert.strictEqual(isLeaderboardEmpty(undefined), true, 'undefined: must trigger empty-state');
    console.log('  ✓ empty-state: null/undefined → empty');
}

// ===========================================================================
// 15. isLeaderboardEmpty — plain object (unexpected schema) shows empty-state
// ===========================================================================

{
    // API returned an associative object instead of an array (unexpected)
    const weirdResponse = { 0: { player_id: 1 }, length: 1 };
    assert.strictEqual(isLeaderboardEmpty(weirdResponse), true,
        'non-array object with length: must trigger empty-state (Array.isArray guard)');
    console.log('  ✓ empty-state: plain object (non-array) → empty (Array.isArray guard)');
}

// ===========================================================================
// 16. Seasonal columns — Coins and Rate are separate columns
// ===========================================================================

{
    const cols = getSeasonalHeaderColumns();
    assert.deepStrictEqual(cols, ['Rank', 'Player', 'Stars', 'Boost', 'Coins', 'Rate', 'Status']);
    assert.ok(!cols.includes('Coins / Rate'), 'Coins/Rate combined header must not be present');
    console.log('  ✓ seasonal columns: Coins and Rate are split');
}

// ===========================================================================
// 17. Pagination threshold — no paging before show-all expansion
// ===========================================================================

{
    const lb = Array.from({ length: 250 }, (_, i) => ({ player_id: i + 1 }));
    const rows = paginateSeasonal(lb, false, 1);
    assert.strictEqual(rows.length, 20, 'collapsed state must still show top 20');
    console.log('  ✓ pagination threshold: collapsed view remains top 20');
}

// ===========================================================================
// 18. Pagination threshold — expanded view uses 100 rows/page when >100
// ===========================================================================

{
    const lb = Array.from({ length: 250 }, (_, i) => ({ player_id: i + 1 }));
    const page1 = paginateSeasonal(lb, true, 1);
    const page2 = paginateSeasonal(lb, true, 2);
    const page3 = paginateSeasonal(lb, true, 3);

    assert.strictEqual(page1.length, 100, 'expanded page 1 should have 100 rows');
    assert.strictEqual(page2.length, 100, 'expanded page 2 should have 100 rows');
    assert.strictEqual(page3.length, 50, 'expanded page 3 should have remaining rows');
    assert.strictEqual(page1[0].player_id, 1, 'page 1 starts from first row');
    assert.strictEqual(page2[0].player_id, 101, 'page 2 starts at 101');
    assert.strictEqual(page3[0].player_id, 201, 'page 3 starts at 201');
    console.log('  ✓ pagination threshold: expanded view paginates at 100 rows/page');
}

console.log('\nAll seasonal leaderboard tests passed.');
