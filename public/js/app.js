/**
 * Too Many Coins - Game Client
 * Complete JavaScript client for the economic competition game
 */
const TMC = {
    // State
    state: {
        player: null,
        seasons: [],
        currentScreen: 'home',
        currentSeason: null,
        currentChat: 'GLOBAL',
        gameState: null,
        pollInterval: null,
        realtimeInterval: null,
        seasonCountdowns: {},
        chatPollInterval: null,
        shopFilter: 'all',
        cosmetics: [],
        myCosmetics: [],
        boostCountdowns: {},
    },

    API_BASE: '/api/index.php',

    // ==================== API ====================
    async api(action, data = {}) {
        try {
            const token = localStorage.getItem('tmc_token');
            const body = JSON.stringify({ action, ...data });
            const resp = await fetch(this.API_BASE + '?action=' + action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Session-Token': token || ''
                },
                body: body
            });
            const json = await resp.json();
            if (json.error && json.error.includes('Authentication required')) {
                this.handleLoggedOut();
            }
            return json;
        } catch (e) {
            console.error('API error:', e);
            return { error: 'Network error. Please try again.' };
        }
    },

    // ==================== INIT ====================
    async init() {
        // Check for stored session
        const token = localStorage.getItem('tmc_token');
        if (token) {
            document.cookie = `tmc_session=${token}; path=/; max-age=86400`;
        }

        await this.refreshGameState();
        this.renderUserArea();

        // Restore the last-visited route on refresh; fall back to home if
        // the player is not logged in or the saved screen was the auth page.
        const savedRoute = this._loadRoute();
        if (savedRoute && savedRoute.screen && savedRoute.screen !== 'auth' && this.state.player) {
            this.navigate(savedRoute.screen, savedRoute.data !== undefined ? savedRoute.data : null);
        } else {
            this.navigate('home');
        }

        // Start polling
        this.startPolling();
        this.startRealtimeClock();
    },

    startPolling() {
        if (this.state.pollInterval) clearInterval(this.state.pollInterval);
        this.state.pollInterval = setInterval(() => this.refreshGameState(), 3000);
    },

    startRealtimeClock() {
        if (this.state.realtimeInterval) clearInterval(this.state.realtimeInterval);
        this.state.realtimeInterval = setInterval(() => this.tickRealtimeViews(), 1000);
    },

    async refreshGameState() {
        const gs = await this.api('game_state');
        if (gs.error) return;
        this.state.gameState = gs;
        this.state.seasons = gs.seasons || [];
        this.syncSeasonCountdowns(this.state.seasons);
        this.state.player = gs.player || null;
        this.syncBoostCountdowns();
        this.renderUserArea();

        if (this.state.currentScreen === 'auth' && this.state.player) {
            this.navigate('home');
        }

        this.updateHUD();
        this.checkIdleModal();

        // Refresh current screen data (but don't re-render season detail to preserve input state)
        if (this.state.currentScreen === 'seasons') this.renderSeasons();
        if (this.state.currentScreen === 'season-detail' && this.state.currentSeason) {
            this.updateSeasonDetailLive();
        }
        if (this.state.currentScreen === 'global-lb') this.loadGlobalLeaderboard();
    },

    // ==================== AUTH ====================
    async login(e) {
        e.preventDefault();
        const email = document.getElementById('login-email').value;
        const password = document.getElementById('login-password').value;
        document.getElementById('login-error').textContent = '';
        const result = await this.api('login', { email, password });
        if (result.error) {
            document.getElementById('login-error').textContent = result.error;
            return;
        }
        localStorage.setItem('tmc_token', result.token);
        document.cookie = `tmc_session=${result.token}; path=/; max-age=86400`;
        this.toast('Welcome back, ' + result.handle + '!', 'success');
        await this.refreshGameState();
        if (!this.state.player || this.state.player.player_id != result.player_id) {
            await this.refreshGameState();
        }
        if (!this.state.player) {
            this.state.player = {
                player_id: result.player_id,
                handle: result.handle,
                global_stars: 0
            };
        }
        this.renderUserArea();
        localStorage.removeItem('tmc_route');
        this.navigate('home');
    },

    async register(e) {
        e.preventDefault();
        const handle = document.getElementById('reg-handle').value;
        const email = document.getElementById('reg-email').value;
        const password = document.getElementById('reg-password').value;
        document.getElementById('register-error').textContent = '';
        const result = await this.api('register', { handle, email, password });
        if (result.error) {
            document.getElementById('register-error').textContent = result.error;
            return;
        }
        localStorage.setItem('tmc_token', result.token);
        document.cookie = `tmc_session=${result.token}; path=/; max-age=86400`;
        this.toast('Account created! Welcome, ' + result.handle + '!', 'success');
        await this.refreshGameState();
        if (!this.state.player || this.state.player.player_id != result.player_id) {
            await this.refreshGameState();
        }
        if (!this.state.player) {
            this.state.player = {
                player_id: result.player_id,
                handle: result.handle,
                global_stars: 0
            };
        }
        this.renderUserArea();
        localStorage.removeItem('tmc_route');
        this.navigate('home');
    },

    async logout() {
        await this.api('logout');
        localStorage.removeItem('tmc_token');
        localStorage.removeItem('tmc_route');
        document.cookie = 'tmc_session=; path=/; max-age=0';
        this.state.player = null;
        this.state.gameState = null;
        this.renderUserArea();
        this.navigate('home');
        this.toast('Logged out.', 'info');
    },

    handleLoggedOut() {
        localStorage.removeItem('tmc_token');
        localStorage.removeItem('tmc_route');
        this.state.player = null;
        this.renderUserArea();
    },

    showAuthTab(tab) {
        document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
        if (tab === 'login') {
            document.getElementById('login-form').style.display = '';
            document.getElementById('register-form').style.display = 'none';
            document.querySelectorAll('.auth-tab')[0].classList.add('active');
        } else {
            document.getElementById('login-form').style.display = 'none';
            document.getElementById('register-form').style.display = '';
            document.querySelectorAll('.auth-tab')[1].classList.add('active');
        }
    },

    // ==================== NAVIGATION ====================
    navigate(screen, data) {
        this.state.currentScreen = screen;
        this._saveRoute(screen, data);

        // Hide all screens
        document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));

        // Update desktop nav
        document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
        const navBtn = document.querySelector(`.nav-btn[data-screen="${screen}"]`);
        if (navBtn) navBtn.classList.add('active');

        // Update mobile bottom nav
        document.querySelectorAll('.bottom-nav-btn').forEach(b => b.classList.remove('active'));
        const bottomBtn = document.querySelector(`.bottom-nav-btn[data-screen="${screen}"]`);
        if (bottomBtn) bottomBtn.classList.add('active');

        // Scroll to top on screen change
        window.scrollTo(0, 0);

        // Show target screen
        const el = document.getElementById('screen-' + screen);
        if (el) el.classList.add('active');

        // Screen-specific logic
        switch (screen) {
            case 'home':
                this.renderHome();
                break;
            case 'auth':
                break;
            case 'seasons':
                this.renderSeasons();
                break;
            case 'season-detail':
                this.state.currentSeason = data;
                this.loadSeasonDetail(data);
                break;
            case 'global-lb':
                this.loadGlobalLeaderboard();
                break;
            case 'shop':
                this.loadShop();
                break;
            case 'chat':
                this.initChat();
                break;
            case 'profile':
                this.loadProfile(data);
                break;
            case 'trade':
                this.renderTradeScreen(data);
                break;
        }
    },

    // ==================== ROUTE PERSISTENCE ====================
    // Save the current route to localStorage so page refresh returns the
    // player to the same screen they were on.  The 'auth' screen is never
    // persisted – on refresh an unauthenticated visitor always sees home.
    _saveRoute(screen, data) {
        if (screen === 'auth') {
            try { localStorage.removeItem('tmc_route'); } catch (e) {}
            return;
        }
        try {
            localStorage.setItem('tmc_route', JSON.stringify({ screen, data: data !== undefined ? data : null }));
        } catch (e) {}
    },

    _loadRoute() {
        try {
            const raw = localStorage.getItem('tmc_route');
            return raw ? JSON.parse(raw) : null;
        } catch (e) { return null; }
    },

    // ==================== BOOST COUNTDOWNS ====================
    // Sync active-boost countdowns using the absolute wall-clock expiry
    // timestamp (expires_at_real) returned by the server.  This allows the
    // client to compute accurate remaining time at any point, including after
    // a page refresh or idle/reconnect period.

    _getBoostKey(boost) {
        return boost.id !== undefined ? boost.id : boost.boost_id;
    },

    syncBoostCountdowns() {
        const p = this.state.player;
        if (!p || !p.active_boosts) { this.state.boostCountdowns = {}; return; }
        const allBoosts = [...(p.active_boosts.self || []), ...(p.active_boosts.global || [])];
        allBoosts.forEach(b => {
            const key = this._getBoostKey(b);
            if (key === undefined || key === null) return;
            this.state.boostCountdowns[key] = {
                expiresAtReal: parseInt(b.expires_at_real) || 0
            };
        });
    },

    // Compute live remaining seconds for a boost using the authoritative
    // wall-clock expiry timestamp.  Returns 0 (never negative) once expired.
    getLiveBoostRemainingSeconds(boost) {
        const key = this._getBoostKey(boost);
        const entry = key !== undefined ? this.state.boostCountdowns[key] : null;
        const expiresAtReal = entry ? entry.expiresAtReal
            : (parseInt(boost.expires_at_real) || 0);
        if (!expiresAtReal) return 0;
        return Math.max(0, expiresAtReal - Math.floor(Date.now() / 1000));
    },

    _formatBoostTimeLeft(remainingSeconds) {
        return remainingSeconds > 0 ? this.formatSecondsRemaining(remainingSeconds) : 'Expiring\u2026';
    },

    // Called every second by the realtime interval to update boost remaining-
    // time labels without a full re-render.
    _tickBoostCountdowns() {
        const p = this.state.player;
        if (!p || !p.active_boosts) return;
        const allBoosts = [...(p.active_boosts.self || []), ...(p.active_boosts.global || [])];
        allBoosts.forEach(b => {
            const key = this._getBoostKey(b);
            const el = document.querySelector(`.active-boost-item[data-boost-id="${key}"] .ab-time`);
            if (!el) return;
            el.textContent = this._formatBoostTimeLeft(this.getLiveBoostRemainingSeconds(b));
        });
    },

    // ==================== RENDER: USER AREA ====================
    renderUserArea() {
        const area = document.getElementById('user-area');
        if (this.state.player) {
            area.innerHTML = `
                <div class="user-info">
                    <span class="user-global-stars" title="Global Stars">&#11088; ${this.formatNumber(this.state.player.global_stars)}</span>
                    <span class="user-handle" onclick="TMC.navigate('profile', ${this.state.player.player_id})">${this.escapeHtml(this.state.player.handle)}</span>
                    <button class="btn btn-sm btn-outline" onclick="TMC.logout()">Logout</button>
                </div>
            `;
        } else {
            area.innerHTML = `
                <button class="btn btn-primary btn-sm" onclick="TMC.navigate('auth')">Login / Register</button>
            `;
        }
    },

    // ==================== RENDER: HOME ====================
    renderHome() {
        const cta = document.getElementById('hero-cta');
        if (this.state.player) {
            if (this.state.player.joined_season_id) {
                cta.innerHTML = `
                    <button class="btn btn-primary btn-lg" onclick="TMC.navigate('season-detail', ${this.state.player.joined_season_id})">
                        Go to Your Season
                    </button>
                `;
            } else {
                cta.innerHTML = `
                    <button class="btn btn-primary btn-lg" onclick="TMC.navigate('seasons')">
                        Browse Seasons
                    </button>
                `;
            }
        } else {
            cta.innerHTML = `
                <button class="btn btn-primary btn-lg" onclick="TMC.navigate('auth')">
                    Get Started
                </button>
            `;
        }
    },

    // ==================== RENDER: HUD ====================
    updateHUD() {
        const hud = document.getElementById('player-hud');
        const p = this.state.player;
        if (!p || !p.player_id || !p.participation) {
            hud.style.display = 'none';
            return;
        }
        hud.style.display = '';
        document.getElementById('hud-coins').textContent = this.formatNumber(p.participation.coins);
        document.getElementById('hud-seasonal-stars').textContent = this.formatNumber(p.participation.seasonal_stars);
        const totalSigils = p.participation.sigils.reduce((a, b) => a + b, 0);
        document.getElementById('hud-sigils').textContent = totalSigils;
        document.getElementById('hud-global-stars').textContent = this.formatNumber(p.global_stars);

        // Boosts count and modifier
        const boosts = p.active_boosts || { self: [], global: [], total_modifier_percent: 0 };
        const boostCount = (boosts.self || []).length + (boosts.global || []).length;
        const boostEl = document.getElementById('hud-boosts');
        if (boostEl) {
            if (boostCount > 0) {
                boostEl.textContent = `${boostCount} (+${boosts.total_modifier_percent}%)`;
                boostEl.className = 'hud-value boost-active';
            } else {
                boostEl.textContent = '0';
                boostEl.className = 'hud-value';
            }
        }

        // Check for new sigil drops and show notification
        this.checkSigilDropNotifications(p);
    },

    tickRealtimeViews() {
        if (this.state.currentScreen === 'seasons') {
            const timerValues = document.querySelectorAll('.season-timer-value');
            timerValues.forEach((el) => {
                const seasonId = el.getAttribute('data-season-id');
                const season = this.state.seasons.find(s => s.season_id == seasonId);
                if (season) el.textContent = this.getSeasonTimerText(season);
            });
        }

        if (this.state.currentScreen === 'season-detail' && this.state.currentSeason) {
            const season = this.state.seasons.find(s => s.season_id == this.state.currentSeason);
            const timerValue = document.querySelector('.timer-value');
            if (season && timerValue) {
                timerValue.textContent = this.getSeasonTimerText(season);
            }
            this._tickBoostCountdowns();
        }
    },

    syncSeasonCountdowns(seasons) {
        if (!Array.isArray(seasons)) return;
        const syncedAtMs = Date.now();
        seasons.forEach((season) => {
            if (!season || season.season_id === undefined || season.season_id === null) return;
            if (season.time_remaining_real_seconds === undefined || season.time_remaining_real_seconds === null) return;
            const remainingSeconds = Math.max(0, parseInt(season.time_remaining_real_seconds, 10) || 0);
            this.state.seasonCountdowns[season.season_id] = {
                remainingSeconds,
                syncedAtMs
            };
        });
    },

    getLiveSeasonSeconds(season) {
        if (!season || season.season_id === undefined || season.season_id === null) return null;
        const countdown = this.state.seasonCountdowns[season.season_id];
        if (!countdown) return null;
        const elapsedSeconds = Math.floor((Date.now() - countdown.syncedAtMs) / 1000);
        return Math.max(0, countdown.remainingSeconds - elapsedSeconds);
    },

    formatSecondsRemaining(seconds) {
        const total = Math.max(0, parseInt(seconds, 10) || 0);
        if (total <= 0) return 'Ended';

        const days = Math.floor(total / 86400);
        const hours = Math.floor((total % 86400) / 3600);
        const minutes = Math.floor((total % 3600) / 60);
        const secs = total % 60;

        if (days > 0) return `${days}d ${hours}h ${minutes}m`;
        if (hours > 0) return `${hours}h ${minutes}m ${secs}s`;
        if (minutes > 0) return `${minutes}m ${secs}s`;
        return `${secs}s`;
    },

    getSeasonCountdownMode(season) {
        if (season && season.countdown_mode) return season.countdown_mode;
        const status = season && (season.computed_status || season.status);
        if (status === 'Scheduled') return 'scheduled';
        if (status === 'Active' || status === 'Blackout') return 'running';
        return 'ended';
    },

    getSeasonCardTimerLabel(season) {
        const mode = this.getSeasonCountdownMode(season);
        if (mode === 'scheduled') return 'Begins in';
        if (mode === 'running') return 'Time Left';
        return 'Ended';
    },

    getSeasonDetailTimerLabel(season) {
        const mode = this.getSeasonCountdownMode(season);
        if (mode === 'scheduled') return 'Begins in';
        if (mode === 'running') return 'Time Remaining';
        return 'Ended';
    },

    getSeasonTimerText(season) {
        const liveSeconds = this.getLiveSeasonSeconds(season);
        if (liveSeconds !== null) return this.formatSecondsRemaining(liveSeconds);
        return season.time_remaining_formatted || 'Ended';
    },

    // Track last known drop count to detect new drops
    _lastDropCount: 0,
    checkSigilDropNotifications(p) {
        const drops = p.recent_drops || [];
        const currentCount = drops.length;
        if (this._lastDropCount > 0 && currentCount > this._lastDropCount) {
            // New drop(s) detected
            const newDrops = drops.slice(0, currentCount - this._lastDropCount);
            newDrops.forEach(d => {
                const tierNames = ['', 'Common', 'Uncommon', 'Rare', 'Epic', 'Legendary'];
                this.toast(`Sigil Drop! Tier ${d.tier} (${tierNames[d.tier]}) ${d.source === 'pity' ? '(Pity)' : ''}`, 'success');
            });
        }
        this._lastDropCount = currentCount;
    },

    checkIdleModal() {
        const modal = document.getElementById('idle-modal');
        if (this.state.player && this.state.player.idle_modal_active) {
            modal.style.display = 'flex';
        } else {
            modal.style.display = 'none';
        }
    },

    async idleAck() {
        const result = await this.api('idle_ack');
        if (result.success) {
            document.getElementById('idle-modal').style.display = 'none';
            this.toast('Welcome back! You are now Active.', 'success');
            await this.refreshGameState();
        }
    },

    // ==================== RENDER: SEASONS ====================
    renderSeasons() {
        const container = document.getElementById('seasons-list');
        if (!this.state.seasons || this.state.seasons.length === 0) {
            container.innerHTML = '<div class="empty-state"><p>No seasons available yet. Check back soon!</p></div>';
            return;
        }

        let html = '';
        for (const s of this.state.seasons) {
            const status = s.computed_status || s.status;
            const statusClass = status.toLowerCase();
            const timerLabel = this.getSeasonCardTimerLabel(s);
            const timerText = this.getSeasonTimerText(s);
            const canJoin = this.state.player && !this.state.player.joined_season_id &&
                           (status === 'Active' || status === 'Blackout');
            const isMyseason = this.state.player && this.state.player.joined_season_id == s.season_id;
            const playerCount = s.player_count || 0;

            html += `
                <div class="season-card ${statusClass} ${isMyseason ? 'my-season' : ''}" onclick="TMC.navigate('season-detail', ${s.season_id})">
                    <div class="season-card-header">
                        <span class="season-id">Season #${s.season_id}</span>
                        <span class="season-status badge badge-${statusClass}">${status}</span>
                    </div>
                    <div class="season-card-body">
                        <div class="season-stat">
                            <span class="stat-label">Players</span>
                            <span class="stat-value">${playerCount}</span>
                        </div>
                        <div class="season-stat">
                            <span class="stat-label">Star Price</span>
                            <span class="stat-value">${this.formatNumber(s.current_star_price)} coins</span>
                        </div>
                        <div class="season-stat">
                            <span class="stat-label season-timer-label" data-season-id="${s.season_id}">${timerLabel}</span>
                            <span class="stat-value season-timer-value" data-season-id="${s.season_id}">${timerText}</span>
                        </div>
                        <div class="season-stat">
                            <span class="stat-label">Coin Supply</span>
                            <span class="stat-value">${this.formatNumber(s.total_coins_supply)}</span>
                        </div>
                    </div>
                    <div class="season-card-footer">
                        ${isMyseason ? '<span class="badge badge-active">YOUR SEASON</span>' : ''}
                        ${canJoin ? '<button class="btn btn-primary btn-sm" onclick="event.stopPropagation(); TMC.joinSeason(' + s.season_id + ')">Join</button>' : ''}
                        ${status === 'Expired' ? '<span class="badge badge-expired">Completed</span>' : ''}
                    </div>
                </div>
            `;
        }
        container.innerHTML = html;
    },

    // ==================== SEASON DETAIL ====================
    updateSeasonDetailLive() {
        // Update dynamic values without re-rendering the entire season detail
        const seasonId = this.state.currentSeason;
        const season = this.state.seasons.find(s => s.season_id == seasonId);
        if (!season) return;

        // Update timer and label for all viewers (including non-participants)
        const timerLabel = document.querySelector('.timer-label');
        const timerValue = document.querySelector('.timer-value');
        if (timerLabel) timerLabel.textContent = this.getSeasonDetailTimerLabel(season);
        if (timerValue) timerValue.textContent = this.getSeasonTimerText(season);

        const p = this.state.player;
        if (!p || !p.participation) {
            this.loadSeasonLeaderboard(seasonId);
            return;
        }

        // Update economy bar values if they exist
        const econValues = document.querySelectorAll('.econ-value');
        if (econValues.length >= 3) {
            econValues[0].textContent = this.formatNumber(season.current_star_price) + ' coins';
            econValues[1].textContent = this.formatNumber(season.total_coins_supply);
            econValues[2].textContent = season.player_count || 0;
        }

        // Update coin display in purchase panel
        const panelInfos = document.querySelectorAll('.panel-info');
        panelInfos.forEach(el => {
            if (el.textContent.includes('Current price:')) {
                el.innerHTML = `Current price: <strong>${this.formatNumber(season.current_star_price)} coins</strong> per star`;
            }
            if (el.textContent.includes('Your coins:')) {
                el.innerHTML = `Your coins: <strong>${this.formatNumber(p.participation.coins)}</strong>`;
            }
        });

        // Update sigil counts
        const sigilCounts = document.querySelectorAll('.sigil-count');
        if (sigilCounts.length === 5) {
            p.participation.sigils.forEach((count, i) => {
                sigilCounts[i].textContent = count;
            });
        }

        // Update lock-in star count
        const lockInBtn = document.querySelector('.panel-lockin .btn-danger');
        if (lockInBtn) {
            lockInBtn.textContent = `Lock-In (${this.formatNumber(p.participation.seasonal_stars)} Stars)`;
        }

        // Re-render the active boosts panel with fresh data from the latest poll
        this.renderActiveBoosts();

        // Refresh leaderboard
        this.loadSeasonLeaderboard(seasonId);
    },

    async loadSeasonDetail(seasonId) {
        const detail = await this.api('season_detail', { season_id: seasonId });
        if (detail.error) {
            document.getElementById('season-detail-content').innerHTML =
                `<div class="error-state"><p>${detail.error}</p></div>`;
            return;
        }
        this.renderSeasonDetail(seasonId, detail);
    },

    renderSeasonDetail(seasonId, detail) {
        if (!detail) {
            // Use cached data from game state
            detail = this.state.seasons.find(s => s.season_id == seasonId);
            if (!detail) return;
        }

        const p = this.state.player;
        const isParticipating = p && p.joined_season_id == seasonId;
        const status = detail.computed_status || detail.status;
        const isBlackout = status === 'Blackout';
        const isExpired = status === 'Expired';
        const timerLabel = this.getSeasonDetailTimerLabel(detail);

        let html = `
            <div class="season-header">
                <div class="season-header-left">
                    <h2>Season #${seasonId}</h2>
                    <span class="badge badge-${status.toLowerCase()} badge-lg">${status}</span>
                    ${isBlackout ? '<span class="badge badge-warning badge-lg">BLACKOUT - No new actions</span>' : ''}
                </div>
                <div class="season-header-right">
                    <div class="season-timer">
                        <span class="timer-label">${timerLabel}</span>
                        <span class="timer-value">${this.getSeasonTimerText(detail)}</span>
                    </div>
                </div>
            </div>

            <div class="season-economy-bar">
                <div class="economy-stat">
                    <span class="econ-label">Star Price</span>
                    <span class="econ-value">${this.formatNumber(detail.current_star_price)} coins</span>
                </div>
                <div class="economy-stat">
                    <span class="econ-label">Coin Supply</span>
                    <span class="econ-value">${this.formatNumber(detail.total_coins_supply)}</span>
                </div>
                <div class="economy-stat">
                    <span class="econ-label">Players</span>
                    <span class="econ-value">${detail.player_count || 0}</span>
                </div>
            </div>
        `;

        // Action panel (if participating)
        if (isParticipating && !isExpired) {
            const part = p.participation;
            html += `
                <div class="action-panels">
                    <!-- Purchase Stars Panel -->
                    <div class="action-panel">
                        <h3>Purchase Seasonal Stars</h3>
                        <p class="panel-info">Current price: <strong>${this.formatNumber(detail.current_star_price)} coins</strong> per star</p>
                        <p class="panel-info">Your coins: <strong>${this.formatNumber(part.coins)}</strong></p>
                        <div class="action-row">
                            <input type="number" id="purchase-stars" min="1" placeholder="Star quantity" class="input-field" oninput="TMC.updatePurchaseEstimate()">
                            <button id="purchase-stars-btn" class="btn btn-primary" onclick="TMC.purchaseStars()" ${isBlackout ? 'disabled' : ''}>Buy Stars</button>
                            <button id="purchase-max-btn" class="btn btn-outline" onclick="TMC.buyMaxStars()" ${isBlackout ? 'disabled' : ''}>Buy Max</button>
                        </div>
                        <p id="purchase-estimate" class="panel-info">Enter a star quantity to see estimated coin cost.</p>
                    </div>

                    <!-- Sigils Panel -->
                    <div class="action-panel">
                        <h3>Your Sigils</h3>
                        <div class="sigil-display">
                            ${part.sigils.map((count, i) => `
                                <div class="sigil-item tier-${i+1}">
                                    <span class="sigil-tier">T${i+1}</span>
                                    <span class="sigil-count">${count}</span>
                                </div>
                            `).join('')}
                        </div>
                    </div>

                    <!-- Vault Panel -->
                    <div class="action-panel">
                        <h3>Sigil Vault</h3>
                        <p class="panel-info">Purchase Sigils with Seasonal Stars</p>
                        <div class="vault-grid">
                            ${(detail.vault || []).map(v => `
                                <div class="vault-item tier-${v.tier}">
                                    <span class="vault-tier">Tier ${v.tier}</span>
                                    <span class="vault-remaining">${v.remaining_supply}/${v.initial_supply} left</span>
                                    <span class="vault-cost">${v.current_cost_stars} stars</span>
                                    <button class="btn btn-sm btn-primary" 
                                        onclick="TMC.purchaseVault(${v.tier})"
                                        ${v.remaining_supply <= 0 || isBlackout ? 'disabled' : ''}>
                                        ${v.remaining_supply <= 0 ? 'Sold Out' : 'Buy'}
                                    </button>
                                </div>
                            `).join('')}
                        </div>
                    </div>

                    <!-- Boosts Panel -->
                    <div class="action-panel panel-boosts">
                        <h3>Boosts</h3>
                        <p class="panel-info">Consume Sigils to activate temporary UBI modifiers.</p>
                        <div id="active-boosts-display"></div>
                        <div class="boost-catalog-actions">
                            <button id="boost-catalog-toggle" class="btn btn-outline btn-sm" onclick="TMC.toggleBoostCatalog()">Show Boost Catalog</button>
                        </div>
                        <div id="boost-catalog-grid" class="boost-catalog-grid boost-catalog-collapsed"></div>
                    </div>

                    <!-- Lock-In Panel -->
                    <div class="action-panel panel-lockin">
                        <h3>Lock-In</h3>
                        <p class="panel-info">Exit the season early and convert <strong>${this.formatNumber(part.seasonal_stars)} Seasonal Stars</strong> to Global Stars.</p>
                        <p class="panel-warning">This will destroy all your Coins, Sigils, and Boosts. This action is irreversible.</p>
                        <button class="btn btn-danger btn-lg" onclick="TMC.confirmLockIn()" 
                            ${!p.can_lock_in || isBlackout ? 'disabled' : ''}>
                            Lock-In (${this.formatNumber(part.seasonal_stars)} Stars)
                        </button>
                    </div>

                    <!-- Trade Panel -->
                    <div class="action-panel">
                        <h3>Trading</h3>
                        <p class="panel-info">Trade Coins and Sigils with other players in this season.</p>
                        <button class="btn btn-primary" onclick="TMC.navigate('trade', ${seasonId})" ${isBlackout ? 'disabled' : ''}>
                            Open Trade Center
                        </button>
                        <div id="my-trades-list" class="trades-list"></div>
                    </div>
                </div>
            `;
        } else if (!isParticipating && !isExpired && this.state.player) {
            html += `
                <div class="join-panel">
                    <h3>Join This Season</h3>
                    <p>Start earning Coins through UBI and compete for the top of the leaderboard.</p>
                    ${this.state.player.joined_season_id ? 
                        '<p class="panel-warning">You are already participating in another season. Lock-In or wait for it to end first.</p>' :
                        `<button class="btn btn-primary btn-lg" onclick="TMC.joinSeason(${seasonId})">Join Season #${seasonId}</button>`
                    }
                </div>
            `;
        }

        // Leaderboard
        html += `
            <div class="season-leaderboard">
                <h3>Season Leaderboard</h3>
                <table class="leaderboard-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Player</th>
                            <th>Seasonal Stars</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="season-lb-body">
                    </tbody>
                </table>
                <div id="season-lb-empty" class="empty-state" style="display:none;">
                    <p>No ranked players yet.</p>
                </div>
            </div>
        `;

        document.getElementById('season-detail-content').innerHTML = html;

        // Load leaderboard
        this.loadSeasonLeaderboard(seasonId);

        // Load trades if participating
        if (isParticipating) this.loadMyTrades();

        this.updatePurchaseEstimate();
        this.renderActiveBoosts();
        this.renderBoostCatalogToggle();
        if (this._boostCatalog) this.renderBoostCatalog();
    },

    async loadSeasonLeaderboard(seasonId) {
        const lb = await this.api('leaderboard', { season_id: seasonId });
        const body = document.getElementById('season-lb-body');
        const empty = document.getElementById('season-lb-empty');
        if (!body) return;

        if (!lb || lb.length === 0 || lb.error) {
            body.innerHTML = '';
            if (empty) empty.style.display = '';
            return;
        }
        if (empty) empty.style.display = 'none';

        body.innerHTML = lb.map((entry, i) => {
            const rank = entry.final_rank || (i + 1);
            const isLockedIn = entry.lock_in_effect_tick !== null;
            const isMe = this.state.player && entry.player_id == this.state.player.player_id;
            let statusBadge = this.renderPlayerStatusBadge(entry);
            if (entry.badge_awarded) {
                const badgeEmoji = { first: '&#129351;', second: '&#129352;', third: '&#129353;' };
                statusBadge += ` <span class="badge badge-${entry.badge_awarded}">${badgeEmoji[entry.badge_awarded] || ''}</span>`;
            } else if (isLockedIn) {
                statusBadge += ' <span class="badge badge-lockin">Locked In</span>';
            } else if (entry.end_membership) {
                statusBadge += ' <span class="badge badge-ended">End-Finisher</span>';
            }

            return `
                <tr class="${isMe ? 'my-row' : ''} ${rank <= 3 ? 'top-three' : ''}">
                    <td class="rank-cell">${rank <= 3 ? ['&#129351;', '&#129352;', '&#129353;'][rank-1] : rank}</td>
                    <td class="player-cell">
                        <span class="player-link" onclick="TMC.navigate('profile', ${entry.player_id})">${this.escapeHtml(entry.handle)}</span>
                    </td>
                    <td class="stars-cell">${this.formatNumber(entry.seasonal_stars)}</td>
                    <td class="status-cell">${statusBadge}</td>
                </tr>
            `;
        }).join('');
    },

    // ==================== ACTIONS ====================
    async joinSeason(seasonId) {
        if (!this.state.player) {
            this.navigate('auth');
            return;
        }
        const result = await this.api('season_join', { season_id: seasonId });
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        this.toast('Joined the season! Start earning Coins.', 'success');
        await this.refreshGameState();
        this.navigate('season-detail', seasonId);
    },

    async purchaseStars() {
        const input = document.getElementById('purchase-stars');
        const starsRequested = parseInt(input.value);
        if (!starsRequested || starsRequested <= 0) {
            this.toast('Enter a valid star quantity.', 'error');
            return;
        }
        const result = await this.api('purchase_stars', { stars_requested: starsRequested });
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        this.toast(`Purchased ${this.formatNumber(result.stars_purchased)} stars for ${this.formatNumber(result.coins_spent)} coins!`, 'success');
        input.value = '';
        this.updatePurchaseEstimate();
        await this.refreshGameState();
    },

    async buyMaxStars() {
        const input = document.getElementById('purchase-stars');
        if (!input) return;

        const season = this.state.seasons.find(s => s.season_id == this.state.currentSeason);
        const starPrice = season ? parseInt(season.current_star_price, 10) : 0;
        const coinsOwned = this.state.player && this.state.player.participation ? this.state.player.participation.coins : 0;

        if (!starPrice || starPrice <= 0) {
            this.toast('Cannot calculate max stars right now.', 'error');
            return;
        }

        const maxStars = Math.floor(coinsOwned / starPrice);
        if (maxStars < 1) {
            this.toast('Not enough coins to buy 1 star at current price.', 'error');
            return;
        }

        input.value = maxStars;
        this.updatePurchaseEstimate();
        await this.purchaseStars();
    },

    updatePurchaseEstimate() {
        const input = document.getElementById('purchase-stars');
        const estimateEl = document.getElementById('purchase-estimate');
        const buyButton = document.getElementById('purchase-stars-btn');
        const buyMaxButton = document.getElementById('purchase-max-btn');
        if (!input || !estimateEl) return;

        const starsRequested = parseInt(input.value, 10);
        const season = this.state.seasons.find(s => s.season_id == this.state.currentSeason);
        const status = season && season.computed_status ? season.computed_status : (season ? season.status : null);
        const isBlackout = status === 'Blackout';

        if (buyButton) {
            buyButton.disabled = isBlackout;
        }
        if (buyMaxButton) {
            buyMaxButton.disabled = isBlackout;
        }

        if (!starsRequested || starsRequested <= 0) {
            estimateEl.classList.remove('panel-warning');
            estimateEl.textContent = 'Enter a star quantity to see estimated coin cost.';
            return;
        }

        const starPrice = season ? parseInt(season.current_star_price, 10) : 0;
        if (!starPrice || starPrice <= 0) {
            if (buyButton && !isBlackout) buyButton.disabled = true;
            if (buyMaxButton && !isBlackout) buyMaxButton.disabled = true;
            estimateEl.classList.remove('panel-warning');
            estimateEl.textContent = 'Coin cost estimate unavailable right now.';
            return;
        }

        const coinsNeeded = starsRequested * starPrice;
        const coinsOwned = this.state.player && this.state.player.participation ? this.state.player.participation.coins : null;
        const maxStars = coinsOwned !== null ? Math.floor(coinsOwned / starPrice) : 0;

        if (buyMaxButton && !isBlackout) {
            buyMaxButton.disabled = maxStars < 1;
        }

        if (coinsOwned !== null && coinsNeeded > coinsOwned) {
            if (buyButton && !isBlackout) buyButton.disabled = true;
            estimateEl.classList.add('panel-warning');
            estimateEl.textContent = `Estimated cost: ${this.formatNumber(coinsNeeded)} coins (${this.formatNumber(starPrice)} each). You need ${this.formatNumber(coinsNeeded - coinsOwned)} more coins.`;
            return;
        }

        if (buyButton && !isBlackout) buyButton.disabled = false;
        estimateEl.classList.remove('panel-warning');
        estimateEl.textContent = `Estimated cost: ${this.formatNumber(coinsNeeded)} coins (${this.formatNumber(starPrice)} each).`;
    },

    async purchaseVault(tier) {
        const result = await this.api('purchase_vault', { tier });
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        this.toast(`Purchased Tier ${tier} Sigil for ${result.cost_stars} stars!`, 'success');
        await this.refreshGameState();
        if (this.state.currentSeason) this.loadSeasonDetail(this.state.currentSeason);
    },

    // ==================== BOOSTS ====================
    _boostCatalog: null,
    _boostCatalogCollapsed: true,

    async loadBoostCatalog() {
        const catalog = await this.api('boost_catalog');
        if (catalog.error) {
            this.toast(catalog.error, 'error');
            return;
        }
        this._boostCatalog = catalog;
        this._boostCatalogCollapsed = false;
        this.renderBoostCatalogToggle();
        this.renderBoostCatalog();
        this.renderActiveBoosts();
    },

    toggleBoostCatalog() {
        if (!this._boostCatalog) {
            this.loadBoostCatalog();
            return;
        }
        this._boostCatalogCollapsed = !this._boostCatalogCollapsed;
        this.renderBoostCatalogToggle();
        this.renderBoostCatalog();
    },

    renderBoostCatalogToggle() {
        const toggleBtn = document.getElementById('boost-catalog-toggle');
        if (!toggleBtn) return;

        if (!this._boostCatalog || this._boostCatalog.length === 0) {
            toggleBtn.textContent = 'Show Boost Catalog';
            return;
        }

        toggleBtn.textContent = this._boostCatalogCollapsed ? 'Show Boost Catalog' : 'Hide Boost Catalog';
    },

    renderBoostCatalog() {
        const grid = document.getElementById('boost-catalog-grid');
        if (!grid || !this._boostCatalog) return;

        grid.classList.toggle('boost-catalog-collapsed', this._boostCatalogCollapsed);
        if (this._boostCatalogCollapsed) {
            grid.innerHTML = '';
            return;
        }

        const p = this.state.player;
        const part = p ? p.participation : null;
        const tierNames = ['', 'Common', 'Uncommon', 'Rare', 'Epic', 'Legendary'];
        const tierIcons = ['', '&#9672;', '&#9670;', '&#9733;', '&#10038;', '&#9830;'];

        grid.innerHTML = `<h4>Available Boosts</h4>` + this._boostCatalog.map(b => {
            const tier = parseInt(b.tier_required);
            const hasSigil = part && part.sigils[tier - 1] >= parseInt(b.sigil_cost);
            const modPercent = (parseInt(b.modifier_fp) / 10000).toFixed(1);
            const durationTicks = parseInt(b.duration_ticks);
            const durationLabel = this.formatBoostDuration(durationTicks, 'short');
            const scopeLabel = b.scope === 'GLOBAL' ? 'All Players' : 'Self Only';
            const scopeClass = b.scope === 'GLOBAL' ? 'scope-global' : 'scope-self';
            const description = this.getBoostDescription(b);
            const displayName = this.getBoostDisplayName(b.name);
            const displayIcon = this.getBoostDisplayIcon(b.icon, tierIcons[tier]);

            return `
                <div class="boost-card tier-${tier} ${hasSigil ? '' : 'boost-locked'}">
                    <div class="boost-card-header">
                        <span class="boost-icon">${displayIcon}</span>
                        <span class="boost-name">${this.escapeHtml(displayName)}</span>
                    </div>
                    ${description ? `<p class="boost-desc">${this.escapeHtml(description)}</p>` : ''}
                    <div class="boost-stats">
                        <span class="boost-modifier">+${modPercent}% UBI</span>
                        <span class="boost-duration">${durationLabel}</span>
                        <span class="boost-scope ${scopeClass}">${scopeLabel}</span>
                    </div>
                    <div class="boost-cost">
                        <span>Cost: ${b.sigil_cost} Tier ${tier} Sigil${parseInt(b.sigil_cost) > 1 ? 's' : ''}</span>
                        <span class="boost-have">(You have: ${part ? part.sigils[tier-1] : 0})</span>
                    </div>
                    <button class="btn btn-sm ${hasSigil ? 'btn-primary' : 'btn-outline'}" 
                        onclick="TMC.activateBoost(${b.boost_id})" 
                        ${!hasSigil ? 'disabled title="Not enough Sigils"' : ''}>
                        Activate
                    </button>
                </div>
            `;
        }).join('');
    },

    getBoostDisplayName(name) {
        const raw = String(name || '').trim();
        if (!raw) return '';
        const withoutLowercaseWords = raw
            .split(/\s+/)
            .filter(part => !/^[a-z][a-z0-9_\-]*$/.test(part));

        const normalized = withoutLowercaseWords.length > 0
            ? withoutLowercaseWords.join(' ')
            : raw;

        const parts = normalized.split(/\s+/);
        if (parts.length <= 1) return normalized;
        return parts.slice(1).join(' ');
    },

    getBoostDisplayIcon(icon, fallbackIcon) {
        const raw = String(icon || '').trim();
        if (!raw) return fallbackIcon || '';

        // Hide plain lowercase icon labels so titles don't show duplicated words like "trickle Trickle".
        if (/^[a-z][a-z0-9_\-]*$/.test(raw)) return fallbackIcon || '';

        return raw;
    },

    formatBoostDuration(durationTicks, style = 'short') {
        const ticks = Math.max(0, parseInt(durationTicks, 10) || 0);
        const minutes = ticks;

        if (minutes >= 60 && minutes % 60 === 0) {
            const hours = minutes / 60;
            if (style === 'long') return `${hours} ${hours === 1 ? 'hour' : 'hours'}`;
            return `${hours} ${hours === 1 ? 'hr' : 'hrs'}`;
        }

        if (style === 'long') return `${minutes} ${minutes === 1 ? 'minute' : 'minutes'}`;
        return `${minutes} min`;
    },

    getBoostDescription(boost) {
        if (!boost) return '';
        const name = String(boost.name || '').trim();
        const raw = String(boost.description || '').trim();
        if (!raw) return '';

        const normalizedName = name.toLowerCase();
        if (raw.toLowerCase() === normalizedName) return '';

        const escapedName = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const prefixed = new RegExp(`^${escapedName}\\s*[:\\-]\\s*`, 'i');
        let cleaned = raw.replace(prefixed, '').trim();

        const durationLabel = this.formatBoostDuration(boost.duration_ticks, 'long');
        cleaned = cleaned.replace(/for\s+\d+\s+(?:hour|hours|minute|minutes)(?=\.|,|$)/i, `for ${durationLabel}`);

        if (!cleaned || cleaned.toLowerCase() === normalizedName) return '';
        return cleaned;
    },

    renderActiveBoosts() {
        const container = document.getElementById('active-boosts-display');
        if (!container) return;

        const p = this.state.player;
        const boosts = p ? p.active_boosts : null;
        if (!boosts || ((boosts.self || []).length === 0 && (boosts.global || []).length === 0)) {
            container.innerHTML = '<p class="empty-text">No active boosts.</p>';
            return;
        }

        let html = `<div class="active-boosts-summary">
            <span class="boost-total-mod">Total UBI Modifier: <strong>+${boosts.total_modifier_percent}%</strong></span>
        </div>`;

        const renderBoost = (b, type) => {
            const remainingSeconds = this.getLiveBoostRemainingSeconds(b);
            const modPercent = (parseInt(b.modifier_fp) / 10000).toFixed(1);
            const timeLeft = this._formatBoostTimeLeft(remainingSeconds);
            const displayName = this.getBoostDisplayName(b.name);
            const boostKey = this._getBoostKey(b);
            return `<div class="active-boost-item ${type}" data-boost-id="${boostKey}">
                <span class="ab-name">${this.escapeHtml(displayName)}</span>
                <span class="ab-mod">+${modPercent}%</span>
                <span class="ab-time">${timeLeft}</span>
                ${type === 'global' ? `<span class="ab-by">by ${this.escapeHtml(b.activator_handle || '')}</span>` : ''}
            </div>`;
        };

        if (boosts.self.length > 0) {
            html += '<div class="ab-section"><h4>Your Boosts</h4>' + boosts.self.map(b => renderBoost(b, 'self')).join('') + '</div>';
        }
        if (boosts.global.length > 0) {
            html += '<div class="ab-section"><h4>Season-Wide Boosts</h4>' + boosts.global.map(b => renderBoost(b, 'global')).join('') + '</div>';
        }

        container.innerHTML = html;
    },

    async activateBoost(boostId) {
        const boost = this._boostCatalog ? this._boostCatalog.find(b => b.boost_id == boostId) : null;
        const name = boost ? this.getBoostDisplayName(boost.name) : `Boost #${boostId}`;
        if (!confirm(`Activate ${name}?\n\nThis will consume ${boost ? boost.sigil_cost : 1} Tier ${boost ? boost.tier_required : '?'} Sigil(s).`)) {
            return;
        }

        const result = await this.api('purchase_boost', { boost_id: boostId });
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        this.toast(result.message, 'success');
        await this.refreshGameState();
        this.loadBoostCatalog();
    },

    confirmLockIn() {
        const stars = this.state.player.participation.seasonal_stars;
        if (!confirm(`Are you sure you want to Lock-In?\n\nThis will:\n- Convert ${this.formatNumber(stars)} Seasonal Stars to Global Stars\n- Destroy ALL your Coins, Sigils, and Boosts\n- Remove you from this season\n\nThis action is IRREVERSIBLE.`)) {
            return;
        }
        this.lockIn();
    },

    async lockIn() {
        const result = await this.api('lock_in');
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        this.toast(result.message, 'success');
        await this.refreshGameState();
        this.navigate('home');
    },

    // ==================== GLOBAL LEADERBOARD ====================
    getActiveJoinedSeason() {
        if (!this.state.player || !this.state.player.joined_season_id) return null;
        const joinedSeasonId = this.state.player.joined_season_id;
        const season = this.state.seasons.find(s => s.season_id == joinedSeasonId);
        if (!season) return null;

        const status = season.computed_status || season.status;
        if (status === 'Active' || status === 'Blackout') return season;
        return null;
    },

    getPlayerStatus(entry) {
        if (!entry.online_current) return 'Offline';
        return entry.activity_state || 'Offline';
    },

    renderPlayerStatusBadge(entry) {
        const status = this.getPlayerStatus(entry);
        return `<span class="badge badge-${status.toLowerCase()}">${status}</span>`;
    },

    setLeaderboardMeta(title, subtitle) {
        const titleEl = document.getElementById('global-lb-title');
        const subtitleEl = document.getElementById('global-lb-subtitle');
        if (titleEl) titleEl.textContent = title;
        if (subtitleEl) subtitleEl.textContent = subtitle;
    },

    setLeaderboardHeader(columns) {
        const theadRow = document.querySelector('#global-lb-content thead tr');
        if (!theadRow) return;
        theadRow.innerHTML = columns.map((c) => `<th>${c}</th>`).join('');
    },

    renderSeasonLeaderboardRows(entries) {
        return entries.map((entry, i) => {
            const rank = entry.final_rank || (i + 1);
            const isLockedIn = entry.lock_in_effect_tick !== null;
            const isMe = this.state.player && entry.player_id == this.state.player.player_id;
            let statusBadge = this.renderPlayerStatusBadge(entry);
            if (entry.badge_awarded) {
                const badgeEmoji = { first: '&#129351;', second: '&#129352;', third: '&#129353;' };
                statusBadge += ` <span class="badge badge-${entry.badge_awarded}">${badgeEmoji[entry.badge_awarded] || ''}</span>`;
            } else if (isLockedIn) {
                statusBadge += ' <span class="badge badge-lockin">Locked In</span>';
            } else if (entry.end_membership) {
                statusBadge += ' <span class="badge badge-ended">End-Finisher</span>';
            }

            return `
                <tr class="${isMe ? 'my-row' : ''} ${rank <= 3 ? 'top-three' : ''}">
                    <td class="rank-cell">${rank <= 3 ? ['&#129351;', '&#129352;', '&#129353;'][rank-1] : rank}</td>
                    <td class="player-cell">
                        <span class="player-link" onclick="TMC.navigate('profile', ${entry.player_id})">${this.escapeHtml(entry.handle)}</span>
                    </td>
                    <td class="stars-cell">${this.formatNumber(entry.seasonal_stars)}</td>
                    <td class="status-cell">${statusBadge}</td>
                </tr>
            `;
        }).join('');
    },

    async loadGlobalLeaderboard() {
        const activeSeason = this.getActiveJoinedSeason();
        const body = document.getElementById('global-lb-body');
        const empty = document.getElementById('global-lb-empty');

        if (activeSeason) {
            this.setLeaderboardMeta(
                `Season #${activeSeason.season_id} Leaderboard`,
                'Ranked by Seasonal Stars in your active season.'
            );
            this.setLeaderboardHeader(['Rank', 'Player', 'Seasonal Stars', 'Status']);

            const lb = await this.api('leaderboard', { season_id: activeSeason.season_id });
            if (!lb || lb.length === 0 || lb.error) {
                body.innerHTML = '';
                empty.style.display = '';
                empty.innerHTML = '<p>No ranked players yet in this season.</p>';
                return;
            }

            empty.style.display = 'none';
            body.innerHTML = this.renderSeasonLeaderboardRows(lb);
            return;
        }

        this.setLeaderboardMeta(
            'Global Leaderboard',
            'Ranked by Global Stars earned this yearly cycle.'
        );
        this.setLeaderboardHeader(['Rank', 'Player', 'Global Stars', 'Status']);
        const lb = await this.api('global_leaderboard');

        if (!lb || lb.length === 0 || lb.error) {
            body.innerHTML = '';
            empty.style.display = '';
            empty.innerHTML = '<p>No players on the leaderboard yet. Earn Global Stars through season outcomes and Lock-In!</p>';
            return;
        }
        empty.style.display = 'none';

        body.innerHTML = lb.map((entry, i) => {
            const rank = i + 1;
            const isMe = this.state.player && entry.player_id == this.state.player.player_id;
            return `
                <tr class="${isMe ? 'my-row' : ''} ${rank <= 3 ? 'top-three' : ''}">
                    <td class="rank-cell">${rank <= 3 ? ['&#129351;', '&#129352;', '&#129353;'][rank-1] : rank}</td>
                    <td class="player-cell">
                        <span class="player-link" onclick="TMC.navigate('profile', ${entry.player_id})">${this.escapeHtml(entry.handle)}</span>
                    </td>
                    <td class="stars-cell">${this.formatNumber(entry.global_stars)}</td>
                    <td class="status-cell">${this.renderPlayerStatusBadge(entry)}</td>
                </tr>
            `;
        }).join('');
    },

    // ==================== SHOP ====================
    async loadShop() {
        const catalog = await this.api('cosmetic_catalog');
        if (catalog.error) return;
        this.state.cosmetics = catalog;

        if (this.state.player) {
            const mine = await this.api('my_cosmetics');
            if (!mine.error) this.state.myCosmetics = mine;
        }

        this.renderShop();
    },

    filterShop(category) {
        this.state.shopFilter = category;
        document.querySelectorAll('.shop-tab').forEach(t => t.classList.remove('active'));
        event.target.classList.add('active');
        this.renderShop();
    },

    renderShop() {
        const grid = document.getElementById('shop-grid');
        let items = this.state.cosmetics;
        if (this.state.shopFilter !== 'all') {
            items = items.filter(c => c.category === this.state.shopFilter);
        }

        const ownedIds = new Set(this.state.myCosmetics.map(c => c.cosmetic_id));

        grid.innerHTML = items.map(c => {
            const owned = ownedIds.has(c.cosmetic_id);
            const canAfford = this.state.player && this.state.player.global_stars >= c.price_global_stars;
            const categoryLabel = c.category.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase());

            return `
                <div class="shop-item ${owned ? 'owned' : ''} ${c.css_class || ''}">
                    <div class="shop-item-header">
                        <span class="shop-item-name">${this.escapeHtml(c.name)}</span>
                        <span class="shop-item-category">${categoryLabel}</span>
                    </div>
                    <p class="shop-item-desc">${this.escapeHtml(c.description || '')}</p>
                    <div class="shop-item-footer">
                        <span class="shop-item-price">&#11088; ${c.price_global_stars}</span>
                        ${owned ? 
                            '<span class="badge badge-owned">Owned</span>' :
                            (this.state.player ? 
                                `<button class="btn btn-sm btn-primary" onclick="TMC.buyCosmetic(${c.cosmetic_id})" ${!canAfford ? 'disabled title="Not enough Global Stars"' : ''}>Buy</button>` :
                                '<span class="shop-item-login">Login to purchase</span>'
                            )
                        }
                    </div>
                </div>
            `;
        }).join('');
    },

    async buyCosmetic(cosmeticId) {
        const cosmetic = this.state.cosmetics.find(c => c.cosmetic_id == cosmeticId);
        if (!confirm(`Purchase "${cosmetic.name}" for ${cosmetic.price_global_stars} Global Stars?`)) return;

        const result = await this.api('purchase_cosmetic', { cosmetic_id: cosmeticId });
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        this.toast('Cosmetic purchased!', 'success');
        await this.refreshGameState();
        this.loadShop();
    },

    // ==================== TRADE ====================
    async renderTradeScreen(seasonId) {
        const content = document.getElementById('trade-content');
        if (!this.state.player || !this.state.player.joined_season_id) {
            content.innerHTML = '<div class="error-state"><p>You must be in a season to trade.</p></div>';
            return;
        }

        // Get players in season
        const players = await this.api('season_players', { season_id: seasonId });
        const otherPlayers = (players || []).filter(p => p.player_id != this.state.player.player_id);

        const part = this.state.player.participation;

        content.innerHTML = `
            <h2>Trade Center</h2>
            <button class="btn btn-outline btn-sm" onclick="TMC.navigate('season-detail', ${seasonId})">Back to Season</button>

            <div class="trade-form-container">
                <h3>Create New Trade</h3>
                <div class="trade-form">
                    <div class="trade-side">
                        <h4>You Offer</h4>
                        <div class="form-group">
                            <label>Coins (you have ${this.formatNumber(part.coins)})</label>
                            <input type="number" id="trade-a-coins" min="0" max="${part.coins}" value="0" class="input-field">
                        </div>
                        ${part.sigils.map((count, i) => `
                            <div class="form-group">
                                <label>Tier ${i+1} Sigils (you have ${count})</label>
                                <input type="number" id="trade-a-sigil-${i}" min="0" max="${count}" value="0" class="input-field input-sm">
                            </div>
                        `).join('')}
                    </div>
                    <div class="trade-arrow">&#8644;</div>
                    <div class="trade-side">
                        <h4>You Request</h4>
                        <div class="form-group">
                            <label>Coins</label>
                            <input type="number" id="trade-b-coins" min="0" value="0" class="input-field">
                        </div>
                        ${[1,2,3,4,5].map(t => `
                            <div class="form-group">
                                <label>Tier ${t} Sigils</label>
                                <input type="number" id="trade-b-sigil-${t-1}" min="0" value="0" class="input-field input-sm">
                            </div>
                        `).join('')}
                    </div>
                </div>
                <div class="form-group">
                    <label>Send To</label>
                    <select id="trade-target" class="input-field">
                        <option value="">Select a player...</option>
                        ${otherPlayers.map(p => `<option value="${p.player_id}">${this.escapeHtml(p.handle)} ${p.online_current ? '(online)' : ''}</option>`).join('')}
                    </select>
                </div>
                <button class="btn btn-primary btn-lg" onclick="TMC.submitTrade()">Send Trade Offer</button>
            </div>

            <div class="my-trades-section">
                <h3>Your Trades</h3>
                <div id="trade-list"></div>
            </div>
        `;

        this.loadMyTrades();
    },

    async submitTrade() {
        const targetId = document.getElementById('trade-target').value;
        if (!targetId) {
            this.toast('Select a player to trade with.', 'error');
            return;
        }

        const sideACoins = parseInt(document.getElementById('trade-a-coins').value) || 0;
        const sideASigils = [0,1,2,3,4].map(i => parseInt(document.getElementById(`trade-a-sigil-${i}`).value) || 0);
        const sideBCoins = parseInt(document.getElementById('trade-b-coins').value) || 0;
        const sideBSigils = [0,1,2,3,4].map(i => parseInt(document.getElementById(`trade-b-sigil-${i}`).value) || 0);

        const result = await this.api('trade_initiate', {
            acceptor_id: parseInt(targetId),
            side_a_coins: sideACoins,
            side_a_sigils: sideASigils,
            side_b_coins: sideBCoins,
            side_b_sigils: sideBSigils
        });

        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        this.toast(`Trade offer sent! Fee: ${this.formatNumber(result.fee)} coins.`, 'success');
        await this.refreshGameState();
        this.loadMyTrades();
    },

    async loadMyTrades() {
        const trades = await this.api('my_trades');
        const container = document.getElementById('trade-list') || document.getElementById('my-trades-list');
        if (!container || !trades || trades.error) return;

        if (trades.length === 0) {
            container.innerHTML = '<p class="empty-text">No trades yet.</p>';
            return;
        }

        container.innerHTML = trades.map(t => {
            const isInitiator = t.initiator_id == this.state.player.player_id;
            const otherHandle = isInitiator ? t.acceptor_handle : t.initiator_handle;
            const sideASigils = JSON.parse(t.side_a_sigils || '[]');
            const sideBSigils = JSON.parse(t.side_b_sigils || '[]');

            let actions = '';
            if (t.status === 'OPEN') {
                if (isInitiator) {
                    actions = `<button class="btn btn-sm btn-danger" onclick="TMC.cancelTrade(${t.trade_id})">Cancel</button>`;
                } else {
                    actions = `
                        <button class="btn btn-sm btn-primary" onclick="TMC.acceptTrade(${t.trade_id})">Accept</button>
                        <button class="btn btn-sm btn-danger" onclick="TMC.declineTrade(${t.trade_id})">Decline</button>
                    `;
                }
            }

            return `
                <div class="trade-item trade-${t.status.toLowerCase()}">
                    <div class="trade-item-header">
                        <span class="trade-with">${isInitiator ? 'To' : 'From'}: ${this.escapeHtml(otherHandle)}</span>
                        <span class="badge badge-${t.status.toLowerCase()}">${t.status}</span>
                    </div>
                    <div class="trade-item-body">
                        <div class="trade-offer">
                            <span class="trade-label">Offer:</span>
                            ${t.side_a_coins > 0 ? `<span>${this.formatNumber(t.side_a_coins)} coins</span>` : ''}
                            ${sideASigils.some(s => s > 0) ? `<span>Sigils: [${sideASigils.join(',')}]</span>` : ''}
                        </div>
                        <div class="trade-request">
                            <span class="trade-label">Request:</span>
                            ${t.side_b_coins > 0 ? `<span>${this.formatNumber(t.side_b_coins)} coins</span>` : ''}
                            ${sideBSigils.some(s => s > 0) ? `<span>Sigils: [${sideBSigils.join(',')}]</span>` : ''}
                        </div>
                        <div class="trade-fee">Fee: ${this.formatNumber(t.locked_fee_coins)} coins</div>
                    </div>
                    <div class="trade-item-actions">${actions}</div>
                </div>
            `;
        }).join('');
    },

    async acceptTrade(tradeId) {
        const result = await this.api('trade_accept', { trade_id: tradeId });
        if (result.error) { this.toast(result.error, 'error'); return; }
        this.toast('Trade completed!', 'success');
        await this.refreshGameState();
        this.loadMyTrades();
    },

    async cancelTrade(tradeId) {
        const result = await this.api('trade_cancel', { trade_id: tradeId });
        if (result.error) { this.toast(result.error, 'error'); return; }
        this.toast('Trade canceled.', 'info');
        await this.refreshGameState();
        this.loadMyTrades();
    },

    async declineTrade(tradeId) {
        const result = await this.api('trade_decline', { trade_id: tradeId });
        if (result.error) { this.toast(result.error, 'error'); return; }
        this.toast('Trade declined.', 'info');
        await this.refreshGameState();
        this.loadMyTrades();
    },

    // ==================== CHAT ====================
    initChat() {
        // Show season tab if in a season
        const seasonTab = document.getElementById('chat-season-tab');
        if (this.state.player && this.state.player.joined_season_id) {
            seasonTab.style.display = '';
        } else {
            seasonTab.style.display = 'none';
        }

        // Show input if logged in
        const inputArea = document.getElementById('chat-input-area');
        inputArea.style.display = this.state.player ? '' : 'none';

        this.loadChat();

        // Start chat polling
        if (this.state.chatPollInterval) clearInterval(this.state.chatPollInterval);
        this.state.chatPollInterval = setInterval(() => {
            if (this.state.currentScreen === 'chat') this.loadChat();
        }, 5000);
    },

    switchChat(channel) {
        this.state.currentChat = channel;
        document.querySelectorAll('.chat-tab').forEach(t => t.classList.remove('active'));
        event.target.classList.add('active');
        this.loadChat();
    },

    async loadChat() {
        const params = { channel: this.state.currentChat };
        if (this.state.currentChat === 'SEASON' && this.state.player) {
            params.season_id = this.state.player.joined_season_id;
        }
        const messages = await this.api('chat_messages', params);
        const container = document.getElementById('chat-messages');

        if (!messages || messages.error || messages.length === 0) {
            container.innerHTML = '<div class="chat-empty">No messages yet. Be the first to say something!</div>';
            return;
        }

        // Reverse to show oldest first
        const sorted = [...messages].reverse();
        container.innerHTML = sorted.map(m => {
            const isMe = this.state.player && m.sender_id == this.state.player.player_id;
            const isAdmin = m.is_admin_post;
            return `
                <div class="chat-msg ${isMe ? 'chat-msg-mine' : ''} ${isAdmin ? 'chat-msg-admin' : ''}">
                    <span class="chat-handle ${isAdmin ? 'admin-handle' : ''}" onclick="TMC.navigate('profile', ${m.sender_id})">
                        ${isAdmin ? '[ADMIN] ' : ''}${this.escapeHtml(m.handle_snapshot)}
                    </span>
                    <span class="chat-text">${this.escapeHtml(m.content)}</span>
                    <span class="chat-time">${this.formatChatTime(m.created_at)}</span>
                </div>
            `;
        }).join('');

        container.scrollTop = container.scrollHeight;
    },

    async sendChat() {
        const input = document.getElementById('chat-input');
        const content = input.value.trim();
        if (!content) return;

        const params = { channel: this.state.currentChat, content };
        if (this.state.currentChat === 'SEASON') {
            params.season_id = this.state.player.joined_season_id;
        }

        const result = await this.api('chat_send', params);
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        input.value = '';
        this.loadChat();
    },

    // ==================== PROFILE ====================
    async loadProfile(playerId) {
        const profile = await this.api('profile', { player_id: playerId });
        const content = document.getElementById('profile-content');

        if (profile.error) {
            content.innerHTML = `<div class="error-state"><p>${profile.error}</p></div>`;
            return;
        }

        if (profile.deleted) {
            content.innerHTML = `<div class="profile-card"><h2>[Removed]</h2><p>This account has been deleted.</p></div>`;
            return;
        }

        const badges = (profile.badges || []).map(b => {
            const icons = {
                seasonal_first: '&#129351;', seasonal_second: '&#129352;', seasonal_third: '&#129353;',
                yearly_top10: '&#127942;'
            };
            return `<span class="profile-badge" title="${b.badge_type}">${icons[b.badge_type] || '&#127775;'}</span>`;
        }).join('');

        const history = (profile.season_history || []).map(h => `
            <tr>
                <td>Season #${h.season_id}</td>
                <td>${this.formatNumber(h.final_seasonal_stars || h.seasonal_stars || 0)}</td>
                <td>${h.final_rank || '-'}</td>
                <td>${this.formatNumber(h.global_stars_earned || 0)}</td>
                <td>${h.lock_in_effect_tick ? 'Lock-In' : (h.end_membership ? 'End-Finisher' : '-')}</td>
            </tr>
        `).join('');

        content.innerHTML = `
            <div class="profile-card">
                <div class="profile-header">
                    <h2>${this.escapeHtml(profile.handle)}</h2>
                    ${profile.role !== 'Player' ? `<span class="badge badge-staff">${profile.role}</span>` : ''}
                </div>
                <div class="profile-stats">
                    <div class="profile-stat">
                        <span class="stat-label">Global Stars</span>
                        <span class="stat-value">&#11088; ${this.formatNumber(profile.global_stars)}</span>
                    </div>
                    <div class="profile-stat">
                        <span class="stat-label">Member Since</span>
                        <span class="stat-value">${new Date(profile.created_at).toLocaleDateString()}</span>
                    </div>
                </div>
                ${badges ? `<div class="profile-badges"><h3>Badges</h3><div class="badges-row">${badges}</div></div>` : ''}
                ${history ? `
                    <div class="profile-history">
                        <h3>Season History</h3>
                        <table class="leaderboard-table">
                            <thead><tr><th>Season</th><th>Stars</th><th>Rank</th><th>Global Earned</th><th>Exit</th></tr></thead>
                            <tbody>${history}</tbody>
                        </table>
                    </div>
                ` : ''}
            </div>
        `;
    },

    // ==================== UTILITIES ====================
    formatNumber(n) {
        if (n === null || n === undefined) return '0';
        return Number(n).toLocaleString();
    },

    escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },

    formatChatTime(dateStr) {
        const d = new Date(dateStr);
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    },

    toast(message, type = 'info') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(() => toast.classList.add('toast-show'), 10);
        setTimeout(() => {
            toast.classList.remove('toast-show');
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }
};

// Initialize on load
document.addEventListener('DOMContentLoaded', () => TMC.init());
