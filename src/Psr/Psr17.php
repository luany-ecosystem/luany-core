<?php

namespace Luany\Core\Psr;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;

/**
 * PSR-17 factories used by the PSR-7 / PSR-15 bridges.
 *
 * Configure explicitly with a factory implementing all four PSR-17
 * interfaces (nyholm/psr7, guzzlehttp/psr7, laminas/diactoros, …):
 *
 *   Psr17::use(new \Nyholm\Psr7\Factory\Psr17Factory());
 *
 * or let it auto-detect nyholm/psr7 / guzzlehttp/psr7 when installed.
 */
final class Psr17
{
    private static ?ServerRequestFactoryInterface $serverRequests = null;
    private static ?ResponseFactoryInterface $responses = null;
    private static ?StreamFactoryInterface $streams = null;
    private static ?UploadedFileFactoryInterface $uploadedFiles = null;

    /** Known all-in-one PSR-17 factories, tried in order. */
    private const DISCOVERABLE = [
        'Nyholm\\Psr7\\Factory\\Psr17Factory',
        'GuzzleHttp\\Psr7\\HttpFactory',
    ];

    /**
     * Use one object implementing the PSR-17 factory interfaces.
     */
    public static function use(object $factory): void
    {
        if (
            !$factory instanceof ServerRequestFactoryInterface
            || !$factory instanceof ResponseFactoryInterface
            || !$factory instanceof StreamFactoryInterface
        ) {
            throw new \InvalidArgumentException(
                'The PSR-17 factory must implement ServerRequestFactoryInterface, ResponseFactoryInterface and StreamFactoryInterface.'
            );
        }

        self::$serverRequests = $factory;
        self::$responses      = $factory;
        self::$streams        = $factory;
        self::$uploadedFiles  = $factory instanceof UploadedFileFactoryInterface ? $factory : null;
    }

    /**
     * Forget configured / discovered factories.
     */
    public static function reset(): void
    {
        self::$serverRequests = self::$responses = self::$streams = null;
        self::$uploadedFiles  = null;
    }

    public static function serverRequests(): ServerRequestFactoryInterface
    {
        self::discover();
        return self::$serverRequests ?? throw self::missing();
    }

    public static function responses(): ResponseFactoryInterface
    {
        self::discover();
        return self::$responses ?? throw self::missing();
    }

    public static function streams(): StreamFactoryInterface
    {
        self::discover();
        return self::$streams ?? throw self::missing();
    }

    public static function uploadedFiles(): ?UploadedFileFactoryInterface
    {
        self::discover();
        return self::$uploadedFiles;
    }

    private static function discover(): void
    {
        if (self::$responses !== null) {
            return;
        }

        foreach (self::DISCOVERABLE as $class) {
            if (class_exists($class)) {
                self::use(new $class());
                return;
            }
        }
    }

    private static function missing(): \LogicException
    {
        return new \LogicException(
            'No PSR-17 factory available. Install one (composer require nyholm/psr7) '
            . 'or configure it with Luany\\Core\\Psr\\Psr17::use($factory).'
        );
    }
}
