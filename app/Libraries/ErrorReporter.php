<?php

namespace App\Libraries;

use Honeybadger\Honeybadger;
use Throwable;

/**
 * Honeybadger error reporting wrapper.
 *
 * Defensive by design: every method no-ops when the
 * `honeybadger-io/honeybadger-php` package is not installed yet or when no
 * API key is configured, so the app keeps working before/after setup.
 *
 * Setup:
 *   1. composer require honeybadger-io/honeybadger-php
 *   2. Put HONEYBADGER_API_KEY in your .env (never commit the key).
 */
class ErrorReporter
{
    private static bool $booted = false;
    private static bool $resolved = false;
    private static $client = null;

    /**
     * Register the global unhandled-exception reporter (production only).
     * Safe to call on every request; runs once per process.
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        if (! defined('ENVIRONMENT') || ENVIRONMENT !== 'production') {
            return;
        }

        $client = self::client();
        if ($client === null) {
            return;
        }

        $previous = set_exception_handler(function (Throwable $e) use ($client, &$previous) {
            try {
                $client->notify($e);
            } catch (Throwable $ignored) {
                // Reporting must never break error handling.
            }

            if (is_callable($previous)) {
                $previous($e);

                return;
            }

            throw $e;
        });
    }

    /**
     * Report an exception from anywhere (controllers, jobs, catch blocks).
     */
    public static function report(Throwable $e): void
    {
        $client = self::client();
        if ($client === null) {
            return;
        }

        try {
            $client->notify($e);
        } catch (Throwable $ignored) {
            // Reporting must never break the app.
        }
    }

    /**
     * Send the verification notification. Returns false when not configured.
     */
    public static function test(): bool
    {
        $client = self::client();
        if ($client === null) {
            return false;
        }

        try {
            $client->customNotification([
                'title'   => 'Special Error',
                'message' => 'Special Error: a special error has occurred',
            ]);

            return true;
        } catch (Throwable $ignored) {
            return false;
        }
    }

    public static function isConfigured(): bool
    {
        return self::client() !== null;
    }

    private static function client()
    {
        if (! self::$resolved) {
            self::$resolved = true;
            self::$client = self::buildClient();
        }

        return self::$client;
    }

    private static function buildClient()
    {
        if (! class_exists(Honeybadger::class)) {
            return null;
        }

        $apiKey = env('HONEYBADGER_API_KEY');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            return null;
        }

        try {
            return Honeybadger::new([
                'api_key'     => trim($apiKey),
                'environment' => defined('ENVIRONMENT') ? ENVIRONMENT : 'production',
            ]);
        } catch (Throwable $ignored) {
            return null;
        }
    }
}
