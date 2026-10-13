<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout;

use LearningMachine\KapitalBank\Checkout\Exception\ConfigurationException;

/**
 * Shape of the POST /v1/refunds body. The public API reference documents the refund
 * object but not the create-refund request; the default here is the shape the live API
 * accepts: {"id": "<payment uuid>", "amount": 2.5}.
 * Everything else is switchable without a code change.
 */
final class RefundOptions
{
    /**
     * @param string       $idField         key that carries the payment uuid (id | originalId | paymentId …)
     * @param AmountFormat $amountFormat    see AmountFormat
     * @param bool         $sendDescription add "description"
     * @param bool         $sendPosDetail   add "posDetail": {merchantId, terminalId}
     * @param array        $extra           JSON object merged last (recursively)
     */
    public function __construct(
        public readonly string $idField = 'id',
        public readonly AmountFormat $amountFormat = AmountFormat::Number,
        public readonly bool $sendDescription = false,
        public readonly bool $sendPosDetail = false,
        public readonly array $extra = [],
    ) {
        if (trim($idField) === '') {
            throw new ConfigurationException('Refund id field must not be empty.', 'refund.id_field');
        }
        if ($extra !== [] && array_is_list($extra)) {
            throw new ConfigurationException('Refund extra must be a JSON object, not a list.', 'refund.extra');
        }
    }

    /**
     * Keys: id_field, amount_format, send_description, send_pos_detail,
     * extra (array) or extra_json (string). Invalid values throw — nothing is sent.
     */
    public static function fromArray(array $config): self
    {
        $format = strtolower(trim((string) ($config['amount_format'] ?? 'number'))) ?: 'number';
        $amountFormat = AmountFormat::tryFrom($format)
            ?? throw new ConfigurationException("Invalid refund amount_format \"{$format}\" (number|string|object|object_string).", 'refund.amount_format');

        $extra = $config['extra'] ?? [];
        $extraJson = trim((string) ($config['extra_json'] ?? ''));
        if ($extraJson !== '') {
            $decoded = json_decode($extraJson, true);
            if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
                throw new ConfigurationException('Refund extra_json is not a JSON object; nothing was sent.', 'refund.extra_json');
            }
            $extra = array_replace_recursive(is_array($extra) ? $extra : [], $decoded);
        }

        return new self(
            idField: trim((string) ($config['id_field'] ?? $config['original_id_field'] ?? 'id')) ?: 'id',
            amountFormat: $amountFormat,
            sendDescription: filter_var($config['send_description'] ?? false, FILTER_VALIDATE_BOOL),
            sendPosDetail: filter_var($config['send_pos_detail'] ?? false, FILTER_VALIDATE_BOOL),
            extra: is_array($extra) ? $extra : [],
        );
    }

    /**
     * The exact refund request body for these options — no client or credentials needed, so
     * an admin page can preview it. CheckoutClient::refundBody() uses the same builder.
     *
     * @param string|null $description added only with sendDescription (cut to 255 characters)
     */
    public function body(string $paymentId, Amount $amount, string $merchantId = '', string $terminalId = '', ?string $description = null): array
    {
        $body = [$this->idField => $paymentId, 'amount' => $this->amountFormat->render($amount)];
        if ($this->sendDescription && $description !== null && $description !== '') {
            $body['description'] = mb_substr($description, 0, 255);
        }
        if ($this->sendPosDetail) {
            $body['posDetail'] = ['merchantId' => $merchantId, 'terminalId' => $terminalId];
        }
        if ($this->extra !== []) {
            $body = array_replace_recursive($body, $this->extra);
        }

        return $body;
    }
}
