<?php

function hxFollowupLoad($file) {
    if (!file_exists($file)) return [];
    $records = json_decode((string)file_get_contents($file), true);
    return is_array($records) ? array_values($records) : [];
}

function hxFollowupSave($file, $records) {
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0755, true);
    $json = json_encode(array_values($records), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($file, $json, LOCK_EX) === false) {
        throw new RuntimeException('Unable to save callback data.');
    }
}

function hxFollowupFindIndex($records, $id) {
    foreach ($records as $index => $record) {
        if (hash_equals((string)($record['id'] ?? ''), (string)$id)) return $index;
    }
    return -1;
}

function hxFollowupStoredStatus($value) {
    $value = strtolower(trim((string)$value));
    return in_array($value, ['scheduled', 'completed', 'cancelled'], true) ? $value : 'scheduled';
}

function hxFollowupInputToUtc($value, $timezone) {
    $value = trim((string)$value);
    try {
        $zone = new DateTimeZone($timezone);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $zone);
        if (!$date || $date->format('Y-m-d\TH:i') !== $value) return '';
        return $date->setTimezone(new DateTimeZone('UTC'))->format('c');
    } catch (Exception $error) {
        return '';
    }
}

function hxFollowupUtcToInput($value, $timezone) {
    try {
        return (new DateTimeImmutable((string)$value))
            ->setTimezone(new DateTimeZone($timezone))
            ->format('Y-m-d\TH:i');
    } catch (Exception $error) {
        return '';
    }
}

function hxFollowupDisplayTime($value, $timezone) {
    try {
        return (new DateTimeImmutable((string)$value))
            ->setTimezone(new DateTimeZone($timezone))
            ->format('M j, Y · H:i');
    } catch (Exception $error) {
        return 'Invalid time';
    }
}

function hxFollowupEffectiveStatus($record, $now = null) {
    $status = hxFollowupStoredStatus($record['status'] ?? 'scheduled');
    if ($status !== 'scheduled') return $status;
    try {
        $scheduled = new DateTimeImmutable((string)($record['scheduledAt'] ?? ''));
        $current = $now instanceof DateTimeImmutable ? $now : new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return $scheduled <= $current ? 'due' : 'upcoming';
    } catch (Exception $error) {
        return 'due';
    }
}

function hxFollowupStatusLabel($status) {
    if ($status === 'due') return 'Call due';
    if ($status === 'upcoming') return 'Upcoming';
    if ($status === 'completed') return 'Completed';
    if ($status === 'cancelled') return 'Cancelled';
    return 'Scheduled';
}

function hxFollowupCsrfToken() {
    if (empty($_SESSION['followup_csrf'])) {
        $_SESSION['followup_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['followup_csrf'];
}

function hxFollowupCsrfValid($token) {
    return isset($_SESSION['followup_csrf'])
        && is_string($token)
        && hash_equals((string)$_SESSION['followup_csrf'], $token);
}

