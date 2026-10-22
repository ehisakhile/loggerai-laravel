<?php

namespace LoggerAI\Laravel\Support;

/**
 * Masks credentials before anything leaves the app: by name (headers,
 * fields, query parameters) and by shape (bearer tokens, JWTs). The same
 * rules as LoggerAI's API and browser script.
 */
final class Redactor
{
    public const MASK = '[REDACTED]';

    private const WORDS = ['password', 'passwd', 'pwd', 'pass', 'passphrase', 'secret', 'token', 'otp', 'pin', 'cvv', 'cvc',
        'ssn', 'cookie', 'authorization', 'auth', 'signature', 'sig', 'credential', 'credentials', 'bvn', 'nin',
        // where someone is: user_lat, userLng, pickup_latitude... (analytics never keep locations)
        'lat', 'lng', 'lon', 'latitude', 'longitude', 'coords', 'coordinates', 'geolocation', 'gps'];

    private const JOINED = ['apikey', 'accesskey', 'privatekey', 'secretkey', 'cardnumber', 'clientsecret', 'sessionid',
        'authtoken', 'accesstoken', 'refreshtoken', 'idtoken', 'csrftoken', 'xsrf', 'setcookie',
        'latlng', 'latlon', 'lnglat', 'lonlat', 'geopoint'];

    private const URL_EXTRA = ['key', 'code', 'access_token', 'id_token', 'll', 'location']; // ?ll=6.52,3.37 (maps)

    /** @var string[] */
    private array $extra;

    public function __construct(array $extra = [])
    {
        $this->extra = array_map('strtolower', $extra);
    }

    public function isSensitive($key): bool
    {
        if (! is_string($key) || $key === '') {
            return false;
        }
        if (in_array(strtolower($key), $this->extra, true)) {
            return true;
        }
        $words = preg_split('/[^A-Za-z0-9]+|(?<=[a-z0-9])(?=[A-Z])/', $key, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_map('strtolower', $words);
        foreach ($words as $w) {
            if (in_array($w, self::WORDS, true)) {
                return true;
            }
        }
        $joined = implode('', $words);
        foreach (self::JOINED as $j) {
            if (str_contains($joined, $j)) {
                return true;
            }
        }

        return false;
    }

    public function string(string $s): string
    {
        $s = preg_replace('/eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/', '[REDACTED_JWT]', $s) ?? $s;
        // A latitude,longitude pair anywhere, e.g. in a path: /nearby/6.524379,3.379206
        $s = preg_replace('/(?<![\d.])-?\d{1,3}\.\d{4,}\s*(?:,|%2C)\s*-?\d{1,3}\.\d{4,}(?![\d.])/i', '[REDACTED_LOCATION]', $s) ?? $s;

        return preg_replace('/\b(bearer|basic|token)\s+[A-Za-z0-9\-._~+\/]{12,}=*/i', '$1 '.self::MASK, $s) ?? $s;
    }

    public function url(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['query'])) {
            return $this->string($url);
        }
        parse_str($parts['query'], $q);
        foreach ($q as $k => $v) {
            if ($v !== '' && ($this->isSensitive((string) $k) || in_array(strtolower((string) $k), self::URL_EXTRA, true))) {
                $q[$k] = self::MASK;
            }
        }
        $base = strtok($url, '?');

        return $this->string($base.'?'.http_build_query($q));
    }

    /** A copy of $value with secrets masked; safe on any array/scalar. */
    public function value($value, int $depth = 0)
    {
        if ($depth > 20) {
            return $value;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                if ($this->isSensitive((string) $k) && $v !== null && $v !== '' && $v !== []) {
                    $out[$k] = self::MASK;
                } elseif (is_string($v) && in_array(strtolower((string) $k), ['url', 'href', 'referer', 'referrer', 'location'], true)) {
                    $out[$k] = $this->url($v);
                } else {
                    $out[$k] = $this->value($v, $depth + 1);
                }
            }

            return $out;
        }
        if (is_string($value)) {
            return $this->string($value);
        }
        if (is_object($value)) {
            return method_exists($value, '__toString') ? $this->string((string) $value) : get_class($value);
        }

        return $value;
    }
}
