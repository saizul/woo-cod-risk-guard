<?php
/**
 * Scores orders and stores the result.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\WP;

use Saizul\CodRiskGuard\Actions\Action;
use Saizul\CodRiskGuard\Engine\OrderContext;
use Saizul\CodRiskGuard\Engine\RiskResult;
use Saizul\CodRiskGuard\Support\IdentifierHasher;

/**
 * Runs the engine for a WooCommerce order and persists the outcome in
 * order meta (through the order CRUD API, so it works with HPOS and with
 * the legacy posts storage alike).
 */
final class OrderScorer
{
    private Plugin $plugin;

    /** True while an existing order is being scored again. */
    private bool $rescoring = false;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Evaluate an order without storing anything.
     */
    public function evaluate(\WC_Order $order): RiskResult
    {
        return $this->plugin->engine()->evaluate($this->plugin->contexts()->fromOrder($order));
    }

    /**
     * Action configured for a result.
     *
     * @param RiskResult     $result Scoring result.
     * @param \WC_Order|null $order  Order, null during the pre-order check.
     */
    public function actionFor(RiskResult $result, ?\WC_Order $order = null): string
    {
        $action = $this->plugin->settings()->actionResolver()->resolve($result);

        /**
         * Filters the action taken for a scored order.
         *
         * @param string         $action One of allow, hold, require_advance, block.
         * @param RiskResult     $result Scoring result.
         * @param \WC_Order|null $order  Order, null during the pre-order check.
         */
        $filtered = apply_filters('codrg_order_action', $action, $result, $order);

        return is_string($filtered) && Action::isValid($filtered) ? $filtered : $action;
    }

    /**
     * Score an order and store the result.
     *
     * @param \WC_Order $order   Order to score.
     * @param bool      $addNote Whether to add a private order note (when enabled in the settings).
     *
     * @return array{result: RiskResult, action: string}
     */
    public function score(\WC_Order $order, bool $addNote = true): array
    {
        $context = $this->plugin->contexts()->fromOrder($order);
        $engine  = $this->plugin->engine();
        $result  = $engine->evaluate($context);
        $action  = $this->actionFor($result, $order);
        $hashes  = $engine->signals()->identifierHashes($context);
        $phone   = (string) ($hashes[IdentifierHasher::TYPE_PHONE] ?? '');

        $order->update_meta_data(OrderMeta::SCORE, $result->score);
        $order->update_meta_data(OrderMeta::LEVEL, $result->level);
        $order->update_meta_data(OrderMeta::RESULT, $result->toArray());
        $order->update_meta_data(OrderMeta::ACTION, $action);
        $order->update_meta_data(OrderMeta::PHONE_HASH, $phone);
        $order->update_meta_data(OrderMeta::SCORED_AT, time());

        $this->plugin->events()->record((int) $order->get_id(), $hashes, $context->createdAt);

        // Count the order once towards the phone's history. Blocked attempts are not orders.
        if ('' !== $phone && Action::BLOCK !== $action && 'yes' !== $order->get_meta(OrderMeta::COUNTED, true)) {
            if ($this->plugin->history()->recordOrder($phone, $context->createdAt)) {
                $order->update_meta_data(OrderMeta::COUNTED, 'yes');
            }
        }

        $order->save();

        if ($addNote && (bool) $this->plugin->settings()->get('order_note', true)) {
            $order->add_order_note($this->note($result, $action), 0, false);
        }

        /**
         * Fires after an order was scored and the result stored.
         *
         * @param \WC_Order    $order   Scored order.
         * @param RiskResult   $result  Scoring result.
         * @param string       $action  Action resolved for the result.
         * @param OrderContext $context Data the score was based on.
         */
        do_action('codrg_order_scored', $order, $result, $action, $context);

        // OTP hook point: let an integration start a verification for a risky new order.
        if (! $this->rescoring && Action::ALLOW !== $action && Action::BLOCK !== $action && ! $context->phoneVerified) {
            $this->plugin->otpVerifier()->requestVerification($context);
        }

        return [
            'result' => $result,
            'action' => $action,
        ];
    }

    /**
     * Score an existing order again (admin button, WP-CLI).
     *
     * The stored action is informational for orders that are already past
     * checkout, so the one-time automatic hold is marked as used: rescoring
     * never changes an order's status later on.
     *
     * @return array{result: RiskResult, action: string}
     */
    public function rescore(\WC_Order $order, bool $addNote = true): array
    {
        $order->update_meta_data(OrderMeta::HOLD_APPLIED, 'yes');

        $this->rescoring = true;
        try {
            return $this->score($order, $addNote);
        } finally {
            $this->rescoring = false;
        }
    }

    /**
     * Result stored on an order, or null when the order was never scored.
     */
    public function stored(\WC_Order $order): ?RiskResult
    {
        $data = $order->get_meta(OrderMeta::RESULT, true);

        if (is_string($data) && '' !== $data) {
            $decoded = json_decode($data, true);
            $data    = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($data) || ! isset($data['score'])) {
            return null;
        }

        return RiskResult::fromArray($data);
    }

    /**
     * Whether the order has a stored score.
     */
    public function isScored(\WC_Order $order): bool
    {
        return '' !== (string) $order->get_meta(OrderMeta::LEVEL, true);
    }

    /**
     * Private order note summarising a result.
     */
    public function note(RiskResult $result, string $action): string
    {
        $lines = [
            sprintf(
                /* translators: 1: score between 0 and 100, 2: risk level name, 3: action name. */
                __('COD risk score: %1$d/100 (%2$s). Action: %3$s.', 'woo-cod-risk-guard'),
                $result->score,
                Presenter::levelLabel($result->level),
                Presenter::actionLabel($action)
            ),
        ];

        foreach ($result->triggered() as $rule) {
            $lines[] = sprintf('- %s (%s)', Presenter::reason($rule), Presenter::points($rule));
        }

        return implode("\n", $lines);
    }

    /**
     * Remove everything the plugin stored about an order (privacy erasure).
     */
    public function forget(\WC_Order $order): void
    {
        foreach (OrderMeta::all() as $key) {
            $order->delete_meta_data($key);
        }
        $order->save();

        $this->plugin->events()->deleteForOrder((int) $order->get_id());
    }
}
