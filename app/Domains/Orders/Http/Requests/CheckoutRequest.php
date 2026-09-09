<?php

namespace App\Domains\Orders\Http\Requests;

use App\Domains\Orders\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Shape validation for POS checkout. SHAPE only — every rule here can be
 * decided from the request alone.
 *
 * What is deliberately NOT validated here, because it depends on
 * server-side prices the client never sees, lives in CheckoutAction:
 * whether the products are sellable, whether the discount exceeds the
 * computed subtotal, and whether a split's two halves sum to the computed
 * total. A rule here could only compare those against numbers the client
 * made up.
 *
 * THE PRICE OMISSION IS THE POINT. There is no rule for a per-item price,
 * so `validated()` strips one if a client sends it, and CheckoutAction
 * never reads one anyway — it looks every price up from `products`. The
 * audited system took unit prices straight from the request payload,
 * which let a tampered request set its own prices; this is the fix, and
 * it is enforced in two independent places on purpose.
 */
class CheckoutRequest extends FormRequest
{
    /**
     * Per line, not per order. Enough for "extra shot, oat milk, extra
     * syrup" without letting one line carry an unbounded list.
     */
    public const MAX_ADD_ONS_PER_ITEM = 5;

    /**
     * A cap on a single add-on's price (₱10,000.00). Add-on prices are
     * CLIENT-SUPPLIED for now — there is no add-on catalog to look them up
     * in yet (see README § Orders, add-on TODO) — so this bounds the blast
     * radius of a bad or malicious payload until one exists.
     */
    public const MAX_ADD_ON_PRICE_CENTS = 1_000_000;

    /**
     * The request header carrying the client-generated idempotency key.
     * A header rather than a body field because it describes the ATTEMPT,
     * not what is being sold — and because it must stay out of the
     * checkout payload the fingerprint is computed from.
     */
    public const IDEMPOTENCY_HEADER = 'Idempotency-Key';

    /**
     * Lifts the idempotency header into the validated data so it is
     * checked by the same rules as everything else, rather than being
     * read raw somewhere downstream.
     *
     * merge() runs unconditionally, so the key is ALWAYS taken from the
     * header — a client cannot smuggle one in through the body and have
     * it honoured, because the body value is overwritten (with null, if
     * no header was sent).
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header(self::IDEMPOTENCY_HEADER),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Opaque to us, so no UUID rule — a client with a different
            // scheme still gets protection instead of a 422. Bounded
            // because it is stored and indexed, and a minimum length
            // because a one-character "key" is not a key, it is a
            // collision waiting for the next cashier.
            'idempotency_key' => ['nullable', 'string', 'min:8', 'max:255'],

            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],

            // Required and positive for a split, and PROHIBITED otherwise.
            // Rejecting rather than quietly ignoring a stray cash_cents on
            // a gcash order: silently dropping it would let a client
            // believe it recorded a split that the database says was not
            // one. (`prohibited_unless` tolerates an explicit null, so a
            // POS that always sends both keys is fine.)
            //
            // `nullable` leads, so that an explicitly-null amount skips the
            // integer/min rules. Without it a POS that always sends every
            // key ("cash_cents": null on a gcash sale) is rejected for
            // sending nothing. required_if and prohibited_unless are
            // implicit rules and still run against null, so a split with a
            // null half is still caught.
            'cash_cents' => [
                'nullable',
                'required_if:payment_method,split',
                'prohibited_unless:payment_method,split',
                'integer',
                'min:1',
            ],
            'gcash_cents' => [
                'nullable',
                'required_if:payment_method,split',
                'prohibited_unless:payment_method,split',
                'integer',
                'min:1',
            ],

            'discount_cents' => ['sometimes', 'integer', 'min:0'],

            // P4: which till this sale is rung up on, for cash-session
            // attribution only — see CheckoutAction::resolveOpenCashSessionId().
            // Optional; omitted means the merchant's default register.
            // Not Rule::exists() scoped to the merchant: tenancy is
            // BelongsToMerchant's job via the scoped Register lookup in
            // the Action, not a validation rule's — a foreign or unknown
            // id there simply resolves to no open session, exactly like
            // "no register configured" would.
            'register_id' => ['sometimes', 'integer', 'min:1'],

            // `list`, not just `array`: a JSON object ({"0": {...}}) would
            // otherwise pass, arrive with string keys, and quietly become a
            // basket whose ordering the client controls. A basket is a
            // sequence of lines; say so.
            'items' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],

            'items.*.add_ons' => ['sometimes', 'array', 'list', 'max:'.self::MAX_ADD_ONS_PER_ITEM],
            'items.*.add_ons.*.name' => ['required', 'string', 'min:1', 'max:100'],
            'items.*.add_ons.*.price_cents' => [
                'required',
                'integer',
                'min:0',
                'max:'.self::MAX_ADD_ON_PRICE_CENTS,
            ],
        ];
    }

    /**
     * The idempotency key for this attempt, or null if the caller did not
     * send the header — in which case checkout behaves exactly as it did
     * before idempotency existed.
     */
    public function idempotencyKey(): ?string
    {
        $key = $this->validated('idempotency_key');

        return is_string($key) ? $key : null;
    }

    /**
     * The checkout payload WITHOUT the idempotency key.
     *
     * Keeping them apart matters twice over: CheckoutAction has no
     * business knowing how the attempt was identified, and the request
     * fingerprint must cover what is being sold and nothing else — a key
     * inside the hashed payload would make every attempt unique and the
     * whole mechanism inert.
     *
     * `register_id` (P4) rides along in this same payload rather than
     * being split out like the key: CheckoutFingerprint::normalise()
     * only reads the fields that change what is sold or charged and
     * silently ignores everything else, so register_id already has no
     * effect on the fingerprint without needing its own exclusion here —
     * which is correct, since which till a sale is rung up on isn't part
     * of "is this the same order."
     *
     * @return array{
     *     payment_method: string,
     *     cash_cents?: int|null,
     *     gcash_cents?: int|null,
     *     discount_cents?: int|null,
     *     register_id?: int,
     *     items: list<array{
     *         product_id: int,
     *         quantity: int,
     *         add_ons?: list<array{name: string, price_cents: int}>
     *     }>
     * }
     */
    public function checkoutPayload(): array
    {
        /**
         * @var array{
         *     payment_method: string,
         *     cash_cents?: int|null,
         *     gcash_cents?: int|null,
         *     discount_cents?: int|null,
         *     register_id?: int,
         *     items: list<array{product_id: int, quantity: int, add_ons?: list<array{name: string, price_cents: int}>}>
         * } $payload
         */
        $payload = Arr::except($this->validated(), ['idempotency_key']);

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'idempotency_key.min' => 'The Idempotency-Key header must be at least 8 characters.',
            'idempotency_key.max' => 'The Idempotency-Key header may not be longer than 255 characters.',
            'cash_cents.prohibited_unless' => 'A cash amount may only be sent with a split payment.',
            'gcash_cents.prohibited_unless' => 'A GCash amount may only be sent with a split payment.',
            'cash_cents.required_if' => 'A split payment needs a cash amount.',
            'gcash_cents.required_if' => 'A split payment needs a GCash amount.',
        ];
    }
}
