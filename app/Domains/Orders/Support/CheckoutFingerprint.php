<?php

namespace App\Domains\Orders\Support;

/**
 * A stable hash of what a checkout request MEANS, used to tell "the same
 * request retried" from "a different request that reused the key".
 *
 * Hashing the raw body would be wrong, and wrongly strict: a tablet that
 * rebuilds its JSON on retry can legitimately emit the same basket with
 * different key ordering, different whitespace, or `discount_cents`
 * omitted rather than sent as 0. Every one of those would hash
 * differently and the retry — the exact case this whole mechanism exists
 * for — would come back as a 409 telling the cashier they had made a
 * mistake they had not made.
 *
 * So the payload is normalised to a canonical form first:
 *
 *  - only the fields that change what is sold or charged are included;
 *    P10's beneficiaries and line->beneficiary assignments ARE such
 *    fields — adding a senior to an otherwise identical basket changes
 *    what is charged, so it must change the hash (see below);
 *  - optional fields are defaulted, so absent and explicit-null and 0
 *    agree;
 *  - add-ons within a line are sorted, and lines within the basket are
 *    sorted, so ordering never affects the hash.
 *
 * Sorting the lines does mean two baskets differing ONLY in line order
 * are one request. That is intended: they sell the same drinks for the
 * same money, and the line order is a display detail. It is not a
 * collision — sorting is a bijection on the multiset of lines, so any
 * genuine difference in what was ordered still changes the hash.
 *
 * The hash is SHA-256, compared with hash_equals. Not for secrecy — the
 * client supplies the input — but because a fingerprint comparison
 * deciding between "replay this order" and "reject this request" should
 * not be a timing-variable string compare.
 */
class CheckoutFingerprint
{
    /**
     * @param  array{
     *     payment_method: string,
     *     cash_cents?: int|null,
     *     gcash_cents?: int|null,
     *     discount_cents?: int|null,
     *     beneficiaries?: list<array{type: string, name: string, id_number: string}>,
     *     items: list<array{
     *         product_id: int,
     *         quantity: int,
     *         beneficiary?: int|null,
     *         add_ons?: list<array{name: string, price_cents: int}>
     *     }>
     * }  $payload  The validated checkout payload, WITHOUT the idempotency
     *              key itself — the key identifies the attempt, it is not
     *              part of what the attempt says.
     */
    public static function for(array $payload): string
    {
        // JSON_THROW_ON_ERROR so an unencodable payload fails loudly here
        // rather than silently hashing the string "false" — which would
        // make every such request look identical to every other.
        $canonical = json_encode(self::normalise($payload), JSON_THROW_ON_ERROR);

        return hash('sha256', $canonical);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function normalise(array $payload): array
    {
        /** @var list<array<string, mixed>> $beneficiaries */
        $beneficiaries = array_values($payload['beneficiaries'] ?? []);

        $normalisedBeneficiaries = array_map(self::normaliseBeneficiary(...), $beneficiaries);

        // Each line carries its beneficiary BY VALUE (the normalised
        // person) rather than by index. This is what keeps "the same
        // basket is the same request" true through a retry: a tablet that
        // rebuilds its JSON may legitimately list the same two
        // beneficiaries in the other order, which renumbers every index
        // while changing nothing about who is being charged what.
        // Hashing the index would turn that retry into a 409 — the exact
        // false rejection this whole class exists to avoid (see the
        // line-ordering note above).
        $lines = array_map(
            fn (array $line): array => self::normaliseLine($line, $normalisedBeneficiaries),
            array_values($payload['items']),
        );

        usort($lines, self::compareCanonically(...));

        // Sorted for the same reason the lines are: the declaration order
        // of two beneficiaries is a display detail, not part of what is
        // sold. A genuine difference (a different person, a different ID)
        // still changes the hash, because sorting is a bijection on the
        // multiset.
        usort($normalisedBeneficiaries, self::compareCanonically(...));

        // Keys are written in a fixed order, so json_encode's output is
        // deterministic without relying on the caller's array ordering.
        return [
            'payment_method' => (string) $payload['payment_method'],
            'discount_cents' => (int) ($payload['discount_cents'] ?? 0),
            'cash_cents' => isset($payload['cash_cents']) ? (int) $payload['cash_cents'] : null,
            'gcash_cents' => isset($payload['gcash_cents']) ? (int) $payload['gcash_cents'] : null,
            'beneficiaries' => $normalisedBeneficiaries,
            'items' => $lines,
        ];
    }

    /**
     * @param  array<string, mixed>  $beneficiary
     * @return array<string, mixed>
     */
    private static function normaliseBeneficiary(array $beneficiary): array
    {
        return [
            'type' => (string) $beneficiary['type'],
            'name' => (string) $beneficiary['name'],
            'id_number' => (string) $beneficiary['id_number'],
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  list<array<string, mixed>>  $beneficiaries  normalised, still in
     *                                                     the payload's own order
     *                                                     so indexes resolve
     * @return array<string, mixed>
     */
    private static function normaliseLine(array $line, array $beneficiaries = []): array
    {
        /** @var list<array<string, mixed>> $addOns */
        $addOns = array_values($line['add_ons'] ?? []);

        $addOns = array_map(fn (array $addOn): array => [
            'name' => (string) $addOn['name'],
            'price_cents' => (int) $addOn['price_cents'],
        ], $addOns);

        usort($addOns, self::compareCanonically(...));

        $beneficiaryIndex = $line['beneficiary'] ?? null;

        return [
            'product_id' => (int) $line['product_id'],
            'quantity' => (int) $line['quantity'],

            // Null for an ordinary line, so an absent key and an explicit
            // null agree — exactly like discount_cents above.
            'beneficiary' => $beneficiaryIndex === null
                ? null
                : ($beneficiaries[(int) $beneficiaryIndex] ?? null),

            'add_ons' => $addOns,
        ];
    }

    /**
     * Orders two normalised structures by their own canonical encoding.
     *
     * Comparing the encodings rather than picking sort keys by hand means
     * a field added to a line or an add-on later is included in the
     * ordering automatically, instead of silently not participating and
     * letting two different baskets sort identically.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function compareCanonically(array $a, array $b): int
    {
        return strcmp(
            json_encode($a, JSON_THROW_ON_ERROR),
            json_encode($b, JSON_THROW_ON_ERROR),
        );
    }
}
