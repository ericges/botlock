<?php declare(strict_types=1);

namespace GES\Botlock\Config;

/**
 * Settings the kernel needs before anything else is constructed. Reading
 * these cannot fail, which is what makes BOTLOCK_FAIL_OPEN usable when
 * the rest of the boot sequence throws.
 */
final readonly class KernelConfig
{
    public function __construct(
        /** Let requests through (with a Botlock-Error header) when booting fails. */
        public bool   $failOpen,
        /** Distinguishes this install's secret and state files from others sharing the state dir. */
        public string $instanceId,
        /** Writable directory for the generated secret and rate-limit state. */
        public string $stateDir,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            failOpen: (bool) Env::bool('FAIL_OPEN', false),
            instanceId: (string) (Env::get('INSTANCE_ID') ?: self::defaultInstanceId()),
            stateDir: \rtrim((string) Env::get('STATE_DIR', \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'botlock'), '/\\'),
        );
    }

    /**
     * Derived from the source location so that two installs on one host
     * do not share a secret by accident.
     */
    public static function defaultInstanceId(): string
    {
        return \substr(\md5(\dirname(__DIR__)), 0, 8);
    }
}
