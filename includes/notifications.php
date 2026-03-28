<?php
/**
 * Too Many Coins - Notifications
 * Persistent per-player notification log helpers.
 */
require_once __DIR__ . '/database.php';

class Notifications {
    public static function create($playerId, $category, $title, $body = null, $options = []) {
        $db = Database::getInstance();
        $isRead = !empty($options['is_read']) ? 1 : 0;
        $readAt = $isRead ? date('Y-m-d H:i:s') : null;
        $eventKey = isset($options['event_key']) ? (string)$options['event_key'] : null;
        $payload = array_key_exists('payload', $options)
            ? json_encode($options['payload'])
            : null;

        $db->query(
            "INSERT INTO player_notifications
             (player_id, category, title, body, event_key, payload_json, is_read, read_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE notification_id = LAST_INSERT_ID(notification_id)",
            [
                (int)$playerId,
                (string)$category,
                (string)$title,
                $body !== null ? (string)$body : null,
                $eventKey,
                $payload,
                $isRead,
                $readAt
            ]
        );

        return (int)$db->getConnection()->lastInsertId();
    }

    public static function listForPlayer($playerId, $limit = 50) {
        $db = Database::getInstance();
        $safeLimit = max(1, min(100, (int)$limit));
        $rows = $db->fetchAll(
            "SELECT notification_id, category, title, body, payload_json, is_read, created_at, read_at
             FROM player_notifications
             WHERE player_id = ? AND removed_at IS NULL
             ORDER BY created_at DESC, notification_id DESC
             LIMIT {$safeLimit}",
            [(int)$playerId]
        );

        foreach ($rows as &$row) {
            $row = self::normalizeRow($row);
        }
        unset($row);

        return $rows;
    }

    public static function getByIdForPlayer($playerId, $notificationId) {
        $db = Database::getInstance();
        $row = $db->fetch(
            "SELECT notification_id, category, title, body, payload_json, is_read, created_at, read_at
             FROM player_notifications
             WHERE player_id = ? AND notification_id = ? AND removed_at IS NULL",
            [(int)$playerId, (int)$notificationId]
        );

        if (!$row) return null;
        return self::normalizeRow($row);
    }

    public static function unreadCount($playerId) {
        $db = Database::getInstance();
        $row = $db->fetch(
            "SELECT COUNT(*) AS c
             FROM player_notifications
             WHERE player_id = ? AND removed_at IS NULL AND is_read = 0",
            [(int)$playerId]
        );

        return (int)($row['c'] ?? 0);
    }

    public static function markRead($playerId, $notificationIds) {
        $ids = self::sanitizeIds($notificationIds);
        if (count($ids) === 0) return 0;

        $db = Database::getInstance();
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $params = array_merge([(int)$playerId], $ids);

        $stmt = $db->query(
            "UPDATE player_notifications
             SET is_read = 1, read_at = COALESCE(read_at, NOW())
             WHERE player_id = ?
               AND removed_at IS NULL
               AND notification_id IN ({$placeholders})",
            $params
        );

        return $stmt->rowCount();
    }

    public static function remove($playerId, $notificationIds) {
        $ids = self::sanitizeIds($notificationIds);
        if (count($ids) === 0) return 0;

        $db = Database::getInstance();
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $params = array_merge([(int)$playerId], $ids);

        $stmt = $db->query(
            "UPDATE player_notifications
             SET removed_at = NOW(),
                 is_read = 1,
                 read_at = COALESCE(read_at, NOW())
             WHERE player_id = ?
               AND removed_at IS NULL
               AND notification_id IN ({$placeholders})",
            $params
        );

        return $stmt->rowCount();
    }

    private static function sanitizeIds($ids) {
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $out = [];
        foreach ($ids as $id) {
            $n = (int)$id;
            if ($n > 0) {
                $out[$n] = $n;
            }
        }

        return array_values($out);
    }

    private static function normalizeRow($row) {
        $row['notification_id'] = (int)$row['notification_id'];
        $row['is_read'] = (bool)$row['is_read'];
        $payload = $row['payload_json'] ?? null;
        $row['payload'] = $payload ? json_decode($payload, true) : null;
        unset($row['payload_json']);
        return $row;
    }
}
