<?php

if (!function_exists('normalize_domain')) {
    function normalize_domain($url)
    {
        if (!$url) {
            return null;
        }

        $url = trim(strtolower($url));
        $url = rtrim($url, '/');

        if ($url === 'null') {
            return null;
        }

        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'http://' . $url;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        if (!$host) {
            return null;
        }

        $host = preg_replace('/^www\./', '', $host);

        if ($port && ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP))) {
            return $host . ':' . $port;
        }

        return $host;
    }
}

if (!function_exists('normalize_allowed_domains')) {
    function normalize_allowed_domains(array $domains)
    {
        $normalized = [];

        foreach ($domains as $domain) {
            $cleanDomain = normalize_domain($domain);

            if ($cleanDomain) {
                $normalized[] = $cleanDomain;
            }
        }

        return array_values(array_unique($normalized));
    }
}

if (!function_exists('is_origin_allowed_for_domain')) {
    function is_origin_allowed_for_domain($origin, array $allowedDomains)
    {
        $originHost = normalize_domain($origin);

        if (!$originHost) {
            return false;
        }

        foreach ($allowedDomains as $allowedDomain) {
            $allowedDomain = normalize_domain($allowedDomain);

            if (!$allowedDomain) {
                continue;
            }

            $allowedHostOnly = explode(':', $allowedDomain)[0];

            if (
                $allowedDomain === 'localhost' ||
                str_starts_with($allowedDomain, 'localhost:') ||
                filter_var($allowedHostOnly, FILTER_VALIDATE_IP)
            ) {
                if ($originHost === $allowedDomain) {
                    return true;
                }

                continue;
            }

            if ($originHost === $allowedDomain) {
                return true;
            }

            if (str_ends_with($originHost, '.' . $allowedDomain)) {
                return true;
            }
        }

        return false;
    }
}