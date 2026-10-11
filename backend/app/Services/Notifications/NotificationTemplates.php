<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;

/**
 * Template rendering. Templates live in resources/notifications/{locale}.php (en-IN is the market default and the
 * fallback; a customer locale without a file, or a type without a template in that file, falls back to it). Variables
 * are an allow-list; every value is untrusted text: control characters removed and the output escaped per format (plain
 * text for push / in-app / SMS, HTML-escaped for the e-mail body). Lengths are bounded per channel.
 */
final class NotificationTemplates
{
    public const VARIABLES = ['restaurant', 'order', 'pickup_time', 'refund_line'];

    /** @var array<string, array<string, mixed>> */
    private array $loaded = [];

    /**
     * @param  array<string, string|null>  $vars
     * @return array{title: string, body: string, subject: string|null, html: string|null, version: int, locale: string}|null
     */
    public function render(string $type, NotificationChannel $channel, ?string $locale, array $vars): ?array
    {
        [$resolvedLocale, $template] = $this->resolve($type, $locale);
        if ($template === null) {
            return null;
        }
        $section = match ($channel) {
            NotificationChannel::Push => $template['push'] ?? null,
            NotificationChannel::InApp => $template['in_app'] ?? null,
            NotificationChannel::Email => $template['email'] ?? null,
            NotificationChannel::Sms => $template['sms'] ?? null,
        };
        if (! is_array($section)) {
            return null;
        }
        $clean = $this->cleanVars($vars, $resolvedLocale);
        $limits = (array) config('notifications.limits');
        $version = (int) ($template['version'] ?? 1);

        if ($channel === NotificationChannel::Email) {
            $subject = $this->fill((string) ($section['subject'] ?? ''), $clean, false);
            $text = $this->fill((string) ($section['body'] ?? ''), $clean, false);
            $html = '<p>'.nl2br($this->fill((string) ($section['body'] ?? ''), $clean, true)).'</p>';

            return ['title' => $this->cut($subject, 160), 'body' => $this->cut($text, 2000), 'subject' => $this->cut($subject, 160), 'html' => $html, 'version' => $version, 'locale' => $resolvedLocale];
        }
        if ($channel === NotificationChannel::Sms) {
            return ['title' => '', 'body' => $this->cut($this->fill((string) ($section['body'] ?? ''), $clean, false), (int) $limits['sms_body']), 'subject' => null, 'html' => null, 'version' => $version, 'locale' => $resolvedLocale];
        }
        $titleLimit = $channel === NotificationChannel::Push ? (int) $limits['push_title'] : (int) $limits['in_app_title'];
        $bodyLimit = $channel === NotificationChannel::Push ? (int) $limits['push_body'] : (int) $limits['in_app_body'];

        return [
            'title' => $this->cut($this->fill((string) ($section['title'] ?? ''), $clean, false), $titleLimit),
            'body' => $this->cut($this->fill((string) ($section['body'] ?? ''), $clean, false), $bodyLimit),
            'subject' => null,
            'html' => null,
            'version' => $version,
            'locale' => $resolvedLocale,
        ];
    }

    public function version(string $type): int
    {
        [, $template] = $this->resolve($type, null);

        return (int) ($template['version'] ?? 0);
    }

    /** @return list<string> */
    public function locales(string $type): array
    {
        $out = [];
        foreach (glob(resource_path('notifications').'/*.php') ?: [] as $file) {
            $locale = basename($file, '.php');
            if (isset($this->load($locale)[$type])) {
                $out[] = $locale;
            }
        }
        sort($out);

        return $out;
    }

    public function fragment(string $key, ?string $locale = null): string
    {
        [, $fragments] = $this->resolve('fragments', $locale);

        return (string) ($fragments[$key] ?? '');
    }

    /** @return array{0: string, 1: array<string, mixed>|null} */
    private function resolve(string $type, ?string $locale): array
    {
        $default = (string) config('notifications.default_locale');
        $candidates = [];
        if ($locale !== null && $locale !== '') {
            $candidates[] = str_replace('_', '-', $locale);
            $candidates[] = strtolower(substr($locale, 0, 2));
        }
        $candidates[] = $default;
        foreach (array_unique($candidates) as $candidate) {
            $file = $this->load($candidate);
            if (isset($file[$type]) && is_array($file[$type])) {
                return [$candidate, $file[$type]];
            }
        }

        return [$default, null];
    }

    /** @return array<string, mixed> */
    private function load(string $locale): array
    {
        if (! array_key_exists($locale, $this->loaded)) {
            $path = resource_path('notifications/'.preg_replace('/[^A-Za-z0-9\-]/', '', $locale).'.php');
            $this->loaded[$locale] = is_file($path) ? (array) require $path : [];
        }

        return $this->loaded[$locale];
    }

    /**
     * @param  array<string, string|null>  $vars
     * @return array<string, string>
     */
    private function cleanVars(array $vars, string $locale): array
    {
        $clean = [];
        foreach (self::VARIABLES as $name) {
            $value = $vars[$name] ?? '';
            $value = is_scalar($value) ? (string) $value : '';
            $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
            $clean[$name] = trim(mb_substr($value, 0, 120));
        }
        if ($clean['pickup_time'] === '') {
            $clean['pickup_time'] = $this->fragment('pickup_time.unknown', $locale);
        }

        return $clean;
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function fill(string $template, array $vars, bool $html): string
    {
        $out = preg_replace_callback('/:([a-z_]+)/', function (array $m) use ($vars, $html): string {
            if (! array_key_exists($m[1], $vars)) {
                return '';
            }

            return $html ? e($vars[$m[1]]) : $vars[$m[1]];
        }, $template) ?? '';

        return trim(preg_replace('/\s{2,}/', ' ', $out) ?? '');
    }

    private function cut(string $text, int $limit): string
    {
        return mb_strlen($text) <= $limit ? $text : rtrim(mb_substr($text, 0, max(1, $limit - 1))).'…';
    }
}
