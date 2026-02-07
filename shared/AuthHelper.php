<?php

class AuthHelper
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Rate Limiting: Check if IP is blocked
     */
    public function checkRateLimit($ip)
    {
        // cleanup old attempts
        $this->cleanupRateLimits();

        $stmt = $this->pdo->prepare("SELECT attempts, blocked_until FROM login_attempts WHERE ip_address = ?");
        $stmt->execute([$ip]);
        $row = $stmt->fetch();

        if ($row) {
            if ($row['blocked_until'] && strtotime($row['blocked_until']) > time()) {
                $wait = strtotime($row['blocked_until']) - time();
                return "Too many failed attempts. Try again in " . ceil($wait / 60) . " minutes.";
            }
        }
        return false; // Not blocked
    }

    /**
     * Rate Limiting: Record a failed attempt
     */
    public function logFailedAttempt($ip)
    {
        $stmt = $this->pdo->prepare("SELECT id, attempts FROM login_attempts WHERE ip_address = ?");
        $stmt->execute([$ip]);
        $row = $stmt->fetch();

        if ($row) {
            $new_count = $row['attempts'] + 1;
            if ($new_count >= 5) {
                // Block for 15 minutes
                $blocked_until = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                $stmt = $this->pdo->prepare("UPDATE login_attempts SET attempts = ?, blocked_until = ? WHERE id = ?");
                $stmt->execute([$new_count, $blocked_until, $row['id']]);
            } else {
                $stmt = $this->pdo->prepare("UPDATE login_attempts SET attempts = ? WHERE id = ?");
                $stmt->execute([$new_count, $row['id']]);
            }
        } else {
            $stmt = $this->pdo->prepare("INSERT INTO login_attempts (ip_address, attempts) VALUES (?, 1)");
            $stmt->execute([$ip]);
        }
    }

    /**
     * Rate Limiting: Clear on success
     */
    public function clearRateLimit($ip)
    {
        $stmt = $this->pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
        $stmt->execute([$ip]);
    }

    private function cleanupRateLimits()
    {
        // Delete records older than 1 hour if not blocked, or expired blocks
        $this->pdo->exec("DELETE FROM login_attempts WHERE (blocked_until IS NULL AND last_attempt_at < (NOW() - INTERVAL 1 HOUR)) OR (blocked_until < NOW())");
    }

    /**
     * TOTP: Verify Code (Google Authenticator)
     * Simplified implementation without external deps
     */
    public function verifyTOTP($secret, $code)
    {
        if (strlen($code) != 6)
            return false;

        $base32 = new Base32();
        $secret_key = $base32->decode($secret);

        $timestamp = floor(time() / 30);

        // Check current, previous, and next window to allow for slight time drift
        for ($i = -1; $i <= 1; $i++) {
            if ($this->getCode($secret_key, $timestamp + $i) == $code) {
                return true;
            }
        }
        return false;
    }

    private function getCode($secret, $time_slice)
    {
        $pack = pack('N*', 0) . pack('N*', $time_slice);
        $hash = hash_hmac('sha1', $pack, $secret, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $value = unpack('N', substr($hash, $offset, 4));
        $value = $value[1];
        $value = $value & 0x7FFFFFFF;
        $modulo = pow(10, 6);
        return str_pad($value % $modulo, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Generate a new Secret
     */
    public function generateSecret()
    {
        $base32 = new Base32();
        return $base32->encode(random_bytes(10)); // 16 chars base32
    }
}

/**
 * Base32 Implementation (RFC 4648)
 * Embedded here to avoid composer dependency complexity for this specific environment
 */
class Base32
{
    private $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function decode($input)
    {
        if (empty($input))
            return '';
        $input = strtoupper($input);
        $padding = substr_count($input, '=');
        $input = str_replace('=', '', $input);
        $binary = '';

        foreach (str_split($input) as $char) {
            $val = strpos($this->map, $char);
            if ($val === false)
                continue;
            $binary .= sprintf('%05b', $val);
        }

        $binary = substr($binary, 0, strlen($binary) - $padding * 8);
        $output = '';

        foreach (str_split($binary, 8) as $byte) {
            $output .= chr(bindec($byte));
        }

        return $output;
    }

    public function encode($input)
    {
        if (empty($input))
            return '';
        $binary = '';

        foreach (str_split($input) as $char) {
            $binary .= sprintf('%08b', ord($char));
        }

        $remainder = strlen($binary) % 5;
        if ($remainder > 0) {
            $binary .= str_repeat('0', 5 - $remainder);
        }

        $output = '';
        foreach (str_split($binary, 5) as $chunk) {
            $output .= $this->map[bindec($chunk)];
        }

        return $output;
    }
}
