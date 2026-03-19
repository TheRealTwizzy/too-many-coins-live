<?php
/**
 * Too Many Coins - Authentication
 */
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';

class Auth {
    
    /**
     * Get current authenticated player from session token
     */
    public static function getCurrentPlayer() {
        $token = $_COOKIE['tmc_session'] ?? ($_SERVER['HTTP_X_SESSION_TOKEN'] ?? null);
        if (!$token) return null;
        
        $db = Database::getInstance();
        $player = $db->fetch(
            "SELECT * FROM players WHERE session_token = ? AND profile_deleted_at IS NULL",
            [$token]
        );
        
        if ($player) {
            // Update last seen
            $db->query("UPDATE players SET last_seen_at = NOW(), online_current = 1 WHERE player_id = ?", 
                [$player['player_id']]);
        }
        
        return $player;
    }
    
    /**
     * Register a new player
     */
    public static function register($handle, $email, $password) {
        $db = Database::getInstance();
        
        // Validate handle
        $error = self::validateHandle($handle);
        if ($error) return ['error' => $error];
        
        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'Invalid email address'];
        }
        
        // Check email uniqueness
        $existing = $db->fetch("SELECT player_id FROM players WHERE email = ?", [$email]);
        if ($existing) return ['error' => 'Email already registered'];
        
        // Check handle uniqueness (including historical)
        $handleLower = strtolower($handle);
        $existingHandle = $db->fetch("SELECT handle_lower FROM handle_registry WHERE handle_lower = ?", [$handleLower]);
        if ($existingHandle) return ['error' => 'Handle is already taken or was previously used'];
        
        $existingPlayer = $db->fetch("SELECT player_id FROM players WHERE handle_lower = ?", [$handleLower]);
        if ($existingPlayer) return ['error' => 'Handle is already taken'];
        
        // Create player
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $token = bin2hex(random_bytes(32));
        
        $db->beginTransaction();
        try {
            $playerId = $db->insert(
                "INSERT INTO players (handle, handle_lower, email, password_hash, session_token, online_current, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, 1, NOW())",
                [$handle, $handleLower, $email, $hash, $token]
            );
            
            // Register handle
            $db->query(
                "INSERT INTO handle_registry (handle_lower, player_id) VALUES (?, ?)",
                [$handleLower, $playerId]
            );
            
            $db->commit();
            
            setcookie('tmc_session', $token, time() + SESSION_LIFETIME, '/', '', false, true);
            
            return [
                'success' => true,
                'player_id' => $playerId,
                'handle' => $handle,
                'token' => $token
            ];
        } catch (Exception $e) {
            $db->rollback();
            return ['error' => 'Registration failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Login
     */
    public static function login($email, $password) {
        $db = Database::getInstance();
        
        $player = $db->fetch(
            "SELECT * FROM players WHERE email = ? AND profile_deleted_at IS NULL",
            [$email]
        );
        
        if (!$player || !password_verify($password, $player['password_hash'])) {
            return ['error' => 'Invalid email or password'];
        }
        
        $token = bin2hex(random_bytes(32));
        $db->query(
            "UPDATE players SET session_token = ?, online_current = 1, last_seen_at = NOW(), 
             connection_seq = connection_seq + 1 WHERE player_id = ?",
            [$token, $player['player_id']]
        );
        
        setcookie('tmc_session', $token, time() + SESSION_LIFETIME, '/', '', false, true);
        
        return [
            'success' => true,
            'player_id' => $player['player_id'],
            'handle' => $player['handle'],
            'token' => $token
        ];
    }
    
    /**
     * Logout
     */
    public static function logout() {
        $player = self::getCurrentPlayer();
        if ($player) {
            $db = Database::getInstance();
            $db->query(
                "UPDATE players SET session_token = NULL, online_current = 0 WHERE player_id = ?",
                [$player['player_id']]
            );
        }
        setcookie('tmc_session', '', time() - 3600, '/');
        return ['success' => true];
    }
    
    /**
     * Validate handle format
     */
    public static function validateHandle($handle) {
        if (strlen($handle) < HANDLE_MIN_LENGTH) return 'Handle must be at least ' . HANDLE_MIN_LENGTH . ' characters';
        if (strlen($handle) > HANDLE_MAX_LENGTH) return 'Handle must be at most ' . HANDLE_MAX_LENGTH . ' characters';
        if (!preg_match(HANDLE_PATTERN, $handle)) return 'Handle may only contain letters, numbers, and underscores';
        if (in_array(strtolower($handle), RESERVED_HANDLES)) return 'This handle is reserved';
        return null;
    }
    
    /**
     * Require authentication, return player or send error
     */
    public static function requireAuth() {
        $player = self::getCurrentPlayer();
        if (!$player) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            exit;
        }
        return $player;
    }
}
