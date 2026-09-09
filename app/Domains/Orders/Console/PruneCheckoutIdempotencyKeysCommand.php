<?php

namespace App\Domains\Orders\Console;

use App\Domains\Orders\Models\CheckoutIdempotencyKey;
use Illuminate\Console\Command;

/**
 * Deletes checkout idempotency keys older than the retention window.
 *
 * Keys are only useful for the life of a retry — seconds, or minutes if a
 * tablet was offline. Keeping them forever would grow an
 * append-only-per-checkout table indefinitely and slow down the very
 * lookup that has to be fast on every sale. Twenty-four hours is far
 * beyond any real retry and short enough that the table stays roughly
 * one day of sales.
 *
 * Pruning a key does not touch the order it produced. The only effect of
 * dropping an old key is that a retry arriving a day late would be
 * treated as a new checkout — which is the correct answer at that point
 * anyway, since nobody is retrying yesterday's coffee.
 *
 * Scheduled hourly in routes/console.php; safe to run by hand at any time.
 */
class PruneCheckoutIdempotencyKeysCommand extends Command
{
    /**
     * How long a key stays useful. Documented in README § Idempotency;
     * override per-run with --hours for a one-off sweep.
     */
    public const DEFAULT_RETENTION_HOURS = 24;

    protected $signature = 'orders:prune-idempotency-keys
                            {--hours= : Retention window in hours (default 24)}';

    protected $description = 'Delete checkout idempotency keys older than the retention window';

    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?? self::DEFAULT_RETENTION_HOURS);

        if ($hours < 1) {
            $this->error('The retention window must be at least 1 hour.');

            return self::FAILURE;
        }

        $cutoff = now()->subHours($hours);

        // withoutGlobalScope: this runs from the scheduler with nobody
        // authenticated, and BelongsToMerchant resolves no tenant to NO
        // ROWS — so a scoped query here would silently delete nothing and
        // the table would grow forever while the command reported success
        // every hour. Pruning is deliberately cross-tenant; it is keyed on
        // age alone.
        $deleted = CheckoutIdempotencyKey::query()
            ->withoutGlobalScope('merchant')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info(sprintf(
            'Pruned %d checkout idempotency key(s) created before %s.',
            $deleted,
            $cutoff->toDateTimeString(),
        ));

        return self::SUCCESS;
    }
}
